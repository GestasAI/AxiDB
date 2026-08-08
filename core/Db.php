<?php
/**
 * AxiDB - Core\Db: la unica clase que usa quien integra AxiDB.
 *
 *   $db = new Axi\Core\Db('./data');
 *   $db->insert('presupuestos', ['cliente' => 'Ana', 'total' => 420]);
 *
 * Responsable de coordinar Storage (documentos) e Index (indices secundarios),
 * de forma que declarar un indice una vez basta: se mantiene solo en cada alta,
 * modificacion y borrado.
 *
 * No conoce ningun dominio. Ver la regla de frontera del plan maestro.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Db
{
    private Storage $storage;
    private Index $index;

    /**
     * @param string $dataPath Directorio de datos. Se crea si no existe.
     * @param array  $options  durable: bool (fsync en cada escritura, def. true)
     */
    public function __construct(string $dataPath, array $options = [])
    {
        $durable       = (bool) ($options['durable'] ?? true);
        $this->storage = new Storage($dataPath, $durable);
        $this->index   = new Index($this->storage);
    }

    /* ─────────────────────────────── Escritura ─────────────────────────────── */

    /** Alta. Si no se da id, se genera uno ordenable por tiempo. */
    public function insert(string $collection, array $data, ?string $id = null): array
    {
        return $this->put($collection, $id ?? self::newId(), $data, true);
    }

    /** Modifica un documento existente. Fusiona salvo que $replace sea true. */
    public function update(string $collection, string $id, array $data, bool $replace = false): array
    {
        if (!$this->storage->exists($collection, $id)) {
            throw new Exception("Db: '{$collection}/{$id}' no existe.");
        }
        return $this->put($collection, $id, $data, $replace);
    }

    /** Alta o modificacion. Mantiene los indices declarados del campo afectado. */
    public function put(string $collection, string $id, array $data, bool $replace = false): array
    {
        $fields = $this->index->fields($collection);
        $before = $fields === [] ? null : $this->storage->get($collection, $id);

        $after = $this->storage->put($collection, $id, $data, $replace);

        if ($fields !== []) {
            $this->index->sync($collection, $fields, $before, $after);
        }
        return $after;
    }

    public function delete(string $collection, string $id): bool
    {
        $fields = $this->index->fields($collection);
        $before = $fields === [] ? null : $this->storage->get($collection, $id);

        $ok = $this->storage->delete($collection, $id);

        if ($ok && $fields !== [] && $before !== null) {
            $this->index->sync($collection, $fields, $before, null);
        }
        return $ok;
    }

    /* ─────────────────────────────── Lectura ─────────────────────────────── */

    public function get(string $collection, string $id): ?array
    {
        return $this->storage->get($collection, $id);
    }

    public function exists(string $collection, string $id): bool
    {
        return $this->storage->exists($collection, $id);
    }

    /** Todos los documentos. O(coleccion): preferir find() o by() con indice. */
    public function all(string $collection): array
    {
        return $this->storage->all($collection);
    }

    public function ids(string $collection): array
    {
        return $this->storage->ids($collection);
    }

    public function count(string $collection): int
    {
        return $this->storage->count($collection);
    }

    /** Constructor de consultas encadenables. */
    public function find(string $collection): Query
    {
        return new Query($this->storage, $this->index, $collection);
    }

    /**
     * Documentos cuyo $field vale $value. Usa el indice si existe; si no,
     * escanea. Es el equivalente generico del "por inquilino" de una app
     * multi-tenant, sin que el nucleo sepa que existen los inquilinos.
     */
    public function by(string $collection, string $field, string $value): array
    {
        return $this->find($collection)->where($field, '=', $value)->get();
    }

    /* ─────────────────────────────── AxiSQL ─────────────────────────────── */

    /**
     * Ejecuta una sentencia AxiSQL. Es una llamada a funcion PHP: analiza la
     * sentencia y toca ficheros. No hay red, ni socket, ni servidor.
     *
     *   $db->sql("SELECT nombre FROM presupuestos WHERE total > 300 ORDER BY total DESC");
     *   $db->sql("INSERT INTO presupuestos (cliente, total) VALUES ('Ana', 421.20)");
     *   $db->sql("CREATE INDEX ON presupuestos (cliente_id)");
     *   $db->sql("EXPLAIN SELECT * FROM presupuestos WHERE cliente_id = 'c1'");
     *
     * Devuelve: lista de documentos en SELECT, entero en COUNT, y un array con
     * el resultado de la operacion en el resto. Con EXPLAIN, el plan.
     */
    public function sql(string $sentencia): mixed
    {
        return (new Sql\Executor($this))->run((new Sql\Parser())->parse($sentencia));
    }

    /* ─────────────────────────────── Indices ─────────────────────────────── */

    /**
     * Declara y construye un indice sobre un campo. Idempotente: volver a
     * llamarlo reconstruye, que es tambien la forma de reparar uno dañado.
     * @return int numero de valores distintos indexados
     */
    public function index(string $collection, string $field): int
    {
        return $this->index->build($collection, $field);
    }

    /**
     * Declara el indice solo si aun no existe. A diferencia de index(), no
     * reconstruye: es barato de llamar en cada escritura para que una coleccion
     * que aparece en tiempo de ejecucion quede indexada desde su primer
     * documento, sin releer la coleccion entera cada vez.
     *
     * @return bool true si lo ha creado ahora, false si ya estaba
     */
    public function ensureIndex(string $collection, string $field): bool
    {
        if ($this->index->isIndexed($collection, $field)) {
            return false;
        }
        $this->index->build($collection, $field);
        return true;
    }

    public function indexes(string $collection): array
    {
        return $this->index->fields($collection);
    }

    public function dropIndex(string $collection, string $field): bool
    {
        return $this->index->drop($collection, $field);
    }

    /** Reconstruye todos los indices declarados de una coleccion. */
    public function reindex(string $collection): array
    {
        $out = [];
        foreach ($this->index->fields($collection) as $field) {
            $out[$field] = $this->index->build($collection, $field);
        }
        return $out;
    }

    /**
     * Comprueba si los indices declarados reflejan la realidad de los
     * documentos. Los documentos se sincronizan a disco con fsync, pero los
     * indices no — son estado derivado — asi que un corte de corriente puede
     * dejarlos cortos. Esta es la forma de detectarlo; reindex() lo repara.
     *
     * @return array<string, array{documentos:int, indexados:int, faltan:int}>
     */
    public function verifyIndexes(string $collection): array
    {
        $out = [];
        foreach ($this->index->fields($collection) as $field) {
            $out[$field] = IndexVerifier::check($this->storage, $this->index, $collection, $field);
        }
        return $out;
    }

    /* ─────────────────────────────── Colecciones ─────────────────────────────── */

    public function collections(): array
    {
        return $this->storage->collections();
    }

    public function dropCollection(string $collection): bool
    {
        return $this->storage->dropCollection($collection);
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    public function storage(): Storage
    {
        return $this->storage;
    }

    public function indexer(): Index
    {
        return $this->index;
    }

    public function path(): string
    {
        return $this->storage->basePath();
    }

    /**
     * Id ordenable por tiempo: YYYYMMDDHHMMSS + 4 cifras de microsegundos +
     * 6 hex aleatorios. Ordenar por id ordena por antigüedad.
     */
    public static function newId(): string
    {
        $us = \substr(\sprintf('%06d', (int) ((\microtime(true) - \time()) * 1000000)), 0, 4);
        return \date('YmdHis') . $us . \bin2hex(\random_bytes(3));
    }
}
