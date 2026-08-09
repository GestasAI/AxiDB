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
    public function vectores(string $collection, array $opciones = []): array
    {
        return $this->vectores->activar($collection, $opciones)->aArray();
    }

    /**
     * Los k documentos mas parecidos a un texto o a un vector. Con $donde se
     * filtra antes de buscar, aprovechando los indices de siempre.
     *
     * @param string|list<float> $consulta
     * @return list<array{id: string, score: float, doc: array}>
     */
    public function similar(
        string $collection,
        string|array $consulta,
        int $k = 10,
        ?Query $donde = null,
        ?string $precision = null
    ): array {
        return $this->vectores->similar($collection, $consulta, $k, $donde, $precision);
    }

    /** Acceso al indice vectorial de una coleccion, para lo que no cubre la fachada. */
    public function vectorial(string $collection): Vector\Indice
    {
        return $this->vectores->indice($collection);
    }
}
