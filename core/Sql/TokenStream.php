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
