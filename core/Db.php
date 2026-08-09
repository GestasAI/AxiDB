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
    /*
     * Los tres con nombre completo, no relativo. Son rasgos de este mismo
     * nucleo, pero el test de frontera mira los `use` con una expresion regular
     * y no puede distinguir un rasgo de una importacion: escribirlos enteros
     * deja claro de donde salen y de paso el test no tiene que adivinar.
     */
    use \Axi\Core\Fachada\ConIndices;
    use \Axi\Core\Fachada\ConVectores;
    use \Axi\Core\Fachada\ConAgentes;
    use \Axi\Core\Fachada\ConTransacciones;
    use \Axi\Core\Fachada\ConDeclaraciones;
    use \Axi\Core\Fachada\ConCopias;
    use \Axi\Core\Fachada\ConEstructura;
    use \Axi\Core\Fachada\ConSalud;

    private Storage $storage;
    private Index $index;
    private Vectores $vectores;
    private ?Agentes\Auditoria $auditoria = null;

    /**
     * @param string $dataPath Directorio de datos. Se crea si no existe.
     * @param array  $options  durable: bool (fsync en cada escritura, def. true)
     *                          clave:   string, contraseña de las colecciones cifradas
     */
    public function __construct(string $dataPath, array $options = [])
    {
        $durable       = (bool) ($options['durable'] ?? true);
        $this->storage  = new Storage($dataPath, $durable, $options['clave'] ?? null);
        $this->index    = new Index($this->storage);
        $this->vectores = new Vectores($this->storage, $options['embedder'] ?? null);

        // Antes de que nadie lea: si un corte dejo una transaccion a medias, se
        // termina o se descarta ahora. Leer un estado a medio aplicar seria el
        // peor momento para enterarse.
        if (($options['recuperar'] ?? true) !== false) {
            $this->recuperar();
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
        $unicos  = $this->storage->unicosDe($collection);
        $esquema = $this->storage->esquemaDe($collection);

        $before = $fields === [] && $unicos === [] && $esquema === []
            ? null
            : $this->storage->get($collection, $id);

        // El esquema, lo primero: si el documento no vale, mejor enterarse
        // antes de reservar nada y antes de tocar el disco.
        [$data, $replace] = $this->aplicarEsquema($collection, $id, $data, $before, $replace);

        // Reservar antes de escribir, y soltar si la escritura no sale. Ver
        // Unicidad: hacerlo al reves obligaria a deshacer un documento guardado.
        $reserva = new Unicidad($this->index, $collection, $id);
        if ($unicos !== []) {
            $reserva->reservar($unicos, $replace || $before === null ? $data : $data + $before, $before);
        }

        try {
            $after = $this->storage->put($collection, $id, $data, $replace);
        } catch (\Throwable $e) {
            $reserva->soltar();
            throw $e;
        }

        if ($fields !== []) {
            $this->index->sync($collection, $fields, $before, $after);
        }
        $this->vectores->alGuardar($collection, $id, $after);
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
        if ($ok) {
            $this->vectores->alBorrar($collection, $id);
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
        $tx = $this->abierta();

        return new Query(
            $this->storage,
            $this->index,
            $collection,
            $tx === null ? null : static fn(): array => $tx->all($collection)
        );
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
