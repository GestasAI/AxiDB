<?php
/**
 * AxiDB - los vectores se guardan y se recuperan tal cual.
 *
 * Antes de preguntarse si la busqueda es buena hay que asegurar lo de debajo:
 * que un vector escrito hoy es exactamente el mismo que se lee mañana, en otro
 * proceso, sin haber perdido un decimal por el camino.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';
require_once __DIR__ . '/_vectores.php';

use Axi\Core\Vector\Store;
use Axi\Core\Vector\Codes;
use Axi\Core\Vector\Quantizer;
use Axi\Core\Vector\Manifest;

$dir = tmpdir('vector_almacen');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Ida y vuelta sin perder nada');

$almacen = almacenNuevo($dir . '/a', 128);

$original = Quantizer::normalizar([...\array_map(
    static fn(int $i) => \sin($i) * ($i % 7 - 3),
    \range(0, 127)
)]);

$ordinal = $almacen->put('v1', $original);
eq('el primero ocupa el ordinal cero', 0, $ordinal);

$leido = $almacen->vectorOf(0);
eq('vuelven las 128 dimensiones', 128, \count($leido));

/*
 * float32, no float64: al guardar se pierden decimales a proposito. Cuatro
 * bytes por dimension en vez de ocho es la mitad de disco y de lectura, y para
 * comparar direcciones sobra. Lo que se exige es que la perdida sea la del
 * formato y nada mas.
 */
$maxError = 0.0;
foreach ($original as $i => $v) {
    $maxError = \max($maxError, \abs($v - $leido[$i]));
}
\printf("    mayor diferencia tras el viaje: %.3e\n", $maxError);
ok('la diferencia es la propia de float32, no un error', $maxError < 1e-6);

eq('el id se recupera', 'v1', $almacen->idOf(0));
eq('y el ordinal desde el id', 0, $almacen->ordinalOf('v1'));
eq('un id que no existe da null', null, $almacen->ordinalOf('nada'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Mil vectores, y todos donde deben');

$almacen2 = almacenNuevo($dir . '/b', 256);
sembrarVectores($almacen2, 1000, 256, true);

eq('mil vectores', 1000, $almacen2->manifest()->cuenta);
eq('mil vivos',    1000, $almacen2->manifest()->alive());
eq('cero bajas',      0, $almacen2->manifest()->bajas);

$bytes = \filesize($dir . '/b/vectores.f32');
eq('el archivo mide exactamente lo que tiene que medir', 1000 * 256 * 4, $bytes);
eq('y el de codigos tambien',  1000 * 32, \filesize($dir . '/b/codigos.bin'));
eq('y el de ids',              1000 * 64, \filesize($dir . '/b/ids.bin'));

ok('el ordinal 500 tiene su id',  $almacen2->idOf(500) === 'd500');
ok('y su vector se lee entero',   \count($almacen2->vectorOf(500)) === 256);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Sobrevive a cerrar el proceso');

/*
 * Se abre un almacen NUEVO sobre el mismo directorio, como haria la siguiente
 * peticion web. Si algo se estaba quedando solo en memoria, aqui se nota.
 */
$antes = [];
foreach ([0, 1, 499, 999] as $o) {
    $antes[$o] = $almacen2->vectorOf($o);
}
unset($almacen2);

$reabierto = new Store($dir . '/b');
eq('el manifiesto se relee', 1000, $reabierto->manifest()->cuenta);
eq('y dice las dimensiones',  256, $reabierto->manifest()->dims);

$iguales = true;
foreach ($antes as $o => $v) {
    if ($v !== $reabierto->vectorOf($o)) {
        $iguales = false;
    }
}
ok('los vectores son byte a byte los mismos tras reabrir', $iguales);
eq('y los ids tambien', 'd999', $reabierto->idOf(999));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] La cuantizacion binaria hace lo que dice');

$a = Quantizer::normalizar([1.0, 1.0, 1.0, 1.0, -1.0, -1.0, -1.0, -1.0]);
$b = Quantizer::normalizar([1.0, 1.0, 1.0, 1.0, -1.0, -1.0, -1.0, -1.0]);
$c = Quantizer::normalizar([-1.0, -1.0, -1.0, -1.0, 1.0, 1.0, 1.0, 1.0]);

eq('ocho dimensiones caben en un byte', 1, \strlen(Quantizer::aBinario($a)));
eq('dos vectores iguales estan a distancia cero',
    0, Codes::distancia(Quantizer::aBinario($a), Quantizer::aBinario($b)));
eq('dos opuestos estan a la distancia maxima',
    8, Codes::distancia(Quantizer::aBinario($a), Quantizer::aBinario($c)));

eq('normalizar deja el vector de longitud 1',
    1.0, \round(\sqrt(\array_sum(\array_map(static fn($x) => $x * $x, $a))), 9));
eq('el coseno de un vector consigo mismo es 1', 1.0, \round(Quantizer::coseno($a, $b), 9));
eq('y con su opuesto, -1',                     -1.0, \round(Quantizer::coseno($a, $c), 9));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Lo que no se acepta');

throws('un vector con dimensiones de menos',
    static fn() => $almacen->put('malo', \array_fill(0, 64, 0.1)));
throws('un vector con texto dentro',
    static fn() => Quantizer::validar(['a', 'b'], 2));
throws('un vector con infinito',
    static fn() => Quantizer::validar([\INF, 0.0], 2));
throws('dimensiones que no son multiplo de ocho',
    static fn() => Manifest::nuevo('embedding', 100, 'test', []));
throws('un id que no cabe en 64 bytes',
    static fn() => $almacen->put(\str_repeat('x', 65), $original));

$ceros = Quantizer::normalizar(\array_fill(0, 128, 0.0));
ok('un vector de ceros se acepta y no revienta',
    \is_int($almacen->put('ceros', $ceros)));

rmrf($dir);
summary();
