<?php
/**
 * AxiDB - Core\Sweeper: limpieza de restos en disco.
 *
 * Un proceso que muere entre crear el temporal de escritura y renombrarlo deja
 * el temporal huerfano. No es peligroso — ni se lista ni se lee, porque los
 * listados descartan cualquier nombre que contenga '.tmp.' — pero ocupa disco.
 *
 * Se separa de Storage porque es mantenimiento, no persistencia.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Sweeper
{
    /**
     * Borra los temporales de un directorio con mas de $minAgeSeconds de vida.
     * El margen de edad evita pisar una escritura en curso de otro proceso.
     *
     * @return int temporales eliminados
     */
    public static function run(string $dir, int $minAgeSeconds = 300): int
    {
        $limite = \time() - \max(0, $minAgeSeconds);
        $n = 0;
        foreach (\glob($dir . '/*.tmp.*') ?: [] as $file) {
            $mtime = @\filemtime($file);
            if ($mtime !== false && $mtime <= $limite && @\unlink($file)) {
                $n++;
            }
        }
        return $n;
    }

    /** Borra un archivo o un arbol de directorios completo. */
    public static function rmrf(string $path): void
    {
        if (!\is_dir($path)) {
            if (!@\unlink($path) && \is_file($path)) {
                /*
                 * Un archivo de solo lectura no se deja borrar en Windows, y en
                 * Linux tampoco si el directorio no da permiso. Se intenta
                 * devolverle la escritura antes de rendirse.
                 *
                 * Sin esto, borrar una coleccion que contenga un archivo
                 * marcado de solo lectura falla a medias y en silencio: quedan
                 * restos que la siguiente ejecucion se encuentra y no entiende.
                 * Paso de verdad, y costo un rato averiguar por que un test
                 * fallaba solo despues de que otro hubiera fallado antes.
                 */
                @\chmod($path, 0666);
                @\unlink($path);
            }
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }
}
