<?php
/**
 * AxiDB - Agentes\Agent: la base de datos, vista por un programa autonomo.
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

namespace Axi\Core\Agents;

use Axi\Core\Db;
use Axi\Core\Query;

final class Agent
{
    public function __construct(
        private string $nombre,
        private Db $db,
        private Sandbox $sandbox,
        private Audit $auditoria,
        private string $dirParadas
    ) {
    }

    public function actorName(): string
    {
        return 'agent:' . $this->nombre;
    }

    /* ─────────────────────────── Interruptor de parada ─────────────────────── */

    /**
     * Detiene el agente y devuelve un TESTIGO. Reanudarlo exige ese testigo.
     *
     * El boton de parada no puede ser voluntario: el agente ejecuta lo que le
     * dice un modelo, y si el mismo objeto que obedece pudiera levantar su propia
     * parada, el boton no serviria de nada. Quien detiene —el operador— se queda
     * con el testigo; el agente no lo tiene, asi que no puede reanudarse solo.
     */
    public function stop(string $motivo = ''): string
    {
        @\mkdir($this->dirParadas, 0755, true);
        $testigo = \bin2hex(\random_bytes(16));
        @\file_put_contents($this->stopFile(), (string) \json_encode([
            'ts'      => \date('c'),
            'motivo'  => $motivo,
            'testigo' => $testigo,
        ]));
        $this->auditoria->record($this->actorName(), 'detener', null, null, true, $motivo ?: null);
        return $testigo;
    }

    /**
     * Reanuda, solo con el testigo que devolvio stop(). Sin el —o con uno que no
     * cuadra— no hace nada y el agente sigue parado. Asi "reanudar" es siempre una
     * decision deliberada de quien tiene el control, no un efecto que el agente
     * pueda provocar.
     */
    public function resume(string $testigo = ''): bool
    {
        $parada = \json_decode((string) @\file_get_contents($this->stopFile()), true);
        $esperado = \is_array($parada) ? (string) ($parada['testigo'] ?? '') : '';
        if ($esperado === '' || !\hash_equals($esperado, $testigo)) {
            return false;
        }
        @\unlink($this->stopFile());
        $this->auditoria->record($this->actorName(), 'reanudar', null, null, true);
        return true;
    }

    public function isStopped(): bool
    {
        return \is_file($this->stopFile());
    }

    /* ─────────────────────────────── Operaciones ───────────────────────────── */

    public function get(string $coleccion, string $id): ?array
    {
        return $this->build('get', $coleccion, $id, fn() => $this->db->get($coleccion, $id));
    }

    public function exists(string $coleccion, string $id): bool
    {
        return $this->build('exists', $coleccion, $id, fn() => $this->db->exists($coleccion, $id));
    }

    public function find(string $coleccion): Query
    {
        return $this->build('find', $coleccion, null, fn() => $this->db->find($coleccion)
            // El JOIN del constructor tambien pasa por el sandbox: sin esto, un
            // agente limitado a 'publica' se llevaba 'secreta' con un
            // find('publica')->join('secreta'), que solo consultaba el perfil.
            ->withJoinGuard(fn(string $c) => $this->sandbox->requireOp('find', $c)));
    }

    public function count(string $coleccion): int
    {
        return $this->build('count', $coleccion, null, fn() => $this->db->count($coleccion));
    }

    public function ids(string $coleccion): array
    {
        return $this->build('ids', $coleccion, null, fn() => $this->db->ids($coleccion));
    }

    public function all(string $coleccion): array
    {
        return $this->build('all', $coleccion, null, fn() => $this->db->all($coleccion));
    }

    public function similar(string $coleccion, string|array $consulta, int $k = 10, ?Query $donde = null): array
    {
        return $this->build(
            'similar',
            $coleccion,
            null,
            fn() => $this->db->similar($coleccion, $consulta, $k, $donde)
        );
    }

    public function insert(string $coleccion, array $datos, ?string $id = null): array
    {
        return $this->build('insert', $coleccion, $id, fn() => $this->db->insert($coleccion, $datos, $id));
    }

    public function update(string $coleccion, string $id, array $datos, bool $reemplazar = false): array
    {
        return $this->build(
            'update',
            $coleccion,
            $id,
            fn() => $this->db->update($coleccion, $id, $datos, $reemplazar)
        );
    }

    public function delete(string $coleccion, string $id): bool
    {
        return $this->build('delete', $coleccion, $id, fn() => $this->db->delete($coleccion, $id));
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

        // TODAS las colecciones que toca la sentencia, no solo el FROM. Un agente
        // limitado a 'publica' llegaba a 'secreta' con un JOIN o una subconsulta,
        // porque aqui solo se miraba $ast['collection']. Y el rastro mentia:
        // anotaba "find sobre publica", la que no leyo. Se comprueba el permiso
        // sobre cada una; si una lista vacia (accion sin colecciones, como SHOW),
        // se comprueba la operacion sin coleccion.
        $tocadas = \Axi\Core\Sql\Reach::collections($ast) ?: [$col];

        $this->guard();
        try {
            foreach ($tocadas as $c) {
                $this->sandbox->requireSqlOp($tipo, $c === '' ? null : $c);
            }
        } catch (NotAllowed $e) {
            $this->auditoria->record($this->actorName(), 'sql:' . $tipo, $col, null, false, $e->getMessage());
            throw $e;
        }

        $resultado = (new \Axi\Core\Sql\Executor($this->db))->run($ast);
        $this->auditoria->record($this->actorName(), 'sql:' . $tipo, $col, null, true);
        return $resultado;
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /**
     * El unico camino por el que pasa todo: comprobar, ejecutar, anotar.
     *
     * Que sea uno solo es lo que hace que no se pueda olvidar la auditoria en
     * una operacion nueva; para añadirla hay que pasar por aqui.
     */
    private function build(string $operacion, ?string $coleccion, ?string $id, callable $tarea): mixed
    {
        $this->guard();
        try {
            $this->sandbox->requireOp($operacion, $coleccion);
        } catch (NotAllowed $e) {
            $this->auditoria->record($this->actorName(), $operacion, $coleccion, $id, false, $e->getMessage());
            throw $e;
        }

        try {
            $resultado = $tarea();
        } catch (\Throwable $e) {
            $this->auditoria->record($this->actorName(), $operacion, $coleccion, $id, false, $e->getMessage());
            throw $e;
        }

        $this->auditoria->record($this->actorName(), $operacion, $coleccion, $id, true);
        return $resultado;
    }

    private function guard(): void
    {
        if ($this->isStopped()) {
            $parada = \json_decode((string) @\file_get_contents($this->stopFile()), true);
            $motivo = \is_array($parada) && !empty($parada['motivo']) ? " Motivo: {$parada['motivo']}" : '';
            throw new NotAllowed("El agente '{$this->nombre}' esta detenido.{$motivo}");
        }
    }

    private function stopFile(): string
    {
        // El nombre del archivo sale de un hash del nombre completo, no de
        // sustituir lo raro por guiones bajos. Con preg_replace, 'bot.uno' y
        // 'bot_uno' producian el mismo archivo: detener a uno detenia al otro, y
        // reanudar al inocente soltaba al peligroso. Un hash separa cualquier par
        // de nombres distintos.
        return $this->dirParadas . '/' . \sha1($this->nombre) . '.stop';
    }
}
