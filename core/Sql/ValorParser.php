<?php
/**
 * AxiDB - Sql\ValorParser: lee una expresion que produce un VALOR.
 *
 * Distinta del ExprParser, que lee una expresion que produce un SI o un NO. Aqui
 * se analiza lo que va en un SELECT o a la derecha de un SET:
 *
 *   precio * 1.21
 *   UNIR(nombre, ' ', apellido)
 *   SUM(total)
 *   MES(fecha)
 *
 * Precedencia, de menos a mas: + y - , luego * / y %, luego lo suelto. Es la de
 * las matematicas de siempre, y saltarsela seria una sorpresa desagradable: si
 * `2 + 3 * 4` diera 20 en vez de 14, nadie se fiaria del resto.
 *
 * Los agregados se marcan aparte —no son una funcion mas— porque no se evaluan
 * documento a documento sino sobre un grupo entero. Ver Agrupacion.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class ValorParser
{
    public const AGREGADOS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    public function __construct(private TokenStream $ts)
    {
    }

    public function parse(): array
    {
        return $this->parseSuma();
    }

    private function parseSuma(): array
    {
        $izq = $this->parseProducto();

        while (true) {
            $tk = $this->ts->peek();
            if ($tk->type !== Token::OP || !\in_array($tk->value, ['+', '-'], true)) {
                return $izq;
            }
            $this->ts->advance();
            $izq = ['t' => 'op', 'op' => (string) $tk->value,
                    'izq' => $izq, 'der' => $this->parseProducto()];
        }
    }

    /**
     * El '*' llega como signo de puntuacion y no como operador, porque es el
     * mismo caracter que el de `SELECT *` y `COUNT(*)`. Se distingue por donde
     * aparece: aqui ya se ha leido un valor a la izquierda, asi que solo puede
     * ser multiplicar.
     */
    private function parseProducto(): array
    {
        $izq = $this->parseUnario();

        while (true) {
            $tk  = $this->ts->peek();
            $mul = $tk->isPunct('*');

            if (!$mul && ($tk->type !== Token::OP || !\in_array($tk->value, ['/', '%'], true))) {
                return $izq;
            }
            $this->ts->advance();
            $izq = ['t' => 'op', 'op' => $mul ? '*' : (string) $tk->value,
                    'izq' => $izq, 'der' => $this->parseUnario()];
        }
    }

    private function parseUnario(): array
    {
        $tk = $this->ts->peek();
        if ($tk->type === Token::OP && $tk->value === '-') {
            $this->ts->advance();
            return ['t' => 'op', 'op' => '-', 'izq' => ['t' => 'lit', 'v' => 0],
                    'der' => $this->parseUnario()];
        }
        return $this->parseAtomo();
    }

    private function parseAtomo(): array
    {
        if ($this->ts->matchPunct('(')) {
            $dentro = $this->parse();
            $this->ts->consumePunct(')');
            return $dentro;
        }

        $tk = $this->ts->peek();

        // COUNT es palabra reservada del lexer; las demas funciones son identificadores.
        if ($tk->type === Token::KW && \strtoupper((string) $tk->value) === 'COUNT') {
            $this->ts->advance();
            return $this->parseAgregado('COUNT');
        }
        if ($tk->type === Token::IDENT && $this->ts->peek(1)->isPunct('(')) {
            $nombre = (string) $this->ts->advance()->value;
            $mayus  = \strtoupper($nombre);

            return \in_array($mayus, self::AGREGADOS, true)
                ? $this->parseAgregado($mayus)
                : $this->parseFuncion($nombre);
        }
        if ($tk->type === Token::IDENT) {
            return ['t' => 'campo', 'nombre' => (string) $this->ts->advance()->value];
        }
        if ($tk->type === Token::KW && \in_array(\strtoupper((string) $tk->value), ['TRUE', 'FALSE', 'NULL'], true)) {
            return ['t' => 'lit', 'v' => $this->ts->consumeLiteral()];
        }
        if ($tk->type === Token::STR || $tk->type === Token::NUM) {
            return ['t' => 'lit', 'v' => $this->ts->consumeLiteral()];
        }

        throw new Exception("AxiSQL: esperaba un valor y encontro {$tk->describe()}.");
    }

    /** SUM(campo), COUNT(*), COUNT(campo)... */
    private function parseAgregado(string $fn): array
    {
        $this->ts->consumePunct('(');

        if ($this->ts->matchPunct('*')) {
            if ($fn !== 'COUNT') {
                throw new Exception("AxiSQL: {$fn}(*) no tiene sentido; solo COUNT(*) lo tiene.");
            }
            $this->ts->consumePunct(')');
            return ['t' => 'agg', 'fn' => 'COUNT', 'arg' => null];
        }
        $arg = $this->parse();
        $this->ts->consumePunct(')');

        return ['t' => 'agg', 'fn' => $fn, 'arg' => $arg];
    }

    private function parseFuncion(string $nombre): array
    {
        $this->ts->consumePunct('(');
        $args = [];
        if (!$this->ts->peek()->isPunct(')')) {
            do {
                $args[] = $this->parse();
            } while ($this->ts->matchPunct(','));
        }
        $this->ts->consumePunct(')');

        Funciones::exigirFirma($nombre, \count($args));
        return ['t' => 'fn', 'nombre' => Funciones::canonico($nombre), 'args' => $args];
    }

    /** True si en algun sitio del arbol hay un agregado. Lo usa el ejecutor. */
    public static function tieneAgregado(?array $expr): bool
    {
        if ($expr === null) {
            return false;
        }
        if (($expr['t'] ?? '') === 'agg') {
            return true;
        }
        foreach (['izq', 'der', 'arg'] as $rama) {
            if (isset($expr[$rama]) && \is_array($expr[$rama]) && self::tieneAgregado($expr[$rama])) {
                return true;
            }
        }
        foreach ($expr['args'] ?? [] as $arg) {
            if (self::tieneAgregado($arg)) {
                return true;
            }
        }
        return false;
    }
}
