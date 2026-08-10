<?php
/**
 * AxiDB - Core\IndexVerifier: diagnostico de indices.
 *
 * Responde a dos preguntas que Index no debe responder porque no son su trabajo:
 * si un indice refleja la realidad de los documentos, y por que no se ha podido
 * escribir cuando falla.
 *
 * Los documentos se sincronizan a disco con fsync; los indices no, porque son
 * estado derivado y se reconstruyen (ver la cabecera de Index). El precio de esa
 * decision es que un corte de corriente puede dejarlos cortos, y entonces hay
 * documentos en disco que las consultas no ven. Esta clase es como se detecta;
 * Index::build() es como se repara.
 */

declare(strict_types=1);

namespace Axi\Core;

final class IndexVerifier
{
    /**
     * Por que no se pudo escribir un indice. La causa mas comun con diferencia
     * es que el dueño del directorio no sea el usuario del proceso: un script de
     * mantenimiento lanzado con otra cuenta deja los indices inservibles para el
     * servidor web.
     */
    public static function permissionHint(string $dir): string
    {
        if (!\function_exists('posix_geteuid') || !\function_exists('posix_getpwuid')) {
            return 'Check the directory permissions and rebuild with reindex().';
        }
        $dueno = \posix_getpwuid((int) @\fileowner($dir))['name'] ?? '?';
        $yo    = \posix_getpwuid(\posix_geteuid())['name'] ?? '?';
        return "The directory is owned by '{$dueno}' and the process runs as '{$yo}'. "
             . 'Fix the owner and rebuild with reindex().';
    }

    /**
     * Recorre los documentos y comprueba, uno a uno, si estan en el indice.
     * O(coleccion): es una tarea de mantenimiento, no de ruta caliente.
     *
     * @return array{documentos:int, indexados:int, faltan:int, sobran:int}
     */
    public static function check(Storage $storage, Index $index, string $collection, string $field): array
    {
        $documentos = 0;
        $indexados  = 0;
        $cache      = [];
        $esperados  = [];

        foreach ($storage->all($collection) as $doc) {
            $value = $doc[$field] ?? null;
            if ($value === null || $value === '' || \is_array($value)) {
                continue;
            }
            $documentos++;
            $value = (string) $value;
            $id    = (string) ($doc['id'] ?? '');

            // Un solo bucket se consulta una vez aunque lo compartan mil documentos.
            $cache[$value] ??= $index->ids($collection, $field, $value) ?? [];
            $esperados[$index->bucketNameOf($collection, $field, $value)][$id] = true;

            if (\in_array($id, $cache[$value], true)) {
                $indexados++;
            }
        }

        return [
            'documentos' => $documentos,
            'indexados'  => $indexados,
            'faltan'     => $documentos - $indexados,
            'sobran'     => self::leftovers($index, $collection, $field, $esperados),
        ];
    }

    /**
     * Entradas del indice que no corresponden a ningun documento.
     *
     * Se leen los archivos del indice, no los documentos, y ese es el unico modo
     * de verlas: partiendo de los documentos solo se encuentra lo que deberia
     * estar, nunca lo que esta de mas.
     *
     * Antes esto solo podia pasar tocando los archivos a mano. Ahora hay un
     * camino propio del motor: un campo unico reserva el valor en el indice
     * ANTES de escribir el documento, asi que un proceso que muera justo en
     * medio deja el valor cogido y sin dueño. No corrompe nada y no se pierde
     * ningun dato, pero ese valor queda bloqueado hasta reconstruir el indice.
     * Se cuenta para que se pueda ver, en vez de esperar a que alguien se queje
     * de que "ese correo dice que ya existe y no existe".
     *
     * @param array<string, array<string,true>> $esperados ids validos por bucket
     */
    private static function leftovers(
        Index $index,
        string $collection,
        string $field,
        array $esperados
    ): int {
        $sobran = 0;
        foreach ($index->buckets($collection, $field) as $bucket => $ids) {
            foreach ($ids as $id) {
                if (!isset($esperados[$bucket][$id])) {
                    $sobran++;
                }
            }
        }
        return $sobran;
    }
}
