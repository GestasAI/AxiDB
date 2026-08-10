<?php
/**
 * AxiDB - Sql\Joins: la parte JOIN de un SELECT.
 *
 *   FROM pedidos p [INNER|LEFT] JOIN clientes c ON p.cli = c.id
 *
 * Aparte del Parser porque cruzar colecciones es un asunto propio y porque
 * juntos pasaban del limite de tamano del proyecto.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class Joins
{
    public function __construct(private TokenStream $ts)
    {
    }

    /**
     * El alias opcional de una coleccion: `FROM pedidos p`.
     *
     * Sin alias, el prefijo es el nombre de la coleccion.
     */
    public function alias(string $coleccion): string
    {
        $this->ts->matchKw('AS');

        return $this->ts->peek()->type === Token::IDENT
            ? $this->ts->consumeIdent()
            : $coleccion;
    }

    /**
     * Cero o mas uniones: `[INNER|LEFT] JOIN <coleccion> [alias] ON a.x = b.y`.
     *
     * @return list<array{coleccion:string, alias:string, tipo:string, izq:string, der:string}>
     */
    public function parse(): array
    {
        $uniones = [];

        while (true) {
            $tipo = JoinPlan::INTERNO;
            if ($this->ts->matchKw('LEFT')) {
                $tipo = JoinPlan::IZQUIERDO;
            } else {
                $this->ts->matchKw('INNER');
            }
            if (!$this->ts->matchKw('JOIN')) {
                if ($tipo === JoinPlan::IZQUIERDO) {
                    throw new Exception('AxiSQL: after LEFT expected JOIN.');
                }
                return $uniones;
            }
            $uniones[] = $this->unaUnion($tipo);
        }
    }

    /** @return array{coleccion:string, alias:string, tipo:string, izq:string, der:string} */
    private function unaUnion(string $tipo): array
    {
        $coleccion = $this->ts->consumeIdent();
        $alias     = $this->alias($coleccion);

        $this->ts->consumeKw('ON');
        $izq = $this->ts->consumeIdent();

        if (!$this->ts->peek()->isOp('=')) {
            throw new Exception(
                "AxiSQL: the ON of a JOIN compares two fields with '='; encontro "
                . $this->ts->peek()->describe() . '.'
            );
        }
        $this->ts->advance();
        $der = $this->ts->consumeIdent();

        // `ON a.x = b.y` y `ON b.y = a.x` dicen lo mismo. Se ordena aqui para
        // que el ejecutor no tenga que volver a preguntarselo.
        $derechaEsLaUnida = \str_starts_with($der, $alias . '.');

        return [
            'coleccion' => $coleccion,
            'alias'     => $alias,
            'tipo'      => $tipo,
            'izq'       => $derechaEsLaUnida ? $izq : $der,
            'der'       => $derechaEsLaUnida ? $der : $izq,
        ];
    }
}
