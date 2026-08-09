<?php
/**
 * AxiDB - Sql\WriteParser: INSERT, UPDATE y DELETE.
 *
 * Separado de Parser por el mismo motivo que DdlParser: son gramaticas
 * independientes y juntas pasaban de 380 lineas. Comparte el TokenStream, asi
 * que el cursor sigue siendo uno solo.
 *
 *   INSERT INTO <c> (<campos>) VALUES (...), (...) [ON DUPLICATE UPDATE]
 *   UPDATE <c> SET <campo> = <expresion>, ... [WHERE ...] [LIMIT n]
 *   DELETE FROM <c> [WHERE ...] [LIMIT n]
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class WriteParser
{
    public function __construct(private TokenStream $ts)
    {
    }

    public function parse(): array
    {
        $cabeza = \strtoupper((string) $this->ts->peek()->value);

        return match ($cabeza) {
            'INSERT' => $this->parseInsert(),
            'UPDATE' => $this->parseUpdate(),
            'DELETE' => $this->parseDelete(),
            default  => throw new Exception("AxiSQL: '{$cabeza}' no es una escritura."),
        };
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

        // Varias filas de una vez: VALUES (...), (...), (...)
        $filas = [];
        do {
            $this->ts->consumePunct('(');
            $valores = [$this->ts->consumeLiteral()];
            while ($this->ts->matchPunct(',')) {
                $valores[] = $this->ts->consumeLiteral();
            }
            $this->ts->consumePunct(')');

            if (\count($campos) !== \count($valores)) {
                throw new Exception(
                    'AxiSQL: la fila ' . (\count($filas) + 1) . ' del INSERT trae '
                    . \count($valores) . ' valores y hay ' . \count($campos) . ' columnas.'
                );
            }
            $filas[] = \array_combine($campos, $valores);
        } while ($this->ts->matchPunct(','));

        /*
         * ON DUPLICATE UPDATE: si ya existe un documento con ese id, se
         * actualiza en vez de fallar. Se escribe con las palabras de siempre y
         * no con un `UPSERT` nuevo, para no tener que explicar una palabra mas.
         */
        $upsert = false;
        if ($this->ts->matchKw('ON')) {
            $this->ts->consumeKw('DUPLICATE');
            $this->ts->consumeKw('UPDATE');
            $upsert = true;
        }

        return [
            'type'       => 'insert',
            'collection' => $coleccion,
            'data'       => $filas[0],
            'rows'       => $filas,
            'upsert'     => $upsert,
        ];
    }

    private function parseUpdate(): array
    {
        $this->ts->consumeKw('UPDATE');
        $coleccion = $this->ts->consumeIdent();
        $this->ts->consumeKw('SET');

        /*
         * A la derecha del igual puede ir una expresion: `SET visitas = visitas + 1`.
         * Las que son un literal pelado se guardan como valor, igual que antes,
         * para que el UPDATE mas comun no pague por evaluar un arbol.
         */
        $set   = [];
        $exprs = [];
        do {
            $campo = $this->ts->consumeIdent();
            if (!$this->ts->peek()->isOp('=')) {
                throw new Exception("AxiSQL: esperaba '=' tras '{$campo}' en SET.");
            }
            $this->ts->advance();

            $valor = (new ValueParser($this->ts))->parse();
            if (($valor['t'] ?? '') === 'lit') {
                $set[$campo] = $valor['v'];
            } else {
                $exprs[$campo] = $valor;
            }
        } while ($this->ts->matchPunct(','));

        return [
            'type'       => 'update',
            'collection' => $coleccion,
            'set'        => $set,
            'set_expr'   => $exprs,
            'where_expr' => $this->ts->matchKw('WHERE') ? (new ExprParser($this->ts))->parse() : null,
            'limit'      => $this->ts->matchKw('LIMIT') ? $this->ts->consumeInt() : null,
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
            'limit'      => $this->ts->matchKw('LIMIT') ? $this->ts->consumeInt() : null,
        ];
    }

    /**
     * `SELECT COUNT(*) FROM ...` y nada mas: la forma que devuelve un numero.
     *
     * Se mira sin consumir nada, porque si detras del parentesis viene una coma
     * o un AS hay que volver a empezar como una proyeccion normal.
     */
}
