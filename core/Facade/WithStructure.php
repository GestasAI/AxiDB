<?php
/**
 * AxiDB - Fachada\WithStructure: cambiar la forma de una coleccion.
 *
 * Renombrar la coleccion, y añadir, quitar o renombrar un campo en todos sus
 * documentos. Lo que en SQL es `ALTER TABLE`.
 *
 * En una base de datos con esquema, renombrar una columna es cambiar una linea
 * de metadatos. Aqui no: cada documento lleva sus campos, asi que hay que
 * reescribirlos todos. Se dice claro porque cambia lo que uno espera del coste:
 * sobre un millon de documentos, esto es un millon de escrituras.
 */

declare(strict_types=1);

namespace Axi\Core\Facade;

use Axi\Core\Exception;

trait WithStructure
{
    /**
     * Cambia el nombre de una coleccion, con todo lo que tiene dentro:
     * documentos, ajustes, indices y vectores.
     *
     * Es un renombrado del directorio, asi que no reescribe ni un documento y
     * cuesta lo mismo con diez que con un millon.
     */
    public function renameCollection(string $de, string $a): bool
    {
        return $this->storage->renameCollection($de, $a);
    }

    /**
     * Añade, quita o renombra un campo en TODOS los documentos.
     *
     *   $db->campo('clientes', 'añadir',    'activo', true);
     *   $db->campo('clientes', 'quitar',    'fax');
     *   $db->campo('clientes', 'renombrar', 'tlf', 'telefono');
     *
     * Reescribe documento a documento y pasa por `put`, asi que los indices, la
     * unicidad y el esquema se mantienen al dia. Eso lo hace mas lento que un
     * ALTER de una base con esquema, y a cambio deja el estado correcto.
     *
     * @return int documentos que cambiaron
     */
    public function campo(string $collection, string $accion, string $campo, mixed $extra = null): int
    {
        $cambiados = 0;

        foreach ($this->all($collection) as $doc) {
            $id     = (string) ($doc['id'] ?? '');
            $nuevo  = self::aplicarA($doc, $accion, $campo, $extra);

            if ($nuevo === null) {
                continue;                       // este documento no cambia
            }
            // replace: al quitar o renombrar hay que escribir el documento
            // ENTERO, porque fusionar dejaria el campo viejo donde estaba.
            $this->put($collection, $id, $nuevo, true);
            $cambiados++;
        }
        return $cambiados;
    }

    /** El documento resultante, o null si esta accion no le afecta. */
    private static function aplicarA(array $doc, string $accion, string $campo, mixed $extra): ?array
    {
        $meta = ['id', '_version', '_createdAt', '_updatedAt'];

        switch ($accion) {
            case 'añadir':
            case 'anadir':
                if (\array_key_exists($campo, $doc)) {
                    return null;                // ya lo tiene: no se pisa
                }
                $doc[$campo] = $extra;
                return $doc;

            case 'quitar':
                if (!\array_key_exists($campo, $doc)) {
                    return null;
                }
                if (\in_array($campo, $meta, true)) {
                    throw new Exception("Cannot remove '{$campo}': lo necesita el motor.");
                }
                unset($doc[$campo]);
                return $doc;

            case 'renombrar':
                if (!\array_key_exists($campo, $doc)) {
                    return null;
                }
                $a = (string) $extra;
                if (\in_array($campo, $meta, true) || \in_array($a, $meta, true)) {
                    throw new Exception("The engine's own fields cannot be renamed ({$campo}).");
                }
                $doc[$a] = $doc[$campo];
                unset($doc[$campo]);
                return $doc;

            default:
                throw new Exception(
                    "Campo: '{$accion}' no es una accion. Hay: añadir, quitar, renombrar."
                );
        }
    }
}
