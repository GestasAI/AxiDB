<?php
/**
 * AxiDB - utilidades compartidas por los tests de vectores.
 *
 * Generar vectores de prueba tiene un detalle que decide si el test mide algo:
 * **los embeddings de verdad se agrupan**. Un modelo coloca los textos parecidos
 * cerca unos de otros, dejando el espacio lleno de racimos. Los vectores
 * uniformemente aleatorios no se parecen a eso: estan repartidos por igual, sin
 * estructura, y ninguna busqueda aproximada funciona bien sobre ellos.
 *
 * Por eso se pueden generar de las dos maneras y cada test dice cual usa.
 */

declare(strict_types=1);

use Axi\Core\Vector\Store;
use Axi\Core\Vector\Quantizer;
use Axi\Core\Vector\Manifest;

/** Un almacen vacio y listo, en el directorio indicado. */
function almacenNuevo(string $dir, int $dims, string $fuente = 'test'): Store
{
    $almacen = new Store($dir);
    $almacen->activar(Manifest::nuevo('embedding', $dims, $fuente, []));
    return $almacen;
}

/**
 * Siembra vectores directamente en el almacen, sin tenerlos todos en memoria.
 *
 * Diez mil vectores de 768 dimensiones en arrays de PHP son 400 MB y el test se
 * queda sin memoria antes de empezar. Se generan de uno en uno y se guardan;
 * despues se leen del disco, que ademas es lo que hace el motor de verdad.
 *
 * @return list<int> indices de unas cuantas consultas de muestra
 */
function sembrarVectores(
    Store $almacen,
    int $cuantos,
    int $dims,
    bool $agrupados,
    string $prefijo = 'd',
    int $semilla = 12345
): array {
    foreach (generadorVectores($cuantos, $dims, $agrupados, $semilla) as $i => $v) {
        $almacen->poner($prefijo . $i, $v);
    }
    $muestras = [];
    for ($c = 0; $c < 5; $c++) {
        $muestras[] = ($c * 1777) % $cuantos;
    }
    return $muestras;
}

/**
 * Los mismos vectores, de uno en uno.
 *
 * @return \Generator<int, list<float>>
 */
function generadorVectores(int $cuantos, int $dims, bool $agrupados, int $semilla = 12345): \Generator
{
    \mt_srand($semilla);

    $centros = [];
    if ($agrupados) {
        for ($c = 0; $c < 50; $c++) {
            $v = [];
            for ($d = 0; $d < $dims; $d++) {
                $v[] = \mt_rand(-1000, 1000) / 1000;
            }
            $centros[] = $v;
        }
    }
    for ($i = 0; $i < $cuantos; $i++) {
        $v = [];
        if ($agrupados) {
            $centro = $centros[$i % 50];
            for ($d = 0; $d < $dims; $d++) {
                $v[] = $centro[$d] + \mt_rand(-350, 350) / 1000;
            }
        } else {
            for ($d = 0; $d < $dims; $d++) {
                $v[] = \mt_rand(-1000, 1000) / 1000;
            }
        }
        yield $i => Quantizer::normalizar($v);
    }
}

/**
 * Vectores normalizados en memoria. Solo para cantidades pequeñas.
 *
 * @param bool $agrupados true imita embeddings reales; false es ruido uniforme
 * @return list<list<float>>
 */
function generarVectores(int $cuantos, int $dims, bool $agrupados, int $semilla = 12345): array
{
    \mt_srand($semilla);                    // mismos vectores en cada ejecucion

    $centros = [];
    if ($agrupados) {
        for ($c = 0; $c < 50; $c++) {
            $v = [];
            for ($d = 0; $d < $dims; $d++) {
                $v[] = \mt_rand(-1000, 1000) / 1000;
            }
            $centros[] = $v;
        }
    }

    $salida = [];
    for ($i = 0; $i < $cuantos; $i++) {
        $v = [];
        if ($agrupados) {
            $centro = $centros[$i % 50];
            for ($d = 0; $d < $dims; $d++) {
                $v[] = $centro[$d] + \mt_rand(-350, 350) / 1000;
            }
        } else {
            for ($d = 0; $d < $dims; $d++) {
                $v[] = \mt_rand(-1000, 1000) / 1000;
            }
        }
        $salida[] = Quantizer::normalizar($v);
    }
    return $salida;
}

/**
 * La respuesta correcta: coseno exacto contra TODOS los vectores guardados.
 * Lenta a proposito, es la vara de medir.
 *
 * Se leen del disco de uno en uno en vez de tenerlos en un array: asi el test
 * mide el motor y no se queda sin memoria antes de empezar.
 *
 * @param list<float> $consulta
 * @return array<string,float> id => score, del mejor al peor
 */
function fuerzaBruta(Store $almacen, array $consulta, int $k): array
{
    $todos = [];
    $almacen->recorrerVectores(function (int $ordinal, array $v) use (&$todos, $consulta, $almacen) {
        $id = $almacen->idDe($ordinal);
        if ($id !== null) {
            $todos[$id] = Quantizer::coseno($consulta, $v);
        }
    });
    \arsort($todos);
    return \array_slice($todos, 0, $k, true);
}

/**
 * Que porcentaje de los buenos aparece en el resultado rapido.
 *
 * @param list<array{id: string, score: float}> $rapido
 * @param array<string,float>                   $exacto
 */
function recall(array $rapido, array $exacto): float
{
    if ($exacto === []) {
        return 100.0;
    }
    $obtenidos = \array_column($rapido, 'id');
    $aciertos  = \count(\array_intersect(\array_keys($exacto), $obtenidos));
    return $aciertos * 100 / \count($exacto);
}
