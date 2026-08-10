<?php
/**
 * AxiDB - Vector\VectorIndex: la busqueda por significado de una coleccion.
 *
 * Junta las tres piezas —el almacen, el generador de vectores y el buscador— y
 * pone encima lo unico que importa de puertas afuera: que quien usa AxiDB haga
 * un `insert` normal y la busqueda semantica funcione.
 *
 *   $db->enableVectors('articulos', ['auto' => ['titulo', 'resumen']]);
 *   $db->insert('articulos', ['titulo' => 'Como podar un olivo', ...]);
 *   $db->similar('articulos', 'cuidados de arboles frutales', 5);
 *
 * Sin `auto`, los vectores se pasan a mano en el campo declarado. Con `auto`,
 * el motor junta esos campos de texto y genera el vector solo, en el mismo
 * momento del alta.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

use Axi\Core\Exception;

final class VectorIndex
{
    private ?Searcher $buscador = null;

    public function __construct(
        private Store $almacen,
        private Embedder $embedder
    ) {
    }

    public function activo(): bool
    {
        return $this->almacen->existe();
    }

    public function manifiesto(): Manifest
    {
        return $this->almacen->manifiesto();
    }

    /**
     * Declara que esta coleccion tiene vectores.
     *
     * @param array $opciones campo, dims, auto
     */
    public function activar(array $opciones = []): Manifest
    {
        $dims = (int) ($opciones['dims'] ?? $this->embedder->dims());
        if ($dims !== $this->embedder->dims()) {
            throw new Exception(
                "Vector: {$dims} dimensions requested but '{$this->embedder->nombre()}' "
                . 'devuelve ' . $this->embedder->dims() . '. Enterarse ahora es mejor que al buscar.'
            );
        }
        $m = Manifest::nuevo(
            (string) ($opciones['campo'] ?? 'embedding'),
            $dims,
            $this->embedder->nombre(),
            \array_map('strval', (array) ($opciones['auto'] ?? [])),
            (string) ($opciones['precision'] ?? Precision::POR_DEFECTO)
        );
        $this->almacen->activar($m);
        return $m;
    }

    public function desactivar(): void
    {
        $this->almacen->desactivar();
        $this->buscador = null;
    }

    /**
     * Indexa un documento si tiene con que. Devuelve true si guardo vector.
     *
     * Tres formas de que un documento tenga vector, en este orden:
     *   1. el campo declarado trae una lista de numeros ya hecha
     *   2. el campo declarado trae texto
     *   3. hay campos en `auto` y se juntan
     */
    public function indexar(string $id, array $documento): bool
    {
        $m     = $this->almacen->manifiesto();
        $valor = $documento[$m->campo] ?? null;

        if (\is_array($valor)) {
            $this->almacen->poner($id, Quantizer::normalizar(Quantizer::validar($valor, $m->dims)));
            return true;
        }

        $texto = \is_string($valor) && $valor !== '' ? $valor : $this->textoAuto($m, $documento);
        if ($texto === '') {
            return false;
        }
        $this->almacen->poner($id, $this->vectorDeTexto($texto, $m));
        return true;
    }

    public function quitar(string $id): bool
    {
        return $this->almacen->quitar($id);
    }

    /**
     * Los k documentos mas parecidos a un texto o a un vector.
     *
     * @param string|list<float> $consulta
     * @param array<string,true> $soloEstos ids permitidos; vacio significa todos
     * @return list<array{id: string, score: float}>
     */
    public function buscar(
        string|array $consulta,
        int $k = 10,
        array $soloEstos = [],
        ?string $precision = null
    ): array {
        $m      = $this->almacen->manifiesto();
        $vector = \is_string($consulta)
            ? $this->vectorDeTexto($consulta, $m)
            : Quantizer::normalizar(Quantizer::validar($consulta, $m->dims));

        $this->buscador ??= new Searcher($this->almacen);
        return $this->buscador->buscar($vector, $k, $soloEstos, $precision);
    }

    /** Retira las bajas. Devuelve cuantas. */
    public function compactar(): int
    {
        return $this->almacen->compactar();
    }

    /** True si conviene compactar: mas de una quinta parte son bajas. */
    public function convieneCompactar(): bool
    {
        return $this->almacen->manifiesto()->convieneCompactar();
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /** @return list<float> normalizado */
    private function vectorDeTexto(string $texto, Manifest $m): array
    {
        $vector = $this->embedder->vector($texto);
        if (\count($vector) !== $m->dims) {
            throw new Exception(
                'Vector: the embedder returned ' . \count($vector) . ' dimensiones y la coleccion '
                . "usa {$m->dims}. Se activo con '{$m->fuente}'."
            );
        }
        return Quantizer::normalizar(Quantizer::validar($vector, $m->dims));
    }

    /** Junta los campos de texto declarados en `auto`. */
    private function textoAuto(Manifest $m, array $documento): string
    {
        $partes = [];
        foreach ($m->auto as $campo) {
            $v = $documento[$campo] ?? null;
            if (\is_string($v) && $v !== '') {
                $partes[] = $v;
            } elseif (\is_array($v)) {
                // Listas de etiquetas, categorias o lo que sea: cada elemento cuenta.
                foreach ($v as $item) {
                    if (\is_string($item) || \is_int($item)) {
                        $partes[] = (string) $item;
                    }
                }
            } elseif (\is_int($v) || \is_float($v)) {
                $partes[] = (string) $v;
            }
        }
        return \implode(' ', $partes);
    }
}
