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
    public static function pistaPermisos(string $dir): string
    {
        if (!\function_exists('posix_geteuid') || !\function_exists('posix_getpwuid')) {
            return 'Revisa los permisos del directorio y reconstruye con reindex().';
        }
        $dueno = \posix_getpwuid((int) @\fileowner($dir))['name'] ?? '?';
        $yo    = \posix_getpwuid(\posix_geteuid())['name'] ?? '?';
        return "El directorio pertenece a '{$dueno}' y el proceso corre como '{$yo}'. "
             . 'Corrige el dueño y reconstruye con reindex().';
    }

    /**
     * Recorre los documentos y comprueba, uno a uno, si estan en el indice.
     * O(coleccion): es una tarea de mantenimiento, no de ruta caliente.
     *
     * @return array{documentos:int, indexados:int, faltan:int}
     */
    public static function check(Storage $storage, Index $index, string $collection, string $field): array
    {
        $documentos = 0;
        $indexados  = 0;
        $cache      = [];

        foreach ($storage->all($collection) as $doc) {
            $value = $doc[$field] ?? null;
            if ($value === null || $value === '' || \is_array($value)) {
                continue;
            }
            $documentos++;
            $value = (string) $value;

            // Un solo bucket se consulta una vez aunque lo compartan mil documentos.
            $cache[$value] ??= $index->ids($collection, $field, $value) ?? [];

            if (\in_array((string) ($doc['id'] ?? ''), $cache[$value], true)) {
                $indexados++;
            }
        }

        return [
            'documentos' => $documentos,
            'indexados'  => $indexados,
            'faltan'     => $documentos - $indexados,
        ];
    }
}
