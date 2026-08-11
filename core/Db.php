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
    // Nombre completo: el test de frontera mira los `use` con regex y no distingue
    // un rasgo de una importacion.
    use \Axi\Core\Facade\WithIndexes;
    use \Axi\Core\Facade\WithVectors;
    use \Axi\Core\Facade\WithAgents;
    use \Axi\Core\Facade\WithTransactions;
    use \Axi\Core\Facade\WithDeclarations;
    use \Axi\Core\Facade\WithBackups;
    use \Axi\Core\Facade\WithStructure;
    use \Axi\Core\Facade\WithHealth;
    use \Axi\Core\Facade\WithExpiry;

    private Storage $storage;
    private Index $index;
    private VectorStore $vectores;
    private Profile $perfil;
    private ?Agents\Audit $auditoria = null;

    /**
     * @param string $dataPath Directorio de datos. Se crea si no existe.
     * @param array  $options  durable: bool (fsync en cada escritura, def. true)
     *                          key:     string, contraseña de las colecciones cifradas
     *                          profile: core | docs | ai. Sin el, todo disponible
     */
    public function __construct(string $dataPath, array $options = [])
    {
        $durable       = (bool) ($options['durable'] ?? true);
        $this->perfil   = new Profile((string) ($options['profile'] ?? Profile::TODO));
        $this->storage  = new Storage($dataPath, $durable, $options['key'] ?? null);
        $this->index    = new Index($this->storage);
        $this->vectores = new VectorStore($this->storage, $options['embedder'] ?? null);

        // Antes de que nadie lea: si un corte dejo una transaccion a medias, se
        // termina o se descarta ahora, no en mitad de una lectura.
        if (($options['recover'] ?? true) !== false) {
            $this->recover();
        }
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
        $fields  = $this->index->fields($collection);
        $unicos  = $this->storage->uniquesOf($collection);
        $esquema = $this->storage->schemaOf($collection);

        // rawGet: el mantenimiento de indices y unicidad trabaja sobre lo que hay
        // en disco. Un vencido conserva su indice y su reserva; get() los ocultaria.
        $before = $fields === [] && $unicos === [] && $esquema === []
            ? null
            : $this->storage->rawGet($collection, $id);

        // El esquema, lo primero: rebotar un documento invalido antes de tocar nada.
        [$data, $replace] = $this->applySchema($collection, $id, $data, $before, $replace);
        $entero = $replace || $before === null ? $data : $data + $before;

        // Antes del cerrojo: soltar el valor unico que retiene un vencido o un
        // proceso muerto. Reclamar huerfanos coge el EX, incompatible con el SH.
        if ($unicos !== []) {
            $this->purgeExpiredOwners($collection, $unicos, $entero);
            $this->reclaimOrphanUniques($collection, $unicos, $entero);
        }

        // Cerrojo estructural COMPARTIDO durante reservar-escribir-sincronizar:
        // mantiene fuera a reindex() y a migrar de driver (que van en EX y
        // reescriben o retiran la coleccion entera). Se coge SIEMPRE —migrar
        // alcanza a cualquier coleccion—; varias altas caben a la vez en SH.
        $candado = $this->index->reindexLock($collection, false);
        try {
            $reserva = new Uniqueness($this->index, $collection, $id);
            if ($unicos !== []) {
                $reserva->reserve($unicos, $entero, $before);   // reservar antes de escribir
            }
            try {
                $after = $this->storage->put($collection, $id, $data, $replace);
            } catch (\Throwable $e) {
                $reserva->release();
                throw $e;
            }
            if ($fields !== []) {
                $this->index->sync($collection, $fields, $before, $after);
            }
            $this->vectores->onSave($collection, $id, $after);
            return $after;
        } finally {
            $this->index->reindexUnlock($candado);
        }
    }

    public function delete(string $collection, string $id): bool
    {
        $fields = $this->index->fields($collection);
        // rawGet: ver tambien un vencido, para limpiar su entrada de indice.
        $before = $fields === [] ? null : $this->storage->rawGet($collection, $id);

        $ok = $this->storage->delete($collection, $id);

        if ($ok && $fields !== [] && $before !== null) {
            $this->index->sync($collection, $fields, $before, null);
        }
        if ($ok) {
            $this->vectores->onDelete($collection, $id);
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

    /**
     * Constructor de consultas encadenables.
     *
     * Con una transaccion abierta, la consulta se resuelve sobre lo que la
     * transaccion ve: lo del disco con lo pendiente por encima. Asi un SELECT
     * dentro de un BEGIN devuelve la verdad y no el estado de antes.
     */
    public function find(string $collection): Query
    {
        $tx = $this->currentTransaction();

        return new Query(
            $this->storage,
            $this->index,
            $collection,
            $tx === null ? null : static fn(): array => $tx->all($collection),
            $this->perfil
        );
    }

    /** Documentos cuyo $field vale $value. Usa el indice si existe; si no, escanea. */
    public function by(string $collection, string $field, string $value): array
    {
        return $this->find($collection)->where($field, '=', $value)->get();
    }

    /**
     * Ejecuta una sentencia AxiSQL. Es una llamada a funcion PHP que analiza la
     * sentencia y toca ficheros: no hay red, ni socket, ni servidor. Devuelve una
     * lista de documentos en SELECT, un entero en COUNT, el plan en EXPLAIN, y un
     * array con el resultado en el resto.
     *
     *   $db->sql("SELECT nombre FROM presupuestos WHERE total > 300 ORDER BY total DESC");
     */
    public function sql(string $sentencia): mixed
    {
        return (new Sql\Executor($this))->run((new Sql\Parser())->parse($sentencia));
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
