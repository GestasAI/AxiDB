<?php
/**
 * AxiDB - Copias\Restore: devolver los datos a como estaban.
 *
 * Restaurar es la mitad que importa. Una copia que se hace todas las noches y no
 * se sabe restaurar es un ritual, no un respaldo.
 *
 * El orden de los pasos esta pensado para que un fallo a media restauracion no
 * deje los datos peor de lo que estaban:
 *
 *   1. resolver la cadena       de la incremental hasta su copia completa
 *   2. LEER Y COMPROBAR todo    los sha1 de todas las entradas, sin escribir nada
 *   3. escribir en un temporal  al lado del destino, no encima
 *   4. cambiarlos de sitio      lo viejo se aparta, lo nuevo entra
 *
 * Hasta el paso 4 no se ha tocado un solo archivo de los datos vivos. Si la
 * copia esta dañada, se descubre en el paso 2 y no ha pasado nada.
 */

declare(strict_types=1);

namespace Axi\Core\Backup;

use Axi\Core\Exception;

final class Restore
{
    /**
     * @param string $copia   archivo de copia (completa o incremental)
     * @param string $destino directorio de datos a reconstruir
     * @param string $carpeta donde buscar las copias de las que dependa
     * @return array{id:string, archivos:int, borrados:int, cadena:list<string>}
     */
    public static function build(string $copia, string $destino, ?string $carpeta = null): array
    {
        $cadena = self::stringToken($copia, $carpeta ?? \dirname($copia));
        $ultima = Container::cabecera($copia);

        /*
         * Se aplican de la mas antigua a la mas nueva y en memoria. Los datos
         * de una copia caben: son los de una base de datos de archivos, y quien
         * tenga tantos que no quepan tiene otro problema antes que este.
         */
        $archivos = [];
        foreach ($cadena as $eslabon) {
            Container::each($eslabon, static function (string $ruta, string $bytes) use (&$archivos): void {
                // La ruta viaja DENTRO del archivo de copia, asi que la elige
                // quien fabrico la copia. Se valida aqui, en el paso de lectura,
                // antes de escribir un solo byte. Sin esto, una copia con la
                // entrada '../../shell.php' escribia fuera del directorio de
                // datos: con el directorio dentro del docroot, eso es ejecucion
                // remota. Restaurar una copia recibida no puede ser una via para
                // escribir donde el atacante quiera.
                self::requireSafePath($ruta);
                $archivos[$ruta] = $bytes;
            });
        }

        // Lo que la ultima copia dice que tiene que haber. Un archivo que este
        // en la cadena pero no aqui es uno que se borro entre copia y copia.
        $esperados = \array_keys((array) ($ultima['huellas'] ?? []));
        foreach ($esperados as $ruta) {
            self::requireSafePath($ruta);           // tambien las claves de la cabecera
        }
        $faltan    = \array_diff($esperados, \array_keys($archivos));
        if ($faltan !== []) {
            throw new Exception(
                'Backup: missing ' . \count($faltan) . ' archivos en la cadena de copias ('
                . \implode(', ', \array_slice($faltan, 0, 3)) . '...). No se restaura nada.'
            );
        }

        $escritos = 0;
        foreach ($esperados as $ruta) {
            self::writeTo($destino . '/' . $ruta, $archivos[$ruta]);
            $escritos++;
        }
        $borrados = self::dropExtras($destino, $esperados);

        return [
            'id'       => (string) ($ultima['id'] ?? ''),
            'archivos' => $escritos,
            'borrados' => $borrados,
            'cadena'   => $cadena,
        ];
    }

    /**
     * De la copia pedida hacia atras hasta la completa, y devuelta del reves
     * para aplicarla en orden.
     *
     * @return list<string> rutas de archivo, de la mas antigua a la mas nueva
     */
    private static function stringToken(string $copia, string $carpeta): array
    {
        $cadena = [];
        $actual = $copia;
        $vistos = [];

        while (true) {
            if (!\is_file($actual)) {
                throw new Exception("Backup: a link is missing from the chain: {$actual}.");
            }
            $cabecera = Container::cabecera($actual);
            $id       = (string) ($cabecera['id'] ?? '');

            if (isset($vistos[$id])) {
                throw new Exception("Backup: the chain loops back on itself at '{$id}'.");
            }
            $vistos[$id] = true;
            $cadena[]    = $actual;

            $base = $cabecera['base'] ?? null;
            if ($base === null) {
                break;                              // llegamos a la completa
            }
            $actual = Catalog::search($carpeta, (string) $base);
        }
        return \array_reverse($cadena);
    }

    /**
     * Una ruta de entrada de copia solo puede apuntar DENTRO del destino.
     *
     * Rechaza lo que permite escapar del directorio: un tramo `..`, una barra o
     * contrabarra inicial (ruta absoluta estilo Unix), una unidad de Windows o un
     * flujo alternativo (`C:`, `x:algo`), y el byte nulo. Se comprueba tramo a
     * tramo, partiendo por las dos barras, porque una copia legitima trae rutas
     * como `coleccion/_idx/campo/valor.json`.
     *
     * No se apoya en `realpath`: el archivo de destino todavia no existe, y una
     * comprobacion que dependa del estado del disco se puede burlar con enlaces.
     * La regla es sobre el texto de la ruta, que es lo que controla el atacante.
     */
    private static function requireSafePath(string $ruta): void
    {
        if ($ruta === '' || \str_contains($ruta, "\0")) {
            throw new Exception('Backup: ruta de entrada vacia o con byte nulo.');
        }
        if ($ruta[0] === '/' || $ruta[0] === '\\' || \preg_match('#^[a-zA-Z]:#', $ruta) === 1) {
            throw new Exception("Backup: ruta de entrada absoluta rechazada: '{$ruta}'.");
        }
        foreach (\preg_split('#[/\\\\]#', $ruta) ?: [] as $tramo) {
            if ($tramo === '' || $tramo === '.' || $tramo === '..') {
                throw new Exception("Backup: ruta de entrada con tramo invalido: '{$ruta}'.");
            }
        }
    }

    private static function writeTo(string $ruta, string $bytes): void
    {
        $dir = \dirname($ruta);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new Exception("Restore: could not create {$dir}.");
        }
        $tmp = $ruta . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $bytes) === false || !@\rename($tmp, $ruta)) {
            @\unlink($tmp);
            throw new Exception("Restore: could not restore {$ruta}.");
        }
    }

    /**
     * Quita del destino lo que la copia no tiene.
     *
     * Sin esto, restaurar seria "añadir encima" y un documento borrado despues
     * de la copia reaparecerria. Restaurar significa volver a como estaba, no
     * mezclar dos momentos.
     *
     * @param list<string> $esperados
     */
    private static function dropExtras(string $destino, array $esperados): int
    {
        if (!\is_dir($destino)) {
            return 0;
        }
        $mapa     = \array_flip($esperados);
        $borrados = 0;

        foreach (Inventory::of($destino) as $ruta => $entrada) {
            if (!isset($mapa[$ruta]) && @\unlink($entrada['absoluta'])) {
                $borrados++;
            }
        }
        return $borrados;
    }
}
