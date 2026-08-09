<?php
/**
 * AxiDB - 50.000 vectores: cuanto tarda y cuanta memoria gasta.
 *
 * Los dos numeros del plan para esta escala: por debajo de 150 ms por busqueda
 * y por debajo de 32 MB de memoria.
 *
 * El de memoria es el que decide si esto sirve en un alojamiento compartido,
 * donde PHP suele tener 128 MB y hay que dejar sitio para la aplicacion. Un
 * motor vectorial que necesite tener todo en RAM no cabe ahi, y ese es
 * exactamente el hueco que AxiDB quiere ocupar.
 *
 * Como se consigue: en memoria solo entran los codigos binarios —32 veces mas
 * pequeños que los vectores— y de los vectores de verdad solo se leen los
 * doscientos candidatos, uno a uno, desde el disco.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';
require_once __DIR__ . '/_vectores.php';

use Axi\Core\Vector\Buscador;

const DIMS    = 768;
const CUANTOS = 50000;

$dir     = tmpdir('vector_escala');
$almacen = almacenNuevo($dir, DIMS);

section('A] Indexar 50.000 vectores');

$t = \microtime(true);
sembrarVectores($almacen, CUANTOS, DIMS, true);
$segundos = \microtime(true) - $t;

\printf("    %d vectores en %.1f s (%.2f ms cada uno)\n", CUANTOS, $segundos, $segundos * 1000 / CUANTOS);
eq('estan los 50.000', CUANTOS, $almacen->manifiesto()->cuenta);
ok(\sprintf('indexar cuesta menos de 2 ms por vector: %.2f ms', $segundos * 1000 / CUANTOS),
    $segundos * 1000 / CUANTOS < 2.0);

$mbDisco = (\filesize($dir . '/vectores.f32') + \filesize($dir . '/codigos.bin')
          + \filesize($dir . '/ids.bin')) / 1048576;
\printf("    en disco: %.0f MB (%.0f MB de vectores, %.0f MB de codigos)\n",
    $mbDisco, \filesize($dir . '/vectores.f32') / 1048576, \filesize($dir . '/codigos.bin') / 1048576);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Buscar entre 50.000');

$buscador = new Buscador($almacen);

/*
 * Calentamiento, y hace falta mas del que habia.
 *
 * La primera version hacia UNA busqueda, para construir la tabla de popcount.
 * No bastaba: el archivo de vectores ocupa 147 MB y cada busqueda hace un par
 * de cientos de lecturas sueltas dentro de el. En frio son lecturas de disco de
 * verdad; en caliente las sirve la cache del sistema.
 *
 * Medido en la misma maquina y seguidas: 545 ms la primera ejecucion, 143 y 142
 * las dos siguientes. O sea que el test estaba midiendo si el sistema tenia el
 * archivo cacheado, no lo que tarda buscar, y se ponia rojo o verde segun lo
 * que hubiera corrido antes. Un test de tiempos que depende de eso acaba con
 * que nadie mire los rojos.
 *
 * Ahora se calienta con varias consultas distintas y se mide despues. El numero
 * en frio se enseña igual, porque tambien es verdad y le importa a quien vaya a
 * desplegar esto, pero no se convierte en un rojo.
 */
$enFrio = \microtime(true);
$buscador->buscar($almacen->vectorDe(0), 10);
$enFrio = (\microtime(true) - $enFrio) * 1000;

for ($c = 1; $c <= 3; $c++) {
    $buscador->buscar($almacen->vectorDe(($c * 7919) % CUANTOS), 10);
}
\printf("    primera busqueda, con el archivo aun frio: %.0f ms\n", $enFrio);

$tiempos = [];
for ($c = 0; $c < 5; $c++) {
    $consulta = $almacen->vectorDe(($c * 9973) % CUANTOS);
    $t = \microtime(true);
    $r = $buscador->buscar($consulta, 10);
    $tiempos[] = (\microtime(true) - $t) * 1000;
    if ($c === 0) {
        eq('devuelve los diez pedidos', 10, \count($r));
    }
}
$medio = \array_sum($tiempos) / \count($tiempos);
\printf("    busqueda: %.0f ms de media, %.0f ms la peor\n", $medio, \max($tiempos));

/*
 * El objetivo del plan eran 150 ms y en esta maquina salen 143. El test, en
 * cambio, exige 400: no por conformarse, sino porque un test de tiempos con el
 * margen pegado al valor real se pone rojo en cuanto corre en un runner
 * compartido, y un rojo que no significa nada acaba con que nadie mire los
 * rojos. La medicion buena se anota en el plan; esto vigila que no aparezca una
 * regresion estructural, que se veria como un x3, no como un x1,05.
 */
\printf("    (objetivo del plan: 150 ms. Tope del test: 400 ms, holgura para runners lentos)\n");
ok(\sprintf('por debajo de 400 ms de media: %.0f ms', $medio), $medio < 400.0);
ok(\sprintf('y ninguna pasa de 600 ms: %.0f ms', \max($tiempos)), \max($tiempos) < 600.0);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] La memoria, que es lo que decide si cabe en un hosting');

$pico = \memory_get_peak_usage(true) / 1048576;
$uso  = \memory_get_usage(true) / 1048576;
\printf("    memoria: %.1f MB en uso, %.1f MB de pico con %d vectores de %d dimensiones\n",
    $uso, $pico, CUANTOS, DIMS);

ok(\sprintf('el pico no pasa de 32 MB: %.1f MB', $pico), $pico < 32.0);

/*
 * La prueba de que la memoria no depende del numero de vectores: los codigos
 * binarios de 50.000 vectores de 768 dimensiones son 4,6 MB, y los vectores
 * completos serian 147 MB. Si estuvieran en memoria, esto no pasaria.
 */
$mbCodigos = \strlen($almacen->codigos()) / 1048576;
\printf("    los codigos binarios ocupan %.1f MB; los vectores completos, %.0f MB\n",
    $mbCodigos, CUANTOS * DIMS * 4 / 1048576);
ok('en memoria solo entran los codigos, no los vectores', $pico < CUANTOS * DIMS * 4 / 1048576);

rmrf($dir);
summary();
