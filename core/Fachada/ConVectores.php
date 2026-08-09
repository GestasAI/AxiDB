<?php
/**
 * AxiDB - Fachada\ConVectores: la busqueda por significado de Db.
 *
 * Db es la puerta de entrada a cuatro subsistemas -documentos, indices,
 * vectores y agentes- y con los cuatro dentro pasaba de 300 lineas. Cada uno
 * vive ahora en su propio archivo, y Db los junta.
 *
 * No cambia nada de puertas afuera: se sigue escribiendo `$db->similar(...)`.
 */

declare(strict_types=1);

namespace Axi\Core\Fachada;

use Axi\Core\Query;
use Axi\Core\Vector;

trait ConVectores
{
    /* ─────────────────────────────── Vectores ─────────────────────────────── */

    /**
     * Activa la busqueda por significado. Con `auto` cada insert genera su
     * vector solo; sin el, se pasa a mano en el campo declarado.
     */
    public function enableVectors(string $collection, array $opciones = []): array
    {
        $this->profile()->exigir('vectors', 'la busqueda por significado');
        return $this->vectores->activar($collection, $opciones)->aArray();
    }

    /**
     * Los k documentos mas parecidos a un texto o a un vector. Con $donde se
     * filtra antes de buscar, aprovechando los indices de siempre.
     *
     * @param string|list<float> $consulta
     * @return list<array{id: string, score: float, doc: array}>
     */
    /**
     * Busca por significado y por palabra a la vez, y funde los dos resultados.
     *
     *   $db->hybrid('articulos', 'levadura casera', 10);
     *
     * Las dos busquedas fallan de maneras distintas —una entiende sinonimos y
     * la otra encuentra un codigo de referencia clavado— asi que juntas
     * encuentran mas que cualquiera por separado.
     *
     * Se combinan por posicion en cada lista, no sumando puntuaciones: el
     * parecido de un coseno y "contiene la palabra" no son comparables, y
     * sumarlos obligaria a inventar un factor de conversion que decidiria el
     * resultado sin que nadie pueda justificarlo. Ver Vector\Hibrida.
     *
     * Busca por palabra en los campos que la coleccion declaro como `auto`, que
     * son los mismos de los que salen los vectores.
     *
     * @return list<array{id:string, doc:array, puntos:float, en:list<string>}>
     */
    public function hybrid(string $collection, string $texto, int $k = 10): array
    {
        $this->profile()->exigir('vectors', 'la busqueda hibrida');

        $porSignificado = $this->similar($collection, $texto, $k * 2);

        $campos = $this->vectorIndex($collection)->manifiesto()->auto;
        $porPalabra = [];
        foreach ($this->all($collection) as $doc) {
            foreach ($campos as $campo) {
                if (\is_string($doc[$campo] ?? null) && \stripos($doc[$campo], $texto) !== false) {
                    $porPalabra[] = ['id' => (string) $doc['id']];
                    break;
                }
            }
        }

        $fuera = [];
        foreach (Vector\Hibrida::fundir($porSignificado, $porPalabra, $k) as $fila) {
            $doc = $this->get($collection, $fila['id']);
            if ($doc !== null) {
                $fuera[] = $fila + ['doc' => $doc];
            }
        }
        return $fuera;
    }

    public function similar(
        string $collection,
        string|array $consulta,
        int $k = 10,
        ?Query $donde = null,
        ?string $precision = null
    ): array {
        $this->profile()->exigir('vectors', 'la busqueda por significado');

        return $this->vectores->similar($collection, $consulta, $k, $donde, $precision);
    }

    /** Acceso al indice vectorial de una coleccion, para lo que no cubre la fachada. */
    public function vectorIndex(string $collection): Vector\Indice
    {
        $this->profile()->exigir('vectors', 'el acceso al indice vectorial');
        return $this->vectores->indice($collection);
    }
}
