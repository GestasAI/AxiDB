<?php
/**
 * AxiDB - Core\Fs: las dos preguntas sobre el sistema de ficheros que el motor
 * no puede permitirse contestar mal.
 *
 * El motor recorre el directorio de datos para dos cosas que hacen daño si se
 * salen de el: borrar (Sweeper) y copiar (Backup). Un enlace colocado dentro de
 * la carpeta de datos convierte cualquiera de esos recorridos en un recorrido
 * fuera. La carpeta de datos suele ser escribible por el propio proceso web, asi
 * que dejar un enlace puesto no es un escenario raro.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Fs
{
    /**
     * True si la ruta es un enlace simbolico o una junction de Windows.
     *
     * is_link() detecta los symlinks en cualquier sistema, pero NO ve las
     * junctions de Windows: para una junction devuelve false y is_dir() devuelve
     * true, asi que un recorrido ingenuo se mete dentro. readlink() no sirve como
     * respaldo —en Windows resuelve tambien los directorios normales, asi que da
     * un falso positivo en todo—. Lo que si distingue: una junction resuelve a un
     * destino distinto de su propia ubicacion. Se compara el realpath de la ruta
     * con el que tendria de ser una entrada normal (padre resuelto + nombre); si
     * difieren, la ruta apunta a otro sitio y es un enlace. El padre pasa por
     * realpath en los dos lados, asi que separadores y mayusculas ya vienen
     * normalizados y la comparacion es justa.
     */
    public static function isLink(string $path): bool
    {
        if (\is_link($path)) {
            return true;
        }
        $real = \realpath($path);
        if ($real === false) {
            return false;                       // no existe: no hay enlace que seguir
        }
        $padre = \realpath(\dirname($path));
        if ($padre === false) {
            return false;
        }
        return \strcasecmp($real, $padre . \DIRECTORY_SEPARATOR . \basename($path)) !== 0;
    }
}
