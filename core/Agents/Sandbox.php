<?php
/**
 * AxiDB - Agentes\Sandbox: que puede tocar un agente.
 *
 * Un agente es un programa que decide solo lo que hace, y eso cambia el
 * planteamiento de la seguridad. Con una aplicacion normal, el codigo esta
 * escrito y se revisa: sabes que va a hacer. Con un agente, no; lo decide un
 * modelo en el momento, a partir de un texto que puede venir de un usuario.
 *
 * Asi que aqui no se pregunta "quien eres" sino **"que tienes permitido"**, y la
 * respuesta se declara por adelantado y por escrito:
 *
 *   $sandbox = new Sandbox(['get', 'find'], ['articulos', 'etiquetas']);
 *
 * Lo que no este en la lista, no pasa. No hay comodin implicito, no hay "por
 * defecto todo": una lista vacia de operaciones significa que no puede hacer
 * nada, no que puede hacerlo todo. Ese detalle es la diferencia entre un
 * descuido y una fuga.
 */

declare(strict_types=1);

namespace Axi\Core\Agents;

use Axi\Core\Exception;

final class Sandbox
{
    /** Todo lo que un agente puede llegar a pedir. Fuera de aqui, no existe. */
    public const OPERACIONES = [
        'get', 'exists', 'find', 'count', 'ids', 'all', 'similar',
        'insert', 'update', 'delete', 'sql',
    ];

    /** Las que modifican datos. Se distinguen para poder dar solo lectura. */
    private const ESCRITURAS = ['insert', 'update', 'delete'];

    /** @var list<string> */
    private array $operaciones;

    /** @var list<string>|null null = cualquier coleccion */
    private ?array $colecciones;

    /**
     * @param list<string>      $operaciones
     * @param list<string>|null $colecciones null para no limitar por coleccion
     */
    public function __construct(array $operaciones, ?array $colecciones = null)
    {
        foreach ($operaciones as $op) {
            if (!\in_array($op, self::OPERACIONES, true)) {
                throw new Exception(
                    "Sandbox: '{$op}' is not an operation. Available: "
                    . \implode(', ', self::OPERACIONES) . '.'
                );
            }
        }
        $this->operaciones = \array_values(\array_unique($operaciones));
        $this->colecciones = $colecciones === null ? null : \array_values($colecciones);
    }

    /** Solo lectura sobre las colecciones indicadas. El caso mas comun. */
    public static function soloLectura(?array $colecciones = null): self
    {
        return new self(['get', 'exists', 'find', 'count', 'ids', 'similar'], $colecciones);
    }

    /**
     * Comprueba y, si no puede, lanza. No devuelve un booleano a proposito:
     * un permiso que se puede ignorar por olvidar un `if` no es un permiso.
     *
     * @throws NotAllowed
     */
    public function requireOp(string $operacion, ?string $coleccion): void
    {
        if (!\in_array($operacion, $this->operaciones, true)) {
            throw new NotAllowed(
                "This agent cannot '{$operacion}'. It is allowed: "
                . ($this->operaciones === [] ? 'nada' : \implode(', ', $this->operaciones)) . '.'
            );
        }
        if ($coleccion !== null && $this->colecciones !== null
            && !\in_array($coleccion, $this->colecciones, true)) {
            throw new NotAllowed(
                "This agent does not reach '{$coleccion}'. It is allowed: "
                . \implode(', ', $this->colecciones) . '.'
            );
        }
    }

    public function isWrite(string $operacion): bool
    {
        return \in_array($operacion, self::ESCRITURAS, true);
    }

    /**
     * Las sentencias SQL se analizan antes de ejecutarse: la operacion de
     * verdad la dice el AST, no la palabra 'sql'. Sin esto, un agente de solo
     * lectura borraria una coleccion entera con un DELETE.
     */
    public function requireSqlOp(string $tipo, ?string $coleccion): void
    {
        $equivalente = match ($tipo) {
            'select', 'count'                                            => 'find',
            'insert'                                                     => 'insert',
            'update'                                                     => 'update',
            'delete', 'drop_collection', 'drop_index'                    => 'delete',
            'create_collection', 'create_index'                          => 'update',
            default                                                      => 'sql',
        };
        $this->requireOp($equivalente, $coleccion);
    }

    /** @return array{operaciones: list<string>, colecciones: list<string>|null} */
    public function summary(): array
    {
        return ['operaciones' => $this->operaciones, 'colecciones' => $this->colecciones];
    }
}
