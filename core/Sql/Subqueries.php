<?php
/**
 * AxiDB - Sql\Subqueries: resolver un SELECT que va dentro de otro.
 *
 *   WHERE cliente_id IN (SELECT id FROM clientes WHERE ciudad = 'Murcia')
 *   WHERE EXISTS (SELECT * FROM avisos WHERE grave = true)
 *
 * Se resuelven ANTES de filtrar y una sola vez, no por documento. Con mil
 * documentos, ejecutar la subconsulta dentro del bucle serian mil consultas
 * para obtener siempre lo mismo.
 *
 * **No hay subconsultas correlacionadas**, o sea las que miran el documento de
 * fuera: `WHERE EXISTS (SELECT * FROM lineas WHERE lineas.pedido = pedidos.id)`
 * no se puede escribir. Eso obliga a ejecutar la subconsulta una vez por
 * documento, y con un motor de archivos eso es una consulta completa por
 * documento. Se dice y se rechaza con un mensaje claro, en vez de aceptarlo y
 * que alguien descubra el coste en produccion.
 *
 * Para lo que se querria un EXISTS correlacionado, casi siempre sirve un JOIN.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Db;
use Axi\Core\Exception;

final class Subqueries
{
    public function __construct(private Db $db)
    {
    }

    /**
     * Recorre el arbol de condiciones y sustituye cada subconsulta por su
     * resultado ya calculado.
     */
    public function resolver(?array $expr): ?array
    {
        if ($expr === null) {
            return null;
        }
        return match ($expr['type'] ?? '') {
            'and', 'or' => [
                'type'  => $expr['type'],
                'left'  => $this->resolver($expr['left']),
                'right' => $this->resolver($expr['right']),
            ],
            'not'      => ['type' => 'not', 'expr' => $this->resolver($expr['expr'])],
            'subquery' => $this->existe($expr),
            'cmp'      => $this->enLista($expr),
            default    => $expr,
        };
    }

    /** `EXISTS (SELECT ...)`: se convierte en un si o un no, ya resuelto. */
    private function existe(array $nodo): array
    {
        $hay = $this->filas((string) $nodo['sql']) !== [];
        $vale = ($nodo['negado'] ?? false) ? !$hay : $hay;

        // Un nodo que siempre se cumple, o que nunca. Se escribe como una
        // comparacion trivial para no tener que enseñarle al evaluador un tipo
        // de nodo nuevo que solo sirve para esto.
        return ['type' => 'cmp', 'expr' => ['t' => 'lit', 'v' => 1], 'op' => '=',
                'value' => $vale ? 1 : 0];
    }

    /** `campo IN (SELECT ...)`: se cambia la subconsulta por la lista de valores. */
    private function enLista(array $nodo): array
    {
        $valor = $nodo['value'] ?? null;
        if (!\is_array($valor) || ($valor['subquery'] ?? null) === null) {
            return $nodo;
        }
        $nodo['value'] = $this->columna((string) $valor['subquery']);
        return $nodo;
    }

    /**
     * La primera columna de la subconsulta, que es lo que compara un IN.
     *
     * Si el SELECT pide varios campos se coge el primero, igual que en SQL. Lo
     * que no se hace es adivinar: si no devuelve ninguna columna, se dice.
     *
     * @return list<mixed>
     */
    private function columna(string $sql): array
    {
        $valores = [];
        foreach ($this->filas($sql) as $fila) {
            if ($fila === []) {
                throw new Exception(
                    'AxiSQL: la subconsulta de un IN tiene que devolver alguna columna.'
                );
            }
            $valores[] = \reset($fila);
        }
        return $valores;
    }

    /** @return list<array> */
    private function filas(string $sql): array
    {
        $r = $this->db->sql($sql);
        return \is_array($r) ? \array_values(\array_filter($r, 'is_array')) : [];
    }
}
