<?php
/**
 * AxiDB - Sql\Proyeccion: que se pide en un SELECT y como se llama cada cosa.
 *
 *   SELECT *
 *   SELECT nombre, precio
 *   SELECT nombre, precio * 1.21 AS conIva, MAYUS(ciudad)
 *   SELECT ciudad, COUNT(*) AS cuantos
 *
 * Aparte del Parser porque decidir que devuelve una consulta es un asunto
 * propio, y porque juntos pasaban del limite de tamaño del proyecto.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

final class Proyeccion
{
    public function __construct(private TokenStream $ts)
    {
    }

    public function esCountPelado(): bool
    {
        $pelado = $this->ts->peek()->type === Token::KW
            && \strtoupper((string) $this->ts->peek()->value) === 'COUNT'
            && $this->ts->peek(1)->isPunct('(')
            && $this->ts->peek(2)->isPunct('*')
            && $this->ts->peek(3)->isPunct(')')
            && $this->ts->peek(4)->type === Token::KW
            && \strtoupper((string) $this->ts->peek(4)->value) === 'FROM';

        if ($pelado) {
            $this->ts->advance();
            $this->ts->advance();
            $this->ts->advance();
            $this->ts->advance();
        }
        return $pelado;
    }

    /**
     * La lista de lo que se pide: `*`, campos, expresiones y alias.
     *
     *   SELECT *
     *   SELECT nombre, precio
     *   SELECT nombre, precio * 1.21 AS conIva, MAYUS(ciudad)
     *   SELECT ciudad, COUNT(*) AS cuantos
     *
     * @return list<string>|list<array{expr?:array, alias?:string, star?:bool}>
     */
    public function parseLista(): array
    {
        // El caso de siempre —`SELECT * FROM`— se resuelve aparte y devuelve la
        // misma forma que antes, para no cambiarle el AST a lo que ya funciona.
        if ($this->ts->peek()->isPunct('*') && !$this->ts->peek(1)->isPunct(',')) {
            $this->ts->advance();
            return ['*'];
        }

        $items = [];
        do {
            if ($this->ts->peek()->isPunct('*')) {
                $this->ts->advance();
                $items[] = ['star' => true];
                continue;
            }
            $expr = (new ValorParser($this->ts))->parse();
            $item = ['expr' => $expr];

            if ($this->ts->matchKw('AS')) {
                $item['alias'] = $this->ts->consumeIdent();
            } elseif ($this->ts->peek()->type === Token::IDENT) {
                $item['alias'] = $this->ts->consumeIdent();   // alias sin AS
            }
            $items[] = $item;
        } while ($this->ts->matchPunct(','));

        return $items;
    }
}
