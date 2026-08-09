<?php
/**
 * AxiDB - Sql\ExprParser: la sub-gramatica del WHERE y del HAVING.
 *
 * Precedencia, de menor a mayor: OR, AND, NOT, comparacion, parentesis.
 * Produce un arbol de nodos {type: and|or|not|cmp} que evalua Evaluator y que
 * el planificador sabe recorrer para decidir si puede apoyarse en un indice.
 *
 * Los dos lados de una comparacion pueden ser expresiones —`WHERE MES(fecha) = 3`,
 * `WHERE precio > coste * 2`— pero el caso corriente, campo contra literal, se
 * emite EXACTAMENTE con la forma de antes: `{field, op, value}`.
 *
 * Eso no es por compatibilidad, es por velocidad: el planificador solo sabe
 * usar un indice cuando ve un `field` con un valor al lado. Si toda comparacion
 * se convirtiera en un arbol, ninguna consulta volveria a usar un indice y todo
 * pasaria a recorrer la coleccion entera, sin que nadie se enterara.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class ExprParser
{
    public function __construct(private TokenStream $ts)
    {
    }

    public function parse(): array
    {
        return $this->parseOr();
    }

    private function parseOr(): array
    {
        $izq = $this->parseAnd();
        while ($this->ts->matchKw('OR')) {
            $izq = ['type' => 'or', 'left' => $izq, 'right' => $this->parseAnd()];
        }
        return $izq;
    }

    private function parseAnd(): array
    {
        $izq = $this->parseNot();
        while ($this->ts->matchKw('AND')) {
            $izq = ['type' => 'and', 'left' => $izq, 'right' => $this->parseNot()];
        }
        return $izq;
    }

    private function parseNot(): array
    {
        if ($this->ts->matchKw('NOT')) {
            return ['type' => 'not', 'expr' => $this->parseNot()];
        }
        return $this->parseCmp();
    }

    private function parseCmp(): array
    {
        // EXISTS (SELECT ...) y NOT EXISTS (SELECT ...)
        if ($this->ts->peek()->isKw('EXISTS')) {
            $this->ts->advance();
            return ['type' => 'subquery', 'negado' => false, 'sql' => Recorte::subconsulta($this->ts)];
        }

        if ($this->ts->peek()->isPunct('(') && $this->pareceCondicion()) {
            $this->ts->advance();
            $dentro = $this->parseOr();
            $this->ts->consumePunct(')');
            return $dentro;
        }

        $izq = (new ValorParser($this->ts))->parse();

        if ($this->ts->matchKw('IS')) {
            $negado = $this->ts->matchKw('NOT');
            $this->ts->consumeKw('NULL');
            return $this->cmp($izq, $negado ? 'IS NOT NULL' : 'IS NULL', null);
        }

        $negado = $this->ts->matchKw('NOT');

        if ($this->ts->matchKw('IN')) {
            return $this->cmp($izq, $negado ? 'NOT IN' : 'IN', $this->parseLista());
        }
        if ($this->ts->matchKw('LIKE')) {
            return $this->cmp($izq, $negado ? 'NOT LIKE' : 'LIKE', $this->ts->consumeLiteral());
        }
        if ($this->ts->matchKw('BETWEEN')) {
            return $this->cmp($izq, $negado ? 'NOT BETWEEN' : 'BETWEEN', $this->parseRango());
        }
        if ($this->ts->matchKw('CONTAINS')) {
            return $this->cmp($izq, $negado ? 'NOT CONTAINS' : 'CONTAINS', $this->ts->consumeLiteral());
        }
        if ($negado) {
            throw new Exception('AxiSQL: tras NOT se espera IN, LIKE, BETWEEN o CONTAINS.');
        }

        $tk = $this->ts->peek();
        if ($tk->type === Token::OP && \in_array($tk->value, ['=', '!=', '>', '>=', '<', '<='], true)) {
            $this->ts->advance();
            return $this->cmp($izq, (string) $tk->value, null, (new ValorParser($this->ts))->parse());
        }

        throw new Exception(
            'AxiSQL: tras ' . Valor::firma($izq) . ' esperaba un operador '
            . '(=, !=, >, <, >=, <=, IN, NOT IN, LIKE, NOT LIKE, BETWEEN, CONTAINS, '
            . "IS NULL, IS NOT NULL) y encontro {$tk->describe()}."
        );
    }

    /**
     * Si el parentesis que viene abre una condicion y no una expresion.
     *
     * `WHERE (a = 1 OR b = 2)` abre una condicion; `WHERE (precio + 1) > 5` abre
     * una cuenta. Se distinguen mirando por encima hasta el parentesis que
     * cierra: si dentro hay un operador de comparacion al primer nivel, es una
     * condicion. Sin esto, `(precio + 1) > 5` intentaria leerse como un WHERE
     * dentro de otro y no cuadraria.
     */
    private function pareceCondicion(): bool
    {
        $nivel = 0;
        for ($i = 0; $i < 200; $i++) {
            $tk = $this->ts->peek($i);
            if ($tk->type === Token::EOF) {
                return false;
            }
            if ($tk->isPunct('(')) {
                $nivel++;
                continue;
            }
            if ($tk->isPunct(')')) {
                if (--$nivel === 0) {
                    return false;
                }
                continue;
            }
            if ($nivel !== 1) {
                continue;
            }
            if ($tk->type === Token::OP
                && \in_array($tk->value, ['=', '!=', '>', '>=', '<', '<='], true)) {
                return true;
            }
            if ($tk->type === Token::KW
                && \in_array(\strtoupper((string) $tk->value), ['IS', 'IN', 'LIKE', 'BETWEEN', 'CONTAINS'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lista entre parentesis de IN / NOT IN, o una subconsulta.
     *
     *   IN ('a', 'b')
     *   IN (SELECT id FROM clientes WHERE ciudad = 'Murcia')
     */
    private function parseLista(): array
    {
        if ($this->ts->peek(1)->isKw('SELECT')) {
            return ['subquery' => Recorte::subconsulta($this->ts)];
        }
        $this->ts->consumePunct('(');
        $valores = [$this->ts->consumeLiteral()];
        while ($this->ts->matchPunct(',')) {
            $valores[] = $this->ts->consumeLiteral();
        }
        $this->ts->consumePunct(')');
        return $valores;
    }

    /**
     * `BETWEEN x AND y`. El AND de aqui es parte del operador, no el conector
     * booleano: se consume aqui mismo para que `a BETWEEN 1 AND 2 AND b = 3`
     * signifique lo que parece.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function parseRango(): array
    {
        $desde = $this->ts->consumeLiteral();
        $this->ts->consumeKw('AND');
        return [$desde, $this->ts->consumeLiteral()];
    }

    /**
     * Un nodo de comparacion.
     *
     * Si a la izquierda hay un campo pelado y a la derecha un literal, se emite
     * la forma antigua para que el planificador pueda usar un indice. En
     * cualquier otro caso, la forma con expresiones.
     */
    private function cmp(array $izq, string $op, mixed $valor, ?array $derExpr = null): array
    {
        $literal = $derExpr === null || ($derExpr['t'] ?? '') === 'lit';
        $derecha = $derExpr !== null && $literal ? $derExpr['v'] : $valor;

        if (($izq['t'] ?? '') === 'campo' && $literal) {
            return ['type' => 'cmp', 'field' => $izq['nombre'], 'op' => $op, 'value' => $derecha];
        }
        $nodo = ['type' => 'cmp', 'expr' => $izq, 'op' => $op, 'value' => $derecha];
        if (!$literal) {
            $nodo['valueExpr'] = $derExpr;
        }
        return $nodo;
    }
}
