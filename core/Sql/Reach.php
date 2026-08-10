<?php
/**
 * AxiDB - Sql\Reach: que colecciones toca una sentencia.
 *
 * El control de acceso —el del puente HTTP y el del sandbox de un agente— tiene
 * que decidir sobre TODAS las colecciones que una sentencia lee o escribe, no
 * solo la del FROM. Un JOIN alcanza otra coleccion; una subconsulta en el WHERE,
 * tambien. Mirar unicamente `collection` dejaba una puerta con el cerrojo puesto
 * y la ventana de al lado abierta.
 *
 * Vive aparte porque lo usan dos sitios (Bridge y Agent) y porque la recursion
 * por el arbol de expresiones tiene su propia miga: las subconsultas se
 * REANALIZAN con el Parser, nunca se leen con una expresion regular. El texto de
 * una subconsulta es SQL, y sacarle el nombre de la tabla con regex es como abrir
 * un candado con una horquilla: funciona hasta que alguien escribe algo que no
 * esperabas.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

final class Reach
{
    /**
     * Nombres de coleccion que toca el AST, sin repetir. Incluye el FROM, cada
     * JOIN y las subconsultas del WHERE y del HAVING (a cualquier profundidad).
     *
     * Una subconsulta que no analiza se representa con un nombre imposible, para
     * que el que decide los permisos la niegue en vez de dejarla pasar sin mirar.
     *
     * @return list<string>
     */
    public static function collections(array $ast): array
    {
        $acc = [];
        self::gather($ast, $acc);
        return \array_keys($acc);
    }

    /** @param array<string,true> $acc por referencia */
    private static function gather(array $ast, array &$acc): void
    {
        if (isset($ast['collection']) && \is_string($ast['collection'])) {
            $acc[$ast['collection']] = true;
        }
        foreach ($ast['joins'] ?? [] as $j) {
            if (isset($j['coleccion']) && \is_string($j['coleccion'])) {
                $acc[$j['coleccion']] = true;
            }
        }
        foreach (['where_expr', 'having'] as $rama) {
            if (isset($ast[$rama]) && \is_array($ast[$rama])) {
                self::fromExpr($ast[$rama], $acc);
            }
        }
    }

    /** @param array<string,true> $acc por referencia */
    private static function fromExpr(array $nodo, array &$acc): void
    {
        foreach (['sql', 'subquery'] as $clave) {
            if (isset($nodo[$clave]) && \is_string($nodo[$clave])) {
                try {
                    self::gather((new Parser())->parse($nodo[$clave]), $acc);
                } catch (\Throwable) {
                    $acc["\0subconsulta-ilegible"] = true;
                }
            }
        }
        foreach (['left', 'right', 'expr', 'value'] as $hijo) {
            if (isset($nodo[$hijo]) && \is_array($nodo[$hijo])) {
                self::fromExpr($nodo[$hijo], $acc);
            }
        }
    }
}
