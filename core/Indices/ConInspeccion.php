<?php
/**
 * AxiDB - Indices\ConInspeccion: mirar el indice tal y como esta en el disco.
 *
 * Lo que hay guardado, sin contrastarlo con nada. Existe para que el verificador
 * pueda detectar entradas que sobran: las que se recorren desde los documentos
 * solo encuentran lo que deberia estar, nunca lo que esta de mas.
 *
 * El caso que hay que poder ver es el de un valor reservado por un campo unico
 * cuyo documento nunca llego a escribirse. Ese valor no aparece en ningun
 * documento, asi que partiendo de los documentos es invisible: hay que leer el
 * directorio.
 */

declare(strict_types=1);

namespace Axi\Core\Indices;

trait ConInspeccion
{
    /**
     * El nombre real del campo, guardado dentro de su propio directorio.
     *
     * Hace falta porque el nombre del DIRECTORIO no es el del campo: para que
     * la carpeta sea portable entre sistemas de archivos que no distinguen
     * mayusculas, `createdAt` se guarda como `createdat~4f2a1c9b`. Y eso no se
     * puede deshacer.
     */
    private const NOMBRE_CAMPO = '_campo.json';

    /**
     * Campos con indice en una coleccion, con su nombre de verdad.
     *
     * Aqui estaba uno de los peores fallos del motor. Esto devolvia el nombre
     * del directorio, y `Db::put()` lo usa para mantener el indice al dia:
     * buscaba `$documento['createdat~4f2a1c9b']`, que no existe en ningun
     * documento, asi que **el indice de cualquier campo con una mayuscula
     * dejaba de actualizarse en cuanto se construia**. Los documentos nuevos no
     * entraban y `by()` no los encontraba. Sin ningun error: invisibles.
     *
     * Un directorio marcado del que no se puede recuperar el nombre —creado
     * antes de que se anotara— no se devuelve: se lista aparte, porque
     * mantenerlo es imposible y callarlo seria repetir el fallo.
     *
     * @return list<string>
     */
    public function fields(string $collection): array
    {
        return $this->recorrerCampos($collection)['legibles'];
    }

    /**
     * Directorios de indice cuyo campo original no se puede saber. Hay que
     * borrarlos y volver a crear el indice: no se pueden reparar solos.
     *
     * @return list<string>
     */
    public function camposIlegibles(string $collection): array
    {
        return $this->recorrerCampos($collection)['ilegibles'];
    }

    /** @return array{legibles: list<string>, ilegibles: list<string>} */
    private function recorrerCampos(string $collection): array
    {
        $root = $this->storage->dir($collection) . '/_idx';
        if (!\is_dir($root)) {
            return ['legibles' => [], 'ilegibles' => []];
        }
        $legibles = $ilegibles = [];

        foreach (\scandir($root) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..' || !\is_dir($root . '/' . $entrada)) {
                continue;
            }
            $nombre = self::leerNombre($root . '/' . $entrada);
            if ($nombre !== null) {
                $legibles[] = $nombre;
            } elseif (\str_contains($entrada, '~')) {
                $ilegibles[] = $entrada;        // el nombre real se perdio
            } else {
                $legibles[] = $entrada;         // sin marca, el directorio ES el campo
            }
        }
        return ['legibles' => $legibles, 'ilegibles' => $ilegibles];
    }

    private static function leerNombre(string $dir): ?string
    {
        $path = $dir . '/' . self::NOMBRE_CAMPO;
        if (!\is_file($path)) {
            return null;
        }
        $json = \json_decode((string) @\file_get_contents($path), true);
        $campo = \is_array($json) ? ($json['campo'] ?? null) : null;
        return \is_string($campo) && $campo !== '' ? $campo : null;
    }

    /** Deja escrito el nombre real del campo al construir su indice. */
    private function anotarCampo(string $dir, string $field): void
    {
        @\file_put_contents(
            $dir . '/' . self::NOMBRE_CAMPO,
            \json_encode(['campo' => $field], JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    /**
     * Los ids guardados en cada archivo de valor del indice.
     *
     * La clave es el nombre del archivo, no el valor: el nombre puede ser un
     * hash y de ahi no se vuelve al original. Para comparar, quien llame usa
     * `nombreDeBucket()` sobre el valor que espera.
     *
     * @return array<string, list<string>>
     */
    public function buckets(string $collection, string $field): array
    {
        $dir = $this->fieldDir($collection, $field);
        if (!\is_dir($dir)) {
            return [];
        }
        $fuera = [];
        foreach (\glob($dir . '/*.json') ?: [] as $archivo) {
            $nombre = \basename($archivo, '.json');
            if ($nombre === \basename(self::NOMBRE_CAMPO, '.json')) {
                continue;                       // la anotacion del campo no es un valor
            }
            $ids = \json_decode((string) @\file_get_contents($archivo), true);
            $fuera[$nombre] = \is_array($ids) ? \array_map('strval', $ids) : [];
        }
        return $fuera;
    }

    /** El nombre de archivo que le corresponde a un valor en esta coleccion. */
    public function nombreDeBucket(string $collection, string $field, string $value): string
    {
        return \basename($this->path($collection, $field, $value), '.json');
    }
}
