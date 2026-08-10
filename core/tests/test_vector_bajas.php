<?php
/**
 * AxiDB - borrar vectores sin desordenar los demas.
 *
 * En un archivo de paso fijo, quitar uno del medio desplazaria todo lo que hay
 * detras: con 50.000 vectores serian 150 MB movidos por cada borrado. Asi que no
 * se borra: se marca la posicion como vacia y se ignora al buscar.
 *
 * Lo que hay que demostrar es que ese apaño no ensucia los resultados —un
 * documento borrado no puede volver a aparecer— y que la limpieza posterior
 * deja el indice igual de correcto.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';
require_once __DIR__ . '/_vectores.php';

use Axi\Core\Vector\Searcher;

const DIMS    = 128;
const CUANTOS = 3000;

$dir     = tmpdir('vector_bajas');
$almacen = almacenNuevo($dir, DIMS);
sembrarVectores($almacen, CUANTOS, DIMS, true);
$buscador = new Searcher($almacen);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Un borrado desaparece de los resultados');

$consulta = $almacen->vectorOf(100);
$primero  = $buscador->search($consulta, 1)[0]['id'];
eq('el mas parecido a si mismo es el mismo', 'd100', $primero);

ok('se borra', $almacen->remove('d100'));
eq('y ya no sale', false, \in_array('d100', \array_column($buscador->search($consulta, 10), 'id'), true));
eq('borrarlo dos veces devuelve false', false, $almacen->remove('d100'));
eq('cuenta una baja', 1, $almacen->manifest()->bajas);
eq('y quedan los demas', CUANTOS - 1, $almacen->manifest()->alive());

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Mil bajas y los resultados siguen siendo correctos');

$borrados = [];
for ($i = 1; $i <= 1000; $i++) {
    $id = 'd' . ($i * 2);                   // los pares
    if ($almacen->remove($id)) {
        $borrados[$id] = true;
    }
}
\printf("    %d borrados, %d vivos, %d bajas\n",
    \count($borrados), $almacen->manifest()->alive(), $almacen->manifest()->bajas);

eq('las cuentas cuadran', CUANTOS - \count($borrados) - 1, $almacen->manifest()->alive());

$colados = 0;
for ($c = 0; $c < 10; $c++) {
    foreach ($buscador->search($almacen->vectorOf($c * 13 + 1), 20) as $r) {
        if (isset($borrados[$r['id']]) || $r['id'] === 'd100') {
            $colados++;
        }
    }
}
eq('ningun borrado se cuela en 200 resultados', 0, $colados);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] La compactacion');

ok('con un tercio de bajas, conviene compactar', $almacen->manifest()->shouldCompact());

$vivosAntes = $almacen->manifest()->alive();
$bajasAntes = $almacen->manifest()->bajas;
$referencia = $buscador->search($almacen->vectorOf(1), 10);
$bytesAntes = \filesize($dir . '/vectores.f32');

$retiradas = $almacen->compact();
\printf("    compactado: %d bajas retiradas, el archivo pasa de %.0f a %.0f KB\n",
    $retiradas, $bytesAntes / 1024, \filesize($dir . '/vectores.f32') / 1024);

// Contra lo que el propio motor contaba, no contra un numero calculado a mano:
// d100 tambien era par y ya estaba borrado, asi que 1000 + 1 daba de mas.
eq('retira exactamente las bajas que habia', $bajasAntes, $retiradas);
eq('los vivos siguen siendo los mismos', $vivosAntes, $almacen->manifest()->alive());
eq('y ya no hay bajas',              0, $almacen->manifest()->bajas);
ok('el archivo encogio',             \filesize($dir . '/vectores.f32') < $bytesAntes);
eq('y mide lo justo', $vivosAntes * DIMS * 4, \filesize($dir . '/vectores.f32'));

ok('compactar otra vez no hace nada', $almacen->compact() === 0);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Y despues de compactar, todo sigue en su sitio');

$buscador2 = new Searcher($almacen);
$despues   = $buscador2->search($almacen->vectorOf($almacen->ordinalOf('d1')), 10);

eq('la misma consulta da los mismos resultados',
    \array_column($referencia, 'id'), \array_column($despues, 'id'));

eq('los ids siguen apuntando a su vector', 'd1', $almacen->idOf($almacen->ordinalOf('d1')));
eq('un borrado sigue sin existir',        null, $almacen->ordinalOf('d100'));

/*
 * Y se puede seguir escribiendo con normalidad. El vector es distinto de todos
 * los demas a proposito: copiando el de otro documento, los dos tendrian el
 * mismo parecido con la consulta y cual sale primero lo decidiria el azar. Un
 * test que depende de un empate no prueba nada.
 */
$aparte = \array_fill(0, DIMS, 0.0);
$aparte[0] = 1.0;
$almacen->put('nuevo', $aparte);

eq('se añade uno nuevo tras compactar', $vivosAntes + 1, $almacen->manifest()->alive());
eq('y se encuentra a si mismo', 'nuevo', $buscador2->search($aparte, 1)[0]['id']);

rmrf($dir);
summary();
