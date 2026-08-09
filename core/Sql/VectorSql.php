<?php
/**
 * AxiDB - Sql\VectorSql: ejecuta `ORDER BY EMBEDDING <-> 'texto'`.
 *
 * Esa clausula no ordena una consulta: la cambia por otra cosa. En vez de leer
 * documentos y ordenarlos, se busca en el indice vectorial y se traen los que
 * salgan. Por eso vive aparte del Executor normal en lugar de ser un `if` mas
 * dentro de select().
 *
 * El WHERE se aplica ANTES de buscar. Reduce el conjunto con los indices de
 * siempre y la busqueda por significado solo mira entre los que quedan. Al reves
 * habria que traer los mas parecidos de toda la coleccion y tirar la mayoria,
 * que ademas devolveria menos de los pedidos.
 *
 * Con una excepcion: una condicion sobre `parecido` no se puede aplicar antes,
 * porque el parecido no existe hasta haber buscado. Esa se separa del resto y se
 * usa como umbral despues:
 *
 *   SELECT titulo FROM articulos WHERE parecido > 0.8
 *   ORDER BY EMBEDDING <-> 'pan de masa madre' LIMIT 20
 *
 * Sirve para lo que uno espera de una busqueda por significado: "damelos si se
 * parecen de verdad, y si no, ninguno". Sin umbral, pedir 20 devuelve 20 aunque
 * el ultimo no tenga nada que ver.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Db;

final class VectorSql
{
    public function __construct(private Db $db)
    {
    }

    /** @return list<array>|array el resultado, o el plan si se pidio EXPLAIN */
    public function ejecutar(array $ast, bool $explicar): array
    {
        $k = (int) ($ast['limit'] ?? 10);

        [$antes, $umbral] = Threshold::separar($ast['where_expr']);

        $filtro = $antes !== null
            ? $this->db->find($ast['collection'])->whereExpr($antes)
            : null;

        if ($explicar) {
            return [
                'operacion' => 'busqueda vectorial',
                'coleccion' => $ast['collection'],
                'consulta'  => $ast['vector'],
                'k'         => $k,
                'prefiltro' => $filtro !== null ? $filtro->plan() : 'ninguno',
                'umbral'    => $umbral === null ? 'ninguno' : Threshold::describe($umbral),
                'como'      => 'criba binaria sobre todos, coseno exacto sobre los candidatos',
            ];
        }

        $campos = $ast['fields'] ?? ['*'];
        $salida = [];
        foreach ($this->db->similar($ast['collection'], (string) $ast['vector'], $k, $filtro) as $fila) {
            if ($umbral !== null && !Threshold::pasa($fila['score'], $umbral)) {
                continue;                       // los resultados vienen ordenados
            }
            $salida[] = $this->proyectar($fila['doc'], $fila['score'], $campos);
        }
        return $salida;
    }

    /**
     * Deja los campos pedidos y añade `_score`, que es lo unico que aporta esta
     * consulta y no estaba en el documento.
     *
     * La proyeccion llega en la forma nueva —expresiones con alias— asi que se
     * reutiliza Resultado, que es quien sabe resolverla. Asi una busqueda
     * vectorial admite lo mismo que un SELECT normal: `SELECT UPPER(titulo)
     * FROM articulos ORDER BY EMBEDDING <-> 'pan'` funciona igual que sin
     * vectores, en vez de tener su propia media proyeccion.
     *
     * El orden lo pone la distancia, asi que aqui no se ordena ni se corta: eso
     * ya viene decidido por el buscador.
     */
    private function proyectar(array $doc, float $score, array $campos): array
    {
        if ($campos === ['*']) {
            $doc['_score'] = $score;
            return $doc;
        }
        $fila = ResultSet::construir([$doc], ['fields' => $campos])[0] ?? [];
        return ['_score' => $score] + $fila;
    }
}
