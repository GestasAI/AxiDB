<?php
/**
 * AxiDB - Sql\ResultSet: de documentos filtrados a las filas que se devuelven.
 *
 * Lo que pasa despues del WHERE, y en este orden, que es el de SQL:
 *
 *   agrupar -> HAVING -> proyectar -> DISTINCT -> ORDER BY -> LIMIT
 *
 * El orden no es negociable. HAVING filtra grupos y por eso va despues de
 * agrupar y antes de proyectar; DISTINCT mira las filas ya proyectadas, asi que
 * `SELECT DISTINCT ciudad` quita ciudades repetidas aunque los documentos fueran
 * distintos en todo lo demas; y LIMIT va el ultimo, porque limitar antes de
 * ordenar devuelve diez cualesquiera en vez de los diez primeros.
 *
 * ORDER BY mira primero la fila y luego el documento de origen. Asi se puede
 * ordenar tanto por un alias del SELECT —`ORDER BY total`— como por un campo que
 * no se ha pedido —`SELECT nombre ... ORDER BY precio`—, que son las dos cosas
 * que uno espera y que no salen gratis si se proyecta antes de ordenar.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Evaluator;

final class ResultSet
{
    /**
     * @param list<array> $documentos ya filtrados por el WHERE
     * @return list<array>
     */
    public static function construir(array $documentos, array $ast): array
    {
        $proyeccion = $ast['fields'] ?? ['*'];
        $agrupa     = ($ast['group_by'] ?? []) !== [];
        $agregados  = Grouping::recoger(
            [...self::expressionsOf($proyeccion), $ast['having'] ?? null]
        );

        $pares = $agrupa || $agregados !== []
            ? self::byGroups($documentos, $ast, $agregados)
            : \array_map(
                static fn(array $doc) => [self::project($doc, $proyeccion, []), $doc],
                $documentos
            );

        if (!empty($ast['distinct'])) {
            $pares = self::distinctRows($pares);
        }
        if (($ast['order_by'] ?? []) !== []) {
            $pares = self::sortRows($pares, $ast['order_by']);
        }
        $filas = \array_map(static fn(array $par) => $par[0], $pares);

        $limite = $ast['limit'] ?? null;
        $desde  = $ast['offset'] ?? 0;
        if ($limite !== null) {
            return \array_slice($filas, $desde, $limite);
        }
        return $desde > 0 ? \array_slice($filas, $desde) : $filas;
    }

    /**
     * @return list<array{0: array, 1: array}> pares de [fila, documento de origen]
     */
    private static function byGroups(array $documentos, array $ast, array $agregados): array
    {
        $pares = [];
        foreach (Grouping::build($documentos, $ast['group_by'] ?? [], $agregados) as $grupo) {
            // El documento de referencia del grupo: sus campos agrupados valen
            // para todos, y el resto sirve para ordenar por algo no agregado.
            $origen = ($grupo['documentos'][0] ?? []) ;
            $origen = $grupo['clave'] + $origen;

            /*
             * El HAVING se evalua sobre la fila ya proyectada, no solo sobre el
             * documento: asi ve los alias del SELECT.
             *
             *   SELECT depto, SUM(salario) AS coste ... HAVING coste > 60000
             *
             * Antes eso devolvia cero filas, en silencio y sin error, porque
             * `coste` no es un campo de ningun documento. Y el mismo alias si
             * funcionaba en ORDER BY, que ordena sobre la fila proyectada: el
             * motor se contradecia consigo mismo segun donde escribieras el
             * nombre. Se resuelve igual que en MySQL y SQLite —primero el alias,
             * despues el campo— y por eso la fila va delante en la suma.
             */
            $fila = self::project($origen, $ast['fields'] ?? ['*'], $grupo['valores']);

            if (isset($ast['having']) && !Evaluator::matches($fila + $origen, $ast['having'], $grupo['valores'])) {
                continue;
            }
            $pares[] = [$fila, $origen];
        }
        return $pares;
    }

    /** @param array<string,mixed> $agregados */
    private static function project(array $doc, array $proyeccion, array $agregados): array
    {
        if ($proyeccion === ['*']) {
            return $doc;
        }
        $fila = [];
        foreach ($proyeccion as $item) {
            if (!empty($item['star'])) {
                $fila += $doc;
                continue;
            }
            $nombre = $item['alias'] ?? Value::firma($item['expr']);
            $fila[$nombre] = Value::of($item['expr'], $doc, $agregados);
        }
        return $fila;
    }

    /** @return list<?array> las expresiones de la proyeccion, sin el `*` */
    private static function expressionsOf(array $proyeccion): array
    {
        if ($proyeccion === ['*']) {
            return [];
        }
        $fuera = [];
        foreach ($proyeccion as $item) {
            if (empty($item['star']) && isset($item['expr'])) {
                $fuera[] = $item['expr'];
            }
        }
        return $fuera;
    }

    /**
     * @param list<array{0: array, 1: array}> $pares
     * @return list<array{0: array, 1: array}>
     */
    private static function distinctRows(array $pares): array
    {
        $vistas = [];
        $fuera  = [];
        foreach ($pares as $par) {
            $huella = (string) \json_encode($par[0], JSON_UNESCAPED_UNICODE);
            if (!isset($vistas[$huella])) {
                $vistas[$huella] = true;
                $fuera[] = $par;
            }
        }
        return $fuera;
    }

    /**
     * @param list<array{0: array, 1: array}> $pares
     * @return list<array{0: array, 1: array}>
     */
    private static function sortRows(array $pares, array $clausulas): array
    {
        \usort($pares, static function (array $a, array $b) use ($clausulas): int {
            foreach ($clausulas as $c) {
                $campo = $c['field'];
                $va = $a[0][$campo] ?? $a[1][$campo] ?? null;
                $vb = $b[0][$campo] ?? $b[1][$campo] ?? null;
                if ($va == $vb) {
                    continue;
                }
                $cmp = ($va > $vb) ? 1 : -1;
                return $c['dir'] === 'desc' ? -$cmp : $cmp;
            }
            return 0;
        });
        return $pares;
    }
}
