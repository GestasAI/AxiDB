<?php
/**
 * AxiDB - Tx\Transaction: lo que ve el codigo dentro de una transaccion.
 *
 * Nada de lo que se escribe aqui toca el disco hasta confirmar. Se acumula, y
 * al final se aplica entero o no se aplica nada.
 *
 * Lee lo que tu mismo has escrito. Sin eso, un `update` seguido de un `get`
 * devolveria el valor viejo dentro de la propia transaccion y no habria forma
 * de encadenar dos pasos sobre el mismo documento.
 *
 * Se apunta la version de todo lo que se lee o se escribe. Al confirmar se
 * comprueba que no haya cambiado por debajo: sin eso, dos transacciones que
 * leen el mismo stock y restan tres cada una dejarian el stock con tres menos
 * en vez de seis, y sin un solo error. Ver Confirmacion.
 */

declare(strict_types=1);

namespace Axi\Core\Tx;

use Axi\Core\Db;
use Axi\Core\Exception;

final class Transaction
{
    /** @var array<string, array{coleccion:string, id:string, accion:string, datos:array}> */
    private array $pendiente = [];

    /** @var array<string, int|null> version que tenia cada documento al mirarlo */
    private array $vistos = [];

    public function __construct(private Db $db)
    {
    }

    /* ─────────────────────────────── Lectura ─────────────────────────────── */

    public function get(string $coleccion, string $id): ?array
    {
        $clave    = self::keyOf($coleccion, $id);
        $guardado = $this->db->get($coleccion, $id);
        $this->vistos[$clave] ??= $guardado === null ? null : (int) ($guardado['_version'] ?? 0);

        if (!isset($this->pendiente[$clave])) {
            return $guardado;
        }
        $op = $this->pendiente[$clave];
        if ($op['accion'] === 'borrar') {
            return null;
        }
        return ['id' => $id] + $op['datos'] + ($guardado ?? []);
    }

    public function exists(string $coleccion, string $id): bool
    {
        return $this->get($coleccion, $id) !== null;
    }

    /**
     * Todos los documentos, con lo pendiente ya aplicado por encima.
     *
     * @return list<array>
     */
    public function all(string $coleccion): array
    {
        $fuera = [];
        foreach ($this->db->all($coleccion) as $doc) {
            $id    = (string) ($doc['id'] ?? '');
            $clave = self::keyOf($coleccion, $id);
            if (isset($this->pendiente[$clave])) {
                continue;                       // se añade abajo, o esta borrado
            }
            $fuera[] = $doc;
        }
        foreach ($this->pendiente as $op) {
            if ($op['coleccion'] === $coleccion && $op['accion'] !== 'borrar') {
                $fuera[] = ['id' => $op['id']] + $op['datos'];
            }
        }
        return $fuera;
    }

    public function count(string $coleccion): int
    {
        return \count($this->all($coleccion));
    }

    /**
     * Consultas encadenables que SI ven lo que la transaccion lleva escrito.
     *
     * La primera version no tenia esto y la documentacion decia que filtrar
     * habia que hacerlo a mano. Estaba mal: una consulta que devuelve el estado
     * de antes es un resultado desfasado, y eso en una web es un fallo, no una
     * limitacion. Se resolvio dandole a Query una fuente alternativa de
     * documentos en vez de reimplementarla.
     */
    public function find(string $coleccion): \Axi\Core\Query
    {
        return $this->db->find($coleccion);
    }

    /* ─────────────────────────────── Escritura ─────────────────────────────── */

    public function insert(string $coleccion, array $datos, ?string $id = null): array
    {
        return $this->put($coleccion, $id ?? Db::newId(), $datos, true);
    }

    public function update(string $coleccion, string $id, array $datos, bool $replace = false): array
    {
        if (!$this->exists($coleccion, $id)) {
            throw new Exception("Tx: '{$coleccion}/{$id}' no existe.");
        }
        return $this->put($coleccion, $id, $datos, $replace);
    }

    /**
     * El documento que devuelve NO lleva `_version` ni `_updatedAt`: esos se
     * asignan al escribir de verdad, al confirmar. Dentro de la transaccion
     * todavia no hay una version, porque todavia no ha pasado nada.
     */
    public function put(string $coleccion, string $id, array $datos, bool $replace = false): array
    {
        $anterior = $this->get($coleccion, $id);            // apunta la version
        $carga    = $datos;

        if (!$replace && $anterior !== null) {
            $carga = $datos + self::withoutMeta($anterior);
        }
        if (isset($this->pendiente[self::keyOf($coleccion, $id)]) && !$replace) {
            $carga = $datos + $this->pendiente[self::keyOf($coleccion, $id)]['datos'];
        }

        $this->pendiente[self::keyOf($coleccion, $id)] = [
            'coleccion' => $coleccion,
            'id'        => $id,
            'accion'    => 'poner',
            'datos'     => self::withoutMeta($carga),
        ];
        return ['id' => $id] + self::withoutMeta($carga);
    }

    public function delete(string $coleccion, string $id): bool
    {
        if (!$this->exists($coleccion, $id)) {
            return false;
        }
        $this->pendiente[self::keyOf($coleccion, $id)] = [
            'coleccion' => $coleccion,
            'id'        => $id,
            'accion'    => 'borrar',
            'datos'     => [],
        ];
        return true;
    }

    /* ─────────────────────────────── Para el motor ─────────────────────────── */

    /** @return list<array{coleccion:string, id:string, accion:string, datos:array}> */
    public function operaciones(): array
    {
        return \array_values($this->pendiente);
    }

    /** @return array<string, int|null> */
    public function vistos(): array
    {
        return $this->vistos;
    }

    public function isEmpty(): bool
    {
        return $this->pendiente === [];
    }

    /** Si esta coleccion tiene cambios sin confirmar. Lo consulta AxiSQL. */
    public function hasPending(string $coleccion): bool
    {
        foreach ($this->pendiente as $op) {
            if ($op['coleccion'] === $coleccion) {
                return true;
            }
        }
        return false;
    }

    public static function keyOf(string $coleccion, string $id): string
    {
        return $coleccion . "\0" . $id;
    }

    /** Los metadatos los pone el motor al escribir; no viajan en el plan. */
    private static function withoutMeta(array $doc): array
    {
        unset($doc['id'], $doc['_version'], $doc['_createdAt'], $doc['_updatedAt']);
        return $doc;
    }
}
