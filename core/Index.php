<?php
/**
 * AxiDB - Core\Index: indices secundarios sobre cualquier campo.
 *
 * Guarda la lista de ids que comparten un valor:
 *   <coleccion>/_idx/<campo>/<valor>.json  ->  ["id1","id2",...]
 *
 * Un archivo por valor (no uno global por campo) para que dos escrituras de
 * valores distintos nunca compitan por el mismo lock.
 *
 * Invariante critica: leer-modificar-escribir ocurre SIEMPRE dentro del mismo
 * flock exclusivo. Si la lectura queda fuera del lock, dos procesos concurrentes
 * parten del mismo estado y la ultima escritura pisa a la anterior.
 *
 * Aqui NO se llama a fsync y en Storage si: se sincroniza a disco lo que no se
 * puede reconstruir. Un documento perdido no vuelve; un indice si. Medido, el
 * fsync del indice costaba 15,7 ms de los 21,5 de cada escritura. Sin el, la
 * escritura llega igual a la cache del sistema y solo un corte de corriente
 * puede dejarla atras; ese caso se resuelve con verify() + build().
 *
 * Limite conocido: un archivo de valor borrado a mano es indistinguible de un
 * valor sin documentos, asi que no se detecta al leer. Lo detecta verify() y lo
 * repara build(). Perder el directorio del campo entero SI se nota, y
 * ensureIndex() lo reconstruye solo. Cubierto en test_reparacion_indices.php.
 *
 * No conoce ningun dominio: cualquier nombre de campo le vale.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Index
{
    use \Axi\Core\Indices\ConUnicos;
    use \Axi\Core\Indices\ConInspeccion;

    public function __construct(private Storage $storage)
    {
    }

    public function storage(): Storage
    {
        return $this->storage;
    }

    public function add(string $collection, string $field, string $value, string $id): void
    {
        if ($value === '' || $id === '') {
            return;
        }
        $this->mutate($collection, $field, $value, static function (array $ids) use ($id) {
            if (!\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            return $ids;
        });
    }

    public function remove(string $collection, string $field, string $value, string $id): void
    {
        if ($value === '' || $id === '') {
            return;
        }
        if (!\is_file($this->path($collection, $field, $value))) {
            return;
        }
        $this->mutate($collection, $field, $value, static fn(array $ids) =>
            \array_values(\array_filter($ids, static fn($x) => $x !== $id))
        );
    }

    /**
     * Ids indexados para un valor. null si no hay archivo, que significa "no
     * hay documentos con este valor": por eso una consulta sin resultados es
     * O(1). Ver la nota sobre el limite conocido en la cabecera del archivo.
     */
    public function ids(string $collection, string $field, string $value): ?array
    {
        $path = $this->path($collection, $field, $value);
        if (!\is_file($path)) {
            return null;
        }
        $ids = \json_decode((string) @\file_get_contents($path), true);
        return \is_array($ids) ? $ids : null;
    }

    /** True si el campo tiene al menos un indice construido en esta coleccion. */
    public function isIndexed(string $collection, string $field): bool
    {
        return \is_dir($this->fieldDir($collection, $field));
    }

    /**
     * Construye o reconstruye el indice de un campo escaneando la coleccion.
     * Idempotente. Es tambien la reparacion ante un indice incompleto.
     */
    public function build(string $collection, string $field): int
    {
        $dir = $this->fieldDir($collection, $field);
        if (\is_dir($dir)) {
            foreach (\glob($dir . '/*.json') ?: [] as $f) {
                @\unlink($f);
            }
        } elseif (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new Exception("No se pudo crear el indice '{$field}' en '{$collection}'.");
        }
        // El directorio se crea aunque no haya nada que indexar: es lo que declara
        // el indice como existente para que put() lo mantenga desde la primera alta.
        $this->anotarCampo($dir, $field);

        $buckets = [];
        foreach ($this->storage->all($collection) as $doc) {
            $value = $doc[$field] ?? null;
            if ($value === null || $value === '' || \is_array($value)) {
                continue;
            }
            $buckets[(string) $value][] = (string) ($doc['id'] ?? '');
        }
        foreach ($buckets as $value => $ids) {
            $this->write($this->path($collection, $field, (string) $value), $ids);
        }
        return \count($buckets);
    }

    /**
     * Sincroniza el indice tras un cambio de documento: quita el valor anterior
     * y añade el nuevo. Solo actua sobre campos ya indexados.
     */
    public function sync(string $collection, array $fields, ?array $before, ?array $after): void
    {
        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field]  ?? null;
            if ($old === $new) {
                continue;
            }
            $id = (string) ($after['id'] ?? $before['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($old !== null && $old !== '' && !\is_array($old)) {
                $this->remove($collection, $field, (string) $old, $id);
            }
            if ($new !== null && $new !== '' && !\is_array($new)) {
                $this->add($collection, $field, (string) $new, $id);
            }
        }
    }

    public function drop(string $collection, string $field): bool
    {
        $dir = $this->fieldDir($collection, $field);
        if (!\is_dir($dir)) {
            return false;
        }
        foreach (\glob($dir . '/*.json') ?: [] as $f) {
            @\unlink($f);
        }
        return @\rmdir($dir);
    }

    /**
     * Lectura, modificacion y escritura bajo un unico lock exclusivo.
     * Este metodo es la razon de existir de esta clase: hacerlo de otra forma
     * pierde entradas en cuanto hay dos escritores a la vez.
     */
    private function mutate(string $collection, string $field, string $value, callable $mutator): void
    {
        $path = $this->path($collection, $field, $value);
        $dir  = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new Exception("Index: no se pudo crear el directorio '{$dir}'.");
        }

        // Fallar en silencio seria el peor error del motor: el documento queda
        // guardado, el indice no lo recoge y es invisible. Causa tipica: un
        // script de mantenimiento con otro usuario dejo el dueño equivocado.
        $fp = @\fopen($path, 'c+');
        if (!$fp) {
            throw new Exception("Index: no se pudo escribir '{$path}'. " . IndexVerifier::pistaPermisos($dir));
        }
        try {
            if (!\flock($fp, LOCK_EX)) {
                throw new Exception("Index: no se pudo bloquear '{$path}'.");
            }
            // Lectura DENTRO del lock: nadie puede colarse entre leer y escribir.
            $raw = \stream_get_contents($fp);
            $ids = $raw !== '' && $raw !== false ? (\json_decode($raw, true) ?: []) : [];

            $new = \array_values(\array_unique($mutator($ids)));
            if ($new === $ids) {
                return;
            }
            // Truncar aqui es aceptable, a diferencia de un documento: el indice
            // es reconstruible. Sin fsync a proposito (ver cabecera del archivo).
            \ftruncate($fp, 0);
            \rewind($fp);
            \fwrite($fp, \json_encode($new));
            \fflush($fp);
        } finally {
            \flock($fp, LOCK_UN);
            \fclose($fp);
        }
    }

    private function write(string $path, array $ids): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        @\file_put_contents($path, \json_encode(\array_values(\array_unique($ids))));
    }

    // El nombre del campo pasa por toPath igual que el de la coleccion: 'Total'
    // y 'total' son claves JSON distintas y no pueden compartir directorio.
    private function fieldDir(string $collection, string $field): string
    {
        return $this->storage->dir($collection)
             . '/_idx/' . Names::toPath(Names::check($field, 'campo'));
    }

    /**
     * Un valor puede ser cualquier cosa (un email, un texto con espacios).
     * Si es un token seguro se usa tal cual — asi el indice es inspeccionable
     * a simple vista; si no, se reduce a un hash estable.
     */
    private function path(string $collection, string $field, string $value): string
    {
        // Cifrada: SIEMPRE hash. Dejar el valor tal cual escribia
        // _idx/email/juan.perez.json: el dato recien cifrado, como nombre.
        $nombre = $this->storage->estaCifrada($collection)
            ? 'h_' . \sha1($value) : Names::forValue($value);

        return $this->fieldDir($collection, $field) . '/' . $nombre . '.json';
    }
}
