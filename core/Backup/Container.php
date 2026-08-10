<?php
/**
 * AxiDB - Copias\Container: el formato de un archivo de copia.
 *
 * Formato propio, y hay un motivo concreto. La copia del motor anterior usaba
 * `ZipArchive`, que es una extension OPCIONAL de PHP: en la maquina donde se
 * escribio esto no esta instalada, asi que aquel codigo de copias no habria
 * podido hacer una sola copia. Una funcion de respaldo que depende de algo que
 * puede faltar es exactamente la que no debe faltar.
 *
 * El formato es deliberadamente tonto, para poder abrirlo a mano si algun dia
 * no hay AxiDB a mano:
 *
 *   AXIDB-COPIA-1\n
 *   {"id":"...","momento":"...","tipo":"completa","huellas":{...}}\n
 *   {"ruta":"clientes/c1.json","bytes":132,"sha1":"..."}\n
 *   <132 bytes tal cual>\n
 *   {"ruta":"clientes/_axidb.json","bytes":97,"sha1":"..."}\n
 *   <97 bytes tal cual>\n
 *
 * Los bytes van crudos, sin base64. Un indice vectorial son megabytes binarios y
 * codificarlos en texto los haria un tercio mas grandes a cambio de nada: la
 * longitud va delante, asi que no hace falta que el contenido sea legible para
 * saber donde acaba.
 *
 * Cada entrada lleva su sha1. Una copia que no puede comprobar si esta intacta
 * solo sirve para tener la conciencia tranquila.
 */

declare(strict_types=1);

namespace Axi\Core\Backup;

use Axi\Core\Exception;

final class Container
{
    private const MARCA = 'AXIDB-COPIA-1';

    /** @var resource */
    private $fp;

    private function __construct($fp)
    {
        $this->fp = $fp;
    }

    /** Abre un archivo nuevo y deja escrita la cabecera. */
    public static function create(string $destino, array $cabecera): self
    {
        $dir = \dirname($destino);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new Exception("Backup: could not create the directory {$dir}.");
        }
        $fp = @\fopen($destino, 'wb');
        if (!$fp) {
            throw new Exception("Backup: could not write to {$destino}.");
        }
        $propio = new self($fp);
        $propio->lineOf(self::MARCA);
        $propio->lineOf(self::json($cabecera));
        return $propio;
    }

    /** Mete un archivo del disco dentro de la copia. */
    public function append(string $ruta, string $origen): void
    {
        $bytes = @\file_get_contents($origen);
        if ($bytes === false) {
            throw new Exception("Backup: could not read {$origen}.");
        }
        $this->lineOf(self::json([
            'ruta'  => $ruta,
            'bytes' => \strlen($bytes),
            'sha1'  => \sha1($bytes),
        ]));
        $this->writeTo($bytes . "\n");
    }

    /**
     * Cierra con fsync. Una copia que se queda en la cache del sistema no es una
     * copia: justo el corte del que protege se la llevaria por delante.
     */
    public function close(): void
    {
        \fflush($this->fp);
        @\fsync($this->fp);
        \fclose($this->fp);
    }

    /** La cabecera, sin leer el contenido. Para listar copias deprisa. */
    public static function cabecera(string $archivo): array
    {
        $fp = @\fopen($archivo, 'rb');
        if (!$fp) {
            throw new Exception("Backup: could not open {$archivo}.");
        }
        try {
            if (\rtrim((string) \fgets($fp), "\n") !== self::MARCA) {
                throw new Exception("Backup: {$archivo} is not an AxiDB backup file.");
            }
            $cabecera = \json_decode((string) \fgets($fp), true);
            if (!\is_array($cabecera)) {
                throw new Exception("Backup: the header of {$archivo} is damaged.");
            }
            return $cabecera;
        } finally {
            \fclose($fp);
        }
    }

    /**
     * Recorre las entradas y llama a $porEntrada(ruta, bytes) con cada una.
     *
     * Comprueba el sha1 de todas: si una no cuadra, se para en el acto en vez de
     * restaurar medio conjunto de datos corrupto encima de los buenos.
     *
     * @return int entradas leidas
     */
    public static function each(string $archivo, callable $porEntrada): int
    {
        $fp = @\fopen($archivo, 'rb');
        if (!$fp) {
            throw new Exception("Backup: could not open {$archivo}.");
        }
        $tamano = (int) @\filesize($archivo);
        try {
            \fgets($fp);                    // marca
            \fgets($fp);                    // cabecera

            $n = 0;
            while (($linea = \fgets($fp)) !== false) {
                $linea = \rtrim($linea, "\n");
                if ($linea === '') {
                    continue;
                }
                $meta = \json_decode($linea, true);
                if (!\is_array($meta) || !isset($meta['ruta'], $meta['bytes'], $meta['sha1'])) {
                    throw new Exception("Backup: unreadable entry in {$archivo}.");
                }
                // El tamaño lo declara la cabecera de la entrada, que viaja dentro
                // del archivo. Antes se pasaba tal cual a fread, que pre-reserva el
                // buffer entero: una entrada de 90 bytes que dijera 'bytes:3e8'
                // hacia reservar 300 MB antes de comprobar nada. Se contrasta con
                // lo que de verdad queda en el archivo: nadie puede leer mas bytes
                // de los que hay, asi que una cifra mayor es mentira y se rechaza
                // sin reservar memoria.
                $declarados = (int) $meta['bytes'];
                $restantes  = \max(0, (int) $tamano - (int) \ftell($fp));
                if ($declarados < 0 || $declarados > $restantes) {
                    throw new Exception(
                        "Backup: la entrada '{$meta['ruta']}' declara {$declarados} bytes y solo "
                        . "quedan {$restantes} en el archivo. Copia dañada o manipulada."
                    );
                }
                $bytes = $declarados > 0 ? (string) \fread($fp, $declarados) : '';
                \fgets($fp);                // el salto de linea que la cierra

                if (\strlen($bytes) !== (int) $meta['bytes'] || \sha1($bytes) !== $meta['sha1']) {
                    throw new Exception(
                        "Backup: '{$meta['ruta']}' no cuadra con su huella. El archivo de copia "
                        . 'esta dañado y no se restaura nada.'
                    );
                }
                $porEntrada((string) $meta['ruta'], $bytes);
                $n++;
            }
            return $n;
        } finally {
            \fclose($fp);
        }
    }

    private function lineOf(string $texto): void
    {
        $this->writeTo($texto . "\n");
    }

    private function writeTo(string $bytes): void
    {
        if (\fwrite($this->fp, $bytes) !== \strlen($bytes)) {
            throw new Exception('Backup: incomplete write. Did the disk fill up?');
        }
    }

    private static function json(array $datos): string
    {
        $json = \json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception('Backup: could not serialise the header.');
        }
        return $json;
    }
}
