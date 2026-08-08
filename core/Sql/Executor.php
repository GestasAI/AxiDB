<?php
/**
 * AxiDB - Sql\Executor: ejecuta el arbol contra el motor.
 *
 * No reimplementa nada: SELECT construye un Query, INSERT llama a Db::insert,
 * CREATE INDEX llama a Db::index. AxiSQL es una forma de escribir lo que ya se
 * puede hacer con la API, no un camino paralelo con sus propias reglas. Por eso
 * `test_sql_paridad.php` puede exigir que las dos vias den lo mismo.
 *
 * Con EXPLAIN no se ejecuta la escritura: se devuelve el plan y se para.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Db;
use Axi\Core\Evaluator;
use Axi\Core\Exception;
use Axi\Core\Query;

final class Executor
{
    public function __construct(private Db $db)
    {
    }

    public function run(array $ast): mixed
    {
        $explicar = (bool) ($ast['explain'] ?? false);

        return match ($ast['type']) {
            'select'            => $this->select($ast, $explicar),
            'count'             => $this->count($ast, $explicar),
            'insert'            => $this->insert($ast, $explicar),
            'update'            => $this->update($ast, $explicar),
            'delete'            => $this->delete($ast, $explicar),
            'create_collection' => $this->crearColeccion($ast, $explicar),
            'drop_collection'   => $this->borrarColeccion($ast, $explicar),
            'create_index'      => $this->crearIndice($ast, $explicar),
            'drop_index'        => $this->borrarIndice($ast, $explicar),
            default             => throw new Exception("AxiSQL: sentencia no soportada '{$ast['type']}'."),
        };
    }

    /* ─────────────────────────────── Lectura ─────────────────────────────── */

    private function select(array $ast, bool $explicar): mixed
    {
        $q = $this->construirConsulta($ast);
        if ($explicar) {
            return $this->explicarConsulta($q, $ast, 'select');
        }
        return $q->get();
    }

    private function count(array $ast, bool $explicar): mixed
    {
        $q = $this->construirConsulta($ast);
        if ($explicar) {
            return $this->explicarConsulta($q, $ast, 'count');
        }
        return $q->count();
    }

    private function construirConsulta(array $ast): Query
    {
        $q = $this->db->find($ast['collection'])->whereExpr($ast['where_expr']);

        foreach ($ast['order_by'] ?? [] as $o) {
            $q->orderBy($o['field'], $o['dir']);
        }
        if (($ast['limit'] ?? null) !== null) {
            $q->limit($ast['limit']);
        }
        if (($ast['offset'] ?? null) !== null) {
            $q->offset($ast['offset']);
        }
        if (($ast['fields'] ?? ['*']) !== ['*']) {
            $q->select($ast['fields']);
        }
        return $q;
    }

    /* ─────────────────────────────── Escritura ─────────────────────────────── */

    private function insert(array $ast, bool $explicar): mixed
    {
        if ($explicar) {
            return $this->plan('insert', $ast['collection'], ['documentos' => 1]);
        }
        return $this->db->insert($ast['collection'], $ast['data']);
    }

    private function update(array $ast, bool $explicar): mixed
    {
        $afectados = $this->afectados($ast);
        if ($explicar) {
            return $this->plan('update', $ast['collection'], [
                'documentos' => \count($afectados),
                'campos'     => \array_keys($ast['set']),
            ]);
        }
        $n = 0;
        foreach ($afectados as $doc) {
            $this->db->put($ast['collection'], (string) $doc['id'], $ast['set']);
            $n++;
        }
        return ['updated' => $n];
    }

    private function delete(array $ast, bool $explicar): mixed
    {
        $afectados = $this->afectados($ast);
        if ($explicar) {
            return $this->plan('delete', $ast['collection'], ['documentos' => \count($afectados)]);
        }
        $n = 0;
        foreach ($afectados as $doc) {
            if ($this->db->delete($ast['collection'], (string) $doc['id'])) {
                $n++;
            }
        }
        return ['deleted' => $n];
    }

    /**
     * Documentos que toca un UPDATE o un DELETE. Se resuelven ANTES de escribir:
     * modificar mientras se recorre el listado deja resultados impredecibles.
     */
    private function afectados(array $ast): array
    {
        return $this->db->find($ast['collection'])->whereExpr($ast['where_expr'])->get();
    }

    /* ─────────────────────────────── Estructura ─────────────────────────────── */

    private function crearColeccion(array $ast, bool $explicar): mixed
    {
        if ($explicar) {
            return $this->plan('create_collection', $ast['collection'], []);
        }
        // Una coleccion existe en cuanto tiene su directorio; no hay esquema que fijar.
        $this->db->storage()->ensureCollection($ast['collection']);
        return ['created' => $ast['collection']];
    }

    private function borrarColeccion(array $ast, bool $explicar): mixed
    {
        if ($explicar) {
            return $this->plan('drop_collection', $ast['collection'], [
                'documentos' => $this->db->count($ast['collection']),
            ]);
        }
        return ['dropped' => $this->db->dropCollection($ast['collection'])];
    }

    /**
     * A diferencia del motor anterior, esto CONSTRUYE el indice. Antes
     * CREATE INDEX solo lo anotaba en un metadato y devolvia exito, asi que la
     * consulta siguiente seguia escaneando y nadie se enteraba.
     */
    private function crearIndice(array $ast, bool $explicar): mixed
    {
        if ($explicar) {
            return $this->plan('create_index', $ast['collection'], [
                'campo'      => $ast['field'],
                'documentos' => $this->db->count($ast['collection']),
            ]);
        }
        if (!empty($ast['unique'])) {
            $this->exigirUnicidad($ast['collection'], $ast['field']);
        }
        return [
            'indexed' => $ast['field'],
            'values'  => $this->db->index($ast['collection'], $ast['field']),
            'unique'  => (bool) ($ast['unique'] ?? false),
        ];
    }

    private function borrarIndice(array $ast, bool $explicar): mixed
    {
        if ($explicar) {
            return $this->plan('drop_index', $ast['collection'], ['campo' => $ast['field']]);
        }
        return ['dropped' => $this->db->dropIndex($ast['collection'], $ast['field'])];
    }

    /**
     * UNIQUE se comprueba al crear el indice y se rechaza si ya hay repetidos.
     * No se vigila despues en cada alta: eso exigiria una transaccion, y
     * prometer una restriccion que no se mantiene es peor que no tenerla.
     */
    private function exigirUnicidad(string $coleccion, string $campo): void
    {
        $vistos = [];
        foreach ($this->db->all($coleccion) as $doc) {
            $v = $doc[$campo] ?? null;
            if ($v === null || \is_array($v)) {
                continue;
            }
            $v = (string) $v;
            if (isset($vistos[$v])) {
                throw new Exception(
                    "AxiSQL: no se puede crear un indice UNIQUE sobre '{$campo}': "
                    . "el valor '{$v}' se repite en '{$vistos[$v]}' y '{$doc['id']}'."
                );
            }
            $vistos[$v] = (string) ($doc['id'] ?? '');
        }
    }

    /* ─────────────────────────────── EXPLAIN ─────────────────────────────── */

    private function explicarConsulta(Query $q, array $ast, string $tipo): array
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
                ? "Resuelto por el indice de '{$plan['field']}': leidos {$plan['candidates']} documentos."
                : "Sin indice util: escaneada la coleccion entera ({$plan['candidates']} documentos).",
        ]);
    }

    private function plan(string $operacion, string $coleccion, array $extra): array
    {
        return \array_merge([
            'explain'    => true,
            'operacion'  => $operacion,
            'coleccion'  => $coleccion,
        ], $extra);
    }
}
