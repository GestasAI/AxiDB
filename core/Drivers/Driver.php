<?php
/**
 * AxiDB - Drivers\Driver: como se guardan los documentos.
 *
 * Dos implementaciones con el mismo contrato y compromisos opuestos:
 *
 *   FsDriver      un archivo por documento. Legible a ojo, diff-eable en git,
 *                 reparable a mano. Paga una llamada al sistema por documento.
 *   PackedDriver  un archivo por coleccion, solo se añade al final. Rapido de
 *                 verdad, a cambio de que el dato deje de verse en el explorador.
 *
 * La eleccion es por coleccion, no global: una coleccion de mucho volumen puede
 * ir en packed y su configuracion en fs, donde interesa poder abrirla y leerla.
 *
 * Lo que NO entra aqui: el ciclo de vida de las colecciones (crear, listar,
 * borrar) es igual para los dos y vive en Collections.
 */

declare(strict_types=1);

namespace Axi\Core\Drivers;

interface Driver
{
    /** Nombre corto del driver, tal y como se guarda en la coleccion. */
    public function driverName(): string;

    /**
     * Crea o actualiza. Fusiona con lo existente salvo que $replace sea true.
     * Devuelve el documento persistido, con id, _version, _createdAt y _updatedAt.
     */
    public function put(string $collection, string $id, array $data, bool $replace = false): array;

    /**
     * Escribe el documento tal y como viene, sin tocarle un solo metadato.
     *
     * Existe solo para copiar entre drivers. put() no sirve para eso: le subiria
     * la version y le pondria la fecha de ahora, con lo que cambiar de formato
     * —que no deberia notarse en el dato— dejaria huella en cada documento.
     */
    public function copyDocument(string $collection, string $id, array $doc): void;

    /** Devuelve el documento o null si no existe. */
    public function get(string $collection, string $id): ?array;

    /** True si se borro algo. */
    public function delete(string $collection, string $id): bool;

    /**
     * Todos los documentos vivos de la coleccion. O(coleccion): para filtrar,
     * los indices.
     */
    public function all(string $collection): array;

    /** Cuantos documentos vivos hay. No debe abrirlos para contarlos. */
    public function count(string $collection): int;

    /**
     * Mantenimiento: retira lo que sobra en disco. En fs, temporales huerfanos;
     * en packed, el espacio muerto que dejan las modificaciones y los borrados.
     * Devuelve cuanto se ha limpiado, en la unidad que tenga sentido para cada uno.
     */
    public function sweep(string $collection, int $minAgeSeconds = 300): int;
}
