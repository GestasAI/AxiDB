<?php
/**
 * AxiDB - Drivers\FsDriver: un documento, un archivo.
 *
 *   <base>/<coleccion>/<id>.json
 *
 * Es el driver por defecto y el que hace que los datos se puedan abrir con un
 * editor, comparar con git y reparar a mano. A cambio paga una llamada al
 * sistema por documento: dar de alta diez mil cuesta segundos, no milisegundos.
 * Cuando eso importa, PackedDriver.
 *
 * Invariantes:
 *  - Toda escritura es atomica: tmp + rename. Nunca se trunca el destino, asi
 *    que un corte a mitad deja el documento anterior intacto.
 *  - fsync() opcional (durable) para sobrevivir a un corte de corriente.
 */

declare(strict_types=1);

namespace Axi\Core\Drivers;

use Axi\Core\Ajustes;
use Axi\Core\Collections;
use Axi\Core\Exception;
use Axi\Core\Meta;
use Axi\Core\Names;
use Axi\Core\Sweeper;

final class FsDriver implements Driver
{
    public function __construct(
        private Collections $colecciones,
        private Ajustes $ajustes
    ) {
    }

    public function nombre(): string
    {
        return 'fs';
    }

    public function put(string $collection, string $id, array $data, bool $replace = false): array
    {
        Names::check($id, 'id');
        $this->colecciones->ensure($collection);
        $path = $this->docPath($collection, $id);

        // El lock vive en un archivo aparte porque el .json se reemplaza entero
        // via rename: mantener el descriptor sobre el destino seria incorrecto.
        $lock = @\fopen($path . '.lock', 'c');
        if (!$lock) {
            throw new Exception("No se pudo abrir el lock de {$path}.");
        }
        try {
            if (!\flock($lock, LOCK_EX)) {
                throw new Exception("Lock exclusivo fallido en {$path}.");
            }
            $data = Meta::aplicar($data, $id, $this->leer($path), $replace);
            $durable = $this->ajustes->esDurable($collection);
            $this->escribirAtomico($path, $data, $durable);
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
        return $data;
    }

    /**
     * Sin lock: el rename ya es atomico y esto solo lo usa la migracion, que es
     * escritor unico sobre una coleccion que aun no se lee desde este driver.
     */
    public function copiar(string $collection, string $id, array $doc): void
    {
        Names::check($id, 'id');
        $this->colecciones->ensure($collection);
        $this->escribirAtomico(
            $this->docPath($collection, $id),
            $doc,
            $this->ajustes->esDurable($collection)
        );
    }

    public function get(string $collection, string $id): ?array
    {
        Names::check($id, 'id');
        return $this->leer($this->docPath($collection, $id));
    }

    public function delete(string $collection, string $id): bool
    {
        Names::check($id, 'id');
        $path = $this->docPath($collection, $id);
        if (!\is_file($path)) {
            return false;
        }
        $ok = @\unlink($path);
        @\unlink($path . '.lock');
        return $ok;
    }

    public function all(string $collection): array
    {
        $out = [];
        foreach ($this->archivos($collection) as $file) {
            $doc = $this->leer($file);
            if ($doc !== null) {
                $out[] = $doc;
            }
        }
        return $out;
    }

    public function count(string $collection): int
    {
        return \count($this->archivos($collection));
    }

    /** Retira temporales huerfanos. Devuelve cuantos. */
    public function sweep(string $collection, int $minAgeSeconds = 300): int
    {
        $dir = $this->colecciones->path($collection);
        return \is_dir($dir) ? Sweeper::run($dir, $minAgeSeconds) : 0;
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /** Rutas de los documentos. Ignora internos (prefijo _) y temporales. */
    private function archivos(string $collection): array
    {
        $dir = $this->colecciones->path($collection);
        if (!\is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '_' || $entry[0] === '.') {
                continue;
            }
            if (\str_ends_with($entry, '.json') && !\str_contains($entry, '.tmp.')) {
                $out[] = $dir . '/' . $entry;
            }
        }
        \sort($out);
        return $out;
    }

    private function docPath(string $collection, string $id): string
    {
        return $this->colecciones->path($collection) . '/' . Names::toPath($id) . '.json';
    }

    private function leer(string $path): ?array
    {
        if (!\is_file($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $doc = \json_decode($raw, true);
        return \is_array($doc) ? $doc : null;
    }

    /**
     * Temporal, fsync opcional y rename sobre el destino. Un lector ve el
     * contenido viejo o el nuevo, nunca uno a medias.
     */
    private function escribirAtomico(string $path, array $data, bool $durable): void
    {
        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        $fp  = @\fopen($tmp, 'wb');
        if (!$fp) {
            throw new Exception("No se pudo crear el temporal de {$path}.");
        }
        try {
            if (\fwrite($fp, Meta::codificar($data)) === false) {
                throw new Exception("Escritura fallida en {$tmp}.");
            }
            \fflush($fp);
            if ($durable && \function_exists('fsync')) {
                @\fsync($fp);
            }
        } finally {
            \fclose($fp);
        }
        if (!self::renombrar($tmp, $path)) {
            @\unlink($tmp);
            throw new Exception("Rename atomico fallido: {$path}.");
        }
    }

    /**
     * Renombra, reintentando durante un instante si el destino esta ocupado.
     *
     * En Windows, `rename()` sobre un archivo que otro proceso tiene abierto
     * falla, aunque solo lo tenga abierto para leer y por milisegundos. Sin
     * reintento, **un lector puede tumbar una escritura**: dos procesos
     * legitimos, ninguno haciendo nada raro, y la escritura lanza. En Linux esto
     * no pasa porque POSIX permite renombrar sobre un archivo abierto.
     *
     * Se descubrio leyendo el registro de errores de PHP: el worker del test de
     * durabilidad moria asi de vez en cuando, y como el test lo mataba igual,
     * nadie se habia fijado.
     *
     * Diez intentos con esperas crecientes suman unos 30 ms. Es tiempo de sobra
     * para que un lector termine, y sigue fallando rapido si el problema es otro
     * —permisos, disco lleno— en vez de quedarse colgado.
     */
    private static function renombrar(string $tmp, string $path): bool
    {
        for ($intento = 0; $intento < 10; $intento++) {
            if (@\rename($tmp, $path)) {
                return true;
            }
            if (!\is_file($tmp)) {
                return false;          // el temporal ya no esta: reintentar no arregla nada
            }
            \usleep(500 + $intento * 500);
        }
        return false;
    }
}
