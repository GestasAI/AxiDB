<?php
/**
 * AxiDB - Drivers\Packed\Descriptores: los archivos abiertos de cada coleccion.
 *
 * Existe por una medicion. Abrir y cerrar el archivo en cada añadido costaba
 * 0,098 ms; reutilizando el descriptor, 0,004 ms. Casi todo el tiempo de un alta
 * se iba en abrir archivos, no en escribir. Mantenerlos abiertos bajo 0,34 ms a
 * 0,032 ms por documento.
 *
 * Esta aparte del driver porque es otra obligacion: el driver decide que se
 * escribe, esto decide que descriptores viven y cuando se sueltan.
 */

declare(strict_types=1);

namespace Axi\Core\Drivers\Packed;

use Axi\Core\Settings;
use Axi\Core\Collections;
use Axi\Core\Exception;

final class Descriptores
{
    /** @var array<string, array{log:Log, off:Offsets}> una entrada por coleccion */
    private array $abiertas = [];

    /** @var array<string, resource> descriptor del lock, uno por coleccion */
    private array $locks = [];

    public function __construct(
        private Collections $colecciones,
        private Settings $ajustes
    ) {
    }

    /** @return array{log:Log, off:Offsets} */
    public function de(string $collection): array
    {
        if (isset($this->abiertas[$collection])) {
            return $this->abiertas[$collection];
        }
        $dir     = $this->colecciones->path($collection);
        $durable = $this->ajustes->esDurable($collection);

        return $this->abiertas[$collection] = [
            'log' => new Log($dir . '/data.axi', $durable),
            'off' => new Offsets($dir . '/offsets.log', $dir . '/offsets.idx', $durable),
        ];
    }

    /**
     * Un unico escritor por coleccion. Los lectores no lo necesitan: como solo
     * se añade, los bytes que ya estan nunca cambian bajo sus pies.
     *
     * El descriptor se reutiliza igual que los de datos —el flock se toma y se
     * suelta cada vez, pero abrir el archivo costaba mas que la escritura—.
     *
     * @return resource
     */
    public function bloquear(string $collection)
    {
        $this->colecciones->ensure($collection);

        if (!isset($this->locks[$collection]) || !\is_resource($this->locks[$collection])) {
            $fp = @\fopen($this->colecciones->path($collection) . '/_write.lock', 'c');
            if (!$fp) {
                throw new Exception("Packed: could not open the lock of '{$collection}'.");
            }
            $this->locks[$collection] = $fp;
        }
        $fp = $this->locks[$collection];

        if (!\flock($fp, LOCK_EX)) {
            throw new Exception("Packed: exclusive lock failed on '{$collection}'.");
        }
        return $fp;
    }

    public function desbloquear($fp): void
    {
        if (\is_resource($fp)) {
            \flock($fp, LOCK_UN);
        }
    }

    /**
     * Suelta estado y descriptores. Hace falta tras cambiar los archivos por
     * fuera, y antes de borrar la coleccion: en Windows no se borra un
     * directorio que tiene archivos abiertos dentro.
     */
    public function olvidar(?string $collection = null): void
    {
        $cuales = $collection === null ? \array_keys($this->abiertas) : [$collection];

        foreach ($cuales as $c) {
            if (isset($this->abiertas[$c])) {
                $this->abiertas[$c]['log']->cerrar();
                $this->abiertas[$c]['off']->cerrar();
                unset($this->abiertas[$c]);
            }
            if (isset($this->locks[$c])) {
                if (\is_resource($this->locks[$c])) {
                    \fclose($this->locks[$c]);
                }
                unset($this->locks[$c]);
            }
        }
    }
}
