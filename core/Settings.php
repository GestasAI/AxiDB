<?php
/**
 * AxiDB - Core\Settings: como se guarda cada coleccion.
 *
 *   <coleccion>/_axidb.json   {"driver":"packed","durability":"safe"}
 *
 * Los ajustes viven DENTRO de la coleccion, no en un archivo de configuracion
 * central, y esa es la diferencia que importa: quien reciba la carpeta sabe
 * como leerla sin que le expliquen nada. Copiar el directorio copia tambien la
 * forma de interpretarlo.
 *
 * Dos ajustes, y los dos por coleccion porque las colecciones no se parecen:
 *
 *   driver       fs (legible a ojo) o packed (rapido escribiendo)
 *   durability   safe (fsync en cada escritura) o fast (sin el)
 *   encrypted    true guarda el contenido cerrado con AES-256-GCM
 *   uniques      campos que no admiten dos documentos con el mismo valor
 *   schema       campos obligatorios, tipos y valores por defecto
 *   ttl          segundos tras los que un documento deja de existir
 *
 * Un catalogo que se puede regenerar desde su origen admite packed+fast; un
 * registro contable, que no se puede rehacer, pide fs+safe.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Settings
{
    public const DRIVERS      = ['fs', 'packed'];
    public const DURABILIDADES = ['safe', 'fast'];

    private const ARCHIVO = '_axidb.json';

    /** @var array<string, array{driver:string, durabilidad:string, cifrado:bool}> */
    private array $cache = [];

    /** @var array<string, array{0:int,1:int}> huella [mtime, tamano] del _axidb.json cacheado */
    private array $sellos = [];

    public function __construct(
        private Collections $colecciones,
        private string $driverPorDefecto = 'fs',
        private string $durabilidadPorDefecto = 'safe'
    ) {
    }

    public function driver(string $collection): string
    {
        return $this->of($collection)['driver'];
    }

    public function durability(string $collection): string
    {
        return $this->of($collection)['durability'];
    }

    public function isDurable(string $collection): bool
    {
        return $this->durability($collection) === 'safe';
    }

    public function isEncrypted(string $collection): bool
    {
        return $this->of($collection)['encrypted'];
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
    public function uniques(string $collection): array
    {
        return $this->of($collection)['uniques'];
    }

    /** Los ajustes de una coleccion que no dice nada, y la lista de los que hay. */
    private static function defaults(string $driver, string $durabilidad): array
    {
        return [
            'driver'     => $driver,
            'durability' => $durabilidad,
            'encrypted'  => false,
            'uniques'    => [],
            'schema'     => [],
            'ttl'        => 0,
        ];
    }

    /** Campos con reglas declaradas. Vacio si la coleccion no tiene esquema. */
    public function schema(string $collection): array
    {
        return $this->of($collection)['schema'];
    }

    /** Segundos de vida de un documento. Cero significa que no caduca. */
    public function ttl(string $collection): int
    {
        return $this->of($collection)['ttl'];
    }

    /**
     * Los nombres que tenian estas claves cuando el archivo se escribia en
     * español, y el nombre que tienen ahora.
     *
     * Se leen las dos formas. Una base creada antes del cambio sigue abriendo
     * con sus reglas puestas, y la primera escritura la deja ya en la nueva.
     * Sin esto, una coleccion con `UNIQUE` declarado se habria abierto sin el
     * —sin un error, sin un aviso— y habria empezado a admitir duplicados: la
     * peor forma posible de renombrar una clave.
     */
    private const ANTES = [
        'durability' => 'durabilidad',
        'encrypted'  => 'cifrado',
        'uniques'    => 'unicos',
        'schema'     => 'esquema',
        'ttl'        => 'caducidad',
    ];

    /** @return array{driver:string, durability:string, encrypted:bool, uniques:list<string>, schema:array, ttl:int} */
    public function of(string $collection): array
    {
        $path  = $this->pathOf($collection);
        // Huella barata del archivo en disco: si otro proceso cambio los ajustes
        // —una migracion de driver, un unique nuevo— la cache en memoria de ESTE
        // proceso ya no vale. Sin esto, un worker seguia escribiendo con el driver
        // viejo tras una migracion y sus documentos se perdian al retirar el
        // formato anterior. filemtime + filesize basta: cambiar de driver cambia
        // el tamano, y un stat es mucho mas barato que releer y decodificar.
        \clearstatcache(true, $path);
        $sello = \is_file($path) ? [(int) @\filemtime($path), (int) @\filesize($path)] : [0, 0];
        if (isset($this->cache[$collection]) && ($this->sellos[$collection] ?? null) === $sello) {
            return $this->cache[$collection];
        }
        $this->sellos[$collection] = $sello;
        $ajustes = self::defaults($this->driverPorDefecto, $this->durabilidadPorDefecto);

        if (\is_file($path)) {
            $json = \json_decode((string) @\file_get_contents($path), true);
            if (\is_array($json)) {
                $leer = static fn(string $clave): mixed
                    => $json[$clave] ?? $json[self::ANTES[$clave] ?? $clave] ?? null;

                if (\in_array($json['driver'] ?? null, self::DRIVERS, true)) {
                    $ajustes['driver'] = $json['driver'];
                }
                if (\in_array($leer('durability'), self::DURABILIDADES, true)) {
                    $ajustes['durability'] = $leer('durability');
                }
                $ajustes['encrypted'] = (bool) ($leer('encrypted') ?? false);
                $ajustes['uniques']   = self::cleanUniques($leer('uniques') ?? []);
                $ajustes['schema']    = \is_array($leer('schema')) ? $leer('schema') : [];
                $ajustes['ttl']       = \max(0, (int) ($leer('ttl') ?? 0));
            }
        }
        return $this->cache[$collection] = $ajustes;
    }

    /**
     * Cambia los ajustes que se le pasen. Los demas se conservan.
     *
     *   $ajustes->set('clientes', ['driver' => 'packed', 'ttl' => 3600]);
     *
     * Recibe un array y no seis parametros opcionales porque son seis y
     * creciendo: con posicionales, añadir el septimo obligaba a escribir cinco
     * `null` seguidos para llegar hasta el, y una llamada asi no se lee.
     *
     * Ojo: cambiar el driver aqui NO mueve los datos; para eso, Storage::migrarA.
     */
    public function set(string $collection, array $cambios): void
    {
        $desconocidos = \array_diff(\array_keys($cambios), \array_keys(self::defaults('fs', 'safe')));
        if ($desconocidos !== []) {
            throw new Exception('Settings: there is no setting named ' . \implode(', ', $desconocidos) . '.');
        }
        if (isset($cambios['driver']) && !\in_array($cambios['driver'], self::DRIVERS, true)) {
            throw new Exception(
                "Settings: unknown driver '{$cambios['driver']}'. Allowed: "
                . \implode(', ', self::DRIVERS) . '.'
            );
        }
        if (isset($cambios['durability']) && !\in_array($cambios['durability'], self::DURABILIDADES, true)) {
            throw new Exception(
                "Settings: unknown durability '{$cambios['durability']}'. Allowed: "
                . \implode(', ', self::DURABILIDADES) . '.'
            );
        }

        // array_merge y no `+`: la union deja primero las claves que cambian, y
        // entonces el orden del _axidb.json baila segun lo que hayas tocado.
        // Este archivo se lee a ojo y se versiona; el orden tiene que ser
        // siempre el mismo para que un diff enseñe el cambio y no la baraja.
        $nuevo = \array_merge($this->of($collection), $cambios);
        $nuevo['uniques'] = self::cleanUniques($nuevo['uniques']);

        $this->colecciones->ensure($collection);
        $path = $this->pathOf($collection);
        @\file_put_contents(
            $path,
            \json_encode($nuevo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
        $this->cache[$collection] = $nuevo;
        \clearstatcache(true, $path);
        $this->sellos[$collection] = \is_file($path) ? [(int) @\filemtime($path), (int) @\filesize($path)] : [0, 0];
    }

    public function forget(?string $collection = null): void
    {
        if ($collection === null) {
            $this->cache  = [];
            $this->sellos = [];
            return;
        }
        unset($this->cache[$collection], $this->sellos[$collection]);
    }

    /** @return list<string> nombres de campo, sin repetidos y en orden estable */
    private static function cleanUniques(mixed $lista): array
    {
        if (!\is_array($lista)) {
            return [];
        }
        $campos = \array_map('strval', \array_filter($lista, 'is_scalar'));
        $campos = \array_values(\array_unique($campos));
        \sort($campos, SORT_STRING);
        return $campos;
    }

    private function pathOf(string $collection): string
    {
        return $this->colecciones->path($collection) . '/' . self::ARCHIVO;
    }
}
