<?php
/**
 * AxiDB - Packed\Offsets: donde esta cada documento dentro del log.
 *
 *   <coleccion>/offsets.log   append-only:  id \t desplazamiento \t longitud
 *   <coleccion>/offsets.idx   instantanea compactada, para arrancar rapido
 *
 * Append-only como el log de datos: reescribir el indice entero en
 * cada alta era el error que hacia cuadratica la escritura del motor viejo. Un
 * borrado se apunta como longitud 0 —la ultima entrada de un id manda—. La
 * instantanea solo acelera el arranque: si falta o esta rota, el mapa se
 * reconstruye recorriendo el log; nunca es la fuente de la verdad.
 */

declare(strict_types=1);

namespace Axi\Core\Drivers\Packed;

use Axi\Core\Exception;

final class Offsets
{
    /** Se compacta la instantanea cuando el log suelto supera estas entradas. */
    private const ENTRADAS_ANTES_DE_INSTANTANEA = 500;

    /** @var array<string, array{0:int,1:int}>|null id => [desplazamiento, longitud] */
    private ?array $mapa = null;

    private $fp = null;                 // descriptor reutilizado, como en Log

    /** Apuntes en el log suelto, los que aun no estan en la instantanea. */
    private int $sueltas = 0;

    /** @var array{0:int,1:int,2:int}|null [tam log, tam idx, mtime idx] leido la ultima vez */
    private ?array $visto = null;
    public function __construct(
        private string $rutaLog,
        private string $rutaInstantanea,
        private bool $durable = true
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (\is_resource($this->fp)) {
            \fclose($this->fp);
        }
        $this->fp = null;
    }

    /** @return array<string, array{0:int,1:int}> */
    public function map(): array
    {
        if ($this->mapa === null) {
            $this->mapa = $this->loadFrom();
            $this->visto = $this->stamp();
        }
        return $this->mapa ?? [];
    }

    /**
     * Relee el mapa del disco si otro proceso lo cambio desde la ultima vez. Se
     * llama con el lock cogido, antes de leer-modificar-escribir: sin esto, cada
     * proceso trabaja sobre la foto que hizo al arrancar y la escritura del otro
     * se pierde, o una consolidacion trunca el log con un mapa desfasado. Barato
     * en el caso normal —un stat, sin releer— y correcto en la carrera.
     */
    public function syncFromDisk(): void
    {
        if ($this->mapa !== null && $this->visto !== null && $this->stamp() === $this->visto) {
            return;
        }
        $this->close();                     // el otro pudo truncar o rehacer el log
        $this->mapa  = $this->loadFrom();
        $this->visto = $this->stamp();
    }

    public function of(string $id): ?array
    {
        $mapa = $this->map();
        return $mapa[$id] ?? null;
    }

    public function alive(): array
    {
        return \array_keys($this->map());
    }

    public function howMany(): int
    {
        return \count($this->map());
    }

    /** Apunta donde ha quedado un documento. Quien llama tiene el lock. */
    public function record(string $id, int $desplazamiento, int $longitud): void
    {
        $this->addLine($id . "\t" . $desplazamiento . "\t" . $longitud . "\n");
        $this->map();                       // fuerza la carga antes de tocarlo
        $this->mapa[$id] = [$desplazamiento, $longitud];
        $this->consolidateIfDue();
        $this->visto = $this->stamp();      // nuestra propia escritura, no ajena
    }

    /**
     * Vuelca el mapa a la instantanea y vacia el log suelto cuando crece
     * demasiado, para que arrancar no cueste cada vez mas. Solo desde el camino de
     * escritura, que es el unico que tiene el lock: hacerlo al cargar escribia y
     * borraba archivos sin proteccion, y en Windows fallaba ademas al borrar uno
     * que otro descriptor tenia abierto.
     */
    private function consolidateIfDue(): void
    {
        if ($this->sueltas <= self::ENTRADAS_ANTES_DE_INSTANTANEA) {
            return;
        }
        $this->persistSnapshot($this->mapa ?? []);

        // Se trunca en vez de borrar: si otro proceso tiene el archivo abierto
        // en modo añadir, borrarlo dejaria su descriptor apuntando a la nada.
        if (\is_resource($this->fp)) {
            \ftruncate($this->fp, 0);
            \fseek($this->fp, 0, SEEK_END);
        } else {
            @\file_put_contents($this->rutaLog, '');
        }
        $this->sueltas = 0;
    }

