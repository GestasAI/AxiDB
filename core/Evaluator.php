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
     * Igualdad con coercion numerica, pero solo cuando de verdad hace falta.
     *
     * El 5 entero y el "5" de texto son el mismo precio: un valor que llega de un
     * formulario HTML es una cadena y tiene que casar con el numero guardado. Por
     * eso se coacciona cuando UN lado es un numero de verdad (int o float) y el
     * otro parece un numero.
     *
     * Lo que NO se hace es coaccionar dos CADENAS entre si. Ahi vivia un agujero:
     * `"0e123..."` y `"0e456..."` son las dos `is_numeric` y las dos valen 0.0 en
     * float, asi que se declaraban iguales —el "magic hash" de PHP—. Quien guarde
     * un token, una firma o un hash como texto y lo compare en un WHERE podia
     * acertar sin conocerlo. Dos cadenas se comparan con `===`, sin magia. Y ese
     * es tambien el motivo de comparar strings a mano en vez de con `==`: el `==`
     * de PHP entre dos cadenas numericas TAMBIEN las trata como numeros.
     */
    private static function areEqual(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (\is_bool($a) || \is_bool($b)) {
            return $a === $b;
        }
        // Un numero de verdad contra un texto solo casa si el texto es una forma
        // DECIMAL SIMPLE de ese numero: digitos, un punto opcional, un signo
        // opcional. "5" casa con 5; "3.14" con 3.14. Lo que NO casa es un texto
        // en notacion cientifica ni en hex: `t = 0` sobre el token "0e123..." no
        // debe encontrarlo, porque ese token vale 0.0 en float pero no es el
        // numero cero. Es el criterio de una base con tipos —el texto y el numero
        // solo se cruzan si el texto ES el numero escrito de la forma normal.
        $decimal = static fn(mixed $v): bool
            => \is_string($v) && \preg_match('/^-?\d+(\.\d+)?$/', $v) === 1;

        $aNum = \is_int($a) || \is_float($a);
        $bNum = \is_int($b) || \is_float($b);
        if (($aNum && $decimal($b)) || ($bNum && $decimal($a))
            || ($aNum && $bNum)) {
            return (float) $a === (float) $b;
        }
        if ($aNum || $bNum) {
            // Un numero contra un texto que NO es un decimal simple: distintos.
            // Sin este corte, el `==` de mas abajo trataria "0e123..." == 0 como
            // numeros (PHP 8 sigue coaccionando dos operandos numericos) y el
            // token colisionaria con el cero.
            return false;
        }
        if (\is_string($a) && \is_string($b)) {
            return $a === $b;                       // sin magic hash
        }
        return $a == $b;
    }

    /** -1, 0 o 1. null se ordena por debajo de todo. */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return $a === $b ? 0 : ($a === null ? -1 : 1);
        }
        // Un array no se convierte a texto: `(string) [1,2]` es "Array" y emite un
        // aviso de PHP que, por el puente HTTP, acababa en la respuesta. Un array
        // se ordena por encima de cualquier escalar, de forma definida y callada.
        $aArr = \is_array($a);
        $bArr = \is_array($b);
        if ($aArr || $bArr) {
            return $aArr <=> $bArr;                 // escalar < array; array == array
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
