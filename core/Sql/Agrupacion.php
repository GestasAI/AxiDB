<?php
/**
 * AxiDB - Sql\Agrupacion: reparte los documentos en grupos y calcula agregados.
 *
 * Sin `GROUP BY` pero con un agregado en el SELECT, todo es UN grupo: eso es lo
 * que hace que `SELECT COUNT(*), SUM(total) FROM pedidos` devuelva una sola fila
 * en vez de una por documento.
 *
 * Que hace cada agregado con los nulos, que es donde se equivocan las
 * implementaciones caseras:
 *
 *   COUNT(*)      cuenta filas, incluidas las que tienen el campo vacio
 *   COUNT(campo)  cuenta solo las que tienen valor. NO es lo mismo
 *   SUM/AVG       ignoran los nulos. AVG divide entre los que habia, no entre todos
 *   MIN/MAX       ignoran los nulos
 *
 * Es lo que hace SQL, y no por imitarlo: `AVG` dividiendo entre el total daria
 * una media falsa en cuanto un solo documento no tuviera el campo.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

final class Agrupacion
{
    /**
     * @param list<array>  $documentos
     * @param list<string> $porCampos   campos del GROUP BY; vacio = un solo grupo
     * @param list<array>  $agregados   nodos 'agg' que hay que calcular
     * @return list<array{clave:array, documentos:list<array>, valores:array<string,mixed>}>
     */
    public static function hacer(array $documentos, array $porCampos, array $agregados): array
    {
        $grupos = [];

        foreach ($documentos as $doc) {
            $clave = [];
            foreach ($porCampos as $campo) {
                $clave[$campo] = $doc[$campo] ?? null;
            }
            $id = $porCampos === [] ? '' : self::identidad($clave);
            $grupos[$id] ??= ['clave' => $clave, 'documentos' => []];
            $grupos[$id]['documentos'][] = $doc;
        }

        // Sin GROUP BY y sin documentos, un agregado sigue teniendo respuesta:
        // COUNT(*) vale cero y SUM vale null. Devolver cero filas seria mentir.
        if ($grupos === [] && $porCampos === [] && $agregados !== []) {
            $grupos[''] = ['clave' => [], 'documentos' => []];
        }

        $fuera = [];
        foreach ($grupos as $grupo) {
            $grupo['valores'] = self::calcular($grupo['documentos'], $agregados);
            $fuera[] = $grupo;
        }
        return $fuera;
    }

    /**
     * @param list<array> $documentos
     * @param list<array> $agregados
     * @return array<string, mixed> por clave de agregado
     */
    private static function calcular(array $documentos, array $agregados): array
    {
        $valores = [];
        foreach ($agregados as $agg) {
            $valores[Valor::claveAgregado($agg)] = self::uno($documentos, $agg);
        }
        return $valores;
    }

    /** @param list<array> $documentos */
    private static function uno(array $documentos, array $agg): mixed
    {
        $fn = (string) $agg['fn'];

        if ($fn === 'COUNT' && ($agg['arg'] ?? null) === null) {
            return \count($documentos);
        }

        $vistos = [];
        foreach ($documentos as $doc) {
            $v = Valor::de($agg['arg'], $doc);
            if ($v !== null) {
                $vistos[] = $v;
            }
        }

        return match ($fn) {
            'COUNT' => \count($vistos),
            'SUM'   => self::sumar($vistos),
            // Siempre float. En PHP 10/2 da un entero, asi que una media salia
            // unas veces 5 y otras 5.5 segun los datos: el mismo campo con dos
            // tipos distintos rompe a quien lo recibe en JSON o lo compara.
            'AVG'   => $vistos === [] ? null : (float) self::sumar($vistos) / \count($vistos),
            'MIN'   => $vistos === [] ? null : \min($vistos),
            'MAX'   => $vistos === [] ? null : \max($vistos),
            default => null,
        };
    }

    /**
     * Suma solo lo que es numero. Un campo con texto en algun documento no
     * convierte la suma en basura: se ignora, igual que un nulo.
     *
     * @param list<mixed> $valores
     */
    private static function sumar(array $valores): int|float|null
    {
        $suma  = 0;
        $hubo  = false;
        foreach ($valores as $v) {
            if (\is_int($v) || \is_float($v)) {
                $suma += $v;
                $hubo  = true;
            }
        }
        return $hubo ? $suma : null;
    }

    /**
     * Una clave estable para el grupo.
     *
     * Con json y no con implode: `['a','b']` e `['ab','']` darian la misma
     * cadena al pegarlos con un separador, y dos grupos distintos acabarian
     * siendo el mismo sin que nadie se enterara.
     */
    private static function identidad(array $clave): string
    {
        return (string) \json_encode($clave, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recoge todos los nodos 'agg' que haya en una lista de expresiones.
     *
     * @param list<?array> $expresiones
     * @return list<array>
     */
    public static function recoger(array $expresiones): array
    {
        $fuera = [];
        foreach ($expresiones as $expr) {
            self::buscar($expr, $fuera);
        }
        return \array_values($fuera);
    }

    /** @param array<string, array> $fuera */
    private static function buscar(?array $expr, array &$fuera): void
    {
        if ($expr === null) {
            return;
        }
        if (($expr['t'] ?? '') === 'agg') {
            $fuera[Valor::claveAgregado($expr)] = $expr;
            return;                             // no hay agregados dentro de agregados
        }
        // izq/der son de las expresiones de valor; left/right/expr son de los
        // arboles de condicion, que es lo que llega desde HAVING.
        foreach (['izq', 'der', 'left', 'right', 'expr'] as $rama) {
            if (isset($expr[$rama]) && \is_array($expr[$rama])) {
                self::buscar($expr[$rama], $fuera);
            }
        }
        foreach ($expr['args'] ?? [] as $arg) {
            self::buscar($arg, $fuera);
        }
    }
}