    /** Marca un documento como muerto. Longitud 0 es la lapida. */
    public function markDeleted(string $id): void
    {
        $this->addLine($id . "\t0\t0\n");
        $this->map();
        unset($this->mapa[$id]);
        $this->visto = $this->stamp();
    }

    /** Sustituye el mapa entero tras una compactacion. */
    public function rewrite(array $mapa): void
    {
        $this->mapa    = $mapa;
        $this->sueltas = 0;
        $this->persistSnapshot($mapa);
        $this->close();                 // no se borra un archivo aun abierto
        @\unlink($this->rutaLog);
        $this->visto = $this->stamp();
    }

    public function delete(): void
    {
        $this->close();
        @\unlink($this->rutaLog);
        @\unlink($this->rutaInstantanea);
        $this->mapa = [];
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /** Huella barata del estado en disco: si cambia, otro proceso escribio. */
    private function stamp(): array
    {
        \clearstatcache(true, $this->rutaLog);
        \clearstatcache(true, $this->rutaInstantanea);
        return [
            \is_file($this->rutaLog) ? (int) \filesize($this->rutaLog) : -1,
            \is_file($this->rutaInstantanea) ? (int) \filesize($this->rutaInstantanea) : -1,
            \is_file($this->rutaInstantanea) ? (int) \filemtime($this->rutaInstantanea) : -1,
        ];
    }

    private function addLine(string $linea): void
    {
        if (!\is_resource($this->fp)) {
            $this->fp = @\fopen($this->rutaLog, 'ab');
            if (!$this->fp) {
                throw new Exception("Packed: could not record the offset in '{$this->rutaLog}'.");
            }
        }
        if (\fwrite($this->fp, $linea) === false) {
            throw new Exception('Packed: failed to record the offset.');
        }
        $this->sueltas++;
        \fflush($this->fp);
        if ($this->durable && \function_exists('fsync')) {
            @\fsync($this->fp);
        }
    }

    /**
     * Instantanea primero, log despues: el log solo contiene lo posterior a la
     * ultima instantanea, y la ultima entrada de cada id manda.
     */
    private function loadFrom(): array
    {
        $mapa = [];

        if (\is_file($this->rutaInstantanea)) {
            $json = \json_decode((string) @\file_get_contents($this->rutaInstantanea), true);
            if (\is_array($json)) {
                $mapa = $json;
            }
        }

        $sueltas = 0;
        if (\is_file($this->rutaLog)) {
            $fp = @\fopen($this->rutaLog, 'rb');
            if ($fp) {
                while (($linea = \fgets($fp)) !== false) {
                    if (!\str_ends_with($linea, "\n")) {
                        break;                   // apunte incompleto: se ignora
                    }
                    $partes = \explode("\t", \rtrim($linea, "\n"));
                    if (\count($partes) !== 3) {
                        continue;
                    }
                    [$id, $desplazamiento, $longitud] = $partes;
                    $sueltas++;
                    if ((int) $longitud === 0) {
                        unset($mapa[$id]);
                    } else {
                        $mapa[$id] = [(int) $desplazamiento, (int) $longitud];
                    }
                }
                \fclose($fp);
            }
        }

        // Cargar es LECTURA: solo se anota cuantas sueltas hay. Consolidar es
        // escritura y la hace record(), que es quien tiene el lock.
        $this->sueltas = $sueltas;
        return $mapa;
    }

    private function persistSnapshot(array $mapa): void
    {
        $tmp = $this->rutaInstantanea . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, \json_encode($mapa)) === false) {
            return;
        }
        if (!@\rename($tmp, $this->rutaInstantanea)) {
            @\unlink($tmp);
        }
    }
}
