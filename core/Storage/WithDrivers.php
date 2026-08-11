<?php
/**
 * AxiDB - Almacen\WithDrivers: que driver usa cada coleccion y como se cambia.
 *
 * Elegir formato, migrar entre formatos y compactar son un asunto propio, y
 * separado de "guardar y leer documentos", que es lo que queda en Storage.
 *
 * No cambia nada de puertas afuera: se sigue escribiendo `$storage->migrateTo(...)`.
 */

declare(strict_types=1);

namespace Axi\Core\Storage;

use Axi\Core\Drivers\Driver;
use Axi\Core\Exception;
use Axi\Core\Migration;
use Axi\Core\Storage;

trait WithDrivers
{
    /* ─────────────────────────────── Drivers ─────────────────────────────── */

    /** Driver declarado por la coleccion. 'fs' si no dice nada. */
    public function driverDe(string $collection): string
    {
        return $this->ajustes->driver($collection);
    }

    /** 'safe' (fsync en cada escritura) o 'fast'. */
    public function durabilityOf(string $collection): string
    {
        return $this->ajustes->durability($collection);
    }

    /**
     * Deja escrito que driver usa una coleccion vacia. No mueve datos.
     *
     * Se niega si ya tiene documentos escritos con otro driver: cambiar la
     * declaracion sin moverlos los dejaria en disco pero invisibles, y eso no
     * puede depender de que el que llama haya leido la documentacion. Para
     * mover, migrarA().
     */
    public function declareDriver(string $collection, string $nombre): void
    {
        $actual = $this->driverDe($collection);
        if ($actual !== $nombre) {
            $cuantos = $this->driverByName($actual)->count($collection);
            if ($cuantos > 0) {
                throw new Exception(
                    "Storage: '{$collection}' ya tiene {$cuantos} documentos en '{$actual}'. "
                    . "Changing the declaration would leave them invisible; use migrarA('{$collection}', '{$nombre}')."
                );
            }
        }
        $this->ajustes->set($collection, ['driver' => $nombre]);
        $this->packed->forget($collection);
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
    public function declareDurability(string $collection, string $nivel): void
    {
        $this->ajustes->set($collection, ['durability' => $nivel]);
        $this->packed->forget($collection);
    }

    /**
     * Cambia el driver de una coleccion migrando sus documentos. Ver Migracion
     * para por que el orden de los pasos es lo que la hace segura.
     *
     * @return int documentos migrados
     */
    public function migrateTo(string $collection, string $destino): int
    {
        self::name($collection, 'coleccion');
        if (!\in_array($destino, self::DRIVERS, true)) {
            throw new Exception("Storage: driver desconocido '{$destino}'.");
        }
        // Cerrojo estructural EXCLUSIVO: migrar lee todo con el driver de origen,
        // lo reescribe con el de destino y retira el viejo. Un alta que cayera en
        // medio escribiria en un formato que va a desaparecer y se perderia. Las
        // altas cogen este mismo cerrojo en SH (Db::put), asi que aqui se espera a
        // las que haya en curso y se bloquean las nuevas hasta terminar. Al soltar,
        // un alta reabre y ve el driver nuevo (los ajustes se releen por huella).
        $this->colecciones->ensure($collection);       // el dir tiene que existir para el lock
        $lockPath = $this->colecciones->path($collection) . '/_reindex.lock';
        $lock = @\fopen($lockPath, 'c');
        if ($lock === false) {
            throw new Exception("Storage: could not open the structural lock of '{$collection}'.");
        }
        try {
            \flock($lock, LOCK_EX);
            $migrados = (new Migration($this->colecciones, $this->ajustes))->mover(
                $collection,
                $this->driverByName($this->driverDe($collection)),
                $this->driverByName($destino)
            );
            $this->packed->forget($collection);
            return $migrados;
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }
    }

    /**
     * Compacta la coleccion ahora, sin esperar al umbral. Solo tiene efecto en
     * packed; en fs no hay nada que compactar. Devuelve los bytes recuperados.
     */
    public function compact(string $collection): int
    {
        self::name($collection, 'coleccion');
        return $this->driverDe($collection) === 'packed'
            ? $this->packed->compact($collection)
            : 0;
    }

    /** Cuanto del archivo es espacio muerto (solo packed). Para diagnostico. */
    public function deadRatio(string $collection): float
    {
        return $this->driverDe($collection) === 'packed'
            ? $this->packed->deadRatio($collection)
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
    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /**
     * El driver de una coleccion, ya envuelto si esta cifrada. Todo lo que
     * lee o escribe documentos pasa por aqui, y por eso el cifrado no se puede
     * olvidar en ningun camino: no hay otro sitio por donde entrar.
     */
    private function driver(string $collection): Driver
    {
        self::name($collection, 'coleccion');
        return $this->wrap(
            $this->driverByName($this->driverDe($collection)),
            $collection
        );
    }

    private function driverByName(string $nombre): Driver
    {
        return $nombre === 'packed' ? $this->packed : $this->fs;
    }
}
