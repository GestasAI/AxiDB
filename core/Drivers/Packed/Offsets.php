<?php
/**
 * AxiDB - Packed\Offsets: donde esta cada documento dentro del log.
 *
 *   <coleccion>/offsets.log   append-only:  id \t desplazamiento \t longitud
 *   <coleccion>/offsets.idx   instantanea compactada, para arrancar rapido
 *
 * Tambien es append-only, por la misma razon que el log de datos: reescribir el
 * indice entero en cada alta fue exactamente el error que hacia cuadratica la
 * escritura del motor viejo. Aqui una alta son dos añadidos y nada mas.
 *
 * Un borrado se apunta como longitud 0: la ultima entrada de un id manda, asi
 * que una entrada con longitud 0 lo da por muerto sin tocar nada de lo anterior.
 *
 * La instantanea es solo una optimizacion de arranque. Si falta o esta rota, el
 * mapa se reconstruye recorriendo el log; nunca es la fuente de la verdad.
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

    /** Descriptor reutilizado, por la misma razon que en Log. */
    private $fp = null;

    /** Apuntes en el log suelto, los que aun no estan en la instantanea. */
    private int $sueltas = 0;

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
        return $this->mapa ??= $this->loadFrom();
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
    }

    /**
     * Vuelca el mapa a la instantanea y vacia el log suelto cuando este ha
     * crecido demasiado, para que arrancar no cueste cada vez mas.
     *
     * Solo se llama desde el camino de escritura, que es el unico que tiene el
     * lock. Hacerlo al cargar —que es un camino de lectura— significaba escribir
     * y borrar archivos sin proteccion, y en Windows fallaba ademas al intentar
     * borrar un archivo que otro descriptor tenia abierto.
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
    }

    /** Sustituye el mapa entero tras una compactacion. */
    public function rewrite(array $mapa): void
    {
        $this->mapa    = $mapa;
        $this->sueltas = 0;
        $this->persistSnapshot($mapa);
        $this->close();                 // no se borra un archivo aun abierto
        @\unlink($this->rutaLog);
    }

    public function delete(): void
    {
        $this->close();
        @\unlink($this->rutaLog);
        @\unlink($this->rutaInstantanea);
        $this->mapa = [];
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

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

        // Cargar es un camino de LECTURA: aqui solo se anota cuantas entradas
        // sueltas hay. Consolidar es una escritura y la hace anotar(), que es
        // quien tiene el lock.
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
