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

use Axi\Core\Vector\Almacen;
use Axi\Core\Vector\Codigos;
use Axi\Core\Vector\Cuantizador;
use Axi\Core\Vector\Manifiesto;

$dir = tmpdir('vector_almacen');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Ida y vuelta sin perder nada');

$almacen = almacenNuevo($dir . '/a', 128);

$original = Cuantizador::normalizar([...\array_map(
    static fn(int $i) => \sin($i) * ($i % 7 - 3),
    \range(0, 127)
)]);

$ordinal = $almacen->poner('v1', $original);
eq('el primero ocupa el ordinal cero', 0, $ordinal);

$leido = $almacen->vectorDe(0);
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

eq('el id se recupera', 'v1', $almacen->idDe(0));
eq('y el ordinal desde el id', 0, $almacen->ordinalDe('v1'));
eq('un id que no existe da null', null, $almacen->ordinalDe('nada'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Mil vectores, y todos donde deben');

$almacen2 = almacenNuevo($dir . '/b', 256);
sembrarVectores($almacen2, 1000, 256, true);

eq('mil vectores', 1000, $almacen2->manifiesto()->cuenta);
eq('mil vivos',    1000, $almacen2->manifiesto()->vivos());
eq('cero bajas',      0, $almacen2->manifiesto()->bajas);

$bytes = \filesize($dir . '/b/vectores.f32');
eq('el archivo mide exactamente lo que tiene que medir', 1000 * 256 * 4, $bytes);
eq('y el de codigos tambien',  1000 * 32, \filesize($dir . '/b/codigos.bin'));
eq('y el de ids',              1000 * 64, \filesize($dir . '/b/ids.bin'));

ok('el ordinal 500 tiene su id',  $almacen2->idDe(500) === 'd500');
ok('y su vector se lee entero',   \count($almacen2->vectorDe(500)) === 256);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Sobrevive a cerrar el proceso');

/*
 * Se abre un almacen NUEVO sobre el mismo directorio, como haria la siguiente
 * peticion web. Si algo se estaba quedando solo en memoria, aqui se nota.
 */
$antes = [];
foreach ([0, 1, 499, 999] as $o) {
    $antes[$o] = $almacen2->vectorDe($o);
}
unset($almacen2);

$reabierto = new Almacen($dir . '/b');
eq('el manifiesto se relee', 1000, $reabierto->manifiesto()->cuenta);
eq('y dice las dimensiones',  256, $reabierto->manifiesto()->dims);

$iguales = true;
foreach ($antes as $o => $v) {
    if ($v !== $reabierto->vectorDe($o)) {
        $iguales = false;
    }
}
ok('los vectores son byte a byte los mismos tras reabrir', $iguales);
eq('y los ids tambien', 'd999', $reabierto->idDe(999));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] La cuantizacion binaria hace lo que dice');

$a = Cuantizador::normalizar([1.0, 1.0, 1.0, 1.0, -1.0, -1.0, -1.0, -1.0]);
$b = Cuantizador::normalizar([1.0, 1.0, 1.0, 1.0, -1.0, -1.0, -1.0, -1.0]);
$c = Cuantizador::normalizar([-1.0, -1.0, -1.0, -1.0, 1.0, 1.0, 1.0, 1.0]);

eq('ocho dimensiones caben en un byte', 1, \strlen(Cuantizador::aBinario($a)));
eq('dos vectores iguales estan a distancia cero',
    0, Codigos::distancia(Cuantizador::aBinario($a), Cuantizador::aBinario($b)));
eq('dos opuestos estan a la distancia maxima',
    8, Codigos::distancia(Cuantizador::aBinario($a), Cuantizador::aBinario($c)));

eq('normalizar deja el vector de longitud 1',
    1.0, \round(\sqrt(\array_sum(\array_map(static fn($x) => $x * $x, $a))), 9));
eq('el coseno de un vector consigo mismo es 1', 1.0, \round(Cuantizador::coseno($a, $b), 9));
eq('y con su opuesto, -1',                     -1.0, \round(Cuantizador::coseno($a, $c), 9));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Lo que no se acepta');

throws('un vector con dimensiones de menos',
    static fn() => $almacen->poner('malo', \array_fill(0, 64, 0.1)));
throws('un vector con texto dentro',
    static fn() => Cuantizador::validar(['a', 'b'], 2));
throws('un vector con infinito',
    static fn() => Cuantizador::validar([\INF, 0.0], 2));
throws('dimensiones que no son multiplo de ocho',
    static fn() => Manifiesto::nuevo('embedding', 100, 'test', []));
throws('un id que no cabe en 64 bytes',
    static fn() => $almacen->poner(\str_repeat('x', 65), $original));

$ceros = Cuantizador::normalizar(\array_fill(0, 128, 0.0));
ok('un vector de ceros se acepta y no revienta',
    \is_int($almacen->poner('ceros', $ceros)));

rmrf($dir);
summary();
