<?php
/**
 * AxiDB - Vector\Archivos: el trato con el disco, y nada mas.
 *
 * Los cuatro archivos del indice vectorial se manejan igual —posiciones fijas,
 * lecturas por desplazamiento, un cerrojo para escribir— asi que esa mecanica
 * vive aqui y Almacen se queda con las decisiones.
 *
 * Separarlo no es orden por el orden: mientras estuvieron juntos, el archivo
 * pasaba de 330 lineas y costaba ver donde acababa la logica de vectores y
 * empezaba la de `fseek`.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

use Axi\Core\Exception;

final class Archivos
{
    private const NOMBRES = [
        'manifiesto' => 'manifiesto.json',
        'codigos'    => 'codigos.bin',
        'vectores'   => 'vectores.f32',
        'ids'        => 'ids.bin',
    ];

    /** @var array<string, resource> descriptores abiertos, uno por archivo */
    private array $abiertos = [];

    public function __construct(private string $dir)
    {
    }

    /**
     * Suelta descriptores. Obligatorio antes de reemplazar o borrar un archivo:
     * en Windows no se renombra sobre algo que esta abierto.
     *
     * Se puede cerrar uno solo, y conviene. Cerrarlos todos por reemplazar el
     * manifiesto obligaba a reabrir los otros tres, y abrir un archivo para
     * escribir cuesta 16 ms en esta maquina: mil bajas se iban en medio minuto
     * de abrir y cerrar archivos que nadie habia tocado.
     */
    public function cerrar(?string $cual = null): void
    {
        foreach ($this->abiertos as $clave => $fp) {
            if ($cual !== null && $clave !== $cual && $clave !== 'r:' . $cual) {
                continue;
            }
            if (\is_resource($fp)) {
                \fclose($fp);
            }
            unset($this->abiertos[$clave]);
        }
    }

    public function __destruct()
    {
        $this->cerrar();
    }

    public function ruta(string $cual): string
    {
        return $this->dir . '/' . self::NOMBRES[$cual];
    }

    public function hay(string $cual): bool
    {
        return \is_file($this->ruta($cual));
    }

    public function crearDirectorio(): void
    {
        if (!\is_dir($this->dir) && !@\mkdir($this->dir, 0755, true) && !\is_dir($this->dir)) {
            throw new Exception("Vector: no se pudo crear {$this->dir}.");
        }
        foreach (['codigos', 'vectores', 'ids'] as $cual) {
            if (!$this->hay($cual)) {
                \file_put_contents($this->ruta($cual), '');
            }
        }
    }

    /** Bytes que ocupa el archivo. Cero si no existe. */
    public function tamaño(string $cual): int
    {
        // clearstatcache primero: PHP recuerda el tamaño de un archivo, y aqui
        // se pregunta justo despues de haberlo agrandado.
        \clearstatcache(true, $this->ruta($cual));
        $t = @\filesize($this->ruta($cual));
        return $t === false ? 0 : $t;
    }

    public function leerTodo(string $cual): string
    {
        return (string) @\file_get_contents($this->ruta($cual));
    }

    /**
     * Lee el trozo del ordinal. Cadena vacia si esa posicion no existe.
     *
     * Con el descriptor cacheado, igual que al escribir. Una busqueda lee
     * doscientos candidatos de uno en uno; abriendo el archivo cada vez, esas
     * doscientas aperturas eran 30 de los 147 ms que costaba buscar entre
     * 50.000 vectores.
     */
    public function leerTrozo(string $cual, int $ordinal, int $ancho): string
    {
        $fp = $this->descriptorLectura($cual);
        if ($fp === null) {
            return '';
        }
        \fseek($fp, $ordinal * $ancho);
        $bytes = (string) \fread($fp, $ancho);
        return \strlen($bytes) === $ancho ? $bytes : '';
    }

    /**
     * Escribe en la posicion del ordinal. Si el archivo era mas corto, el hueco
     * queda a ceros, que es justo lo que significa "de baja".
     */
    public function escribirEn(string $cual, int $ordinal, int $ancho, string $dato): void
    {
        $fp = $this->descriptor($cual);
        \fseek($fp, $ordinal * $ancho);
        if (\fwrite($fp, $dato) !== \strlen($dato)) {
            throw new Exception('Vector: escritura incompleta en ' . $this->ruta($cual) . '.');
        }
        \fflush($fp);
    }

    /**
     * El descriptor de un archivo, abierto una sola vez.
     *
     * Medido indexando 2.000 vectores: abriendo y cerrando en cada escritura,
     * 18,7 ms por vector. Son tres archivos por vector, y cada apertura en
     * Windows pasa por el antivirus.
     *
     * Es exactamente la misma leccion que dio el driver empaquetado en la ola
     * A4, y aqui se volvio a pagar entera por no haberla aplicado de entrada.
     *
     * @return resource
     */
    /**
     * Descriptor solo de lectura, tambien cacheado y con su propia clave.
     *
     * Separado del de escritura porque un archivo se puede leer aunque no se
     * pueda escribir: si se reutilizara el de escritura, una coleccion de solo
     * lectura dejaria de poder consultarse.
     *
     * @return resource|null
     */
    private function descriptorLectura(string $cual)
    {
        $clave = 'r:' . $cual;
        if (isset($this->abiertos[$clave]) && \is_resource($this->abiertos[$clave])) {
            return $this->abiertos[$clave];
        }
        $fp = @\fopen($this->ruta($cual), 'rb');
        return $fp ? $this->abiertos[$clave] = $fp : null;
    }

    private function descriptor(string $cual)
    {
        if (isset($this->abiertos[$cual]) && \is_resource($this->abiertos[$cual])) {
            return $this->abiertos[$cual];
        }
        $ruta = $this->ruta($cual);
        $fp   = @\fopen($ruta, 'r+b') ?: @\fopen($ruta, 'w+b');
        if (!$fp) {
            throw new Exception("Vector: no se pudo escribir en {$ruta}.");
        }
        return $this->abiertos[$cual] = $fp;
    }

    /**
     * Temporal y renombrado. Con reintentos por lo mismo que en FsDriver: en
     * Windows, un lector con el archivo abierto hace fallar el renombrado.
     */
    public function escribirAtomico(string $cual, string $contenido): void
    {
        $this->cerrar($cual);               // no se renombra sobre un archivo abierto
        $ruta = $this->ruta($cual);
        $tmp  = $ruta . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $contenido) === false) {
            throw new Exception("Vector: no se pudo escribir {$tmp}.");
        }
        for ($intento = 0; $intento < 10; $intento++) {
            if (@\rename($tmp, $ruta)) {
                return;
            }
            \usleep(500 + $intento * 500);
        }
        @\unlink($tmp);
        throw new Exception("Vector: no se pudo reemplazar {$ruta}.");
    }

    /**
     * Ejecuta algo con el cerrojo del indice cogido. Un unico escritor, igual
     * que en el driver empaquetado.
     */
    public function conCerrojo(callable $tarea): mixed
    {
        $fp = @\fopen($this->dir . '/_vec.lock', 'c');
        if (!$fp) {
            throw new Exception("Vector: no se pudo abrir el cerrojo en {$this->dir}.");
        }
        try {
            if (!\flock($fp, LOCK_EX)) {
                throw new Exception("Vector: cerrojo exclusivo fallido en {$this->dir}.");
            }
            return $tarea();
        } finally {
            \flock($fp, LOCK_UN);
            \fclose($fp);
        }
    }

    /** Borra el indice entero. Para desactivar los vectores de una coleccion. */
    public function borrar(): void
    {
        $this->cerrar();
        foreach (\array_keys(self::NOMBRES) as $cual) {
            @\unlink($this->ruta($cual));
        }
        @\unlink($this->dir . '/_vec.lock');
        foreach (\glob($this->dir . '/*.tmp.*') ?: [] as $tmp) {
            @\unlink($tmp);
        }
        @\rmdir($this->dir);
    }
}
