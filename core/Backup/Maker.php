<?php
/**
 * AxiDB - Copias\Maker: hacer una copia, completa o incremental.
 *
 * Una copia incremental guarda solo los archivos que han cambiado desde otra
 * copia anterior, pero su cabecera lleva las huellas de TODOS los archivos que
 * habia en ese momento, no solo las de los que cambiaron.
 *
 * Esa decision es lo que hace que restaurar sea sencillo y comprobable: con la
 * lista completa se sabe exactamente que archivos deberia haber al terminar, y
 * por tanto tambien cuales sobran y hay que borrar. Sin ella habria que deducir
 * los borrados comparando cadenas de copias, que es justo el tipo de calculo que
 * falla el dia que se necesita.
 *
 * Cuesta unos kilobytes por copia. Es el mejor dinero que se gasta aqui.
 */

declare(strict_types=1);

namespace Axi\Core\Backup;

use Axi\Core\Exception;

final class Maker
{
    public const COMPLETA    = 'completa';
    public const INCREMENTAL = 'incremental';

    /**
     * @param string      $base    directorio de datos
     * @param string      $carpeta donde dejar el archivo de copia
     * @param string|null $desde   copia anterior, para hacerla incremental
     * @return array{id:string, tipo:string, base:?string, archivos:int, guardados:int, bytes:int, archivo:string}
     */
    public static function build(string $base, string $carpeta, ?string $desde = null): array
    {
        if (!\is_dir($base)) {
            throw new Exception("Backup: there is nothing to back up in {$base}.");
        }
        $inventario = Inventory::of($base);
        $huellas    = Inventory::huellas($inventario);

        $anteriores = $desde === null ? [] : self::fingerprintsOf($desde);
        $tipo       = $desde === null ? self::COMPLETA : self::INCREMENTAL;

        $id       = self::newId($tipo);
        $destino  = \rtrim($carpeta, '/\\') . '/' . $id . Catalog::EXTENSION;
        $cabecera = [
            'version' => 1,
            'id'      => $id,
            'momento' => \date('c'),
            'tipo'    => $tipo,
            'base'    => $desde === null ? null : Container::cabecera($desde)['id'],
            'huellas' => $huellas,
        ];

        $contenedor = Container::create($destino, $cabecera);
        $guardados  = 0;

        try {
            // El 'data.axi' de cada coleccion empaquetada se copia EL ULTIMO, despues
            // de sus 'offsets'. El log de desplazamientos solo apunta a bytes que ya
            // estan en data.axi (primero el dato, despues el apunte) y data.axi solo
            // crece. Copiando los offsets antes y el data.axi despues, el data.axi de
            // la copia es un superconjunto de lo que los offsets referencian: aunque
            // otro proceso escriba en medio, todo lo que la copia dice tener se puede
            // leer. Al reves —el orden alfabetico, data.axi antes que offsets— los
            // offsets copiados apuntaban a bytes que no habian entrado en el data.axi.
            $ordenado = $inventario;
            \uksort($ordenado, static function (string $a, string $b): int {
                $da = \str_ends_with($a, '/data.axi') || $a === 'data.axi';
                $db = \str_ends_with($b, '/data.axi') || $b === 'data.axi';
                return $da <=> $db ?: \strcmp($a, $b);
            });

            foreach ($ordenado as $ruta => $entrada) {
                // En una incremental, lo que no ha cambiado no se vuelve a
                // guardar: ya esta en la copia de la que cuelga.
                if (($anteriores[$ruta] ?? null) === $entrada['sha1']) {
                    continue;
                }
                $contenedor->append($ruta, $entrada['absoluta']);
                $guardados++;
            }
        } finally {
            $contenedor->close();
        }

        return [
            'id'        => $id,
            'tipo'      => $tipo,
            'base'      => $cabecera['base'],
            'archivos'  => \count($inventario),
            'guardados' => $guardados,
            'bytes'     => (int) @\filesize($destino),
            'archivo'   => $destino,
        ];
    }

    /** @return array<string, string> */
    private static function fingerprintsOf(string $copia): array
    {
        if (!\is_file($copia)) {
            throw new Exception("Backup: the previous backup {$copia} does not exist.");
        }
        $huellas = Container::cabecera($copia)['huellas'] ?? [];
        return \is_array($huellas) ? \array_map('strval', $huellas) : [];
    }

    /**
     * Un id ordenable por tiempo y legible: se ve de un vistazo cual es la mas
     * reciente al listar el directorio, sin abrir ninguna.
     *
     * Lleva microsegundos, y no es un adorno. Con precision de segundo, dos
     * copias seguidas empatan al ordenar y "la mas reciente" queda al azar. De
     * eso depende de que copia cuelga una incremental, asi que un empate ahi
     * la encadena a la copia equivocada y la cadena de restauracion sale mal.
     * Lo destapo el test, que hacia las dos copias dentro del mismo segundo.
     */
    private static function newId(string $tipo): string
    {
        $t  = \microtime(true);
        $us = \sprintf('%06d', (int) (($t - (int) $t) * 1000000));

        return \date('Ymd-His', (int) $t) . '-' . $us
            . '-' . ($tipo === self::COMPLETA ? 'c' : 'i')
            . '-' . \bin2hex(\random_bytes(3));
    }
}
