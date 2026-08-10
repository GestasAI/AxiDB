<?php
/**
 * AxiDB - Copias\Exchange: sacar y meter datos en JSON y CSV.
 *
 * No es una copia de seguridad y conviene no confundirlos. Una copia guarda el
 * directorio entero —indices, ajustes, vectores— para volver exactamente a un
 * momento. Esto saca los DOCUMENTOS de una coleccion para llevarlos a otro
 * sitio: una hoja de calculo, otra base de datos, un correo.
 *
 * Por eso exportar no guarda metadatos de estructura y importar no los exige.
 *
 * El CSV se escribe y se lee con `fputcsv`/`fgetcsv`, que estan en el nucleo de
 * PHP y ya saben de comillas, comas dentro del valor y saltos de linea dentro de
 * una celda. Escribir eso a mano es la forma mas rapida de tener un exportador
 * que funciona hasta que alguien pone una coma en un nombre.
 */

declare(strict_types=1);

namespace Axi\Core\Backup;

use Axi\Core\Exception;

final class Exchange
{
    /**
     * @param list<array> $documentos
     * @return int documentos escritos
     */
    public static function aJson(array $documentos, string $destino): int
    {
        $json = \json_encode(
            \array_values($documentos),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new Exception('Export: could not serialise: ' . \json_last_error_msg());
        }
        self::persist($destino, $json . "\n");
        return \count($documentos);
    }

    /** @return list<array> */
    public static function desdeJson(string $origen): array
    {
        $json = \json_decode(self::readAt($origen), true);
        if (!\is_array($json)) {
            throw new Exception("Import: {$origen} does not contain a valid JSON list.");
        }
        return \array_values(\array_filter($json, 'is_array'));
    }

    /**
     * A CSV. La primera fila son los nombres de columna: la union de todos los
     * campos que aparecen, no los del primer documento.
     *
     * Mirar solo el primero es el error clasico: si el segundo documento trae un
     * campo que el primero no tenia, se pierde sin avisar.
     *
     * @param list<array> $documentos
     */
    public static function aCsv(array $documentos, string $destino, string $separador = ','): int
    {
        $columnas = [];
        foreach ($documentos as $doc) {
            foreach (\array_keys($doc) as $campo) {
                $columnas[$campo] = true;
            }
        }
        $columnas = \array_keys($columnas);

        $fp = @\fopen($destino, 'wb');
        if (!$fp) {
            throw new Exception("Export: could not write to {$destino}.");
        }
        try {
            \fputcsv($fp, $columnas, $separador, '"', '\\');
            foreach ($documentos as $doc) {
                $fila = [];
                foreach ($columnas as $campo) {
                    $fila[] = self::toCell($doc[$campo] ?? null);
                }
                \fputcsv($fp, $fila, $separador, '"', '\\');
            }
        } finally {
            \fclose($fp);
        }
        return \count($documentos);
    }

    /**
     * De CSV a documentos. Todo llega como texto salvo lo que se reconoce sin
     * ambiguedad: numeros, booleanos y vacios.
     *
     * Una celda vacia se convierte en null y no en cadena vacia, porque en una
     * hoja de calculo una celda que nadie ha rellenado significa "no hay dato".
     *
     * @return list<array>
     */
    public static function desdeCsv(string $origen, string $separador = ','): array
    {
        $fp = @\fopen($origen, 'rb');
        if (!$fp) {
            throw new Exception("Import: could not read {$origen}.");
        }
        try {
            $columnas = \fgetcsv($fp, 0, $separador, '"', '\\');
            if (!\is_array($columnas)) {
                throw new Exception("Import: {$origen} is empty or is not a CSV.");
            }
            $documentos = [];
            while (($fila = \fgetcsv($fp, 0, $separador, '"', '\\')) !== false) {
                if ($fila === [null] || $fila === []) {
                    continue;                       // linea en blanco al final
                }
                $doc = [];
                foreach ($columnas as $i => $campo) {
                    $doc[(string) $campo] = self::fromCell($fila[$i] ?? null);
                }
                $documentos[] = $doc;
            }
            return $documentos;
        } finally {
            \fclose($fp);
        }
    }

    /** Una lista o un mapa no caben en una celda: van como JSON dentro. */
    private static function toCell(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if (\is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }
        if (\is_array($valor)) {
            return (string) \json_encode($valor, JSON_UNESCAPED_UNICODE);
        }
        return (string) $valor;
    }

    private static function fromCell(?string $celda): mixed
    {
        if ($celda === null || $celda === '') {
            return null;
        }
        if ($celda === 'true' || $celda === 'false') {
            return $celda === 'true';
        }
        if (\str_starts_with($celda, '[') || \str_starts_with($celda, '{')) {
            $json = \json_decode($celda, true);
            if (\is_array($json)) {
                return $json;
            }
        }
        // is_numeric acepta '0x1A' y ' 12 ' segun la version; el patron no.
        if (\preg_match('/^-?\d+$/', $celda) === 1) {
            return (int) $celda;
        }
        if (\preg_match('/^-?\d*\.\d+$/', $celda) === 1) {
            return (float) $celda;
        }
        return $celda;
    }

    private static function persist(string $destino, string $contenido): void
    {
        if (@\file_put_contents($destino, $contenido) === false) {
            throw new Exception("Export: could not write to {$destino}.");
        }
    }

    private static function readAt(string $origen): string
    {
        $bytes = @\file_get_contents($origen);
        if ($bytes === false) {
            throw new Exception("Import: could not read {$origen}.");
        }
        return $bytes;
    }
}
