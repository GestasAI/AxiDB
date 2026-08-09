<?php
/**
 * AxiDB - Tx\Aplicador: pasar el plan a documentos de verdad.
 *
 * Se usa en los dos sitios que aplican un plan: al confirmar, y al recuperar
 * una transaccion que se quedo a medias. Tiene que ser el mismo codigo, porque
 * si recuperar aplicara de otra forma que confirmar, el estado tras un corte no
 * seria el que habria salido sin corte.
 *
 * Pasa por `Db::put` y `Db::delete` a proposito, no por Storage: asi los
 * indices, la unicidad y los vectores se mantienen igual que en una escritura
 * normal. Una transaccion no es una puerta trasera al almacen.
 *
 * Aplicar dos veces deja el mismo resultado porque se escribe el documento
 * entero. Lo unico que avanza de mas es `_version`, y se dice en la guia: tras
 * recuperar de un corte, la version puede haber subido dos en vez de una.
 */

declare(strict_types=1);

namespace Axi\Core\Tx;

use Axi\Core\Db;

final class Aplicador
{
    /**
     * @param list<array{coleccion:string, id:string, accion:string, datos?:array}> $operaciones
     * @return int operaciones aplicadas
     */
    public static function aplicar(Db $db, array $operaciones): int
    {
        $hechas = 0;
        foreach ($operaciones as $op) {
            $coleccion = (string) ($op['coleccion'] ?? '');
            $id        = (string) ($op['id'] ?? '');
            if ($coleccion === '' || $id === '') {
                continue;
            }
            if (($op['accion'] ?? '') === 'borrar') {
                $db->delete($coleccion, $id);
            } else {
                $db->put($coleccion, $id, (array) ($op['datos'] ?? []), true);
            }
            $hechas++;
        }
        return $hechas;
    }
}
