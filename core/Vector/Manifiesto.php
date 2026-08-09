<?php
/**
 * AxiDB - Vector\Manifiesto: lo que hay que saber de los vectores sin leerlos.
 *
 * Un archivo diminuto —una docena de campos— que se lee en cada busqueda. Por
 * eso NO lleva dentro el mapa de ids: con 50.000 vectores ese mapa serian mas de
 * un megabyte de JSON que habria que analizar antes de cada consulta, y la
 * consulta entera dura 100 ms. La correspondencia entre ordinal e id vive en su
 * propio archivo de ancho fijo, que se lee solo para los pocos resultados que se
 * devuelven.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

use Axi\Core\Exception;

final class Manifiesto
{
    public const ANCHO_ID = 64;

    private function __construct(
        public readonly string $campo,
        public readonly int $dims,
        public readonly string $fuente,
        /** @var list<string> */
        public readonly array $auto,
        public int $cuenta,
        public int $bajas,
        public readonly string $precision = Precision::POR_DEFECTO
    ) {
    }

    /** @param list<string> $auto */
    public static function nuevo(
        string $campo,
        int $dims,
        string $fuente,
        array $auto,
        string $precision = Precision::POR_DEFECTO
    ): self {
        if ($dims < 8 || $dims % 8 !== 0) {
            throw new Exception("Vector: las dimensiones han de ser multiplo de 8 y al menos 8; llego {$dims}.");
        }
        if ($dims > 16384) {
            throw new Exception("Vector: {$dims} dimensiones es mas de lo razonable (tope 16384).");
        }
        return new self($campo, $dims, $fuente, \array_values($auto), 0, 0, Precision::valida($precision));
    }

    public static function desdeArray(array $d): self
    {
        $m = new self(
            (string) ($d['campo'] ?? 'embedding'),
            (int) ($d['dims'] ?? 0),
            (string) ($d['fuente'] ?? 'hash'),
            \array_map('strval', (array) ($d['auto'] ?? [])),
            (int) ($d['cuenta'] ?? 0),
            (int) ($d['bajas'] ?? 0),
            // Un indice creado antes de que existieran los modos no lleva el
            // campo, y su comportamiento era el de 'rapida'. Se respeta: nadie
            // deberia notar un cambio de velocidad por actualizar.
            Precision::valida((string) ($d['precision'] ?? Precision::POR_DEFECTO))
        );
        if ($m->dims < 8) {
            throw new Exception('Vector: el manifiesto no dice cuantas dimensiones tiene.');
        }
        return $m;
    }

    public function aArray(): array
    {
        return [
            'version' => 1,
            'campo'   => $this->campo,
            'dims'    => $this->dims,
            'metrica' => 'coseno',
            'fuente'  => $this->fuente,
            'auto'    => $this->auto,
            'cuenta'  => $this->cuenta,
            'bajas'   => $this->bajas,
            'precision' => $this->precision,
        ];
    }

    /** Bytes que ocupa un codigo binario. */
    public function anchoCodigo(): int
    {
        return \intdiv($this->dims, 8);
    }

    /** Bytes que ocupa un vector float32. */
    public function anchoFloat(): int
    {
        return $this->dims * 4;
    }

    /** Vectores vivos, sin contar las bajas. */
    public function vivos(): int
    {
        return $this->cuenta - $this->bajas;
    }

    /**
     * True si conviene compactar. Mismo criterio que el driver empaquetado: por
     * debajo de una quinta parte de basura, mover megabytes cuesta mas de lo que
     * ahorra.
     */
    public function convieneCompactar(): bool
    {
        return $this->cuenta > 0 && $this->bajas / $this->cuenta > 0.2;
    }
}
