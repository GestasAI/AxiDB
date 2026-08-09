<?php
/**
 * AxiDB - Vector\Ids: que documento es cada posicion, y quien sigue vivo.
 *
 * Un solo archivo de ancho fijo —64 bytes por registro— que responde a las tres
 * preguntas del indice vectorial:
 *
 *   ordinal -> id     para devolver resultados
 *   id -> ordinal     para reemplazar o dar de baja
 *   quien esta vivo   para no devolver lo que se borro
 *
 * Y hace de registro de bajas sin necesitar otro archivo: un registro a ceros
 * significa "aqui hubo un documento y ya no esta". Tener las dos cosas en el
 * mismo sitio evita el problema clasico de dos archivos que se desincronizan.
 *
 * ── Por que hay un mapa en memoria ──────────────────────────────────────────
 *
 * La primera version releia el archivo entero en cada alta para averiguar si el
 * id ya existia. Medido: **10,8 ms por vector**, con el archivo en 19 KB. No era
 * el tamaño; era que leerlo con file_get_contents mientras el propio proceso lo
 * tiene abierto para escribir cuesta carisimo en Windows.
 *
 * Ahora la correspondencia se guarda en memoria y se actualiza al escribir. Para
 * no quedarse con datos viejos si otro proceso mete mano, se compara el tamaño
 * del archivo —una llamada barata— y se reconstruye si no cuadra.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

use Axi\Core\Exception;

final class Ids
{
    /** @var array<string,int>|null id => ordinal, sin las bajas */
    private ?array $mapa = null;

    /** Tamaño del archivo cuando se construyo el mapa. */
    private int $tamañoVisto = -1;

    public function __construct(private Archivos $archivos)
    {
    }

    public static function empaquetar(string $id): string
    {
        if (\strlen($id) > Manifiesto::ANCHO_ID) {
            throw new Exception('Vector: el id no cabe en ' . Manifiesto::ANCHO_ID . ' bytes.');
        }
        return \str_pad($id, Manifiesto::ANCHO_ID, "\0");
    }

    public function escribir(int $ordinal, string $id): void
    {
        $this->archivos->escribirEn('ids', $ordinal, Manifiesto::ANCHO_ID, self::empaquetar($id));

        if ($this->mapa !== null) {
            $this->mapa[$id]   = $ordinal;
            $this->tamañoVisto = \max($this->tamañoVisto, ($ordinal + 1) * Manifiesto::ANCHO_ID);
        }
    }

    public function darDeBaja(int $ordinal): void
    {
        $this->archivos->escribirEn(
            'ids',
            $ordinal,
            Manifiesto::ANCHO_ID,
            \str_repeat("\0", Manifiesto::ANCHO_ID)
        );
        if ($this->mapa !== null) {
            $id = \array_search($ordinal, $this->mapa, true);
            if ($id !== false) {
                unset($this->mapa[$id]);
            }
        }
    }

    /** El id de un ordinal, o null si esa posicion esta de baja. */
    public function de(int $ordinal): ?string
    {
        $bruto = $this->archivos->leerTrozo('ids', $ordinal, Manifiesto::ANCHO_ID);
        $id    = \rtrim($bruto, "\0");
        return $id === '' ? null : $id;
    }

    public function ordinalDe(string $id): ?int
    {
        return $this->mapa()[$id] ?? null;
    }

    /**
     * La correspondencia completa, id por ordinal.
     *
     * Se reconstruye solo si el archivo ha cambiado de tamaño respecto a la
     * ultima vez. Eso cubre a otro proceso añadiendo vectores; una baja hecha
     * por otro proceso no cambia el tamaño y no se veria, pero tampoco importa:
     * quien devuelve resultados vuelve a leer el archivo para confirmarlo.
     *
     * @return array<string,int>
     */
    public function mapa(): array
    {
        $tamaño = $this->archivos->tamaño('ids');
        if ($this->mapa !== null && $tamaño === $this->tamañoVisto) {
            return $this->mapa;
        }

        $crudo = $this->archivos->leerTodo('ids');
        $total = \intdiv(\strlen($crudo), Manifiesto::ANCHO_ID);
        $mapa  = [];
        for ($i = 0; $i < $total; $i++) {
            $id = \rtrim(\substr($crudo, $i * Manifiesto::ANCHO_ID, Manifiesto::ANCHO_ID), "\0");
            if ($id !== '') {
                $mapa[$id] = $i;
            }
        }
        $this->tamañoVisto = $tamaño;
        return $this->mapa = $mapa;
    }

    /**
     * Ordinales vivos. Se lee del disco a proposito, sin usar el mapa: esto lo
     * llama quien va a devolver resultados, y ahi conviene la verdad del
     * archivo por si otro proceso dio algo de baja.
     *
     * @return array<int,true>
     */
    public function vivos(): array
    {
        $crudo = $this->archivos->leerTodo('ids');
        $total = \intdiv(\strlen($crudo), Manifiesto::ANCHO_ID);
        $vivos = [];
        for ($i = 0; $i < $total; $i++) {
            if ($crudo[$i * Manifiesto::ANCHO_ID] !== "\0") {
                $vivos[$i] = true;
            }
        }
        return $vivos;
    }

    /** Tira el mapa. Tras compactar, los ordinales son otros. */
    public function olvidar(): void
    {
        $this->mapa        = null;
        $this->tamañoVisto = -1;
    }
}
