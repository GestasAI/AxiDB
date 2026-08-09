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
 *   cifrado       true guarda el contenido cerrado con AES-256-GCM
 *   unicos        campos que no admiten dos documentos con el mismo valor
 *   esquema       campos obligatorios, tipos y valores por defecto
 *   caducidad     segundos tras los que un documento deja de existir
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

    /** @var array<string, array{driver:string, durabilidad:string, cifrado:bool}> */
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

    public function estaCifrada(string $collection): bool
    {
        return $this->de($collection)['cifrado'];
    }

    /**
     * Campos que no admiten dos documentos con el mismo valor.
     *
     * Viven aqui y no en el directorio del indice porque se consultan en CADA
     * escritura: leerlos de este archivo, que ya esta cacheado en memoria, no
     * cuesta nada, y buscar un archivo marcador en el disco costaria una
     * llamada al sistema por alta.
     *
     * @return list<string>
     */
    public function unicos(string $collection): array
    {
        return $this->de($collection)['unicos'];
    }

    /** Los ajustes de una coleccion que no dice nada, y la lista de los que hay. */
    private static function porDefecto(string $driver, string $durabilidad): array
    {
        return [
            'driver'      => $driver,
            'durabilidad' => $durabilidad,
            'cifrado'     => false,
            'unicos'      => [],
            'esquema'     => [],
            'caducidad'   => 0,
        ];
    }

    /** Campos con reglas declaradas. Vacio si la coleccion no tiene esquema. */
    public function esquema(string $collection): array
    {
        return $this->de($collection)['esquema'];
    }

    /** Segundos de vida de un documento. Cero significa que no caduca. */
    public function caducidad(string $collection): int
    {
        return $this->de($collection)['caducidad'];
    }

    /** @return array{driver:string, durabilidad:string, cifrado:bool, unicos:list<string>, esquema:array, caducidad:int} */
    public function de(string $collection): array
    {
        if (isset($this->cache[$collection])) {
            return $this->cache[$collection];
        }
        $ajustes = self::porDefecto($this->driverPorDefecto, $this->durabilidadPorDefecto);

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
                $ajustes['cifrado']   = (bool) ($json['cifrado'] ?? false);
                $ajustes['unicos']    = self::limpiarUnicos($json['unicos'] ?? []);
                $ajustes['esquema']   = \is_array($json['esquema'] ?? null) ? $json['esquema'] : [];
                $ajustes['caducidad'] = \max(0, (int) ($json['caducidad'] ?? 0));
            }
        }
        return $this->cache[$collection] = $ajustes;
    }

    /**
     * Cambia los ajustes que se le pasen. Los demas se conservan.
     *
     *   $ajustes->fijar('clientes', ['driver' => 'packed', 'caducidad' => 3600]);
     *
     * Recibe un array y no seis parametros opcionales porque son seis y
     * creciendo: con posicionales, añadir el septimo obligaba a escribir cinco
     * `null` seguidos para llegar hasta el, y una llamada asi no se lee.
     *
     * Ojo: cambiar el driver aqui NO mueve los datos; para eso, Storage::migrarA.
     */
    public function fijar(string $collection, array $cambios): void
    {
        $desconocidos = \array_diff(\array_keys($cambios), \array_keys(self::porDefecto('fs', 'safe')));
        if ($desconocidos !== []) {
            throw new Exception('Ajustes: no existe el ajuste ' . \implode(', ', $desconocidos) . '.');
        }
        if (isset($cambios['driver']) && !\in_array($cambios['driver'], self::DRIVERS, true)) {
            throw new Exception(
                "Ajustes: driver desconocido '{$cambios['driver']}'. Admitidos: "
                . \implode(', ', self::DRIVERS) . '.'
            );
        }
        if (isset($cambios['durabilidad']) && !\in_array($cambios['durabilidad'], self::DURABILIDADES, true)) {
            throw new Exception(
                "Ajustes: durabilidad desconocida '{$cambios['durabilidad']}'. Admitidas: "
                . \implode(', ', self::DURABILIDADES) . '.'
            );
        }

        // array_merge y no `+`: la union deja primero las claves que cambian, y
        // entonces el orden del _axidb.json baila segun lo que hayas tocado.
        // Este archivo se lee a ojo y se versiona; el orden tiene que ser
        // siempre el mismo para que un diff enseñe el cambio y no la baraja.
        $nuevo = \array_merge($this->de($collection), $cambios);
        $nuevo['unicos'] = self::limpiarUnicos($nuevo['unicos']);

        $this->colecciones->ensure($collection);
        @\file_put_contents(
            $this->ruta($collection),
            \json_encode($nuevo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
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

    /** @return list<string> nombres de campo, sin repetidos y en orden estable */
    private static function limpiarUnicos(mixed $lista): array
    {
        if (!\is_array($lista)) {
            return [];
        }
        $campos = \array_map('strval', \array_filter($lista, 'is_scalar'));
        $campos = \array_values(\array_unique($campos));
        \sort($campos, SORT_STRING);
        return $campos;
    }

    private function ruta(string $collection): string
    {
        return $this->colecciones->path($collection) . '/' . self::ARCHIVO;
    }
}
