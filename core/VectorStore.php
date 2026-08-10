<?php
/**
 * AxiDB - Core\VectorStore: los indices vectoriales de todas las colecciones.
 *
 * Es la pieza que hace que activar vectores no cambie como se usa AxiDB. Db la
 * avisa en cada alta y en cada baja, y ella decide si hay algo que indexar. Las
 * colecciones sin vectores no pagan nada: una comprobacion de si existe un
 * directorio, cacheada.
 *
 * Vive fuera de Db porque Db ya tiene bastante con ser la fachada, y porque asi
 * el motor vectorial entero se puede quitar de en medio sin tocar el CRUD.
 */

declare(strict_types=1);

namespace Axi\Core;

use Axi\Core\Vector\Store;
use Axi\Core\Vector\Embedder;
use Axi\Core\Vector\Embedders\Hash;
use Axi\Core\Vector\VectorIndex;

final class VectorStore
{
    /** @var array<string, VectorIndex|null> null significa "esta coleccion no tiene" */
    private array $indices = [];

    private Embedder $embedder;

    public function __construct(
        private Storage $storage,
        ?Embedder $embedder = null
    ) {
        /*
         * Por defecto, el generador que funciona sin red. No es el mejor —no
         * entiende de significado— pero es el unico que se puede poner de
         * defecto con honradez: cualquier otro exigiria una clave de API o un
         * servidor levantado, y AxiDB promete funcionar copiando una carpeta.
         */
        $this->embedder = $embedder ?? new Hash();
    }

    public function activar(string $coleccion, array $opciones = []): Vector\Manifest
    {
        $this->exigirSinCifrar($coleccion);
        $this->storage->ensureCollection($coleccion);
        $indice = new VectorIndex(new Store($this->dir($coleccion)), $this->embedder);
        $m      = $indice->activar($opciones);
        $this->indices[$coleccion] = $indice;

        /*
         * Los documentos que ya estaban se indexan ahora.
         *
         * Sin esto, activar los vectores sobre una coleccion con contenido
         * dejaba fuera todo lo anterior: `similar()` solo encontraba lo escrito
         * DESPUES, y no habia ningun error que lo dijera. El mismo fallo
         * silencioso que ya se corrigio en `cifrar()`, que si reescribe lo que
         * hay, y que `index()` nunca tuvo porque siempre recorre la coleccion.
         *
         * Es idempotente: volver a activar reindexa, que es tambien la forma de
         * reparar un indice vectorial incompleto.
         */
        foreach ($this->storage->all($coleccion) as $doc) {
            $indice->indexar((string) ($doc['id'] ?? ''), $doc);
        }
        return $m;
    }

    /**
     * Vectores y cifrado no conviven, y no es una limitacion tecnica: es que
     * juntarlos engaña.
     *
     * Un embedding no es un resumen inocente del texto. De un vector se puede
     * reconstruir aproximadamente lo que lo genero —hay ataques publicados que
     * recuperan buena parte de la frase original—, asi que guardar el documento
     * cerrado y su vector en claro, al lado, deja el secreto accesible por la
     * puerta de atras mientras la coleccion dice estar cifrada.
     *
     * Se rechaza en vez de avisar en la documentacion: una promesa de cifrado a
     * medias es peor que no cifrar, porque el que la usa cree estar protegido.
     */
    private function exigirSinCifrar(string $coleccion): void
    {
        if ($this->storage->isEncrypted($coleccion)) {
            throw new Exception(
                "Vectors cannot be enabled on '{$coleccion}': esta cifrada. "
                . 'The text that produced an embedding can be reconstructed from it, so '
                . 'el indice vectorial dejaria en claro justo lo que el cifrado protege.'
            );
        }
    }

    /** El indice de una coleccion. Lanza si no tiene vectores activados. */
    public function indice(string $coleccion): VectorIndex
    {
        $indice = $this->de($coleccion);
        if ($indice === null) {
            throw new Exception(
                "Vector: collection '{$coleccion}' no tiene vectores. "
                . "Enable them with \$db->enableVectors('{$coleccion}')."
            );
        }
        return $indice;
    }

    public function alGuardar(string $coleccion, string $id, array $documento): void
    {
        $this->de($coleccion)?->indexar($id, $documento);
    }

    public function alBorrar(string $coleccion, string $id): void
    {
        $this->de($coleccion)?->quitar($id);
    }

    /**
     * Busqueda por significado, con el documento entero de vuelta.
     *
     * Si se pasa una consulta normal, primero se filtra con ella —usando los
     * indices de siempre— y la busqueda vectorial solo mira entre los que
     * pasaron el filtro. El orden importa: al reves habria que traer miles de
     * documentos para descartarlos despues.
     *
     * @param string|list<float> $consulta
     * @return list<array{id: string, score: float, doc: array}>
     */
    public function similar(
        string $coleccion,
        string|array $consulta,
        int $k,
        ?Query $donde,
        ?string $precision = null
    ): array {
        $indice = $this->indice($coleccion);

        $soloEstos = [];
        if ($donde !== null) {
            foreach ($donde->get() as $doc) {
                if (isset($doc['id'])) {
                    $soloEstos[(string) $doc['id']] = true;
                }
            }
            if ($soloEstos === []) {
                return [];
            }
        }

        $salida = [];
        foreach ($indice->buscar($consulta, $k, $soloEstos, $precision) as $fila) {
            $doc = $this->storage->get($coleccion, $fila['id']);
            if ($doc !== null) {
                $salida[] = ['id' => $fila['id'], 'score' => $fila['score'], 'doc' => $doc];
            }
        }
        return $salida;
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    private function de(string $coleccion): ?VectorIndex
    {
        if (\array_key_exists($coleccion, $this->indices)) {
            return $this->indices[$coleccion];
        }
        $almacen = new Store($this->dir($coleccion));
        return $this->indices[$coleccion] = $almacen->existe()
            ? new VectorIndex($almacen, $this->embedder)
            : null;
    }

    private function dir(string $coleccion): string
    {
        return $this->storage->dir($coleccion) . '/_vec';
    }
}
