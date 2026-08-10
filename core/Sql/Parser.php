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
    private string $sql = '';

    /**
     * Tope de tamaño de una sentencia. 256 KB es el mismo limite que el cuerpo
     * HTTP, y deja sitio de sobra para cualquier consulta que escriba una
     * persona. Una sentencia mayor no es una consulta: es un intento de agotar
     * la memoria tokenizandola. Se corta antes de tocar el lexer.
     */
    private const MAX_SQL_BYTES = 262144;

    public function parse(string $sql): array
    {
        if (\strlen($sql) > self::MAX_SQL_BYTES) {
            throw new Exception(
                'AxiSQL: la sentencia pasa de ' . self::MAX_SQL_BYTES . ' bytes ('
                . \strlen($sql) . '). Demasiado grande para ser una consulta.'
            );
        }
        $this->ts  = new TokenStream((new Lexer())->tokenize($sql), $sql);
        $this->sql = $sql;

        if ($this->ts->atEof()) {
            throw new Exception('AxiSQL: sentencia vacia.');
        }

        // EXPLAIN <sentencia>: se analiza igual y se marca para no ejecutar.
        $explicar = $this->ts->matchKw('EXPLAIN');

        $cabeza = $this->ts->peek();
        if ($cabeza->type !== Token::KW) {
            throw new Exception(
                "AxiSQL: a statement starts with SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, "
                . "ALTER, SHOW, DESCRIBE, BEGIN, COMMIT o ROLLBACK; "
                . "encontro {$cabeza->describe()}."
            );
        }

        $ast = match (\strtoupper((string) $cabeza->value)) {
            'SELECT' => $this->parseSelect(),
            'INSERT' => (new WriteParser($this->ts))->parse(),
            'UPDATE' => (new WriteParser($this->ts))->parse(),
            'DELETE' => (new WriteParser($this->ts))->parse(),
            'CREATE' => $this->ts->peek(1)->isKw('VIEW')
                ? $this->parseCreateView()
                : (new DdlParser($this->ts))->parse(),
            'DROP', 'ALTER', 'SHOW', 'DESCRIBE' => (new DdlParser($this->ts))->parse(),
            'BEGIN', 'COMMIT', 'ROLLBACK' => $this->parseTransaction(),
            default  => throw new Exception(
                "AxiSQL: a statement cannot start with {$cabeza->describe()}."
            ),
        };

        $this->ts->matchPunct(';');
        $this->ts->expectEof();

        $ast['explain'] = $explicar;
        return $ast;
    }

    /**
     * BEGIN | COMMIT | ROLLBACK, con un TRANSACTION opcional detras que se
     * acepta y se ignora: lo escribe mucha gente por costumbre y rechazarlo
     * solo serviria para molestar.
     */
    private function parseTransaction(): array
    {
        $palabra = \strtoupper((string) $this->ts->advance()->value);
        $this->ts->matchKw('TRANSACTION');
        return ['type' => \strtolower($palabra)];
    }

    /**
     * CREATE VIEW <nombre> AS <SELECT ...>
     *
     * Una vista es una consulta con nombre y nada mas: no guarda datos ni los
     * duplica. Al usarla se ejecuta su SELECT en ese momento, asi que siempre
     * devuelve lo que hay ahora.
     *
     * Se guarda el TEXTO del SELECT, no su arbol. El arbol es un detalle interno
     * que puede cambiar entre versiones, y una vista guardada tiene que seguir
     * significando lo mismo dentro de dos años. El texto ademas se puede leer.
     *
     * Vive aqui y no en DdlParser porque hacen falta las dos cosas que solo
     * tiene el Parser: saber analizar un SELECT y tener el SQL original delante.
     */
    private function parseCreateView(): array
    {
        $this->ts->consumeKw('CREATE');
        $this->ts->consumeKw('VIEW');
        $nombre = $this->ts->consumeIdent();
        $this->ts->consumeKw('AS');

        if (!$this->ts->peek()->isKw('SELECT')) {
            throw new Exception(
                'AxiSQL: a view is defined with a SELECT; found '
                . $this->ts->peek()->describe() . '.'
            );
        }
        $desde = $this->ts->peek()->pos;
        $this->parseSelect();                   // se analiza para validarlo ya

        return [
            'type' => 'create_view',
            'view' => $nombre,
            'sql'  => \rtrim(\trim(\substr($this->sql, $desde)), ';'),
        ];
    }

    private function parseSelect(): array
    {
        $this->ts->consumeKw('SELECT');

        $distinto = $this->ts->matchKw('DISTINCT');

        /*
         * `SELECT COUNT(*) FROM x` a secas devuelve un numero, no una fila, y
         * asi lleva funcionando desde la ola A3. Se conserva porque es lo que
         * espera quien ya lo usa; en cuanto hay algo mas —otro campo, un alias,
         * un GROUP BY— pasa a ser un SELECT normal que devuelve filas.
         */
        $proyeccion = new Projection($this->ts);
        $esCount    = $proyeccion->isBareCount();
        $campos     = $esCount ? ['*'] : $proyeccion->parseList();

        $this->ts->consumeKw('FROM');

        $coleccion = $this->ts->consumeIdent();
        $uniones   = new Joins($this->ts);
        $alias     = $uniones->alias($coleccion);

        $ast = [
            'type'       => $esCount ? 'count' : 'select',
            'collection' => $coleccion,
            'alias'      => $alias,
            'joins'      => $uniones->parse(),
            'fields'     => $campos,
            'distinct'   => $distinto,
            'group_by'   => [],
            'having'     => null,
            'where_expr' => null,
            'order_by'   => [],
            'limit'      => null,
            'offset'     => null,
        ];

        if ($this->ts->matchKw('WHERE')) {
            $ast['where_expr'] = (new ExprParser($this->ts))->parse();
        }

        if ($this->ts->matchKw('GROUP')) {
            $this->ts->consumeKw('BY');
            do {
                $ast['group_by'][] = $this->ts->consumeIdent();
            } while ($this->ts->matchPunct(','));
        }

        if ($this->ts->matchKw('HAVING')) {
            if ($ast['group_by'] === [] && $ast['fields'] === ['*']) {
                throw new Exception(
                    'AxiSQL: HAVING filters groups, and there are none here. '
                    . 'With GROUP BY or an aggregate in the SELECT it makes sense; with neither, use WHERE.'
                );
            }
            $ast['having'] = (new ExprParser($this->ts))->parse();
        }

        if ($this->ts->matchKw('ORDER')) {
            $this->ts->consumeKw('BY');

            /*
             * ORDER BY EMBEDDING <-> 'texto' no ordena por un campo: cambia la
             * consulta entera por una busqueda vectorial. Se detecta aqui, en
             * cuanto aparece la palabra, porque despues ya no se distingue de un
             * orden normal.
             */
            if ($this->ts->matchKw('EMBEDDING')) {
                if (!$this->ts->peek()->isOp('<->')) {
                    throw new Exception("AxiSQL: after EMBEDDING expected '<->' y el texto a buscar.");
                }
                $this->ts->advance();
                $ast['vector'] = $this->ts->consumeLiteral();
                $ast['limit']  = null;

                if ($this->ts->matchKw('LIMIT')) {
                    $ast['limit'] = $this->ts->consumeUnsignedInt();
                }
                return $ast;
            }

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
            $ast['limit'] = $this->ts->consumeUnsignedInt();
        }
        if ($this->ts->matchKw('OFFSET')) {
            $ast['offset'] = $this->ts->consumeUnsignedInt();
        }

        return $ast;
    }


}
