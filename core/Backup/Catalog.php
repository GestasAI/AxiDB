<?php
/**
 * AxiDB - Copias\Catalog: que copias hay en una carpeta.
 *
 * Lee solo la cabecera de cada archivo, no el contenido: listar veinte copias de
 * un gigabyte tiene que costar lo mismo que listar veinte archivos vacios.
 */

declare(strict_types=1);

namespace Axi\Core\Backup;

use Axi\Core\Exception;

final class Catalog
{
    public const EXTENSION = '.axicopia';

    /**
     * Las copias de la carpeta, de la mas reciente a la mas antigua.
     *
     * Un archivo que no se puede leer no rompe el listado: sale marcado. Una
     * copia corrupta entre veinte buenas no debe impedir ver las otras
     * diecinueve, que es justo cuando mas falta hacen.
     *
     * @return list<array{archivo:string, id:string, momento:string, tipo:string, base:?string, archivos:int}>
     */
    public static function de(string $carpeta): array
    {
        $copias = [];
        foreach (\glob(\rtrim($carpeta, '/\\') . '/*' . self::EXTENSION) ?: [] as $archivo) {
            try {
                $c = Container::cabecera($archivo);
                $copias[] = [
                    'archivo'  => $archivo,
                    'id'       => (string) ($c['id'] ?? ''),
                    'momento'  => (string) ($c['momento'] ?? ''),
                    'tipo'     => (string) ($c['tipo'] ?? ''),
                    'base'     => isset($c['base']) ? (string) $c['base'] : null,
                    'archivos' => \count((array) ($c['huellas'] ?? [])),
                ];
            } catch (Exception $e) {
                $copias[] = [
                    'archivo' => $archivo, 'id' => '', 'momento' => '',
                    'tipo' => 'ilegible', 'base' => null, 'archivos' => 0,
                    'error' => $e->getMessage(),
                ];
            }
        }
        /*
         * Se ordena por id y no por `momento`, y esto lo enseño un test que
         * fallaba: `momento` tiene precision de segundo, asi que dos copias
         * seguidas empatan y el orden lo decidia lo que devolviera glob(). El
         * id lleva microsegundos y es unico, asi que ordenarlo como cadena da
         * el orden cronologico exacto.
         *
         * Importa mas de lo que parece: de "cual es la ultima" depende de que
         * copia cuelga la siguiente incremental.
         */
        \usort($copias, static fn($a, $b) => \strcmp((string) $b['id'], (string) $a['id']));
        return $copias;
    }

    /** La ruta del archivo que tiene ese id. Lanza si no aparece. */
    public static function buscar(string $carpeta, string $id): string
    {
        foreach (self::de($carpeta) as $copia) {
            if ($copia['id'] === $id) {
                return $copia['archivo'];
            }
        }
        throw new Exception(
            "Backup: cannot find the backup '{$id}' in {$carpeta}. An incremental backup "
            . 'necesita las anteriores de las que cuelga.'
        );
    }

    /** La mas reciente, o null si no hay ninguna legible. */
    public static function ultima(string $carpeta): ?array
    {
        foreach (self::de($carpeta) as $copia) {
            if ($copia['tipo'] !== 'ilegible') {
                return $copia;
            }
        }
        return null;
    }
}
