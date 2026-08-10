<?php
/**
 * AxiDB - Packed\Log: el archivo de datos de una coleccion empaquetada.
 *
 *   <coleccion>/data.axi   una linea JSON por version escrita del documento
 *
 * Solo se añade al final. Nunca se modifica ni se borra un byte ya escrito, y
 * de ahi salen las dos propiedades que hacen esto rapido y seguro a la vez:
 *
 *  - Un lector no necesita bloqueo. Los bytes que ya estan nunca cambian, asi
 *    que puede leer por desplazamiento mientras otro escribe.
 *  - Un corte a mitad de un añadido solo puede dejar una ultima linea
 *    incompleta. Como el desplazamiento se apunta DESPUES de escribir el dato,
 *    esa linea no la referencia nadie y se ignora sola.
 *
 * El espacio de las versiones viejas no se recupera al escribir: lo recoge la
 * compactacion cuando pasa del umbral.
 */

declare(strict_types=1);

namespace Axi\Core\Drivers\Packed;

use Axi\Core\Exception;
use Axi\Core\Meta;

final class Log
{
    /**
     * Descriptor de escritura, abierto una vez y reutilizado. Abrir y cerrar en
     * cada añadido cuesta 0,098 ms medidos; mantenerlo abierto, 0,004. En una
     * carga masiva esa diferencia es casi todo el tiempo.
     */
    private $fp = null;

    public function __construct(
        private string $path,
        private bool $durable = true
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Suelta el descriptor. Obligatorio antes de reemplazar el archivo. */
    public function close(): void
    {
        if (\is_resource($this->fp)) {
            \fclose($this->fp);
        }
        $this->fp = null;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function hasIndex(): bool
    {
        return \is_file($this->path);
    }

    public function size(): int
    {
        return $this->hasIndex() ? (int) @\filesize($this->path) : 0;
    }

    /**
     * Añade un documento al final. Devuelve [desplazamiento, longitud], que es
     * lo que hay que apuntar en el indice para poder recuperarlo.
     *
     * Quien llama debe tener el lock de escritura de la coleccion: dos añadidos
     * simultaneos sin lock podrian entrelazarse a mitad de linea.
     */
    public function append(array $doc): array
    {
        $linea = Meta::codificarPlano($doc) . "\n";

        $fp = $this->handleFor();

        // fseek al final antes de preguntar la posicion: en Windows, ftell()
        // sobre un descriptor en modo 'a' no refleja el tamaño real del archivo
        // y los desplazamientos apuntarian al sitio equivocado.
        \fseek($fp, 0, SEEK_END);
        $desplazamiento = \ftell($fp);

        if (\fwrite($fp, $linea) === false) {
            throw new Exception("Packed: append failed in '{$this->path}'.");
        }
        \fflush($fp);
        // El dato se sincroniza ANTES de que nadie apunte a el. Al reves, un
        // corte dejaria un desplazamiento apuntando a bytes que no llegaron.
        if ($this->durable && \function_exists('fsync')) {
            @\fsync($fp);
        }
        return [$desplazamiento, \strlen($linea)];
    }

    /** Lee un documento por desplazamiento. null si no se puede interpretar. */
    public function readAt(int $desplazamiento, int $longitud): ?array
    {
        $fp = @\fopen($this->path, 'rb');
        if (!$fp) {
            return null;
        }
        try {
            if (\fseek($fp, $desplazamiento) !== 0) {
                return null;
            }
            $raw = \fread($fp, $longitud);
        } finally {
            \fclose($fp);
        }
        return \is_string($raw) ? Meta::decodificar(\rtrim($raw, "\n")) : null;
    }

    /**
     * Recorre el archivo entero devolviendo [desplazamiento, longitud, doc] de
     * cada linea completa. Se usa para reconstruir el indice y para compactar.
     *
     * Una ultima linea sin salto final es una escritura que se corto: se
     * descarta, que es justo lo que debe pasar.
     */
    public function each(): \Generator
    {
        if (!$this->hasIndex()) {
            return;
        }
        $fp = @\fopen($this->path, 'rb');
        if (!$fp) {
            return;
        }
        try {
            $desplazamiento = 0;
            while (($linea = \fgets($fp)) !== false) {
                $longitud = \strlen($linea);
                if (!\str_ends_with($linea, "\n")) {
                    break;                       // añadido incompleto: se ignora
                }
                $doc = Meta::decodificar(\rtrim($linea, "\n"));
                if ($doc !== null) {
                    yield [$desplazamiento, $longitud, $doc];
                }
                $desplazamiento += $longitud;
            }
        } finally {
            \fclose($fp);
        }
    }

    private function handleFor()
    {
        if (!\is_resource($this->fp)) {
            $this->fp = @\fopen($this->path, 'ab');
            if (!$this->fp) {
                throw new Exception("Packed: could not open for append '{$this->path}'.");
            }
        }
        return $this->fp;
    }

    /** Reemplaza el archivo entero de forma atomica. Lo usa la compactacion. */
    public function replaceWith(string $rutaTemporal): void
    {
        $this->close();                 // no se puede reemplazar con el abierto
        if (!@\rename($rutaTemporal, $this->path)) {
            @\unlink($rutaTemporal);
            throw new Exception("Packed: could not replace '{$this->path}'.");
        }
    }

    public function delete(): void
    {
        @\unlink($this->path);
    }
}
