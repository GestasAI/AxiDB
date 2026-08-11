<?php
/**
 * AxiDB - Indices\WithReindex: el cerrojo estructural de una coleccion.
 *
 * Vive dentro de Index porque el cerrojo protege justo lo que Index reescribe al
 * reconstruir. build() y migrar de driver lo cogen en EX; un alta (Db::put) en SH
 * durante todo el ciclo de reservar, escribir y sincronizar. Asi las reescrituras
 * de la coleccion entera no se cuelan entre la reserva y la escritura del
 * documento —donde una reserva sin documento se perderia en el escaneo y colaria
 * un duplicado—, y las altas no se esperan entre si.
 *
 * El archivo es '_reindex.lock' en el directorio de la coleccion, y no se borra.
 */

declare(strict_types=1);

namespace Axi\Core\Indexes;

use Axi\Core\Exception;

trait WithReindex
{
    /** @return resource */
    public function reindexLock(string $collection, bool $exclusivo)
    {
        $dir = $this->storage->dir($collection);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new Exception("Index: could not create the directory '{$dir}'.");
        }
        $fp = @\fopen($dir . '/_reindex.lock', 'c');
        if (!$fp) {
            throw new Exception("Index: could not open the reindex lock in '{$collection}'.");
        }
        if (!\flock($fp, $exclusivo ? LOCK_EX : LOCK_SH)) {
            \fclose($fp);
            throw new Exception("Index: could not lock reindex in '{$collection}'.");
        }
        return $fp;
    }

    /** @param resource $fp */
    public function reindexUnlock($fp): void
    {
        if (\is_resource($fp)) {
            \flock($fp, LOCK_UN);
            \fclose($fp);
        }
    }
}
