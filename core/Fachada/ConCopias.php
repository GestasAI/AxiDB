<?php
/**
 * AxiDB - Fachada\ConCopias: respaldar y restaurar desde Db.
 *
 *   $db->copiar('./copias');                    // completa
 *   $db->copiar('./copias', incremental: true); // solo lo que cambio
 *   $db->copias('./copias');                    // que hay guardado
 *   $db->restaurar('./copias/20260809-...axicopia');
 */

declare(strict_types=1);

namespace Axi\Core\Fachada;

use Axi\Core\Copias\Catalogo;
use Axi\Core\Copias\Copia;
use Axi\Core\Copias\Intercambio;
use Axi\Core\Copias\Restauracion;
use Axi\Core\Exception;

trait ConCopias
{
    /**
     * Guarda una copia en la carpeta indicada y devuelve como fue.
     *
     * Antes de copiar se recuperan las transacciones a medias. Copiar un diario
     * sin resolver guardaria una decision pendiente que se reaplicaria al
     * restaurar, en un momento que ya no tiene nada que ver.
     *
     * La incremental cuelga de la ultima copia que haya en la carpeta. Si no hay
     * ninguna, sale completa: es lo unico que puede salir, y avisar de ello con
     * un error solo serviria para obligar a escribir un `if` en el que llama.
     *
     * @return array{id:string, tipo:string, archivos:int, guardados:int, bytes:int, archivo:string}
     */
    public function copiar(string $carpeta, bool $incremental = false): array
    {
        $this->recuperar();
        $this->storage->cerrar();           // suelta los descriptores del formato empaquetado

        $anterior = $incremental ? Catalogo::ultima($carpeta) : null;

        return Copia::hacer(
            $this->storage->basePath(),
            $carpeta,
            $anterior === null ? null : $anterior['archivo']
        );
    }

    /**
     * Las copias que hay en una carpeta, de la mas reciente a la mas antigua.
     *
     * @return list<array{archivo:string, id:string, momento:string, tipo:string, base:?string, archivos:int}>
     */
    public function copias(string $carpeta): array
    {
        return Catalogo::de($carpeta);
    }

    /**
     * Devuelve los datos al estado de esa copia.
     *
     * Restaurar SUSTITUYE: lo que la copia no tiene se borra. No es una mezcla
     * de dos momentos, es volver a uno. Un documento creado despues de la copia
     * desaparece, que es lo que significa restaurar.
     *
     * La copia se lee y se comprueba entera —todos los sha1— antes de tocar un
     * solo archivo de los datos vivos. Una copia dañada se descubre sin haber
     * hecho daño.
     *
     * @return array{id:string, archivos:int, borrados:int, cadena:list<string>}
     */
    public function restaurar(string $copia): array
    {
        if (!\is_file($copia)) {
            throw new Exception("Copia: no existe el archivo {$copia}.");
        }
        $this->storage->cerrar();

        $hecho = Restauracion::hacer($copia, $this->storage->basePath());

        // Lo que hay en memoria ya no vale: los ajustes, los indices y los
        // descriptores abiertos son de los datos de antes de restaurar.
        $this->storage->olvidar();
        return $hecho;
    }

    /* ─────────────────────────────── Llevarse los datos ─────────────────────── */

    /**
     * Saca los documentos de una coleccion a un archivo.
     *
     * Esto NO es una copia de seguridad: no guarda indices, ni ajustes, ni
     * vectores. Es para llevarse los datos a otro sitio —una hoja de calculo,
     * otra base— y por eso solo salen los documentos.
     *
     * El formato se deduce de la extension, o se dice a mano.
     *
     * @return int documentos exportados
     */
    public function exportar(string $collection, string $destino, ?string $formato = null): int
    {
        $documentos = $this->all($collection);

        return match (self::formatoDe($destino, $formato)) {
            'csv'   => Intercambio::aCsv($documentos, $destino),
            default => Intercambio::aJson($documentos, $destino),
        };
    }

    /**
     * Mete documentos de un archivo en una coleccion.
     *
     * Se escriben con `put`, asi que pasan por todo lo de siempre: esquema,
     * unicidad, indices y vectores. Importar no es una puerta trasera; si un
     * documento no cumple las reglas de la coleccion, se rechaza igual que si
     * se hubiera escrito a mano.
     *
     * Un documento con `id` respeta su id; sin el, se le genera uno.
     *
     * @return int documentos importados
     */
    public function importar(string $collection, string $origen, ?string $formato = null): int
    {
        $documentos = match (self::formatoDe($origen, $formato)) {
            'csv'   => Intercambio::desdeCsv($origen),
            default => Intercambio::desdeJson($origen),
        };

        $n = 0;
        foreach ($documentos as $doc) {
            $id = isset($doc['id']) && $doc['id'] !== '' ? (string) $doc['id'] : null;
            unset($doc['id'], $doc['_version'], $doc['_createdAt'], $doc['_updatedAt']);

            $this->insert($collection, $doc, $id);
            $n++;
        }
        return $n;
    }

    private static function formatoDe(string $archivo, ?string $formato): string
    {
        $elegido = \strtolower($formato ?? \pathinfo($archivo, PATHINFO_EXTENSION));
        if (!\in_array($elegido, ['json', 'csv'], true)) {
            throw new Exception(
                "Intercambio: no se reconoce el formato '{$elegido}'. Hay json y csv; "
                . 'ponlo en la extension del archivo o pasalo como tercer argumento.'
            );
        }
        return $elegido;
    }
}
