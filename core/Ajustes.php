<?php
/**
 * AxiDB - Core\Ajustes: como se guarda cada coleccion.
 *
 *   <coleccion>/_axidb.json   {"driver":"packed","durabilidad":"safe"}
 *
 * Los ajustes viven DENTRO de la coleccion, no en un archivo de configuracion
 * central, y esa es la diferencia que importa: quien reciba la carpeta sabe
 * como leerla sin que le expliquen nada. Copiar el directorio copia tambien la
 * forma de interpretarlo.
 *
 * Dos ajustes, y los dos por coleccion porque las colecciones no se parecen:
 *
 *   driver        fs (legible a ojo) o packed (rapido escribiendo)
 *   durabilidad   safe (fsync en cada escritura) o fast (sin el)
 *
 * Un catalogo que se puede regenerar desde su origen admite packed+fast; un
 * registro contable, que no se puede rehacer, pide fs+safe.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Ajustes
{
    public const DRIVERS      = ['fs', 'packed'];
    public const DURABILIDADES = ['safe', 'fast'];

    private const ARCHIVO = '_axidb.json';

    /** @var array<string, array{driver:string, durabilidad:string}> */
    private array $cache = [];

    public function __construct(
        private Collections $colecciones,
        private string $driverPorDefecto = 'fs',
        private string $durabilidadPorDefecto = 'safe'
    ) {
    }

    public function driver(string $collection): string
    {
        return $this->de($collection)['driver'];
    }

    public function durabilidad(string $collection): string
    {
        return $this->de($collection)['durabilidad'];
    }

    public function esDurable(string $collection): bool
    {
        return $this->durabilidad($collection) === 'safe';
    }

    /** @return array{driver:string, durabilidad:string} */
    public function de(string $collection): array
    {
        if (isset($this->cache[$collection])) {
            return $this->cache[$collection];
        }
        $ajustes = [
            'driver'      => $this->driverPorDefecto,
            'durabilidad' => $this->durabilidadPorDefecto,
        ];

        $path = $this->ruta($collection);
        if (\is_file($path)) {
            $json = \json_decode((string) @\file_get_contents($path), true);
            if (\is_array($json)) {
                if (\in_array($json['driver'] ?? null, self::DRIVERS, true)) {
                    $ajustes['driver'] = $json['driver'];
                }
                if (\in_array($json['durabilidad'] ?? null, self::DURABILIDADES, true)) {
                    $ajustes['durabilidad'] = $json['durabilidad'];
                }
            }
        }
        return $this->cache[$collection] = $ajustes;
    }

    /**
     * Cambia uno o los dos ajustes. Los que no se pasen se conservan.
     * Ojo: cambiar el driver aqui NO mueve los datos; para eso, Storage::migrarA.
     */
    public function fijar(string $collection, ?string $driver = null, ?string $durabilidad = null): void
    {
        if ($driver !== null && !\in_array($driver, self::DRIVERS, true)) {
            throw new Exception(
                "Ajustes: driver desconocido '{$driver}'. Admitidos: " . \implode(', ', self::DRIVERS) . '.'
            );
        }
        if ($durabilidad !== null && !\in_array($durabilidad, self::DURABILIDADES, true)) {
            throw new Exception(
                "Ajustes: durabilidad desconocida '{$durabilidad}'. Admitidas: "
                . \implode(', ', self::DURABILIDADES) . '.'
            );
        }

        $actual = $this->de($collection);
        $nuevo  = [
            'driver'      => $driver      ?? $actual['driver'],
            'durabilidad' => $durabilidad ?? $actual['durabilidad'],
        ];

        $this->colecciones->ensure($collection);
        @\file_put_contents(
            $this->ruta($collection),
            \json_encode($nuevo, JSON_PRETTY_PRINT) . "\n"
        );
        $this->cache[$collection] = $nuevo;
    }

    public function olvidar(?string $collection = null): void
    {
        if ($collection === null) {
            $this->cache = [];
            return;
        }
        unset($this->cache[$collection]);
    }

    private function ruta(string $collection): string
    {
        return $this->colecciones->path($collection) . '/' . self::ARCHIVO;
    }
}
