<?php
/**
 * AxiDB - Agentes\Agente: la base de datos, vista por un programa autonomo.
 *
 * Tiene los mismos metodos que Db, con tres diferencias que no se pueden
 * esquivar porque no hay forma de llegar a Db desde aqui:
 *
 *   1. Cada operacion pasa por el sandbox antes de ejecutarse.
 *   2. Cada operacion queda anotada, con su actor, tambien si se rechazo.
 *   3. Si el agente esta detenido, no se ejecuta nada.
 *
 * El interruptor de parada vive en un ARCHIVO, no en memoria. Un agente en
 * marcha suele estar en otro proceso —una cola, un cron, una peticion larga— y
 * un booleano en memoria no lo pararia. Con un archivo, `detener()` desde
 * cualquier sitio se nota en la siguiente operacion que intente.
 */

declare(strict_types=1);

namespace Axi\Core\Agentes;

use Axi\Core\Db;
use Axi\Core\Query;

final class Agente
{
    public function __construct(
        private string $nombre,
        private Db $db,
        private Sandbox $sandbox,
        private Auditoria $auditoria,
        private string $dirParadas
    ) {
    }

    public function actor(): string
    {
        return 'agent:' . $this->nombre;
    }

    /* ─────────────────────────── Interruptor de parada ─────────────────────── */

    public function detener(string $motivo = ''): void
    {
        @\mkdir($this->dirParadas, 0755, true);
        @\file_put_contents($this->archivoParada(), (string) \json_encode([
            'ts'     => \date('c'),
            'motivo' => $motivo,
        ]));
        $this->auditoria->anotar($this->actor(), 'detener', null, null, true, $motivo ?: null);
    }

    public function reanudar(): void
    {
        @\unlink($this->archivoParada());
        $this->auditoria->anotar($this->actor(), 'reanudar', null, null, true);
    }

    public function detenido(): bool
    {
        return \is_file($this->archivoParada());
    }

    /* ─────────────────────────────── Operaciones ───────────────────────────── */

    public function get(string $coleccion, string $id): ?array
    {
        return $this->hacer('get', $coleccion, $id, fn() => $this->db->get($coleccion, $id));
    }

    public function exists(string $coleccion, string $id): bool
    {
        return $this->hacer('exists', $coleccion, $id, fn() => $this->db->exists($coleccion, $id));
    }

    public function find(string $coleccion): Query
    {
        return $this->hacer('find', $coleccion, null, fn() => $this->db->find($coleccion));
    }

    public function count(string $coleccion): int
    {
        return $this->hacer('count', $coleccion, null, fn() => $this->db->count($coleccion));
    }

    public function ids(string $coleccion): array
    {
        return $this->hacer('ids', $coleccion, null, fn() => $this->db->ids($coleccion));
    }

    public function all(string $coleccion): array
    {
        return $this->hacer('all', $coleccion, null, fn() => $this->db->all($coleccion));
    }

    public function similar(string $coleccion, string|array $consulta, int $k = 10, ?Query $donde = null): array
    {
        return $this->hacer(
            'similar',
            $coleccion,
            null,
            fn() => $this->db->similar($coleccion, $consulta, $k, $donde)
        );
    }

    public function insert(string $coleccion, array $datos, ?string $id = null): array
    {
        return $this->hacer('insert', $coleccion, $id, fn() => $this->db->insert($coleccion, $datos, $id));
    }

    public function update(string $coleccion, string $id, array $datos, bool $reemplazar = false): array
    {
        return $this->hacer(
            'update',
            $coleccion,
            $id,
            fn() => $this->db->update($coleccion, $id, $datos, $reemplazar)
        );
    }

    public function delete(string $coleccion, string $id): bool
    {
        return $this->hacer('delete', $coleccion, $id, fn() => $this->db->delete($coleccion, $id));
    }

    /**
     * SQL, con la sentencia analizada antes de decidir si se permite.
     *
     * Mirar solo la palabra 'sql' seria dejar la puerta abierta: un agente de
     * solo lectura podria mandar un DELETE. Se analiza, se comprueba el tipo
     * real y la coleccion real, y solo entonces se ejecuta.
     */
    public function sql(string $sentencia): mixed
    {
        $ast = (new \Axi\Core\Sql\Parser())->parse($sentencia);
        $tipo = (string) ($ast['type'] ?? 'sql');
        $col  = isset($ast['collection']) ? (string) $ast['collection'] : null;

        $this->guardia();
        try {
            $this->sandbox->exigirSql($tipo, $col);
        } catch (NoPermitido $e) {
            $this->auditoria->anotar($this->actor(), 'sql:' . $tipo, $col, null, false, $e->getMessage());
            throw $e;
        }

        $resultado = (new \Axi\Core\Sql\Executor($this->db))->run($ast);
        $this->auditoria->anotar($this->actor(), 'sql:' . $tipo, $col, null, true);
        return $resultado;
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /**
     * El unico camino por el que pasa todo: comprobar, ejecutar, anotar.
     *
     * Que sea uno solo es lo que hace que no se pueda olvidar la auditoria en
     * una operacion nueva; para añadirla hay que pasar por aqui.
     */
    private function hacer(string $operacion, ?string $coleccion, ?string $id, callable $tarea): mixed
    {
        $this->guardia();
        try {
            $this->sandbox->exigir($operacion, $coleccion);
        } catch (NoPermitido $e) {
            $this->auditoria->anotar($this->actor(), $operacion, $coleccion, $id, false, $e->getMessage());
            throw $e;
        }

        try {
            $resultado = $tarea();
        } catch (\Throwable $e) {
            $this->auditoria->anotar($this->actor(), $operacion, $coleccion, $id, false, $e->getMessage());
            throw $e;
        }

        $this->auditoria->anotar($this->actor(), $operacion, $coleccion, $id, true);
        return $resultado;
    }

    private function guardia(): void
    {
        if ($this->detenido()) {
            $parada = \json_decode((string) @\file_get_contents($this->archivoParada()), true);
            $motivo = \is_array($parada) && !empty($parada['motivo']) ? " Motivo: {$parada['motivo']}" : '';
            throw new NoPermitido("El agente '{$this->nombre}' esta detenido.{$motivo}");
        }
    }

    private function archivoParada(): string
    {
        return $this->dirParadas . '/' . \preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->nombre) . '.stop';
    }
}
