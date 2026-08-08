<?php
/**
 * AxiDB - Sql\Token: unidad emitida por el Lexer.
 *
 * Transporta tipo, valor y posicion. La posicion existe para que un error de
 * sintaxis pueda decir donde esta el problema y no solo que lo hay.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

final class Token
{
    public const KW    = 'KW';      // keyword reservado (SELECT, FROM, ...)
    public const IDENT = 'IDENT';   // identificador
    public const STR   = 'STR';     // literal de texto
    public const NUM   = 'NUM';     // entero o decimal
    public const OP    = 'OP';      // = != > < >= <=
    public const PUNCT = 'PUNCT';   // ( ) , ; *
    public const EOF   = 'EOF';

    public function __construct(
        public readonly string $type,
        public readonly string|int|float|null $value,
        public readonly int $pos
    ) {
    }

    public function isKw(string $kw): bool
    {
        return $this->type === self::KW && \strtoupper((string) $this->value) === \strtoupper($kw);
    }

    public function isPunct(string $p): bool
    {
        return $this->type === self::PUNCT && $this->value === $p;
    }

    public function isOp(string $op): bool
    {
        return $this->type === self::OP && $this->value === $op;
    }

    /** Descripcion corta para mensajes de error. */
    public function describe(): string
    {
        return $this->type === self::EOF ? 'fin de la sentencia' : "'{$this->value}'";
    }
}
