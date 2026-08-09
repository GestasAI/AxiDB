<?php
/**
 * AxiDB - Fachada\ConIndices: los indices secundarios de Db.
 *
 * Db es la puerta de entrada a cuatro subsistemas -documentos, indices,
 * vectores y agentes- y con los cuatro dentro pasaba de 300 lineas. Cada uno
 * vive ahora en su propio archivo, y Db los junta.
 *
 * No cambia nada de puertas afuera: se sigue escribiendo `$db->index(...)`.
 */

declare(strict_types=1);

namespace Axi\Core\Fachada;

use Axi\Core\Index;
use Axi\Core\IndexVerifier;
use Axi\Core\Unicidad;

trait ConIndices
{
    /* ─────────────────────────────── Indices ─────────────────────────────── */

    /**
     * Declara y construye un indice sobre un campo. Idempotente: volver a
     * llamarlo reconstruye, que es tambien la forma de reparar uno dañado.
     * @return int numero de valores distintos indexados
     */
    public function index(string $collection, string $field): int
    {
        return $this->index->build($collection, $field);
    }

    /**
     * Declara el indice solo si aun no existe. A diferencia de index(), no
     * reconstruye: es barato de llamar en cada escritura para que una coleccion
     * que aparece en tiempo de ejecucion quede indexada desde su primer
     * documento, sin releer la coleccion entera cada vez.
     *
     * @return bool true si lo ha creado ahora, false si ya estaba
     */
    public function ensureIndex(string $collection, string $field): bool
    {
        if ($this->index->isIndexed($collection, $field)) {
            return false;
        }
        $this->index->build($collection, $field);
        return true;
    }

    public function indexes(string $collection): array
    {
        return $this->index->fields($collection);
    }

    /**
     * Declara que un campo no admite valores repetidos, y lo hace cumplir en
     * cada alta y cada modificacion a partir de ese momento.
     *
     * Exige que la coleccion no tenga ya repetidos: declarar la restriccion
     * sobre datos que la incumplen dejaria un estado que ninguna escritura
     * posterior puede arreglar.
     *
     * @return int valores distintos indexados
     */
    public function unico(string $collection, string $field): int
    {
        Unicidad::exigirSinRepetidos($this->storage, $collection, $field);
        $valores = $this->index->build($collection, $field);
        $this->storage->declararUnico($collection, $field);
        return $valores;
    }

    /** @return list<string> campos declarados unicos */
    public function unicos(string $collection): array
    {
        return $this->storage->unicosDe($collection);
    }

    /**
     * Acceso al indice secundario, para lo que no cubre la fachada.
     *
     * Simetrico a `storage()` y a `vectorial()`: los tres subsistemas se pueden
     * alcanzar. Sin este, quien necesitara algo de Index —inspeccionar buckets,
     * reservar un valor— tenia que hacer `new Index($db->storage())`, que
     * funciona pero crea una segunda instancia y no esta escrito en ningun sitio.
     */
    public function indice(): Index
    {
        return $this->index;
    }

    /**
     * Quitar el indice quita tambien la unicidad. Dejarla declarada sin indice
     * seria una restriccion que el motor no puede comprobar —la reserva vive en
     * el propio indice—, o sea otra promesa a medias.
     */
    public function dropIndex(string $collection, string $field): bool
    {
        $this->storage->declararUnico($collection, $field, false);
        return $this->index->drop($collection, $field);
    }

    /** Reconstruye todos los indices declarados de una coleccion. */
    public function reindex(string $collection): array
    {
        $out = [];
        foreach ($this->index->fields($collection) as $field) {
            $out[$field] = $this->index->build($collection, $field);
        }
        return $out;
    }

    /**
     * Comprueba si los indices declarados reflejan la realidad de los
     * documentos. Los documentos se sincronizan a disco con fsync, pero los
     * indices no — son estado derivado — asi que un corte de corriente puede
     * dejarlos cortos. Esta es la forma de detectarlo; reindex() lo repara.
     *
     * @return array<string, array{documentos:int, indexados:int, faltan:int}>
     */
    public function verifyIndexes(string $collection): array
    {
        $out = [];
        foreach ($this->index->fields($collection) as $field) {
            $out[$field] = IndexVerifier::check($this->storage, $this->index, $collection, $field);
        }

        /*
         * Los directorios de indice de los que no se puede saber que campo
         * guardaban. Pasa con los creados antes de que el nombre se anotara, si
         * el campo llevaba mayusculas: `createdAt` se guarda como
         * `createdat~4f2a1c9b` y de ahi no se vuelve al original.
         *
         * Salen aqui porque ese indice NO se mantiene —no hay forma de saber
         * que campo mirar en cada documento— y callarlo seria repetir el fallo
         * que se acaba de corregir. Se arregla borrandolo y creandolo de nuevo.
         */
        foreach ($this->index->camposIlegibles($collection) as $directorio) {
            $out[$directorio] = [
                'ilegible' => true,
                'aviso'    => "El indice '{$directorio}' es de una version anterior y no dice que "
                    . 'campo guarda, asi que no se puede mantener. Borralo con '
                    . "dropIndex('{$collection}', '<campo>') y vuelve a crearlo.",
            ];
        }
        return $out;
    }
}
