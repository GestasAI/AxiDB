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
    public const DRIVERS     = Ajustes::DRIVERS;
    public const POR_DEFECTO = 'fs';

    private Collections $colecciones;
    private Ajustes $ajustes;
    private FsDriver $fs;
    private PackedDriver $packed;

    public function __construct(
        private string $base,
        private bool $durable = true
    ) {
        if (!\is_dir($this->base) && !@\mkdir($this->base, 0755, true) && !\is_dir($this->base)) {
            throw new Exception("No se pudo crear el directorio de datos: {$this->base}");
        }
        Blindaje::aplicar($this->base);
        $this->colecciones = new Collections($this->base);
        $this->ajustes     = new Ajustes($this->colecciones, self::POR_DEFECTO, $durable ? 'safe' : 'fast');
        $this->fs          = new FsDriver($this->colecciones, $this->ajustes);
        $this->packed      = new PackedDriver($this->colecciones, $this->ajustes);
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

    /** Ids reales, los que se pueden volver a pasar a get(). */
    public function ids(string $collection): array
    {
        $ids = \array_column($this->all($collection), 'id');
        \sort($ids);
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

    /* ─────────────────────────────── Drivers ─────────────────────────────── */

    /** Driver declarado por la coleccion. 'fs' si no dice nada. */
    public function driverDe(string $collection): string
    {
        return $this->ajustes->driver($collection);
    }

    /** 'safe' (fsync en cada escritura) o 'fast'. */
    public function durabilidadDe(string $collection): string
    {
        return $this->ajustes->durabilidad($collection);
    }

    /**
     * Deja escrito que driver usa una coleccion vacia. No mueve datos.
     *
     * Se niega si ya tiene documentos escritos con otro driver: cambiar la
     * declaracion sin moverlos los dejaria en disco pero invisibles, y eso no
     * puede depender de que el que llama haya leido la documentacion. Para
     * mover, migrarA().
     */
    public function declararDriver(string $collection, string $nombre): void
    {
        $actual = $this->driverDe($collection);
        if ($actual !== $nombre) {
            $cuantos = $this->driverPorNombre($actual)->count($collection);
            if ($cuantos > 0) {
                throw new Exception(
                    "Storage: '{$collection}' ya tiene {$cuantos} documentos en '{$actual}'. "
                    . "Cambiar la declaracion los dejaria invisibles; usa migrarA('{$collection}', '{$nombre}')."
                );
            }
        }
        $this->ajustes->fijar($collection, $nombre, null);
        $this->packed->olvidar($collection);
    }

    /**
     * Fija la durabilidad de una coleccion.
     *
     *   safe  fsync en cada escritura. El dato esta en el disco antes de que la
     *         llamada devuelva, y sobrevive a un corte de corriente.
     *   fast  sin fsync. La escritura llega a la cache del sistema, asi que
     *         sobrevive a que el proceso muera pero no a que se vaya la luz.
     *
     * Es por coleccion porque no todas valen lo mismo: una que se puede
     * regenerar desde su origen no necesita pagar el fsync.
     */
    public function declararDurabilidad(string $collection, string $nivel): void
    {
        $this->ajustes->fijar($collection, null, $nivel);
        $this->packed->olvidar($collection);
    }

    /**
     * Cambia el driver de una coleccion migrando sus documentos. Ver Migracion
     * para por que el orden de los pasos es lo que la hace segura.
     *
     * @return int documentos migrados
     */
    public function migrarA(string $collection, string $destino): int
    {
        self::name($collection, 'coleccion');
        if (!\in_array($destino, self::DRIVERS, true)) {
            throw new Exception("Storage: driver desconocido '{$destino}'.");
        }
        $migrados = (new Migracion($this->colecciones, $this->ajustes))->mover(
            $collection,
            $this->driverPorNombre($this->driverDe($collection)),
            $this->driverPorNombre($destino)
        );
        $this->packed->olvidar($collection);
        return $migrados;
    }

    /**
     * Compacta la coleccion ahora, sin esperar al umbral. Solo tiene efecto en
     * packed; en fs no hay nada que compactar. Devuelve los bytes recuperados.
     */
    public function compactar(string $collection): int
    {
        self::name($collection, 'coleccion');
        return $this->driverDe($collection) === 'packed'
            ? $this->packed->compactar($collection)
            : 0;
    }

    /** Cuanto del archivo es espacio muerto (solo packed). Para diagnostico. */
    public function proporcionMuerta(string $collection): float
    {
        return $this->driverDe($collection) === 'packed'
            ? $this->packed->proporcionMuerta($collection)
            : 0.0;
    }

    /**
     * Suelta los descriptores que el driver mantenga abiertos.
     *
     * Normalmente no hace falta: se cierran solos al acabar el proceso. Si hace
     * falta cuando algo externo va a reemplazar o borrar los archivos —en
     * Windows no se renombra sobre un archivo abierto— o al abrir dos instancias
     * sobre el mismo directorio.
     */
    public function cerrar(): void
    {
        $this->packed->olvidar();
    }

    /** Atajo a Names::check para las demas clases del nucleo. */
    public static function name(string $value, string $kind): string
    {
        return Names::check($value, $kind);
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    private function driver(string $collection): Driver
    {
        self::name($collection, 'coleccion');
        return $this->driverPorNombre($this->driverDe($collection));
    }

    private function driverPorNombre(string $nombre): Driver
    {
        return $nombre === 'packed' ? $this->packed : $this->fs;
    }

}
