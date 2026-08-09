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

use Axi\Core\Vector\Searcher;

const DIMS    = 768;
const CUANTOS = 50000;

$dir     = tmpdir('vector_escala');
$almacen = almacenNuevo($dir, DIMS);

section('A] Indexar 50.000 vectores');

/*
 * Lo que se vigila: que el alta numero 49.000 cueste lo mismo que la 25.000.
 *
 * Habia un tope de 2 ms por vector y se puso rojo con 2,06 y con 2,15 en una
 * maquina cargada, sin que nadie hubiera tocado el indexado. Un numero absoluto
 * mide el ordenador; lo que es del motor es que el coste por alta no crezca con
 * el tamaño de la coleccion. Si algun dia el indexado pasara a reescribir el
 * archivo entero en cada alta —el error clasico— el ultimo bloque se disparara
 * frente al anterior en cualquier maquina.
 *
 * Los dos bloques se toman en regimen, y esto importa: el primer intento
 * comparaba el primer millar contra el ultimo y daba x4,77, con el test en rojo
 * y el motor intacto. Los primeros miles caben en la cache del sistema. Medido
 * de fuera, indexando desde cero cada vez:
 *
 *   5.000 vectores -> 0,751 ms cada uno    (todavia en cache)
 *  10.000 vectores -> 2,054 ms cada uno
 *  20.000 vectores -> 2,026 ms cada uno
 *  40.000 vectores -> 2,068 ms cada uno
 *
 * Plano a partir de los diez mil. Asi que se calienta hasta bien pasado eso y se
 * comparan dos millares separados por veinticuatro mil altas.
 */
$bloque   = 1000;
$calentar = 24000;

sembrarVectores($almacen, $calentar, DIMS, true, 'w');

$t = \microtime(true);
sembrarVectores($almacen, $bloque, DIMS, true, 'a');
$msPrimeros = (\microtime(true) - $t) * 1000 / $bloque;

sembrarVectores($almacen, CUANTOS - $calentar - (2 * $bloque), DIMS, true, 'b');

$t = \microtime(true);
sembrarVectores($almacen, $bloque, DIMS, true, 'c');
$msUltimos = (\microtime(true) - $t) * 1000 / $bloque;

eq('estan los 50.000', CUANTOS, $almacen->manifiesto()->cuenta);

$degrada = $msUltimos / \max($msPrimeros, 0.0001);
\printf("    el millar 25.000: %.2f ms por vector | el ultimo: %.2f ms | x%.2f\n",
    $msPrimeros, $msUltimos, $degrada);

ok(\sprintf('indexar no se encarece segun crece la coleccion: x%.2f entre el millar 25.000 y el ultimo', $degrada),
    $degrada < 2.0);

$mbDisco = (\filesize($dir . '/vectores.f32') + \filesize($dir . '/codigos.bin')
          + \filesize($dir . '/ids.bin')) / 1048576;
\printf("    en disco: %.0f MB (%.0f MB de vectores, %.0f MB de codigos)\n",
    $mbDisco, \filesize($dir . '/vectores.f32') / 1048576, \filesize($dir . '/codigos.bin') / 1048576);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Buscar entre 50.000');

$buscador = new Searcher($almacen);

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

\printf("    (objetivo del plan: 150 ms por busqueda)\n");

/*
 * Aqui habia un tope de 400 ms. Se puso rojo en la CI de Windows con 448: un
 * x1,12 sobre el margen, en un runner compartido, midiendo tiempo de pared. No
 * era una regresion, y el comentario que acompañaba al tope ya decia que lo que
 * se quiere vigilar es un x3 y no un x1,05. Un numero absoluto no sabe distinguir
 * las dos cosas, porque no sabe en que maquina corre.
 *
 * Lo que si es una propiedad del motor y no de la maquina: la criba binaria hace
 * que la segunda pasada lea doscientos vectores en vez de cincuenta mil. Eso se
 * mide contra `exacta`, que es esta misma busqueda sin criba, en la misma maquina
 * y en la misma ejecucion. Si alguien rompe la criba, la ventaja se desploma
 * hacia x1 en cualquier hardware; si el runner va lento, van lentas las dos
 * medidas y la proporcion aguanta.
 */
$picoSinExacta = \memory_get_peak_usage(true) / 1048576;

$t        = \microtime(true);
$conTodos = $buscador->buscar($almacen->vectorDe(31337 % CUANTOS), 10, [], 'exacta');
$sinCriba = (\microtime(true) - $t) * 1000;
eq('sin criba tambien devuelve los diez', 10, \count($conTodos));

$ventaja = $sinCriba / \max($medio, 0.001);
\printf("    sin criba (exacta, lee los 50.000): %.0f ms; la criba ahorra x%.0f\n", $sinCriba, $ventaja);
ok(\sprintf('la criba binaria ahorra al menos x5: x%.1f', $ventaja), $ventaja >= 5.0);

/*
 * Y un techo absoluto, pero muy holgado y solo como aviso de catastrofe: 143 ms
 * en esta maquina, 448 en el runner mas lento que hemos visto. Dos segundos no
 * los cruza el ruido de una maquina compartida; si se cruzan, ha pasado algo
 * gordo que merece mirarse en cualquier hardware.
 */
ok(\sprintf('ninguna busqueda llega a 2 s: %.0f ms la peor', \max($tiempos)), \max($tiempos) < 2000.0);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] La memoria, que es lo que decide si cabe en un hosting');

/*
 * El pico que se mide es el de antes de la busqueda exacta, y no por maquillarlo:
 * `exacta` puntua los cincuenta mil de una vez, asi que su memoria crece con el
 * tamaño de la coleccion. Es el precio conocido del modo que renuncia a la criba,
 * y mezclarlo aqui convertiria esta cifra —la del funcionamiento normal, que es
 * la que decide si esto cabe en un hosting compartido— en otra cosa. Se enseñan
 * las dos, porque las dos son verdad.
 */
$pico = $picoSinExacta;
$uso  = \memory_get_usage(true) / 1048576;
\printf("    memoria: %.1f MB en uso, %.1f MB de pico con %d vectores de %d dimensiones\n",
    $uso, $pico, CUANTOS, DIMS);
\printf("    (con una busqueda exacta de por medio, el pico sube a %.1f MB)\n",
    \memory_get_peak_usage(true) / 1048576);

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
