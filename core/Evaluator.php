<?php
/**
 * AxiDB - Core\Evaluator: la semantica de comparacion del motor.
 *
 * Unico sitio donde se decide que significa que un documento cumpla una
 * condicion. Lo usan las dos entradas —el constructor de consultas y AxiSQL—
 * para que `find()->where('precio','>',5)` y `WHERE precio > 5` no puedan
 * divergir nunca. Tener dos evaluadores es tener dos motores.
 *
 * Vive en el nucleo y no en Sql/ a proposito: comparar valores es de la base de
 * datos, no del lenguaje con el que se le pregunta.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Evaluator
{
    /** Operadores admitidos, en el orden en que se documentan. */
    public const OPERADORES = [
        '=', '!=', '>', '>=', '<', '<=',
        'IN', 'NOT IN', 'LIKE', 'NOT LIKE', 'CONTAINS', 'BETWEEN', 'NOT BETWEEN',
        'IS NULL', 'IS NOT NULL',
    ];

    /**
     * Evalua un arbol de expresion (and / or / not / cmp) sobre un documento.
     * Un arbol nulo no filtra nada.
     */
    public static function matches(array $doc, ?array $expr, array $agregados = []): bool
    {
        if ($expr === null) {
            return true;
        }
        return match ($expr['type'] ?? null) {
            'and' => self::matches($doc, $expr['left'], $agregados)
                  && self::matches($doc, $expr['right'], $agregados),
            'or'  => self::matches($doc, $expr['left'], $agregados)
                  || self::matches($doc, $expr['right'], $agregados),
            'not' => !self::matches($doc, $expr['expr'], $agregados),
            'cmp' => self::comparison($doc, $expr, $agregados),
            default => throw new Exception(
                "Evaluator: unknown expression node '" . ($expr['type'] ?? 'null') . "'."
            ),
        };
    }

    /**
     * Una comparacion cuyo lado izquierdo puede ser un campo o una expresion.
     *
     * `WHERE precio > 5` compara un campo; `WHERE MONTH(fecha) = 3` y
     * `HAVING SUM(total) > 100` comparan el resultado de calcular algo. Se
     * distinguen por si el nodo trae `expr`: el camino del campo se queda
     * exactamente como estaba, que es el de casi todas las consultas y el que
     * no debe pagar nada por esto.
     */
    private static function comparison(array $doc, array $nodo, array $agregados): bool
    {
        if (isset($nodo['expr'])) {
            // El lado derecho tambien puede ser una expresion: `precio > coste * 2`.
            $derecha = isset($nodo['valueExpr'])
                ? Sql\Value::of($nodo['valueExpr'], $doc, $agregados)
                : ($nodo['value'] ?? null);

            return self::onValue(
                Sql\Value::of($nodo['expr'], $doc, $agregados),
                true,
                (string) $nodo['op'],
                $derecha
            );
        }
        return self::cmp($doc, (string) $nodo['field'], (string) $nodo['op'], $nodo['value'] ?? null);
    }

    /**
     * Una sola comparacion. Recibe el documento entero, y no solo el valor,
     * porque IS NULL tiene que poder distinguir "el campo vale null" de
     * "el campo no esta".
     */
    public static function cmp(array $doc, string $field, string $op, mixed $target): bool
    {
        return self::onValue($doc[$field] ?? null, \array_key_exists($field, $doc), $op, $target);
    }

    /** El nucleo de la comparacion, ya con el valor de la izquierda resuelto. */
    private static function onValue(mixed $actual, bool $existe, string $op, mixed $target): bool
    {
        $op = \strtoupper(\trim($op));

        return match ($op) {
            '=', '=='     => self::areEqual($actual, $target),
            '!=', '<>'    => !self::areEqual($actual, $target),
            '>'           => self::compare($actual, $target) > 0,
            '>='          => self::compare($actual, $target) >= 0,
            '<'           => self::compare($actual, $target) < 0,
            '<='          => self::compare($actual, $target) <= 0,
            'IN'          => \is_array($target) && self::inList($actual, $target),
            'NOT IN'      => \is_array($target) && !self::inList($actual, $target),
            'LIKE'        => \is_string($actual) && self::like($actual, (string) $target),
            'NOT LIKE'    => !(\is_string($actual) && self::like($actual, (string) $target)),
            'CONTAINS'    => self::contains($actual, $target),
            'BETWEEN'     => self::entre($actual, $target),
            'NOT BETWEEN' => !self::entre($actual, $target),
            'IS NULL'     => !$existe || $actual === null,
            'IS NOT NULL' => $existe && $actual !== null,
            default => throw new Exception(
                "Evaluator: unsupported operator '{$op}'. Admitidos: "
                . \implode(', ', self::OPERADORES) . '.'
            ),
        };
    }

    /**
     * Igualdad con coercion numerica: el 5 entero y el "5" de texto son el
     * mismo precio. Sin ella, un valor que ha pasado por un formulario HTML
     * nunca casaria con el numero guardado.
     */
    private static function areEqual(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (\is_bool($a) || \is_bool($b)) {
            return $a === $b;
        }
        if (\is_numeric($a) && \is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return $a == $b;
    }

    /** -1, 0 o 1. null se ordena por debajo de todo. */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return $a === $b ? 0 : ($a === null ? -1 : 1);
        }
        if (\is_numeric($a) && \is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }
        return \strcmp((string) $a, (string) $b);
    }

    private static function inList(mixed $aguja, array $pajar): bool
    {
        foreach ($pajar as $item) {
            if (self::areEqual($aguja, $item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * BETWEEN incluye los dos extremos, como en SQL: quien escribe
     * `precio BETWEEN 10 AND 20` esta pensando en "de diez a veinte".
     *
     * @param mixed $limites [minimo, maximo]
     */
    private static function entre(mixed $actual, mixed $limites): bool
    {
        if (!\is_array($limites) || \count($limites) !== 2 || $actual === null) {
            return false;
        }
        return self::compare($actual, $limites[0]) >= 0
            && self::compare($actual, $limites[1]) <= 0;
    }

    /** LIKE con % (cualquier secuencia) y _ (un caracter). Sin distinguir mayusculas. */
    private static function like(string $actual, string $patron): bool
    {
        $regex = '/^' . \strtr(\preg_quote($patron, '/'), ['%' => '.*', '_' => '.']) . '$/iu';
        return (bool) \preg_match($regex, $actual);
    }

    /** En texto, subcadena. En lista, pertenencia. */
    private static function contains(mixed $actual, mixed $aguja): bool
    {
        if (\is_array($actual)) {
            return self::inList($aguja, $actual);
        }
        if (\is_string($actual) && (\is_string($aguja) || \is_numeric($aguja))) {
            return \stripos($actual, (string) $aguja) !== false;
        }
        return false;
    }
}
