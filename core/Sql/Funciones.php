<?php
/**
 * AxiDB - Sql\Funciones: lo que se puede llamar dentro de una consulta.
 *
 * Nombres en castellano, como el resto de AxiDB. Y pocas: cada funcion que se
 * añade hay que documentarla, probarla y mantenerla para siempre, asi que solo
 * entran las que resuelven algo que de otro modo obliga a sacar los documentos a
 * PHP y recorrerlos a mano.
 *
 * Las de fecha son las que mas falta hacian. Una fecha en AxiDB es una cadena
 * ISO 8601 —`2026-08-09T12:13:21+02:00`— asi que hasta ahora "los pedidos del
 * mes pasado" habia que calcularlo fuera. Con `MONTH()` y `AÑO()` cabe en el WHERE.
 *
 * Todo lo que recibe algo que no entiende devuelve null en vez de reventar. En
 * una consulta sobre miles de documentos, uno con el campo vacio no puede tirar
 * la consulta entera: eso convertiria cualquier dato irregular en una caida.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class Funciones
{
    /** Cuantos argumentos admite cada una: [minimo, maximo]. */
    private const FIRMAS = [
        // Texto
        'UPPER'   => [1, 1],  'LOWER'  => [1, 1],  'LENGTH'   => [1, 1],
        'TRIM' => [1, 1],  'CONCAT'   => [1, 99], 'SUBSTR'   => [2, 3],
        'REPLACE' => [3, 3],
        // Numero
        'ROUND' => [1, 2], 'ABS'    => [1, 1],  'CEIL'   => [1, 1],
        'FLOOR'    => [1, 1],
        // Fecha
        'NOW'   => [0, 0],  'CURDATE'    => [0, 0],  'DATE'   => [1, 1],
        'YEAR'    => [1, 1],  'MONTH'    => [1, 1],  'DAY'     => [1, 1],
        'HOUR'    => [1, 1],  'DATEDIFF' => [2, 2],
        // Generales
        'IFNULL' => [2, 2],
    ];

    /**
     * `AÑO` con eñe es lo natural en castellano, asi que se acepta.
     *
     * Aparecen las dos formas de la eñe porque `strtoupper` trabaja con bytes:
     * sobre 'año' deja 'AñO', no 'AÑO'. Sin mbstring —que este nucleo no usa a
     * proposito— la unica manera de cubrir las dos es nombrarlas.
     */
    private const ALIAS = [
        'AÑO' => 'YEAR', 'AñO' => 'YEAR',
        'YEAR' => 'YEAR', 'MONTH' => 'MONTH', 'NOW' => 'NOW',
    ];

    public static function existe(string $nombre): bool
    {
        return isset(self::FIRMAS[self::canonico($nombre)]);
    }

    public static function canonico(string $nombre): string
    {
        $nombre = \strtoupper($nombre);
        return self::ALIAS[$nombre] ?? $nombre;
    }

    /** Comprueba el numero de argumentos al ANALIZAR, no al ejecutar. */
    public static function exigirFirma(string $nombre, int $cuantos): void
    {
        $canon = self::canonico($nombre);
        if (!isset(self::FIRMAS[$canon])) {
            throw new Exception(
                "AxiSQL: la funcion '{$nombre}' no existe. Hay: "
                . \implode(', ', \array_keys(self::FIRMAS)) . '.'
            );
        }
        [$min, $max] = self::FIRMAS[$canon];
        if ($cuantos < $min || $cuantos > $max) {
            throw new Exception(
                "AxiSQL: {$canon}() admite " . ($min === $max ? "{$min}" : "de {$min} a {$max}")
                . " argumentos y le llegan {$cuantos}."
            );
        }
    }

    /** @param list<mixed> $args */
    public static function llamar(string $nombre, array $args): mixed
    {
        return match (self::canonico($nombre)) {
            'UPPER'     => self::texto($args[0], static fn($s) => \strtoupper($s)),
            'LOWER'     => self::texto($args[0], static fn($s) => \strtolower($s)),
            'TRIM'   => self::texto($args[0], static fn($s) => \trim($s)),
            'LENGTH'    => self::largo($args[0]),
            'CONCAT'      => \implode('', \array_map(static fn($v) => self::comoTexto($v), $args)),
            'SUBSTR'     => self::trozo($args),
            'REPLACE' => self::texto($args[0], static fn($s) => \str_replace(
                self::comoTexto($args[1]), self::comoTexto($args[2]), $s
            )),

            'ROUND'  => self::numero($args[0], static fn($n) => \round($n, (int) ($args[1] ?? 0))),
            'ABS'       => self::numero($args[0], static fn($n) => \abs($n)),
            'CEIL'     => self::numero($args[0], static fn($n) => (float) \ceil($n)),
            'FLOOR'     => self::numero($args[0], static fn($n) => (float) \floor($n)),

            'NOW'     => \date('c'),
            'CURDATE'       => \date('Y-m-d'),
            'DATE'     => self::parte($args[0], 'Y-m-d'),
            'YEAR'      => self::parteInt($args[0], 'Y'),
            'MONTH'       => self::parteInt($args[0], 'n'),
            'DAY'       => self::parteInt($args[0], 'j'),
            'HOUR'      => self::parteInt($args[0], 'G'),
            'DATEDIFF' => self::diasEntre($args[0], $args[1]),

            'IFNULL'   => $args[0] ?? $args[1],
            default     => throw new Exception("AxiSQL: la funcion '{$nombre}' no existe."),
        };
    }

    private static function texto(mixed $v, callable $fn): ?string
    {
        return \is_scalar($v) ? $fn((string) $v) : null;
    }

    private static function numero(mixed $v, callable $fn): int|float|null
    {
        return \is_int($v) || \is_float($v) ? $fn($v) : null;
    }

    private static function largo(mixed $v): ?int
    {
        if (\is_array($v)) {
            return \count($v);
        }
        return \is_scalar($v) ? \strlen((string) $v) : null;
    }

    private static function trozo(array $args): ?string
    {
        if (!\is_scalar($args[0])) {
            return null;
        }
        $desde = (int) $args[1];
        return isset($args[2])
            ? \substr((string) $args[0], $desde, (int) $args[2])
            : \substr((string) $args[0], $desde);
    }

    private static function comoTexto(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return \is_scalar($v) ? (string) $v : '';
    }

    /** Una marca de tiempo, o null si eso no es una fecha. */
    private static function marca(mixed $v): ?int
    {
        if (!\is_string($v) || $v === '') {
            return null;
        }
        $t = \strtotime($v);
        return $t === false ? null : $t;
    }

    private static function parte(mixed $v, string $formato): ?string
    {
        $t = self::marca($v);
        return $t === null ? null : \date($formato, $t);
    }

    private static function parteInt(mixed $v, string $formato): ?int
    {
        $t = self::marca($v);
        return $t === null ? null : (int) \date($formato, $t);
    }

    private static function diasEntre(mixed $a, mixed $b): ?int
    {
        $ta = self::marca($a);
        $tb = self::marca($b);
        if ($ta === null || $tb === null) {
            return null;
        }
        return \intdiv($tb - $ta, 86400);
    }
}
