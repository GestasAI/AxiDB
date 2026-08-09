<?php
/**
 * AxiDB - Sql\Vectorial: ejecuta `ORDER BY EMBEDDING <-> 'texto'`.
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
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Db;

final class Vectorial
{
    public function __construct(private Db $db)
    {
    }

    /** @return list<array>|array el resultado, o el plan si se pidio EXPLAIN */
    public function ejecutar(array $ast, bool $explicar): array
    {
        $k      = (int) ($ast['limit'] ?? 10);
        $filtro = $ast['where_expr'] !== null
            ? $this->db->find($ast['collection'])->whereExpr($ast['where_expr'])
            : null;

        if ($explicar) {
            return [
                'operacion' => 'busqueda vectorial',
                'coleccion' => $ast['collection'],
                'consulta'  => $ast['vector'],
                'k'         => $k,
                'prefiltro' => $filtro !== null ? $filtro->plan() : 'ninguno',
                'como'      => 'criba binaria sobre todos, coseno exacto sobre los candidatos',
            ];
        }

        $campos = $ast['fields'] ?? ['*'];
        $salida = [];
        foreach ($this->db->similar($ast['collection'], (string) $ast['vector'], $k, $filtro) as $fila) {
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
     * vectorial admite lo mismo que un SELECT normal: `SELECT MAYUS(titulo)
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
        $fila = Resultado::construir([$doc], ['fields' => $campos])[0] ?? [];
        return ['_score' => $score] + $fila;
    }
}
