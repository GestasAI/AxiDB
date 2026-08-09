<?php
/**
 * AxiDB - Vector\Hibrida: buscar por significado y por palabra a la vez.
 *
 * Las dos busquedas fallan de maneras distintas, y por eso juntas funcionan
 * mejor que cualquiera de las dos:
 *
 *   por significado   encuentra "pan de masa madre" buscando "levadura casera",
 *                     pero puede no encontrar un codigo de referencia exacto
 *   por palabra       encuentra "REF-4471" clavado, y no entiende sinonimos
 *
 * Se combinan por FUSION DE RANGOS, no sumando puntuaciones. El motivo es que
 * no son comparables: el parecido de un coseno va de -1 a 1 y "cuantas veces
 * aparece la palabra" no tiene escala. Sumarlas obliga a inventar un factor de
 * conversion que nadie sabe justificar, y ese factor decide el resultado.
 *
 * La formula es la de Reciprocal Rank Fusion:
 *
 *   puntos(documento) = suma sobre cada lista de  1 / (K + posicion)
 *
 * Solo mira EN QUE PUESTO va cada documento en cada lista, no con cuanta
 * fuerza. Un documento que sale segundo en las dos gana a uno que sale primero
 * en una y no aparece en la otra, que es justo lo que se quiere de una busqueda
 * hibrida.
 *
 * K = 60 es el valor del articulo original de Cormack y Clarke (2009) y el que
 * usan las implementaciones que lo adoptaron despues. Amortigua las primeras
 * posiciones: sin el, el primero valdria el doble que el segundo.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

final class Hibrida
{
    private const K = 60;

    /**
     * @param list<array{id:string}> $porSignificado ordenados de mas a menos
     * @param list<array{id:string}> $porPalabra     ordenados de mas a menos
     * @return list<array{id:string, puntos:float, en:list<string>}>
     */
    public static function fundir(array $porSignificado, array $porPalabra, int $k = 10): array
    {
        $puntos = [];
        $en     = [];

        foreach (['significado' => $porSignificado, 'palabra' => $porPalabra] as $lista => $filas) {
            foreach (\array_values($filas) as $posicion => $fila) {
                $id = (string) ($fila['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $puntos[$id] = ($puntos[$id] ?? 0.0) + 1 / (self::K + $posicion + 1);
                $en[$id][]   = $lista;
            }
        }
        \arsort($puntos);

        $fuera = [];
        foreach ($puntos as $id => $suma) {
            $fuera[] = ['id' => (string) $id, 'puntos' => \round($suma, 8), 'en' => $en[$id]];
            if (\count($fuera) >= $k) {
                break;
            }
        }
        return $fuera;
    }
}
