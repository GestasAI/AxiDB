<?php
/**
 * AxiDB - Sql\Lexer: convierte una cadena AxiSQL en tokens.
 *
 * Nota de seguridad: el lexer no interpola nada. Un literal entre comillas es
 * siempre un valor, nunca sintaxis, asi que un punto y coma, un guion doble o
 * un UNION dentro de una cadena viajan como texto. Ver test_sql_inyeccion.php.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class Lexer
{
    /** Keywords reconocidos. Se comparan sin distinguir mayusculas. */
    private const KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT',
        'IN', 'LIKE', 'IS', 'NULL', 'CONTAINS',
        'ORDER', 'BY', 'ASC', 'DESC', 'LIMIT', 'OFFSET',
        'INSERT', 'INTO', 'VALUES',
        'UPDATE', 'SET',
        'DELETE',
        'CREATE', 'DROP', 'COLLECTION', 'TABLE',
        'INDEX', 'INDEXES', 'UNIQUE', 'ON',
        'COUNT', 'EXPLAIN',
        'TRUE', 'FALSE',
        'EMBEDDING',
        'BEGIN', 'COMMIT', 'ROLLBACK', 'TRANSACTION',
        'DISTINCT', 'AS', 'GROUP', 'HAVING', 'BETWEEN', 'DUPLICATE',
        'SHOW', 'DESCRIBE', 'COLLECTIONS', 'ALTER', 'RENAME', 'TO',
        'ADD', 'FIELD', 'VIEW',
        'JOIN', 'INNER', 'LEFT', 'EXISTS',
    ];

    /** @return Token[] terminado siempre en un token EOF */
    public function tokenize(string $input): array
    {
        $tokens = [];
        $len    = \strlen($input);
        $i      = 0;

        while ($i < $len) {
            $c = $input[$i];

            if (\ctype_space($c)) {
                $i++;
                continue;
            }

            // Comentario: -- hasta fin de linea
            if ($c === '-' && ($input[$i + 1] ?? '') === '-') {
                while ($i < $len && $input[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($c === "'" || $c === '"') {
                [$tokens[], $i] = $this->leerCadena($input, $i, $len);
                continue;
            }

            /*
             * Un '-' arranca un numero negativo solo si NO puede ser una resta.
             *
             * Con la regla ingenua —guion seguido de digito— la expresion
             * `stock -1` se leia como `stock` y `-1`, o sea dos valores pegados
             * y ningun operador. Se distingue por lo que hay DETRAS: si el token
             * anterior puede cerrar una expresion (un campo, un numero, un
             * parentesis que cierra), entonces el guion es una resta.
             */
            if (\ctype_digit($c) || ($c === '-' && \ctype_digit($input[$i + 1] ?? '')
                    && !self::cierraExpresion($tokens[\count($tokens) - 1] ?? null))) {
                [$tokens[], $i] = $this->leerNumero($input, $i, $len);
                continue;
            }

            if (\ctype_alpha($c) || $c === '_') {
                $inicio = $i;
                /*
                 * El punto entra en el identificador: `pedidos.cliente_id`. Hace
                 * falta para los JOIN, donde hay que poder decir de que lado es
                 * cada campo.
                 *
                 * Solo si detras del punto viene una letra: asi `1.5` sigue
                 * siendo un decimal y `x.` no se traga el punto de nada.
                 */
                while ($i < $len && (\ctype_alnum($input[$i]) || $input[$i] === '_'
                    || ($input[$i] === '.' && \ctype_alpha($input[$i + 1] ?? '')))) {
                    $i++;
                }
                $palabra = \substr($input, $inicio, $i - $inicio);
                $mayus   = \strtoupper($palabra);
                $tokens[] = \in_array($mayus, self::KEYWORDS, true)
                    ? new Token(Token::KW, $mayus, $inicio)
                    : new Token(Token::IDENT, $palabra, $inicio);
                continue;
            }

            /*
             * El operador de distancia va ANTES que los de dos caracteres: si no,
             * '<->' se leeria como '<-' —que no existe— o peor, como '<' seguido
             * de '->'. Se escribe asi por ser el que usa pgvector: reutilizar una
             * notacion que la gente ya conoce sale gratis.
             */
            if (\substr($input, $i, 3) === '<->') {
                $tokens[] = new Token(Token::OP, '<->', $i);
                $i += 3;
                continue;
            }

            // Operadores de dos caracteres primero: '<=' no es '<' seguido de '='.
            $dos = \substr($input, $i, 2);
            if (\in_array($dos, ['!=', '<>', '>=', '<=', '=='], true)) {
                $norm = match ($dos) { '<>' => '!=', '==' => '=', default => $dos };
                $tokens[] = new Token(Token::OP, $norm, $i);
                $i += 2;
                continue;
            }

            if (\in_array($c, ['=', '>', '<', '+', '-', '/', '%'], true)) {
                $tokens[] = new Token(Token::OP, $c, $i);
                $i++;
                continue;
            }

            if (\in_array($c, ['(', ')', ',', ';', '*'], true)) {
                $tokens[] = new Token(Token::PUNCT, $c, $i);
                $i++;
                continue;
            }

            throw new Exception("AxiSQL: caracter inesperado '{$c}' en la posicion {$i}.");
        }

        $tokens[] = new Token(Token::EOF, null, $len);
        return $tokens;
    }

    /**
     * Si este token puede ser el final de una expresion.
     *
     * Sirve para saber si el '-' que viene detras es una resta o el signo de un
     * numero. Un campo, un numero, un texto o un ')' cierran expresion; un
     * operador o un '(' no.
     */
    private static function cierraExpresion(?Token $anterior): bool
    {
        if ($anterior === null) {
            return false;
        }
        if (\in_array($anterior->type, [Token::IDENT, Token::NUM, Token::STR], true)) {
            return true;
        }
        return $anterior->isPunct(')');
    }

    /** Cadena entre comillas. La comilla doblada ('') escapa una comilla. */
    private function leerCadena(string $input, int $i, int $len): array
    {
        $comilla = $input[$i];
        $inicio  = $i;
        $i++;
        $buf = '';
        while ($i < $len) {
            if ($input[$i] === $comilla) {
                if (($input[$i + 1] ?? '') === $comilla) {
                    $buf .= $comilla;
                    $i += 2;
                    continue;
                }
                return [new Token(Token::STR, $buf, $inicio), $i + 1];
            }
            $buf .= $input[$i];
            $i++;
        }
        throw new Exception("AxiSQL: cadena sin cerrar desde la posicion {$inicio}.");
    }

    private function leerNumero(string $input, int $i, int $len): array
    {
        $inicio = $i;
        if ($input[$i] === '-') {
            $i++;
        }
        while ($i < $len && \ctype_digit($input[$i])) {
            $i++;
        }
        $esDecimal = false;
        if (($input[$i] ?? '') === '.' && \ctype_digit($input[$i + 1] ?? '')) {
            $esDecimal = true;
            $i++;
            while ($i < $len && \ctype_digit($input[$i])) {
                $i++;
            }
        }
        $crudo = \substr($input, $inicio, $i - $inicio);
        return [new Token(Token::NUM, $esDecimal ? (float) $crudo : (int) $crudo, $inicio), $i];
    }
}
