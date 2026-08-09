<?php
/**
 * AxiDB - Http\Bridge: de una accion JSON a una llamada al motor.
 *
 * Siete acciones y ni una mas. No es una limitacion tecnica: cada cosa que se
 * expone por HTTP es superficie que hay que defender para siempre. Lo que no
 * este aqui, no se puede pedir desde fuera.
 *
 * `sql` se analiza antes de ejecutarse para saber sobre que coleccion actua y
 * si escribe. Asi el ambito de un token vale tambien para SQL, en vez de ser la
 * puerta trasera por la que un token de solo lectura borra una coleccion.
 */

declare(strict_types=1);

namespace Axi\Core\Http;

use Axi\Core\Db;
use Axi\Core\Query;
use Axi\Core\Sql\Executor;
use Axi\Core\Sql\Parser;

final class Bridge
{
    private const ACCIONES = ['insert', 'get', 'update', 'delete', 'find', 'count', 'sql'];

    /** Tipos de AxiSQL que no modifican nada. */
    private const SQL_LECTURA = ['select', 'count'];

    public function __construct(
        private Db $db,
        private Permissions $permisos
    ) {
    }

    public function atender(Request $p): Response
    {
        $accion = $p->texto('accion');
        if ($accion === null || !\in_array($accion, self::ACCIONES, true)) {
            return Response::mal(400, 'Accion desconocida. Admitidas: ' . \implode(', ', self::ACCIONES) . '.');
        }

        $ast = $accion === 'sql' ? $this->analizar($p) : null;

        $coleccion = $ast !== null ? (string) ($ast['collection'] ?? '') : (string) $p->texto('coleccion');
        $escribe   = $ast !== null ? self::sqlEscribe($ast) : Permissions::escribe($accion);

        if ($coleccion === '') {
            return Response::mal(400, "Falta 'coleccion'.");
        }

        [$vale, $codigo, $motivo] = $this->permisos->decide($escribe, $coleccion, $p->token, $p->esLocal);
        if (!$vale) {
            return Response::mal($codigo, $motivo);
        }

        return Response::bien($this->ejecutar($accion, $coleccion, $p, $ast));
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /**
     * Analiza la sentencia una sola vez: el AST que decide los permisos es
     * exactamente el que despues se ejecuta.
     *
     * @throws BadRequest
     */
    private function analizar(Request $p): array
    {
        $sentencia = $p->texto('sentencia');
        if ($sentencia === null || \trim($sentencia) === '') {
            throw new BadRequest("Falta 'sentencia'.");
        }
        try {
            return (new Parser())->parse($sentencia);
        } catch (\Axi\Core\Exception $e) {
            // Una sentencia mal escrita es culpa de quien la manda, no del
            // motor: 400 con el motivo del analizador, que dice exactamente
            // que esperaba y que encontro. Devolver 500 aqui seria mentir.
            throw new BadRequest($e->getMessage());
        }
    }

    private static function sqlEscribe(array $ast): bool
    {
        if (!empty($ast['explain'])) {
            return false;
        }
        return !\in_array($ast['type'] ?? '', self::SQL_LECTURA, true);
    }

    private function ejecutar(string $accion, string $coleccion, Request $p, ?array $ast): mixed
    {
        return match ($accion) {
            'insert' => $this->db->insert($coleccion, $this->datos($p), $p->texto('id')),
            'get'    => $this->db->get($coleccion, $this->id($p)),
            'update' => $this->db->update(
                $coleccion,
                $this->id($p),
                $this->datos($p),
                (bool) ($p->cuerpo['reemplazar'] ?? false)
            ),
            'delete' => $this->db->delete($coleccion, $this->id($p)),
            'find'   => $this->consulta($coleccion, $p)->get(),
            'count'  => $this->consulta($coleccion, $p)->count(),
            'sql'    => (new Executor($this->db))->run((array) $ast),
        };
    }

    private function consulta(string $coleccion, Request $p): Query
    {
        $q = $this->db->find($coleccion);

        foreach ((array) ($p->cuerpo['donde'] ?? []) as $condicion) {
            if (!\is_array($condicion) || \count($condicion) < 2) {
                throw new BadRequest("Cada condicion de 'donde' es [campo, operador, valor].");
            }
            $q->where((string) $condicion[0], (string) $condicion[1], $condicion[2] ?? null);
        }

        $orden = $p->cuerpo['orden'] ?? null;
        if (\is_array($orden) && isset($orden[0])) {
            $q->orderBy((string) $orden[0], (string) ($orden[1] ?? 'asc'));
        }
        if (isset($p->cuerpo['limite'])) {
            $q->limit((int) $p->cuerpo['limite']);
        }
        if (isset($p->cuerpo['salto'])) {
            $q->offset((int) $p->cuerpo['salto']);
        }
        if (isset($p->cuerpo['campos']) && \is_array($p->cuerpo['campos'])) {
            $q->select(\array_map('strval', $p->cuerpo['campos']));
        }
        return $q;
    }

    private function id(Request $p): string
    {
        $id = $p->texto('id');
        if ($id === null || $id === '') {
            throw new BadRequest("Falta 'id'.");
        }
        return $id;
    }

    private function datos(Request $p): array
    {
        $datos = $p->cuerpo['datos'] ?? null;
        if (!\is_array($datos) || \array_is_list($datos)) {
            throw new BadRequest("'datos' debe ser un objeto JSON.");
        }
        return $datos;
    }
}
