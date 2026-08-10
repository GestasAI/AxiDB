<?php
/**
 * AxiDB - Sql\TokenStream: el cursor sobre los tokens.
 *
 * Existe para que Parser, DdlParser y ExprParser compartan un unico cursor y
 * un unico juego de helpers. En la version anterior cada parser llevaba su
 * copia de peek/advance/consume* y se pasaban el indice a mano con getters:
 * tres copias del mismo codigo y un sitio mas donde desincronizarse.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class TokenStream
{
    private int $i = 0;

    /** @param Token[] $tokens */
    /**
     * @param string $fuente el SQL original. Hace falta para recortar el texto
     *                       de una subconsulta o de una vista, que se guardan
     *                       tal cual se escribieron y no como arbol.
     */
    public function __construct(private array $tokens, private string $fuente = '')
    {
    }

    public function fuente(): string
    {
        return $this->fuente;
    }

    public function peek(int $adelante = 0): Token
    {
        return $this->tokens[$this->i + $adelante] ?? $this->tokens[\array_key_last($this->tokens)];
    }

    public function advance(): Token
    {
        return $this->tokens[$this->i++] ?? $this->peek();
    }

    public function atEof(): bool
    {
        return $this->peek()->type === Token::EOF;
    }

    /* ─────────────────── Consumo condicional (no lanza) ─────────────────── */

    public function matchKw(string $kw): bool
    {
        if ($this->peek()->isKw($kw)) {
            $this->advance();
            return true;
        }
        return false;
    }

    public function matchPunct(string $p): bool
    {
        if ($this->peek()->isPunct($p)) {
            $this->advance();
            return true;
        }
        return false;
    }

    /** Primera de varias keywords que encaje, o null. Devuelve la encontrada. */
    public function matchAnyKw(string ...$kws): ?string
    {
        foreach ($kws as $kw) {
            if ($this->peek()->isKw($kw)) {
                $this->advance();
                return $kw;
            }
        }
        return null;
    }

    /* ─────────────────── Consumo obligatorio (lanza) ─────────────────── */

    public function consumeKw(string $kw): void
    {
        $tk = $this->peek();
        if (!$tk->isKw($kw)) {
            throw new Exception("AxiSQL: expected {$kw} but found {$tk->describe()} at position {$tk->pos}.");
        }
        $this->advance();
    }

    public function consumePunct(string $p): void
    {
        $tk = $this->peek();
        if (!$tk->isPunct($p)) {
            throw new Exception("AxiSQL: esperaba '{$p}' y encontro {$tk->describe()} en la posicion {$tk->pos}.");
        }
        $this->advance();
    }

    public function consumeIdent(): string
    {
        $tk = $this->peek();
        if ($tk->type !== Token::IDENT) {
            throw new Exception(
                "AxiSQL: expected a name but found {$tk->describe()} at position {$tk->pos}."
            );
        }
        $this->advance();
        return (string) $tk->value;
    }

    public function consumeInt(): int
    {
        $tk = $this->peek();
        if ($tk->type !== Token::NUM || !\is_int($tk->value)) {
            throw new Exception(
                "AxiSQL: expected an integer but found {$tk->describe()} at position {$tk->pos}."
            );
        }
        $this->advance();
        return $tk->value;
    }

    /**
     * Profundidad de anidamiento de expresiones, para cortar el descenso
     * recursivo antes de que agote la pila.
     *
     * `WHERE ((((...))))` con unos miles de parentesis —apenas 50 KB de SQL, por
     * debajo del limite de tamaño— mataba el proceso: cada nivel es una llamada
     * recursiva mas. 200 niveles es mas de lo que anida cualquier consulta que
     * escriba una persona; pasar de ahi es un ataque, no una consulta.
     */
    private int $profundidad = 0;

    private const MAX_PROFUNDIDAD = 200;

    public function descend(): void
    {
        if (++$this->profundidad > self::MAX_PROFUNDIDAD) {
            throw new Exception(
                'AxiSQL: expresion demasiado anidada (tope ' . self::MAX_PROFUNDIDAD . ' niveles).'
            );
        }
    }

    public function ascend(): void
    {
        $this->profundidad--;
    }

    /**
     * Un entero que no puede ser negativo. Para LIMIT y OFFSET.
     *
     * `LIMIT -1` no daba error: se colaba hasta array_slice, que interpreta el
     * negativo como "cuenta desde el otro extremo". `LIMIT -1` sobre cinco
     * documentos devolvia cuatro, y `OFFSET -4` paginaba desde el final. Si el
     * numero viene de una paginacion externa, el resultado deja de ser el pedido
     * sin que nadie lo note. Un limite negativo no significa nada: se rechaza.
     */
    public function consumeUnsignedInt(): int
    {
        $tk = $this->peek();
        $n  = $this->consumeInt();
        if ($n < 0) {
            throw new Exception(
                "AxiSQL: LIMIT y OFFSET no pueden ser negativos; llego {$n} en la posicion {$tk->pos}."
            );
        }
        return $n;
    }

    /** Literal: texto, numero, TRUE, FALSE o NULL. */
    public function consumeLiteral(): mixed
    {
        $tk = $this->peek();
        if ($tk->type === Token::STR || $tk->type === Token::NUM) {
            $this->advance();
            return $tk->value;
        }
        if ($tk->isKw('TRUE'))  { $this->advance(); return true; }
        if ($tk->isKw('FALSE')) { $this->advance(); return false; }
        if ($tk->isKw('NULL'))  { $this->advance(); return null; }

        throw new Exception(
            "AxiSQL: expected a value but found {$tk->describe()} at position {$tk->pos}."
        );
    }

    public function expectEof(): void
    {
        $tk = $this->peek();
        if ($tk->type !== Token::EOF) {
            throw new Exception(
                "AxiSQL: unexpected {$tk->describe()} at the end, at position {$tk->pos}."
            );
        }
    }
}
