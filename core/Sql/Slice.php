<?php
/**
 * AxiDB - Sql\Slice: sacar el texto de una subconsulta de dentro del SQL.
 *
 * Las subconsultas y las vistas se guardan y se ejecutan como TEXTO, no como
 * arbol. El arbol es un detalle interno que puede cambiar entre versiones; el
 * texto significa lo mismo dentro de dos años y ademas se puede leer.
 *
 * Aparte porque recortar por posiciones tiene su propia trampa, explicada abajo,
 * y porque el analizador de condiciones ya estaba en su limite de tamaño.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class Slice
{
    /**
     * Se traga `( SELECT ... )` y devuelve el texto de dentro.
     *
     * Se guarda el texto y se ejecuta despues con `$db->sql()`, en vez de
     * analizarlo aqui: asi la subconsulta admite exactamente lo mismo que una
     * consulta suelta —agregados, JOIN, funciones— sin que haya que mantener
     * dos gramaticas que se parecen.
     */
    public static function subconsulta(TokenStream $ts): string
    {
        $ts->consumePunct('(');
        $desde = $ts->peek()->pos;

        if (!$ts->peek()->isKw('SELECT')) {
            throw new Exception(
                'AxiSQL: a subquery starts with SELECT; found '
                . $ts->peek()->describe() . '.'
            );
        }

        /*
         * Se avanza contando parentesis hasta el que cierra, y el corte se hace
         * en la POSICION de ese parentesis.
         *
         * La primera version sumaba la longitud del ultimo token leido, y eso
         * falla con los textos: el token de una cadena guarda su valor ya sin
         * comillas, asi que 'Murcia' mide seis y ocupa ocho. El corte se quedaba
         * dos caracteres corto y la subconsulta salia con la comilla sin cerrar.
         */
        $nivel = 1;
        while (true) {
            $tk = $ts->peek();
            if ($tk->type === Token::EOF) {
                throw new Exception('AxiSQL: the closing parenthesis of the subquery is missing.');
            }
            if ($tk->isPunct('(')) {
                $nivel++;
            }
            if ($tk->isPunct(')') && --$nivel === 0) {
                $ts->advance();
                return \trim(\substr($ts->fuente(), $desde, $tk->pos - $desde));
            }
            $ts->advance();
        }
    }

}
