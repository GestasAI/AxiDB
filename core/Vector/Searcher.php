<?php
/**
 * AxiDB - Vector\Searcher: encontrar los mas parecidos, en dos pasadas.
 *
 *   1. Criba binaria sobre TODOS los vectores. Barata y aproximada.
 *   2. Coseno exacto sobre los pocos que sobreviven. Cara y precisa.
 *
 * La gracia esta en que la segunda pasada solo mira unos cientos, asi que su
 * coste no depende de cuantos vectores haya guardados.
 *
 * Cuantos sobreviven a la primera pasada lo decide el modo de precision, y de
 * eso depende todo lo demas. Ver Vector\Precision para los tres modos y los
 * numeros que los justifican.
 *
 * El recall depende de como sean los vectores, y esto hay que decirlo claro:
 * con embeddings de verdad —que se agrupan por significado— la criba binaria
 * acierta de sobra en cualquier modo. Con vectores uniformemente aleatorios,
 * que no se parecen a nada que genere un modelo, `rapida` baja al 84%; para eso
 * estan `equilibrada`, que llega al 100%, y `exacta`, que no depende de la
 * criba en absoluto.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

final class Searcher
{
    public function __construct(private Store $almacen)
    {
    }

    /**
     * Los k documentos mas parecidos.
     *
     * @param list<float>        $consulta vector ya normalizado
     * @param array<string,true> $soloEstos ids permitidos; vacio significa todos
     * @param string|null        $precision sobreescribe la de la coleccion
     * @return list<array{id: string, score: float}> de mas a menos parecido
     */
    public function search(array $consulta, int $k = 10, array $soloEstos = [], ?string $precision = null): array
    {
        $m = $this->almacen->manifest();
        if ($k < 1 || $m->alive() === 0) {
            return [];
        }

        $vivos = $this->allowedOrdinals($m, $soloEstos);
        if ($vivos === null) {
            return [];                              // el filtro no deja pasar a nadie
        }

        $cuantos = Precision::candidates(
            $precision === null ? $m->precision : Precision::valida($precision),
            $k
        );

        return $this->refine($this->candidates($consulta, $cuantos, $vivos), $consulta, $k);
    }

    /**
     * Los ordinales que llegan a la segunda pasada.
     *
     * En `exacta` no hay criba: entran todos los vivos y el coseno decide. Es
     * mucho mas caro —732 ms frente a 37 con 10.000 vectores— y a cambio el
     * resultado no depende de que la criba binaria haya acertado.
     *
     * @param list<float>     $consulta
     * @param array<int,true> $vivos
     * @return list<int>
     */
    private function candidates(array $consulta, ?int $cuantos, array $vivos): array
    {
        /*
         * Si caben todos, no se criba.
         *
         * Con 150 documentos y 200 candidatos, la criba binaria no descarta a
         * nadie: recorre los 150, ordena por distancia de Hamming y devuelve los
         * 150. Es trabajo que no cambia el resultado. Saltarsela da el mismo
         * conjunto, exacto por construccion, y mas rapido.
         *
         * Por eso una coleccion pequeña sale exacta sin que haya que pensarlo.
         */
        $vivosTotal = $this->almacen->manifest()->alive();
        if ($cuantos !== null && $cuantos >= $vivosTotal) {
            $cuantos = null;
        }

        if ($cuantos !== null) {
            return Codes::masCercanos(
                $this->almacen->codes(),
                Quantizer::aBinario($consulta),
                $cuantos,
                $vivos
            );
        }
        if ($vivos !== []) {
            return \array_keys($vivos);
        }
        // Sin bajas ni filtro, los ordinales son 0..cuenta-1 y no hace falta
        // construir el mapa de vivos para saberlo.
        return \range(0, $this->almacen->manifest()->cuenta - 1);
    }

    /**
     * Segunda pasada: coseno exacto sobre los candidatos, ordenar y cortar.
     *
     * @param list<int>   $candidatos
     * @param list<float> $consulta
     * @return list<array{id: string, score: float}>
     */
    private function refine(array $candidatos, array $consulta, int $k): array
    {
        $puntuados = [];
        foreach ($candidatos as $ordinal) {
            $vector = $this->almacen->vectorOf($ordinal);
            if ($vector === null) {
                continue;
            }
            $puntuados[$ordinal] = Quantizer::coseno($consulta, $vector);
        }
        \arsort($puntuados);

        $salida = [];
        foreach ($puntuados as $ordinal => $score) {
            $id = $this->almacen->idOf($ordinal);
            if ($id === null) {
                continue;                           // se dio de baja por el camino
            }
            $salida[] = ['id' => $id, 'score' => \round($score, 6)];
            if (\count($salida) >= $k) {
                break;
            }
        }
        return $salida;
    }

    /**
     * Que ordinales entran en la criba.
     *
     *   null              nadie: el filtro dejo la lista vacia
     *   array vacio       todos, y sin pagar el coste de comprobar
     *   array con claves  solo esos
     *
     * El caso "todos" es el habitual y por eso se distingue: comprobar la
     * pertenencia a un mapa 10.000 veces cuesta, y sin bajas ni filtro no hay
     * nada que comprobar.
     *
     * @param array<string,true> $soloEstos
     * @return array<int,true>|null
     */
    private function allowedOrdinals(Manifest $m, array $soloEstos): ?array
    {
        if ($soloEstos === []) {
            return $m->bajas > 0 ? $this->almacen->alive() : [];
        }

        // Una sola pasada por el archivo de ids, no una por cada id buscado.
        $mapa      = $this->almacen->idMap();
        $admitidos = [];
        foreach (\array_keys($soloEstos) as $id) {
            $ordinal = $mapa[(string) $id] ?? null;
            if ($ordinal !== null) {
                $admitidos[$ordinal] = true;        // mapaIds ya excluye las bajas
            }
        }
        return $admitidos === [] ? null : $admitidos;
    }
}
