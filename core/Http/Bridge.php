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

    public function handle(Request $p): Response
    {
        $accion = $p->textOf('accion');
        if ($accion === null || !\in_array($accion, self::ACCIONES, true)) {
            return Response::mal(400, 'Accion desconocida. Admitidas: ' . \implode(', ', self::ACCIONES) . '.');
        }

        $ast = $accion === 'sql' ? $this->analyse($p) : null;

        $coleccion = $ast !== null ? (string) ($ast['collection'] ?? '') : (string) $p->textOf('coleccion');
        $escribe   = $ast !== null ? self::sqlWrites($ast) : Permissions::isWrite($accion);

        if ($coleccion === '') {
            return Response::mal(400, "Falta 'coleccion'.");
        }

        // Se decide el permiso sobre TODAS las colecciones que toca la sentencia,
        // no solo la del FROM. Antes se miraba unicamente $ast['collection']: un
        // token con acceso a 'pedidos' leia 'usuarios' con un JOIN, y un anonimo
        // sobre una coleccion publica interrogaba cualquier otra con una
        // subconsulta. El FROM era una puerta con el cerrojo puesto y la ventana
        // de al lado abierta.
        foreach (self::collectionsOf($ast, $coleccion) as $c) {
            [$vale, $codigo, $motivo] = $this->permisos->decide($escribe, $c, $p->token, $p->esLocal);
            if (!$vale) {
                return Response::mal($codigo, $motivo);
            }
        }

        return Response::bien($this->execute($accion, $coleccion, $p, $ast));
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /**
     * Analiza la sentencia una sola vez: el AST que decide los permisos es
     * exactamente el que despues se ejecuta.
     *
     * @throws BadRequest
     */
    private function analyse(Request $p): array
    {
        $sentencia = $p->textOf('sentencia');
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

    private static function sqlWrites(array $ast): bool
    {
        if (!empty($ast['explain'])) {
            return false;
        }
        return !\in_array($ast['type'] ?? '', self::SQL_LECTURA, true);
    }

    /**
     * TODAS las colecciones que toca una sentencia: FROM, JOINs y subconsultas.
     * Para una accion que no es SQL, solo la coleccion pedida. La recoleccion
     * vive en Sql\Reach, que la comparte con el sandbox de los agentes.
     *
     * @return list<string>
     */
    private static function collectionsOf(?array $ast, string $coleccionSimple): array
    {
        if ($ast === null) {
            return $coleccionSimple === '' ? [] : [$coleccionSimple];
        }
        return \Axi\Core\Sql\Reach::collections($ast);
    }

    private function execute(string $accion, string $coleccion, Request $p, ?array $ast): mixed
    {
        return match ($accion) {
            'insert' => $this->db->insert($coleccion, $this->dataOf($p), $p->textOf('id')),
            'get'    => $this->db->get($coleccion, $this->id($p)),
            'update' => $this->db->update(
                $coleccion,
                $this->id($p),
                $this->dataOf($p),
                (bool) ($p->cuerpo['reemplazar'] ?? false)
            ),
            'delete' => $this->db->delete($coleccion, $this->id($p)),
            'find'   => $this->query($coleccion, $p)->get(),
            'count'  => $this->query($coleccion, $p)->count(),
            'sql'    => (new Executor($this->db))->run((array) $ast),
        };
    }

    private function query(string $coleccion, Request $p): Query
    {
        $q = $this->db->find($coleccion);

        foreach ((array) ($p->cuerpo['donde'] ?? []) as $condicion) {
            if (!\is_array($condicion) || \count($condicion) < 2) {
                throw new BadRequest("Each condition of 'donde' es [campo, operador, valor].");
            }
            // El campo y el operador tienen que ser texto. Antes se hacia
            // `(string) $condicion[0]` a ciegas: un array ahi disparaba un
            // "Array to string conversion" que PHP imprime ANTES del JSON, con la
            // ruta y la linea del archivo —un mapa para quien esta hurgando— y
            // ademas rompia la respuesta para todo cliente. Se comprueba el tipo
            // y se contesta 400 explicando la forma correcta.
            $campo = self::asField($condicion[0], "el campo de una condicion de 'donde'");
            $op    = self::asField($condicion[1], "el operador de una condicion de 'donde'");
            $q->where($campo, $op, $condicion[2] ?? null);
        }

        $orden = $p->cuerpo['orden'] ?? null;
        if (\is_array($orden) && isset($orden[0])) {
            $q->orderBy(self::asField($orden[0], "el campo de 'orden'"), (string) ($orden[1] ?? 'asc'));
        }
        if (isset($p->cuerpo['limite'])) {
            $q->limit((int) $p->cuerpo['limite']);
        }
        if (isset($p->cuerpo['salto'])) {
            $q->offset((int) $p->cuerpo['salto']);
        }
        if (isset($p->cuerpo['campos']) && \is_array($p->cuerpo['campos'])) {
            $q->select(\array_map(
                static fn($c): string => self::asField($c, "un nombre de campo de 'campos'"),
                $p->cuerpo['campos']
            ));
        }
        return $q;
    }

    /**
     * Un valor que va a usarse como nombre de campo u operador: tiene que ser
     * texto o entero. Cualquier otra cosa —un array, un objeto— se rechaza con un
     * 400 claro, en vez de dejar que `(string)` genere un aviso de PHP.
     */
    private static function asField(mixed $v, string $que): string
    {
        if (!\is_string($v) && !\is_int($v)) {
            throw new BadRequest("AxiDB: {$que} debe ser texto, no " . \get_debug_type($v) . '.');
        }
        return (string) $v;
    }

    private function id(Request $p): string
    {
        $id = $p->textOf('id');
        if ($id === null || $id === '') {
            throw new BadRequest("Falta 'id'.");
        }
        return $id;
    }

    private function dataOf(Request $p): array
    {
        $datos = $p->cuerpo['datos'] ?? null;
        if (!\is_array($datos) || \array_is_list($datos)) {
            throw new BadRequest("'datos' debe ser un objeto JSON.");
        }
        return $datos;
    }
}
