<?php
/**
 * AxiDB - Core\Index: indices secundarios sobre cualquier campo.
 *
 * Guarda la lista de ids que comparten un valor:
 *   <coleccion>/_idx/<campo>/<valor>.json  ->  ["id1","id2",...]
 *
 * Un archivo por valor (no uno global por campo) para que dos escrituras de
 * valores distintos nunca compitan por el mismo lock. Invariante critica:
 * leer-modificar-escribir ocurre SIEMPRE dentro del mismo flock exclusivo, o dos
 * procesos parten del mismo estado y la ultima escritura pisa a la anterior.
 *
 * Aqui NO se llama a fsync y en Storage si: se sincroniza lo que no se puede
 * reconstruir. Un documento perdido no vuelve; un indice si. Medido, el fsync del
 * indice costaba 15,7 ms de los 21,5 de cada escritura; sin el, un corte de
 * corriente puede dejarlo corto, y eso lo repara verify() + build().
 *
 * Limite conocido: un archivo de valor borrado a mano no se distingue de un valor
 * sin documentos al leer. Lo detecta verify() y lo repara build(). No conoce
 * ningun dominio: cualquier nombre de campo le vale.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Index
{
    use \Axi\Core\Indexes\WithUniques;
    use \Axi\Core\Indexes\WithInspection;
    use \Axi\Core\Indexes\WithReindex;

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
        $dir  = $this->fieldDir($collection, $field);
        // EX de reindexado: reconstruir reescribe todos los cubos escaneando los
        // documentos. Una reserva recien hecha cuyo documento aun no se escribio
        // no aparece en el escaneo, asi que un alta en curso tiene que esperar (va
        // en SH) para que su reserva no se pierda y cuele un duplicado.
        $lock = $this->reindexLock($collection, true);
        try {
            if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                throw new Exception("Could not create the index '{$field}' on '{$collection}'.");
            }
            $this->recordField($dir, $field);       // declara el indice, aunque este vacio

            $buckets = [];
            foreach ($this->storage->all($collection) as $doc) {
                $value = $doc[$field] ?? null;
                if ($value === null || $value === '' || \is_array($value)) {
                    continue;
                }
                $buckets[(string) $value][] = (string) ($doc['id'] ?? '');
            }
            // Cada cubo se escribe con temp+rename y NO se hace un `unlink *.json`
            // de golpe al empezar: borrar-y-reescribir dejaba el cubo un instante
            // vacio, y aun con el EX cogido bastaba para que un alta que acababa de
            // coger el SH lo leyera vacio y colara un duplicado. Aqui nunca esta vacio.
            $vivos = [];
            foreach ($buckets as $value => $ids) {
                $ruta = $this->path($collection, $field, (string) $value);
                $this->write($ruta, $ids);
                $vivos[\basename($ruta)] = true;
            }
            foreach (\glob($dir . '/*.json') ?: [] as $f) {   // retira solo cubos sin documentos
                if (\basename($f) !== '_campo.json' && !isset($vivos[\basename($f)])) {
                    @\unlink($f);
                }
            }
            return \count($buckets);
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
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
            throw new Exception("Index: could not create the directory '{$dir}'.");
        }

        // Fallar en silencio seria el peor error del motor: el documento queda
        // guardado, el indice no lo recoge y es invisible.
        $fp = @\fopen($path, 'c+');
        if (!$fp) {
            throw new Exception("Index: could not write '{$path}'. " . IndexVerifier::permissionHint($dir));
        }
        try {
            if (!\flock($fp, LOCK_EX)) {
                throw new Exception("Index: could not lock '{$path}'.");
            }
            // Lectura DENTRO del lock: nadie se cuela entre leer y escribir.
            $raw = \stream_get_contents($fp);
            $ids = $raw !== '' && $raw !== false ? (\json_decode($raw, true) ?: []) : [];

            $new = \array_values(\array_unique($mutator($ids)));
            if ($new === $ids) {
                return;
            }
            // Truncar es aceptable: el indice es reconstruible. Sin fsync a proposito.
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
        // Temp + rename: el cubo pasa de su contenido viejo al nuevo de un golpe.
        // Con file_put_contents directo, un lector pillaba el instante entre el
        // truncado y la escritura y lo veia vacio; rename es atomico y no lo abre.
        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, \json_encode(\array_values(\array_unique($ids)))) === false
            || !@\rename($tmp, $path)) {
            @\unlink($tmp);
        }
    }

    // toPath separa 'Total' de 'total': claves JSON distintas, directorios distintos.
    private function fieldDir(string $collection, string $field): string
    {
        return $this->storage->dir($collection)
             . '/_idx/' . Names::toPath(Names::check($field, 'campo'));
    }

    private function path(string $collection, string $field, string $value): string
    {
        // Cifrada: nombre de cubo CON CLAVE (HMAC), para que quien tenga el disco
        // no localice el archivo probando sha1('moroso'). Sin cifrar, forValue, que
        // deja el nombre legible si el valor es un token seguro.
        $nombre = $this->storage->indexBucket($collection, $value) ?? Names::forValue($value);

        return $this->fieldDir($collection, $field) . '/' . $nombre . '.json';
    }
}
