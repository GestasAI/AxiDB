<?php
/**
 * AxiDB - Sql\Threshold: separar la condicion sobre el parecido del resto del WHERE.
 *
 *   WHERE ciudad = 'Murcia' AND parecido > 0.8
 *          └── antes de buscar        └── despues, como umbral
 *
 * Hacen falta las dos mitades y en dos momentos distintos. `ciudad` reduce el
 * conjunto ANTES de buscar, con sus indices. `parecido` no existe hasta despues
 * de buscar: es lo que devuelve la busqueda, no un campo del documento.
 *
 * Solo se separan las condiciones unidas por AND en el primer nivel. Un
 * `parecido > 0.8 OR ciudad = 'Murcia'` no se puede partir sin cambiar lo que
 * significa, asi que se rechaza en vez de dar un resultado que parece bueno.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class Threshold
{
    /**
     * Parte el WHERE en dos: lo que filtra documentos y lo que filtra parecido.
     *
     * @return array{0: ?array, 1: ?array} [antes de buscar, umbral]
     */
    public static function separar(?array $expr): array
    {
        if ($expr === null) {
            return [null, null];
        }
        if (self::esDelParecido($expr)) {
            return [null, $expr];
        }
        if (($expr['type'] ?? '') !== 'and') {
            self::exigirSinParecidoDentro($expr);
            return [$expr, null];
        }

        [$izqAntes, $izqUmbral] = self::separar($expr['left']);
        [$derAntes, $derUmbral] = self::separar($expr['right']);

        if ($izqUmbral !== null && $derUmbral !== null) {
            throw new Exception(
                "AxiSQL: only one condition is allowed on '" . self::CAMPO . "' por consulta."
            );
        }
        $antes = match (true) {
            $izqAntes === null => $derAntes,
            $derAntes === null => $izqAntes,
            default            => ['type' => 'and', 'left' => $izqAntes, 'right' => $derAntes],
        };
        return [$antes, $izqUmbral ?? $derUmbral];
    }

    public const CAMPO = 'parecido';

    /** Si el resultado alcanza el umbral. */
    public static function pasa(float $parecido, array $umbral): bool
    {
        $limite = (float) ($umbral['value'] ?? 0);

        return match ((string) $umbral['op']) {
            '>'  => $parecido >  $limite,
            '>=' => $parecido >= $limite,
            '<'  => $parecido <  $limite,
            '<=' => $parecido <= $limite,
            '='  => \abs($parecido - $limite) < 0.000001,
            default => true,
        };
    }

    public static function describe(array $umbral): string
    {
        return self::CAMPO . ' ' . $umbral['op'] . ' ' . (string) ($umbral['value'] ?? '');
    }

    private static function esDelParecido(array $nodo): bool
    {
        return ($nodo['type'] ?? '') === 'cmp'
            && ($nodo['field'] ?? null) === self::CAMPO;
    }

    /**
     * Se niega si `parecido` aparece donde no se puede separar.
     *
     * Dentro de un OR o de un NOT, partir la condicion cambiaria lo que
     * significa. Devolver algo parecido-pero-no-igual seria peor que decir que
     * no: quien escribe la consulta se fiaria del resultado.
     */
    private static function exigirSinParecidoDentro(array $nodo): void
    {
        if (self::esDelParecido($nodo)) {
            throw new Exception(
                "AxiSQL: '" . self::CAMPO . "' solo se puede comparar en el primer nivel del WHERE, "
                . 'unido con AND. Dentro de un OR o un NOT no se puede separar de lo demas sin '
                . 'cambiar lo que significa la consulta.'
            );
        }
        foreach (['left', 'right', 'expr'] as $rama) {
            if (isset($nodo[$rama]) && \is_array($nodo[$rama])) {
                self::exigirSinParecidoDentro($nodo[$rama]);
            }
        }
    }
}
