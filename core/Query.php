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
    private array  $plan    = ['strategy' => 'scan', 'field' => null, 'value' => null, 'candidates' => 0];

    /** Operadores unarios: where('stock','IS NULL') no lleva valor. */
    private const UNARIOS = ['IS NULL', 'IS NOT NULL'];

    public function __construct(
        private Storage $storage,
        private Index $index,
        private string $collection
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

    public function get(): array
    {
        $docs = $this->candidates();

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

    /**
     * Conjunto de partida. Si hay una igualdad indexable, se resuelve por
     * indice: O(coincidencias) en vez de O(coleccion).
     */
    private function candidates(): array
    {
        $igualdad = $this->igualdadIndexable();

        if ($igualdad === null) {
            $docs = $this->storage->all($this->collection);
            $this->plan = [
                'strategy'   => 'scan',
                'field'      => null,
                'value'      => null,
                'candidates' => \count($docs),
            ];
            return $docs;
        }

        [$campo, $valor] = $igualdad;
        $ids  = $this->index->ids($this->collection, $campo, $valor) ?? [];
        $docs = [];
        foreach ($ids as $id) {
            $d = $this->storage->get($this->collection, (string) $id);
            if ($d !== null) {
                $docs[] = $d;
            }
        }
        $this->plan = [
            'strategy'   => 'index',
            'field'      => $campo,
            'value'      => $valor,
            'candidates' => \count($docs),
        ];
        return $docs;
    }

    /**
     * Busca una igualdad sobre campo indexado que se cumpla siempre. En un
     * arbol solo vale bajando por ramas AND: dentro de un OR la condicion puede
     * no cumplirse, y dentro de un NOT se cumple justo al reves, asi que en esos
     * casos el indice no puede descartar nada y toca escanear.
     *
     * @return array{0:string,1:string}|null [campo, valor]
     */
    private function igualdadIndexable(): ?array
    {
        foreach ($this->where as $c) {
            $encontrada = $this->indexable($c['field'], $c['op'], $c['value']);
            if ($encontrada !== null) {
                return $encontrada;
            }
        }
        return $this->expr === null ? null : $this->buscarEnAnd($this->expr);
    }

    private function buscarEnAnd(array $nodo): ?array
    {
        if (($nodo['type'] ?? '') === 'and') {
            return $this->buscarEnAnd($nodo['left']) ?? $this->buscarEnAnd($nodo['right']);
        }
        if (($nodo['type'] ?? '') === 'cmp') {
            return $this->indexable($nodo['field'], $nodo['op'], $nodo['value'] ?? null);
        }
        return null;
    }

    private function indexable(string $field, string $op, mixed $value): ?array
    {
        if (\strtoupper($op) !== '=' || $value === null || \is_array($value) || \is_bool($value)) {
            return null;
        }
        if (!$this->index->isIndexed($this->collection, $field)) {
            return null;
        }
        return [$field, (string) $value];
    }
}
