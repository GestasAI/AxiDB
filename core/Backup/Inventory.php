<?php
/**
 * AxiDB - Copias\Inventory: que archivos entran en una copia, y con que huella.
 *
 * Se copia el directorio de datos entero: documentos, ajustes, indices y
 * vectores. Todo, porque el criterio de "esto se puede reconstruir" es
 * justamente el que falla cuando hace falta la copia.
 *
 * Lo que NO entra, y por que:
 *
 *   _tx/, _tx.lock    transacciones a medias. Se recuperan ANTES de copiar, asi
 *                     que a estas alturas no queda ninguna; guardar un diario
 *                     suelto solo serviria para reaplicarlo al restaurar.
 *   *.lock            cerrojos. Son estado del proceso vivo, no datos.
 *   *.tmp.*           temporales de una escritura atomica en curso.
 *
 * El sha1 de cada archivo es lo que permite que las copias incrementales sepan
 * que ha cambiado, y que la restauracion pueda comprobar que lo que llega es lo
 * que salio.
 */

declare(strict_types=1);

namespace Axi\Core\Backup;

final class Inventory
{
    /** Nombres exactos que nunca se copian. */
    private const FUERA = ['_tx.lock', '_vec.lock', '_write.lock'];

    /**
     * Todos los archivos copiables, con su ruta relativa y su huella.
     *
     * @return array<string, array{absoluta:string, sha1:string}> clave: ruta relativa
     */
    public static function de(string $base): array
    {
        $fuera = [];
        self::recorrer($base, '', $fuera);
        \ksort($fuera, SORT_STRING);
        return $fuera;
    }

    /** @param array<string, array{absoluta:string, sha1:string}> $acumulado */
    private static function recorrer(string $dir, string $prefijo, array &$acumulado): void
    {
        foreach (\scandir($dir) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..' || $entrada === '_tx') {
                continue;
            }
            $absoluta = $dir . '/' . $entrada;
            $relativa = $prefijo === '' ? $entrada : $prefijo . '/' . $entrada;

            if (\is_dir($absoluta)) {
                self::recorrer($absoluta, $relativa, $acumulado);
                continue;
            }
            if (!self::copiable($entrada)) {
                continue;
            }
            $bytes = @\file_get_contents($absoluta);
            if ($bytes === false) {
                continue;                   // ilegible ahora mismo: no se inventa
            }
            $acumulado[$relativa] = ['absoluta' => $absoluta, 'sha1' => \sha1($bytes)];
        }
    }

    private static function copiable(string $nombre): bool
    {
        if (\in_array($nombre, self::FUERA, true)) {
            return false;
        }
        return !\str_ends_with($nombre, '.lock') && !\str_contains($nombre, '.tmp.');
    }

    /**
     * Solo las huellas, sin las rutas absolutas. Es lo que se guarda en la
     * cabecera de la copia para saber, mas tarde, que ha cambiado.
     *
     * @param array<string, array{absoluta:string, sha1:string}> $inventario
     * @return array<string, string>
     */
    public static function huellas(array $inventario): array
    {
        return \array_map(static fn(array $e): string => $e['sha1'], $inventario);
    }
}
