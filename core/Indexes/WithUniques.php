<?php
/**
 * AxiDB - Indices\WithUniques: reclamar un valor para un solo documento.
 *
 * Vive dentro de Index y no en una clase aparte por una razon concreta: la
 * comprobacion y la reserva tienen que ocurrir bajo EL MISMO cerrojo. Si se
 * consultara el indice desde fuera y se escribiera despues, dos procesos que
 * insertan el mismo correo a la vez pasarian los dos la comprobacion y los dos
 * escribirian. Una restriccion que se cumple casi siempre no es una restriccion.
 *
 * El cerrojo es el del archivo de ese valor —`_idx/<campo>/<valor>.json`— asi
 * que dos altas con valores distintos no se esperan la una a la otra.
 */

declare(strict_types=1);

namespace Axi\Core\Indexes;

use Axi\Core\Exception;

trait WithUniques
{
    /**
     * Reserva el valor para $id. Lanza si ya lo tiene otro documento.
     *
     * Reclamar ANTES de escribir el documento, no despues, es lo que hace que
     * la restriccion valga: si se escribiera primero habria que deshacer una
     * escritura ya hecha, y ese deshacer puede fallar.
     *
     * Lo que esto NO evita: si el proceso muere entre la reserva y la escritura
     * del documento, el indice se queda con una entrada que no apunta a nada y
     * ese valor queda ocupado. No se pierde ningun dato y `verifyIndexes()` lo
     * detecta como sobrante, pero conviene saberlo.
     */
    public function reclamar(string $collection, string $field, string $value, string $id): void
    {
        $this->mutate(
            $collection,
            $field,
            $value,
            static function (array $ids) use ($field, $value, $id): array {
                foreach ($ids as $dueño) {
                    if ((string) $dueño !== $id) {
                        throw new Exception(
                            "'{$field}' es unico y el valor '{$value}' ya es de '{$dueño}'."
                        );
                    }
                }
                $ids[] = $id;
                return $ids;
            }
        );
    }
}
