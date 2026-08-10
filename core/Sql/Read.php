<?php
/**
 * AxiDB - Sql\Read: ejecuta SELECT y COUNT.
 *
 * Tres caminos, y se eligen en este orden porque cada uno excluye al siguiente:
 *
 *   vector   ORDER BY EMBEDDING: el orden lo pone la distancia
 *   vista    el FROM apunta a una consulta guardada
 *   cruce    hay JOIN, asi que el filtro va despues de unir
 *   normal   Query resuelve el WHERE, y Resultado hace el resto
 *
 * Aparte del ejecutor porque leer tiene sus propias reglas y porque juntos
 * pasaban del limite de tamano del proyecto.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Db;
use Axi\Core\Evaluator;
use Axi\Core\Exception;
use Axi\Core\Query;

final class Read
{
    /** Profundidad de vistas anidadas en curso, para cortar ciclos. */
    private static int $profundidadVista = 0;

    private const MAX_VISTAS = 8;

    public function __construct(private Db $db)
    {
    }

    public function execute(array $ast, bool $explicar): mixed
    {
        return $ast['type'] === 'count'
            ? $this->count($ast, $explicar)
            : $this->select($ast, $explicar);
    }

    /** El plan de un EXPLAIN, con la misma forma que en el resto del motor. */
    private function plan(string $operacion, string $coleccion, array $extra): array
    {
        return \array_merge([
            'explain'   => true,
            'operacion' => $operacion,
            'coleccion' => $coleccion,
        ], $extra);
    }

    /* ─────────────────────────────── Lectura ─────────────────────────────── */

    private function select(array $ast, bool $explicar): mixed
    {
        if (isset($ast['vector'])) {
            return (new VectorSql($this->db))->execute($ast, $explicar);
        }
        // La vista solo entra si el FROM no es ya una coleccion de verdad. Antes
        // se miraba la vista primero, asi que una vista con el nombre de una
        // coleccion existente la tapaba —y ademas SELECT y COUNT discrepaban,
        // porque count no pasaba por aqui. Una coleccion real siempre gana.
        if (!\in_array($ast['collection'], $this->db->collections(), true)) {
            $deVista = $this->resolveView($ast);
            if ($deVista !== null) {
                if ($explicar) {
                    return $this->plan('select', $ast['collection'], ['estrategia' => 'vista']);
                }
                return ResultSet::construir($deVista, $ast);
            }
        }

        if (($ast['joins'] ?? []) !== []) {
            return $this->withJoin($ast, $explicar);
        }

        $q = $this->buildQuery($ast);
        if ($explicar) {
            return $this->explainQuery($q, $ast, 'select');
        }
        /*
         * Query resuelve el WHERE —y usa indices si puede—; de ahi en adelante
         * es cosa de Resultado: agrupar, proyectar, DISTINCT, ordenar y cortar.
         * Se separan porque Query trabaja con documentos y a partir de la
         * proyeccion ya no hay documentos, hay filas.
         */
        return ResultSet::construir($q->get(), $ast);
    }

    private function count(array $ast, bool $explicar): mixed
    {
        $q = $this->buildQuery($ast);
        if ($explicar) {
            return $this->explainQuery($q, $ast, 'count');
        }
        return $q->count();
    }

    /**
     * La consulta que resuelve el WHERE. Ni ordena, ni corta, ni proyecta:
     * de eso se encarga Resultado, porque ordenar por un alias o limitar
     * despues de agrupar solo se puede hacer sobre las filas ya construidas.
     *
     * La excepcion es COUNT(*) pelado, que no construye filas y puede seguir
     * contando en el propio Query.
     */
    private function buildQuery(array $ast): Query
    {
        // Db::find() ya devuelve la vista correcta: si hay una transaccion
        // abierta, la consulta se resuelve sobre lo que esa transaccion ve.
        return $this->db->find($ast['collection'])
            ->whereExpr((new Subqueries($this->db))->resolve($ast['where_expr']));
    }

    /**
     * Una consulta con JOIN.
     *
     * El WHERE se aplica DESPUES de cruzar, y por tanto sin indices. Tiene que
     * ser asi: la condicion puede hablar de campos de la coleccion unida, y
     * filtrar antes con un indice de la izquierda descartaria documentos que la
     * union habria traido. Correcto antes que rapido; `EXPLAIN` lo dice.
     */
    private function withJoin(array $ast, bool $explicar): mixed
    {
        // El JOIN escrito en SQL pasa por la misma puerta de perfil que join()
        // del constructor. Sin esto, con perfil 'core' —que no incluye relaciones—
        // el join() se paraba pero `SELECT ... JOIN` no: dos caminos para lo mismo
        // con dos reglas distintas.
        $this->db->profile()->requireCapability('relations', 'JOIN');

        if ($explicar) {
            return $this->plan('select', $ast['collection'], [
                'estrategia' => 'cruce',
                'uniones'    => \array_map(
                    static fn(array $u): string => $u['tipo'] . ' ' . $u['coleccion'],
                    $ast['joins']
                ),
                'detalle' => 'con JOIN el filtro va despues de cruzar, sin indices',
            ]);
        }
        $filas = JoinPlan::aplicar(
            fn(string $c): array => $this->db->all($c),
            $this->db->all($ast['collection']),
            (string) ($ast['alias'] ?? $ast['collection']),
            $ast['joins']
        );

        $donde = (new Subqueries($this->db))->resolve($ast['where_expr']);
        if ($donde !== null) {
            $filas = \array_values(\array_filter(
                $filas,
                static fn(array $fila) => Evaluator::matches($fila, $donde)
            ));
        }
        return ResultSet::construir($filas, $ast);
    }

    /**
     * Si el FROM apunta a una vista, se ejecuta la consulta que la vista guarda
     * y el resto de la sentencia se aplica sobre ese resultado.
     *
     * Se resuelve aqui y no en el parser porque una vista es un dato: existe o
     * no existe segun lo que haya guardado en la base, y eso no se sabe al
     * analizar el texto.
     */
    private function resolveView(array $ast): ?array
    {
        $sql = Structure::sqlDeVista($this->db, (string) ($ast['collection'] ?? ''));
        if ($sql === null) {
            return null;
        }

        // El texto de la vista se REPARSEA y se exige que sea un SELECT antes de
        // ejecutarlo. Antes se hacia `db->sql($texto)` a ciegas: como axidb_vistas
        // era una coleccion normal, cualquiera que escribiera una fila con un
        // `DROP COLLECTION` de cuerpo lograba que la siguiente lectura de esa
        // vista lo ejecutara. Una vista es una consulta de lectura, y solo eso.
        $arbol = (new Parser())->parse($sql);
        if (($arbol['type'] ?? '') !== 'select' && ($arbol['type'] ?? '') !== 'count') {
            throw new \Axi\Core\Exception(
                "AxiSQL: la vista '{$ast['collection']}' no define un SELECT. Una vista solo lee."
            );
        }

        // Tope de anidamiento: una vista que se referencia a si misma —o dos que
        // se referencian entre si— recurria hasta agotar la memoria, y la vista
        // quedaba guardada, asi que cada lectura posterior volvia a matar el
        // proceso. Se corta con un error del motor, no con un fatal de PHP.
        if (self::$profundidadVista >= self::MAX_VISTAS) {
            throw new \Axi\Core\Exception(
                "AxiSQL: vistas anidadas demasiado profundas (tope " . self::MAX_VISTAS . "). "
                . '¿Una vista que se referencia a si misma?'
            );
        }
        self::$profundidadVista++;
        try {
            $filas = (array) $this->db->sql($sql);
        } finally {
            self::$profundidadVista--;
        }

        /*
         * El WHERE de la consulta de fuera se aplica AQUI.
         *
         * Lo normal es que lo resuelva Query, pero una vista no pasa por Query:
         * sus filas ya vienen calculadas. La primera version se saltaba este
         * paso y `SELECT * FROM vista WHERE total > 260` devolvia tambien los de
         * 250, sin ningun error. Un filtro que no filtra y no avisa es de lo
         * peor que puede hacer una base de datos.
         */
        if (($ast['where_expr'] ?? null) === null) {
            return $filas;
        }
        return \array_values(\array_filter(
            $filas,
            static fn(array $fila) => Evaluator::matches($fila, $ast['where_expr'])
        ));
    }

    private function explainQuery(Query $q, array $ast, string $tipo): array
    {
        $q->count();                       // resuelve el plan sin devolver datos
        $plan = $q->plan();

        return $this->plan($tipo, $ast['collection'], [
            'estrategia'  => $plan['strategy'],
            'campo'       => $plan['field'],
            'valor'       => $plan['value'],
            'candidatos'  => $plan['candidates'],
            'total'       => $this->db->count($ast['collection']),
            'orden'       => $ast['order_by'] ?? [],
            'detalle'     => $plan['strategy'] === 'index'
                ? "Resolved by the index on '{$plan['field']}': leidos {$plan['candidates']} documentos."
                : "No usable index: scanned the whole collection ({$plan['candidates']} documentos).",
        ]);
    }

}
