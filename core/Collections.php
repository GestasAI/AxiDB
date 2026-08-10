<?php
/**
 * AxiDB - Core\Collections: ciclo de vida de las colecciones.
 *
 * Crear, listar y borrar colecciones no es persistir documentos: son dos
 * responsabilidades distintas y viven en archivos distintos. Storage delega
 * aqui y mantiene su interfaz publica.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Collections
{
    public function __construct(private string $base)
    {
    }

    public function path(string $collection): string
    {
        return $this->base . '/' . Names::toPath(Names::check($collection, 'coleccion'));
    }

    public function exists(string $collection): bool
    {
        return \is_dir($this->path($collection));
    }

    /**
     * Crea la coleccion vacia. Idempotente. Devuelve true solo si la ha creado
     * ahora: put() ya crea el directorio al escribir, esto existe para poder
     * declarar una coleccion antes de que tenga documentos.
     */
    public function ensure(string $collection): bool
    {
        $dir = $this->path($collection);
        if (\is_dir($dir)) {
            return false;
        }
        if (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new Exception("Could not create collection '{$collection}'.");
        }
        return true;
    }

    /**
     * Nombres de las colecciones existentes, tal y como estan en disco. Se
     * pueden pasar de vuelta a cualquier operacion: un nombre ya en forma de
     * ruta se traduce a si mismo.
     */
    public function all(): array
    {
        $out = [];
        foreach (\scandir($this->base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '_' || $entry[0] === '.') {
                continue;
            }
            if (\is_dir($this->base . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        \sort($out);
        return $out;
    }

    public function drop(string $collection): bool
    {
        $dir = $this->path($collection);
        if (!\is_dir($dir)) {
            return false;
        }
        Sweeper::rmrf($dir);
        return !\is_dir($dir);
    }
}
