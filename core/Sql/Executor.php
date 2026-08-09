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
    private Session $sesion;

    public function __construct(private Db $db)
    {
        $this->sesion = new Session($db);
    }

    public function run(array $ast): mixed
    {
        $explicar = (bool) ($ast['explain'] ?? false);

        return match ($ast['type']) {
            'select', 'count'   => (new Read($this->db))->ejecutar($ast, $explicar),
            'insert', 'update', 'delete' => (new Write($this->db, $this->sesion))->ejecutar($ast, $explicar),
            'create_collection' => $this->crearColeccion($ast, $explicar),
            'drop_collection'   => $this->borrarColeccion($ast, $explicar),
            'create_index'      => $this->crearIndice($ast, $explicar),
            'drop_index'        => $this->borrarIndice($ast, $explicar),
            'begin', 'commit', 'rollback' => $explicar
                ? $this->plan($ast['type'], '', [])
                : $this->sesion->ejecutar($ast['type']),
            'show_collections', 'show_indexes', 'describe', 'create_view',
            'alter_rename', 'alter_add_field', 'alter_drop_field', 'alter_rename_field'
                => $explicar
                    ? $this->plan($ast['type'], $ast['collection'] ?? '', [])
                    : (new Structure($this->db))->ejecutar($ast),
            default             => throw new Exception("AxiSQL: sentencia no soportada '{$ast['type']}'."),
        };
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
        $unico = !empty($ast['unique']);
        return [
            'indexed' => $ast['field'],
            'values'  => $unico
                ? $this->db->unique($ast['collection'], $ast['field'])
                : $this->db->index($ast['collection'], $ast['field']),
            'unique'  => $unico,
        ];
    }

    private function borrarIndice(array $ast, bool $explicar): mixed
    {
        if ($explicar) {
            return $this->plan('drop_index', $ast['collection'], ['campo' => $ast['field']]);
        }
        return ['dropped' => $this->db->dropIndex($ast['collection'], $ast['field'])];
    }

    /* ─────────────────────────────── EXPLAIN ─────────────────────────────── */

    private function plan(string $operacion, string $coleccion, array $extra): array
    {
        return \array_merge([
            'explain'    => true,
            'operacion'  => $operacion,
            'coleccion'  => $coleccion,
        ], $extra);
    }
}
