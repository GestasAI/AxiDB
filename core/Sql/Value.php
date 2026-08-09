<?php
/**
 * AxiDB - Sql\Value: calcula lo que vale una expresion sobre un documento.
 *
 * El compañero de ValorParser: uno lee el arbol, este lo resuelve.
 *
 * Regla que atraviesa todo el archivo: **null se propaga y no revienta**. Sumar
 * a un campo que no existe da null, no un aviso ni un cero. En una consulta
 * sobre miles de documentos, uno con un campo vacio no puede tirar la consulta
 * entera ni, peor todavia, colar un cero que parezca un dato de verdad.
 *
 * Dividir por cero da null por lo mismo. Es lo que hace SQL, y aqui ademas es lo
 * unico razonable: la alternativa seria decidir por el usuario que un precio
 * dividido entre cero vale infinito.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

final class Value
{
    /**
     * @param array $expr      arbol de ValorParser
     * @param array $documento el documento sobre el que se resuelve
     * @param array $agregados valores ya calculados de los agregados, por clave
     */
    public static function de(array $expr, array $documento, array $agregados = []): mixed
    {
        return match ($expr['t'] ?? '') {
            'lit'   => $expr['v'],
            'campo' => $documento[$expr['nombre']] ?? null,
            'op'    => self::operar(
                (string) $expr['op'],
                self::de($expr['izq'], $documento, $agregados),
                self::de($expr['der'], $documento, $agregados)
            ),
            'fn'    => Functions::llamar(
                (string) $expr['nombre'],
                \array_map(static fn(array $a) => self::de($a, $documento, $agregados), $expr['args'])
            ),
            // Un agregado ya viene resuelto de Agrupacion: aqui solo se recoge.
            'agg'   => $agregados[self::claveAgregado($expr)] ?? null,
            default => null,
        };
    }

    /**
     * La clave con la que se guarda el resultado de un agregado.
     *
     * Tiene que ser la MISMA al calcularlo y al recogerlo, asi que se deriva del
     * arbol y no de como estaba escrito en la consulta: `SUM(a+b)` y `SUM( a + b )`
     * son el mismo agregado y no deben calcularse dos veces.
     */
    public static function claveAgregado(array $expr): string
    {
        return (string) $expr['fn'] . '(' . self::firma($expr['arg'] ?? null) . ')';
    }

    /** Un texto estable que representa el arbol. Tambien sirve de nombre de columna. */
    public static function firma(?array $expr): string
    {
        if ($expr === null) {
            return '*';
        }
        return match ($expr['t'] ?? '') {
            'lit'   => \is_string($expr['v']) ? "'" . $expr['v'] . "'" : \var_export($expr['v'], true),
            'campo' => (string) $expr['nombre'],
            'op'    => self::firma($expr['izq']) . ' ' . $expr['op'] . ' ' . self::firma($expr['der']),
            'fn'    => $expr['nombre'] . '(' . \implode(', ', \array_map(
                static fn(array $a) => self::firma($a), $expr['args']
            )) . ')',
            'agg'   => self::claveAgregado($expr),
            default => '?',
        };
    }

    private static function operar(string $op, mixed $a, mixed $b): mixed
    {
        // El '+' sobre textos concatena, como se espera en una base de datos que
        // guarda documentos. Para numeros suma. Mezclar los dos es un error del
        // que escribe la consulta, y devolver null lo deja a la vista.
        if ($op === '+' && (\is_string($a) || \is_string($b)) && $a !== null && $b !== null) {
            return \is_string($a) && \is_string($b) ? $a . $b : null;
        }
        if (!self::esNumero($a) || !self::esNumero($b)) {
            return null;
        }
        return match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => self::dividir($a, $b),
            '%' => (float) $b === 0.0 ? null : \fmod((float) $a, (float) $b),
            default => null,
        };
    }

    private static function dividir(int|float $a, int|float $b): int|float|null
    {
        return (float) $b === 0.0 ? null : $a / $b;
    }

    private static function esNumero(mixed $v): bool
    {
        return \is_int($v) || \is_float($v);
    }
}
