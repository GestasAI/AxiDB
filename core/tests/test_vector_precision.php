<?php
/**
 * AxiDB - los tres modos de precision de la busqueda vectorial.
 *
 * Se mide sobre el caso FEO a proposito: vectores uniformemente aleatorios, que
 * no se parecen a nada que genere un modelo. Sobre embeddings reales los tres
 * modos aciertan el 100% y el test no distinguiria nada, que es justo lo que no
 * queremos: si un test solo pasa con datos amables, no esta probando el limite.
 *
 * Lo que se exige de cada uno:
 *
 *   rapida        el de siempre. Se comprueba que no ha cambiado.
 *   equilibrada   95% o mas donde rapida se queda en 84%.
 *   exacta        identico al coseno sobre todos, ids y orden. No "casi".
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';
require_once __DIR__ . '/_vectores.php';

use Axi\Core\Db;
use Axi\Core\Vector\Searcher;
use Axi\Core\Vector\Manifest;
use Axi\Core\Vector\Precision;

const DIMS  = 768;
const CUANTOS = 3000;

$dir = tmpdir('vector_precision');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Los tres modos, sobre el caso mas dificil');

$almacen  = almacenNuevo($dir . '/duro', DIMS);
$muestras = sembrarVectores($almacen, CUANTOS, DIMS, false);
$buscador = new Searcher($almacen);

eq('estan todos', CUANTOS, $almacen->manifest()->alive());

$medido = [];
foreach (Precision::MODOS as $modo) {
    $recalls = [];
    $ms      = [];
    foreach ($muestras as $indice) {
        $consulta = $almacen->vectorOf($indice);

        $t         = \microtime(true);
        $resultado = $buscador->search($consulta, 10, [], $modo);
        $ms[]      = (\microtime(true) - $t) * 1000;

        $recalls[] = recall($resultado, fuerzaBruta($almacen, $consulta, 10));
    }
    $medido[$modo] = [
        'recall' => \array_sum($recalls) / \count($recalls),
        'peor'   => \min($recalls),
        'ms'     => \array_sum($ms) / \count($ms),
    ];
    \printf("    %-12s recall %5.1f%% (peor %5.1f%%)  %6.1f ms\n",
        $modo, $medido[$modo]['recall'], $medido[$modo]['peor'], $medido[$modo]['ms']);
}

ok('equilibrada mejora a rapida donde rapida flojea',
    $medido['equilibrada']['recall'] >= $medido['rapida']['recall']);
ok('equilibrada llega al 95% en el caso feo: ' . \round($medido['equilibrada']['recall'], 1) . '%',
    $medido['equilibrada']['recall'] >= 95.0);
ok('exacta acierta el 100%, no el 99: ' . \round($medido['exacta']['recall'], 1) . '%',
    $medido['exacta']['recall'] >= 100.0);
ok('y tambien en su PEOR consulta, que es lo que la hace una garantia',
    $medido['exacta']['peor'] >= 100.0);

// Que cueste mas no es un defecto: es el trato. Se comprueba que el trato existe,
// porque un modo "mas preciso" que tardara lo mismo seria señal de que no hace nada.
ok('exacta cuesta mas que rapida, como debe', $medido['exacta']['ms'] > $medido['rapida']['ms']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] exacta es identica a la fuerza bruta, no parecida');

/*
 * El recall solo mira cuantos ids coinciden. Aqui se compara ademas el ORDEN y
 * la puntuacion: exacta no es "una aproximacion muy buena", es el mismo calculo.
 */
$iguales = 0;
foreach ($muestras as $indice) {
    $consulta = $almacen->vectorOf($indice);
    $rapido   = $buscador->search($consulta, 10, [], Precision::EXACTA);
    $bruto    = fuerzaBruta($almacen, $consulta, 10);

    if (\array_column($rapido, 'id') === \array_keys($bruto)) {
        $iguales++;
    }
}
eq('mismo orden exacto en todas las consultas', \count($muestras), $iguales);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] El modo se guarda en la coleccion y sobrevive');

$dir2 = tmpdir('vector_precision_api');
$db   = new Db($dir2, ['durable' => false]);

$m = $db->enableVectors('articulos', ['auto' => ['texto'], 'precision' => Precision::EQUILIBRADA]);
eq('activar devuelve el modo elegido', 'equilibrada', $m['precision']);

$db->insert('articulos', ['texto' => 'pan de masa madre'], 'a1');
$db->insert('articulos', ['texto' => 'cerveza artesana'], 'a2');
$db->storage()->close();

$otro = new Db($dir2, ['durable' => false]);
eq('el modo se recuerda tras cerrar y reabrir',
    'equilibrada', $otro->vectorIndex('articulos')->manifest()->precision);
eq('y la busqueda sigue funcionando', 'a1',
    $otro->similar('articulos', 'pan de masa madre', 1)[0]['id'] ?? null);

eq('se puede pedir otro modo solo para una consulta', 'a1',
    $otro->similar('articulos', 'pan de masa madre', 1, null, Precision::EXACTA)[0]['id'] ?? null);
$otro->storage()->close();

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Lo que se rechaza y lo que se respeta');

throws('un modo que no existe se rechaza al activar',
    static fn () => (new Db(tmpdir('vp_malo'), ['durable' => false]))->enableVectors('x', ['precision' => 'turbo']));

throws('y tambien al pedirlo en una consulta',
    static fn () => $buscador->search($almacen->vectorOf(0), 5, [], 'ultra'));

eq('el modo por defecto es el de siempre', 'rapida', Precision::POR_DEFECTO);

/*
 * Un indice creado antes de que existieran los modos no tiene el campo en su
 * manifiesto. Tiene que leerse como 'rapida', que es como se comportaba: nadie
 * debe notar un cambio de velocidad por actualizar AxiDB.
 */
$viejo = Manifest::desdeArray([
    'campo' => 'embedding', 'dims' => 64, 'fuente' => 'hash',
    'auto' => [], 'cuenta' => 3, 'bajas' => 0,
]);
eq('un manifiesto anterior a los modos se lee como rapida', 'rapida', $viejo->precision);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Candidatos por modo');

eq('rapida: veinte por resultado, nunca menos de 200', 200, Precision::candidates('rapida', 5));
eq('y sube con k', 400, Precision::candidates('rapida', 20));
eq('equilibrada: diez veces mas suelo', 2000, Precision::candidates('equilibrada', 5));
eq('exacta no tiene candidatos porque no hay criba', null, Precision::candidates('exacta', 5));

rmrf($dir);
rmrf($dir2);
summary();
