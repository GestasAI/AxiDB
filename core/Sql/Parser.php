<?php
/**
 * AxiDB - Sql\Parser: de tokens a arbol de sintaxis.
 *
 * Descenso recursivo. Cada sentencia produce un array con 'type' y sus campos.
 * Las expresiones WHERE las resuelve ExprParser y el DDL, DdlParser: los tres
 * comparten el mismo TokenStream, asi que el cursor nunca se desincroniza.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class Parser
{
    private TokenStream $ts;

    public function parse(string $sql): array
    {
        $this->ts = new TokenStream((new Lexer())->tokenize($sql));

        if ($this->ts->atEof()) {
            throw new Exception('AxiSQL: sentencia vacia.');
        }

        // EXPLAIN <sentencia>: se analiza igual y se marca para no ejecutar.
        $explicar = $this->ts->matchKw('EXPLAIN');

        $cabeza = $this->ts->peek();
        if ($cabeza->type !== Token::KW) {
            throw new Exception(
                "AxiSQL: una sentencia empieza por SELECT, INSERT, UPDATE, DELETE, CREATE o DROP; "
                . "encontro {$cabeza->describe()}."
            );
        }

        $ast = match (\strtoupper((string) $cabeza->value)) {
            'SELECT' => $this->parseSelect(),
            'INSERT' => $this->parseInsert(),
            'UPDATE' => $this->parseUpdate(),
            'DELETE' => $this->parseDelete(),
            'CREATE', 'DROP' => (new DdlParser($this->ts))->parse(),
            default  => throw new Exception(
                "AxiSQL: no se puede empezar una sentencia por {$cabeza->describe()}."
            ),
        };

        $this->ts->matchPunct(';');
        $this->ts->expectEof();

        $ast['explain'] = $explicar;
        return $ast;
    }

    private function parseSelect(): array
    {
        $this->ts->consumeKw('SELECT');

        $esCount = false;
        if ($this->ts->matchKw('COUNT')) {
            $this->ts->consumePunct('(');
            $this->ts->consumePunct('*');
            $this->ts->consumePunct(')');
            $esCount = true;
            $campos  = ['*'];
        } else {
            $campos = $this->parseListaCampos();
        }

        $this->ts->consumeKw('FROM');

        $ast = [
            'type'       => $esCount ? 'count' : 'select',
            'collection' => $this->ts->consumeIdent(),
            'fields'     => $campos,
            'where_expr' => null,
            'order_by'   => [],
            'limit'      => null,
            'offset'     => null,
        ];

        if ($this->ts->matchKw('WHERE')) {
            $ast['where_expr'] = (new ExprParser($this->ts))->parse();
        }

        if ($this->ts->matchKw('ORDER')) {
            $this->ts->consumeKw('BY');
            do {
                $campo = $this->ts->consumeIdent();
                $dir   = 'asc';
                if ($this->ts->matchKw('DESC')) {
                    $dir = 'desc';
                } else {
                    $this->ts->matchKw('ASC');
                }
                $ast['order_by'][] = ['field' => $campo, 'dir' => $dir];
            } while ($this->ts->matchPunct(','));
        }

        if ($this->ts->matchKw('LIMIT')) {
            $ast['limit'] = $this->ts->consumeInt();
        }
        if ($this->ts->matchKw('OFFSET')) {
            $ast['offset'] = $this->ts->consumeInt();
        }

        return $ast;
    }

    private function parseInsert(): array
    {
        $this->ts->consumeKw('INSERT');
        $this->ts->consumeKw('INTO');
        $coleccion = $this->ts->consumeIdent();

        $this->ts->consumePunct('(');
        $campos = [$this->ts->consumeIdent()];
        while ($this->ts->matchPunct(',')) {
            $campos[] = $this->ts->consumeIdent();
        }
        $this->ts->consumePunct(')');

        $this->ts->consumeKw('VALUES');
        $this->ts->consumePunct('(');
        $valores = [$this->ts->consumeLiteral()];
        while ($this->ts->matchPunct(',')) {
            $valores[] = $this->ts->consumeLiteral();
        }
        $this->ts->consumePunct(')');

        if (\count($campos) !== \count($valores)) {
            throw new Exception(
                'AxiSQL: INSERT con ' . \count($campos) . ' columnas y '
                . \count($valores) . ' valores.'
            );
        }

        return [
            'type'       => 'insert',
            'collection' => $coleccion,
            'data'       => \array_combine($campos, $valores),
        ];
    }

    private function parseUpdate(): array
    {
        $this->ts->consumeKw('UPDATE');
        $coleccion = $this->ts->consumeIdent();
        $this->ts->consumeKw('SET');

        $set = [];
        do {
            $campo = $this->ts->consumeIdent();
            if (!$this->ts->peek()->isOp('=')) {
                throw new Exception("AxiSQL: esperaba '=' tras '{$campo}' en SET.");
            }
            $this->ts->advance();
            $set[$campo] = $this->ts->consumeLiteral();
        } while ($this->ts->matchPunct(','));

        return [
            'type'       => 'update',
            'collection' => $coleccion,
            'set'        => $set,
            'where_expr' => $this->ts->matchKw('WHERE') ? (new ExprParser($this->ts))->parse() : null,
        ];
    }

    private function parseDelete(): array
    {
        $this->ts->consumeKw('DELETE');
        $this->ts->consumeKw('FROM');
        $coleccion = $this->ts->consumeIdent();

        return [
            'type'       => 'delete',
            'collection' => $coleccion,
            'where_expr' => $this->ts->matchKw('WHERE') ? (new ExprParser($this->ts))->parse() : null,
        ];
    }

    /** '*' o una lista de nombres de campo. */
    private function parseListaCampos(): array
    {
        if ($this->ts->matchPunct('*')) {
            return ['*'];
        }
        $campos = [$this->ts->consumeIdent()];
        while ($this->ts->matchPunct(',')) {
            $campos[] = $this->ts->consumeIdent();
        }
        return $campos;
    }
}
