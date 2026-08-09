<?php
/**
 * AxiDB - ¿encuentra la busqueda vectorial lo que deberia?
 *
 * El gate de la ola: recall@10 del 95% o mas frente al coseno exacto sobre
 * 10.000 vectores, en menos de 50 ms.
 *
 * "Recall@10" es cuantos de los diez mejores de verdad aparecen entre los diez
 * que devuelve la busqueda rapida. Es LA medida de una busqueda aproximada: de
 * nada sirve responder en un milisegundo si lo que responde no es lo que hay.
 *
 * Y la advertencia sobre la que se apoya todo esto: **el recall depende de como
 * sean los vectores**. Los embeddings de verdad se agrupan por significado —los
 * textos parecidos caen cerca— y sobre ellos la criba binaria acierta de sobra.
 * Con vectores uniformemente aleatorios, que no se parecen a nada que genere un
 * modelo, el mismo algoritmo baja mucho. Se miden los dos casos y solo se exige
 * el 95% sobre el realista, diciendo por que.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';
require_once __DIR__ . '/_vectores.php';

use Axi\Core\Vector\Buscador;

const DIMS      = 768;
const CUANTOS   = 10000;
const CONSULTAS = 5;

$dir = tmpdir('vector_recall');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Sobre embeddings realistas (agrupados por significado)');

$almacen = almacenNuevo($dir . '/agrupados', DIMS);

$t = \microtime(true);
$muestras = sembrarVectores($almacen, CUANTOS, DIMS, true);
\printf("    %d vectores indexados en %.1f s (%.2f ms cada uno)\n",
    CUANTOS, \microtime(true) - $t, (\microtime(true) - $t) * 1000 / CUANTOS);

eq('estan todos', CUANTOS, $almacen->manifiesto()->vivos());

$buscador = new Buscador($almacen);
$recalls  = [];
$tiempos  = [];

foreach ($muestras as $indice) {
    $consulta = $almacen->vectorDe($indice);

    $t         = \microtime(true);
    $rapido    = $buscador->buscar($consulta, 10);
    $tiempos[] = (\microtime(true) - $t) * 1000;

    $recalls[] = recall($rapido, fuerzaBruta($almacen, $consulta, 10));
}

$recall = \array_sum($recalls) / \count($recalls);
$peor   = \min($recalls);
$medio  = \array_sum($tiempos) / \count($tiempos);

\printf("    recall@10: %.0f%% de media, %.0f%% el peor de %d consultas\n", $recall, $peor, CONSULTAS);
\printf("    tiempo:    %.1f ms de media, %.1f ms el peor\n", $medio, \max($tiempos));

ok("recall@10 medio del 95% o mas: {$recall}% (GATE DE LA OLA)", $recall >= 95.0);
ok("y ninguna consulta suelta baja del 80%: {$peor}%",           $peor >= 80.0);
/*
 * El gate de la ola son 50 ms y en esta maquina salen unos 45. El test exige
 * 150, por lo mismo que en test_vector_escala: la medicion que vale para el
 * gate esta anotada en el plan, y aqui lo que se vigila es que no aparezca una
 * regresion de las que se ven a simple vista.
 */
\printf("    (gate: 50 ms. Tope del test: 150 ms, con holgura para runners lentos)\n");
ok(\sprintf('por debajo de 150 ms de media: %.1f ms', $medio), $medio < 150.0);
ok(\sprintf('y ninguna pasa de 250 ms: %.1f ms', \max($tiempos)), \max($tiempos) < 250.0);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Lo que cuesta hacerlo exacto');

$consulta = $almacen->vectorDe(42);

$t = \microtime(true);
$exacto = fuerzaBruta($almacen, $consulta, 10);
$msExacto = (\microtime(true) - $t) * 1000;

$t = \microtime(true);
$rapido = $buscador->buscar($consulta, 10);
$msRapido = (\microtime(true) - $t) * 1000;

\printf("    coseno sobre los %d: %.0f ms | dos pasadas: %.0f ms | x%.0f\n",
    CUANTOS, $msExacto, $msRapido, $msExacto / \max(0.001, $msRapido));

ok('la busqueda aproximada es al menos 3 veces mas rapida',
    $msExacto / \max(0.001, $msRapido) >= 3.0);
eq('y el primero es el mismo que daria el exacto',
    \array_key_first($exacto), $rapido[0]['id'] ?? null);
ok('un vector se encuentra a si mismo con score casi 1',
    ($rapido[0]['score'] ?? 0) > 0.999);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] El peor caso: vectores aleatorios, medido y dicho');

/*
 * Ningun modelo genera esto. Se mide para saber donde esta el suelo del
 * algoritmo y para no anunciar un recall que solo se cumple con datos amables.
 */
$almacen2 = almacenNuevo($dir . '/aleatorios', DIMS);
sembrarVectores($almacen2, 2000, DIMS, false, 'r');
$buscador2 = new Buscador($almacen2);

$recalls = [];
for ($c = 0; $c < 3; $c++) {
    $consulta  = $almacen2->vectorDe(($c * 577) % 2000);
    $recalls[] = recall($buscador2->buscar($consulta, 10), fuerzaBruta($almacen2, $consulta, 10));
}
$peorCaso = \array_sum($recalls) / \count($recalls);
\printf("    recall@10 con vectores aleatorios: %.0f%%\n", $peorCaso);

ok("incluso en el peor caso encuentra mas de la mitad: {$peorCaso}%", $peorCaso >= 50.0);
eq('y el identico a la consulta sale el primero',
    'r7', $buscador2->buscar($almacen2->vectorDe(7), 5)[0]['id'] ?? null);

rmrf($dir);
summary();
