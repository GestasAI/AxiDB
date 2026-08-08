<?php
/**
 * AxiDB - Sql\ExprParser: la sub-gramatica del WHERE.
 *
 * Precedencia, de menor a mayor: OR, AND, NOT, comparacion, parentesis.
 * Produce un arbol de nodos {type: and|or|not|cmp} que evalua Evaluator y que
 * Query sabe recorrer para decidir si puede apoyarse en un indice.
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
        if ($this->ts->matchPunct('(')) {
            $dentro = $this->parseOr();
            $this->ts->consumePunct(')');
            return $dentro;
        }

        $campo = $this->ts->consumeIdent();

        if ($this->ts->matchKw('IS')) {
            $negado = $this->ts->matchKw('NOT');
            $this->ts->consumeKw('NULL');
            return $this->cmp($campo, $negado ? 'IS NOT NULL' : 'IS NULL', null);
        }

        if ($this->ts->matchKw('NOT')) {
            $this->ts->consumeKw('IN');
            return $this->cmp($campo, 'NOT IN', $this->parseLista());
        }

        if ($this->ts->matchKw('IN')) {
            return $this->cmp($campo, 'IN', $this->parseLista());
        }

        if ($this->ts->matchKw('LIKE')) {
            return $this->cmp($campo, 'LIKE', $this->ts->consumeLiteral());
        }

        if ($this->ts->matchKw('CONTAINS')) {
            return $this->cmp($campo, 'CONTAINS', $this->ts->consumeLiteral());
        }

        $tk = $this->ts->peek();
        if ($tk->type === Token::OP) {
            $this->ts->advance();
            return $this->cmp($campo, (string) $tk->value, $this->ts->consumeLiteral());
        }

        throw new Exception(
            "AxiSQL: tras '{$campo}' esperaba un operador "
            . '(=, !=, >, <, >=, <=, IN, NOT IN, LIKE, CONTAINS, IS NULL, IS NOT NULL) '
            . "y encontro {$tk->describe()}."
        );
    }

    /** Lista entre parentesis de IN / NOT IN. */
    private function parseLista(): array
    {
        $this->ts->consumePunct('(');
        $valores = [$this->ts->consumeLiteral()];
        while ($this->ts->matchPunct(',')) {
            $valores[] = $this->ts->consumeLiteral();
        }
        $this->ts->consumePunct(')');
        return $valores;
    }

    private function cmp(string $campo, string $op, mixed $valor): array
    {
        return ['type' => 'cmp', 'field' => $campo, 'op' => $op, 'value' => $valor];
    }
}
