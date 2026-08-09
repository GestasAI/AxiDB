<?php
/**
 * AxiDB - Core\Storage: la puerta de entrada al almacenamiento.
 *
 * Ya no guarda documentos: elige quien los guarda. Cada coleccion declara como
 * quiere guardarse en su propio `_axidb.json`, asi que la carpeta de datos se
 * explica sola: quien la reciba sabe como leerla sin que le expliquen nada.
 *
 *   fs      un archivo por documento. Legible, diff-eable, reparable a mano.
 *   packed  un archivo por coleccion. Ordenes de magnitud mas rapido escribiendo.
 *
 * El defecto es fs, que es lo que habia: una instalacion existente no cambia de
 * formato por actualizar. Pasar a packed es explicito y por coleccion.
 */

declare(strict_types=1);

namespace Axi\Core;

use Axi\Core\Drivers\Driver;
use Axi\Core\Drivers\FsDriver;
use Axi\Core\Drivers\PackedDriver;

final class Storage
{
    use \Axi\Core\Storage\WithEncryption;
    use \Axi\Core\Storage\WithDrivers;

    public const DRIVERS     = Settings::DRIVERS;
    public const POR_DEFECTO = 'fs';

    private Collections $colecciones;
    private Settings $ajustes;
    private FsDriver $fs;
    private PackedDriver $packed;

    public function __construct(
        private string $base,
        private bool $durable = true,
        ?string $clave = null
    ) {
        if (!\is_dir($this->base) && !@\mkdir($this->base, 0755, true) && !\is_dir($this->base)) {
            throw new Exception("No se pudo crear el directorio de datos: {$this->base}");
        }
        Shield::aplicar($this->base);
        $this->colecciones = new Collections($this->base);
        $this->ajustes     = new Settings($this->colecciones, self::POR_DEFECTO, $durable ? 'safe' : 'fast');
        $this->fs          = new FsDriver($this->colecciones, $this->ajustes);
        $this->packed      = new PackedDriver($this->colecciones, $this->ajustes);

        $this->prepararCifrado($this->base, $clave);
    }

    public function basePath(): string
    {
        return $this->base;
    }

    /* ─────────────────────────────── Documentos ─────────────────────────────── */

    public function put(string $collection, string $id, array $data, bool $replace = false): array
    {
        return $this->driver($collection)->put($collection, $id, $data, $replace);
    }

    public function get(string $collection, string $id): ?array
    {
        return $this->driver($collection)->get($collection, $id);
    }

    public function exists(string $collection, string $id): bool
    {
        return $this->get($collection, $id) !== null;
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->driver($collection)->delete($collection, $id);
    }

    public function all(string $collection): array
    {
        return $this->driver($collection)->all($collection);
    }

    public function count(string $collection): int
    {
        return $this->driver($collection)->count($collection);
    }

    /**
     * Ids reales, los que se pueden volver a pasar a get().
     *
     * SORT_STRING no es una precaucion: sin el, esto devuelve mal el orden.
     *
     * Un id es fecha + microsegundos + seis caracteres hexadecimales, y a veces
     * ese sufijo sale todo digitos. Entonces la cadena entera parece un numero,
     * y sort() a secas compara numeros: 24 digitos no caben en un float, asi que
     * ids distintos salen iguales y el orden queda al azar. Peor todavia cuando
     * el sufijo empieza por 'e' —2026...00955e8814— porque eso se lee como
     * notacion cientifica y vale infinito, y ese id se va al final de la lista.
     *
     * Se descubrio por un test que fallaba una vez de cada seis. Ordenar cadenas
     * como cadenas.
     */
    public function ids(string $collection): array
    {
        $ids = \array_column($this->all($collection), 'id');
        \sort($ids, SORT_STRING);
        return $ids;
    }

    public function sweep(string $collection, int $minAgeSeconds = 300): int
    {
        return $this->driver($collection)->sweep($collection, $minAgeSeconds);
    }

    /* ─────────────────────────────── Colecciones ─────────────────────────────── */

    public function collections(): array
    {
        return $this->colecciones->all();
    }

    public function dropCollection(string $collection): bool
    {
        $this->packed->olvidar($collection);
        return $this->colecciones->drop($collection);
    }

    public function ensureCollection(string $collection): bool
    {
        return $this->colecciones->ensure($collection);
    }

    public function dir(string $collection): string
    {
        return $this->colecciones->path($collection);
    }

    /**
     * Renombra una coleccion moviendo su directorio.
     *
     * No reescribe ni un documento: los ajustes, los indices y los vectores
     * viven dentro de la carpeta, asi que van con ella. Cuesta lo mismo con
     * diez documentos que con un millon.
     */
    public function renameCollection(string $de, string $a): bool
    {
        self::name($de, 'coleccion');
        self::name($a, 'coleccion');

        $origen  = $this->colecciones->path($de);
        $destino = $this->colecciones->path($a);

        if (!\is_dir($origen)) {
            throw new Exception("Storage: la coleccion '{$de}' no existe.");
        }
        if (\is_dir($destino)) {
            throw new Exception("Storage: ya hay una coleccion '{$a}'.");
        }
        $this->cerrar();                        // sin descriptores abiertos no hay quien impida mover
        $this->olvidar();

        if (!@\rename($origen, $destino)) {
            throw new Exception("Storage: no se pudo renombrar '{$de}' a '{$a}'.");
        }
        return true;
    }

    /* ─────────────────────────────── Unicidad ─────────────────────────────── */

    /** @return list<string> campos que no admiten valores repetidos */
    public function unicosDe(string $collection): array
    {
        return $this->ajustes->uniques($collection);
    }

    /** Declara o retira la unicidad de un campo. No comprueba los datos: eso es de Db. */
    public function declararUnico(string $collection, string $field, bool $unico = true): void
    {
        $campos = $this->unicosDe($collection);
        $campos = $unico
            ? [...$campos, $field]
            : \array_values(\array_filter($campos, static fn($c) => $c !== $field));

        $this->ajustes->fijar($collection, ['uniques' => $campos]);
    }

    /* ─────────────────────────────── Esquema y caducidad ───────────────────── */

    /** @return array<string, array> reglas por campo. Vacio: la coleccion no tiene esquema */
    public function esquemaDe(string $collection): array
    {
        return $this->ajustes->schema($collection);
    }

    public function defineSchema(string $collection, array $reglas): void
    {
        $this->ajustes->fijar($collection, ['schema' => SchemaRules::validarReglas($reglas)]);
    }

    /** Segundos de vida de un documento. Cero: no caduca. */
    public function caducidadDe(string $collection): int
    {
        return $this->ajustes->ttl($collection);
    }

    public function defineTtl(string $collection, int $segundos): void
    {
        if ($segundos < 0) {
            throw new Exception("Storage: la caducidad no puede ser negativa ({$segundos}).");
        }
        $this->ajustes->fijar($collection, ['ttl' => $segundos]);
        $this->caducados = [];
    }

    public function cerrar(): void
    {
        $this->packed->olvidar();
    }

    /**
     * Tira todo lo que hay en memoria sobre los datos.
     *
     * Se llama tras restaurar una copia: los ajustes cacheados, los decoradores
     * montados y los descriptores abiertos son de los datos ANTERIORES. Seguir
     * usandolos leeria un archivo que ya no es el que era.
     */
    public function olvidar(): void
    {
        $this->packed->olvidar();
        $this->ajustes->olvidar();
        $this->cifrados = [];
        $this->caducados = [];
    }

    /** Atajo a Names::check para las demas clases del nucleo. */
    public static function name(string $value, string $kind): string
    {
        return Names::check($value, $kind);
    }

}
