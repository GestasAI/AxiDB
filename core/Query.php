<?php
/**
 * AxiDB - Core\Query: constructor de consultas encadenables.
 *
 *   $db->find('presupuestos')->where('estado','=','pendiente')
 *                            ->orderBy('total','desc')->limit(20)->get();
 *
 * Dos formas de filtrar que acaban en el mismo sitio: where() encadenado, que
 * combina con AND, y whereExpr() con un arbol (lo que produce AxiSQL con sus
 * AND/OR/NOT anidados). Las dos las evalua Core\Evaluator.
 *
 * Optimizacion: si el filtro contiene una igualdad sobre un campo indexado que
 * se cumple siempre —encadenada, o en la rama AND de un arbol—, la consulta
 * arranca leyendo solo los ids de ese indice en vez de escanear la coleccion.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Query
{
    private array  $where   = [];
    private ?array $expr    = null;
    private array  $orderBy = [];
    private ?int   $limit   = null;
    private int    $offset  = 0;
    private array  $fields  = [];

    /** @var list<array{coleccion:string, alias:string, tipo:string, izq:string, der:string}> */
    private array  $uniones = [];
    private array  $plan    = ['strategy' => 'scan', 'field' => null, 'value' => null, 'candidates' => 0];

    /** Operadores unarios: where('stock','IS NULL') no lleva valor. */
    private const UNARIOS = ['IS NULL', 'IS NOT NULL'];

    /**
     * @param \Closure|null $fuente documentos de partida. Null: del disco, con
     *                              indices. Dentro de una transaccion, su vista.
     */
    public function __construct(
        private Storage $storage,
        private Index $index,
        private string $collection,
        private ?\Closure $fuente = null,
        private ?Perfil $perfil = null
    ) {
    }

    public function where(string $field, string $op, mixed $value = null): self
    {
        // Con dos argumentos hay dos lecturas posibles:
        //   where('estado', 'pendiente') -> igualdad, el segundo es el valor
        //   where('stock', 'IS NULL')    -> operador unario, no hay valor
        if (\func_num_args() === 2 && !\in_array(\strtoupper($op), self::UNARIOS, true)) {
            $value = $op;
            $op    = '=';
        }
        $this->where[] = ['field' => $field, 'op' => \strtoupper($op), 'value' => $value];
        return $this;
    }

    /** Arbol de expresion completo (and / or / not / cmp), como el de AxiSQL. */
    public function whereExpr(?array $expr): self
    {
        $this->expr = $expr;
        return $this;
    }

    public function orderBy(string $field, string $dir = 'asc'): self
    {
        $this->orderBy[] = ['field' => $field, 'dir' => \strtolower($dir) === 'desc' ? 'desc' : 'asc'];
        return $this;
    }

    public function limit(int $n): self
    {
        $this->limit = \max(0, $n);
        return $this;
    }

    public function offset(int $n): self
    {
        $this->offset = \max(0, $n);
        return $this;
    }

    /** Proyeccion: devuelve solo estos campos. */
    public function select(array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * Cruza con otra coleccion. El equivalente del JOIN de AxiSQL.
     *
     *   $db->find('pedidos')->join('clientes', 'cli', 'id')->get();
     *
     * Los campos de la coleccion unida llegan con su nombre delante:
     * `clientes.nombre`. Los de esta se quedan como estan. Asi no hay
     * ambiguedad si las dos tienen un campo que se llama igual.
     *
     * Con `izquierdo: true` es un LEFT JOIN: los documentos que no casan se
     * conservan con la otra parte a nulos, en vez de desaparecer.
     */
    public function join(string $coleccion, string $campoAqui, string $campoAlla, bool $izquierdo = false): self
    {
        $this->perfil?->exigir('relaciones', 'join() y JOIN');

        $this->uniones[] = [
            'coleccion' => $coleccion,
            'alias'     => $coleccion,
            'tipo'      => $izquierdo ? Sql\Cruce::IZQUIERDO : Sql\Cruce::INTERNO,
            'izq'       => $campoAqui,
            'der'       => $campoAlla,
        ];
        return $this;
    }

    public function get(): array
    {
        [$docs, $this->plan] = Partida::de(
            $this->storage, $this->index, $this->collection,
            $this->where, $this->expr, $this->fuente
        );

        /*
         * El cruce va ANTES de filtrar. Tiene que ser asi: la condicion puede
         * hablar de un campo de la coleccion unida, y filtrar primero
         * descartaria documentos que la union habria traido.
         *
         * Por eso una consulta con join() no usa indices para el filtro. Es el
         * mismo compromiso que en AxiSQL, y por el mismo motivo.
         */
        if ($this->uniones !== []) {
            $docs = Sql\Cruce::aplicar(
                fn(string $c): array => $this->storage->all($c),
                $docs,
                $this->collection,
                $this->uniones
            );
            $this->plan['strategy'] = 'cruce';
        }

        foreach ($this->where as $clausula) {
            $docs = \array_filter(
                $docs,
                static fn($d) => Evaluator::cmp($d, $clausula['field'], $clausula['op'], $clausula['value'])
            );
        }
        if ($this->expr !== null) {
            $docs = \array_filter($docs, fn($d) => Evaluator::matches($d, $this->expr));
        }
        $docs = \array_values($docs);

        if ($this->orderBy !== []) {
            $docs = $this->ordenar($docs);
        }
        if ($this->limit !== null) {
            $docs = \array_slice($docs, $this->offset, $this->limit);
        } elseif ($this->offset > 0) {
            $docs = \array_slice($docs, $this->offset);
        }
        if ($this->fields !== []) {
            $flip = \array_flip($this->fields);
            $docs = \array_map(static fn($d) => \array_intersect_key($d, $flip), $docs);
        }
        return $docs;
    }

    public function first(): ?array
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function count(): int
    {
        $guardado = [$this->limit, $this->offset, $this->fields];
        [$this->limit, $this->offset, $this->fields] = [null, 0, []];
        $n = \count($this->get());
        [$this->limit, $this->offset, $this->fields] = $guardado;
        return $n;
    }

    /**
     * Como se ha resuelto la ultima consulta: por indice o escaneando, sobre que
     * campo y cuantos documentos se leyeron de partida. Alimenta a EXPLAIN.
     */
    public function plan(): array
    {
        return $this->plan;
    }

    private function ordenar(array $docs): array
    {
        $clausulas = $this->orderBy;
        \usort($docs, static function ($a, $b) use ($clausulas) {
            foreach ($clausulas as $c) {
                $va = $a[$c['field']] ?? null;
                $vb = $b[$c['field']] ?? null;
                if ($va == $vb) {
                    continue;
                }
                $cmp = ($va > $vb) ? 1 : -1;
                return $c['dir'] === 'desc' ? -$cmp : $cmp;
            }
            return 0;
        });
        return $docs;
    }

}
