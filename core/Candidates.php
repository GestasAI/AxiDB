<?php
/**
 * AxiDB - Core\Candidates: de que documentos arranca una consulta.
 *
 * Tres caminos, y elegir bien es la diferencia entre leer diez documentos o
 * leer la coleccion entera:
 *
 *   transaccion   los que ve la transaccion abierta. Sin indices, porque el
 *                 indice vive en el disco y no sabe lo que aun no se ha
 *                 confirmado: resolverlo por indice daria el conjunto de antes
 *   indice        hay una igualdad sobre un campo indexado que se cumple
 *                 siempre. O(coincidencias) en vez de O(coleccion)
 *   recorrido     lo demas
 *
 * Devuelve tambien el plan, que es lo que cuenta EXPLAIN. Que la decision y su
 * explicacion salgan del mismo sitio no es cosmetico: si se calcularan aparte,
 * EXPLAIN podria decir una cosa y la consulta hacer otra.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Candidates
{
    /**
     * @param \Closure|null $fuente documentos ya resueltos, si los hay
     * @return array{0: list<array>, 1: array} [documentos, plan]
     */
    public static function de(
        Storage $storage,
        Index $index,
        string $collection,
        array $where,
        ?array $expr,
        ?\Closure $fuente
    ): array {
        if ($fuente !== null) {
            $docs = ($fuente)();
            return [$docs, self::plan('transaccion', null, null, \count($docs))];
        }

        $igualdad = (new Planner($index, $collection))->igualdadIndexable($where, $expr);

        if ($igualdad === null) {
            $docs = $storage->all($collection);
            return [$docs, self::plan('scan', null, null, \count($docs))];
        }

        [$campo, $valor] = $igualdad;
        $docs = [];
        foreach ($index->ids($collection, $campo, $valor) ?? [] as $id) {
            $d = $storage->get($collection, (string) $id);
            if ($d !== null) {
                $docs[] = $d;
            }
        }
        return [$docs, self::plan('index', $campo, $valor, \count($docs))];
    }

    private static function plan(string $estrategia, ?string $campo, ?string $valor, int $cuantos): array
    {
        return ['strategy' => $estrategia, 'field' => $campo,
                'value' => $valor, 'candidates' => $cuantos];
    }
}
