<?php
/**
 * AxiDB - saber que esta pasando ahi dentro.
 *
 * Lo que hace falta para poner esto en produccion y no enterarse de los
 * problemas por un cliente.
 *
 * La parte que de verdad importa es la ultima: que un problema REAL aparezca en
 * la revision. Un panel de salud que siempre dice "todo bien" es peor que no
 * tener panel, porque da confianza sin motivo.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir = tmpdir('salud');
$db  = new Db($dir, ['durable' => false]);

$db->sql("INSERT INTO clientes (nombre, ciudad) VALUES ('Ana', 'Murcia'), ('Juan', 'Lorca')");
$db->put('clientes', 'raro', ['nombre' => 'Eva', 'notas' => 'solo este lo tiene'], true);
$db->sql('CREATE UNIQUE INDEX ON clientes (nombre)');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] describir(): una foto de lo que hay');

$campos = [];
foreach ($db->describir('clientes') as $c) {
    $campos[$c['campo']] = $c;
}

eq('dice el tipo que ve',            'texto', $campos['ciudad']['tipo'] ?? null);
eq('y en cuantos documentos esta',         2, $campos['ciudad']['documentos'] ?? null);
eq('de cuantos',                           3, $campos['ciudad']['de'] ?? null);

/*
 * Este es el dato que importa en una coleccion sin esquema: saber que `notas`
 * esta en uno de tres es lo que avisa de que el dato no es fiable. Saber solo
 * que el campo existe no avisa de nada.
 */
eq('un campo que solo tiene uno se ve como tal', 1, $campos['notas']['documentos'] ?? null);

$db->declararEsquema('clientes', ['nombre' => ['tipo' => 'texto', 'obligatorio' => true]]);
$campos = [];
foreach ($db->describir('clientes') as $c) {
    $campos[$c['campo']] = $c;
}
eq('lo declarado se distingue de lo observado', 'texto', $campos['nombre']['declarado'] ?? null);

// Ojo con `??` aqui: el valor ES null, asi que `?? 'x'` devolveria 'x' y el
// test comprobaria lo contrario de lo que dice. Hay que mirar la clave.
ok('y lo no declarado se ve vacio',
    \array_key_exists('declarado', $campos['ciudad']) && $campos['ciudad']['declarado'] === null);

// Un campo con dos tipos en distintos documentos: el aviso mas util de todos.
$db->put('clientes', 'mixto', ['nombre' => 'Z', 'ciudad' => 42], true);
$campos = [];
foreach ($db->describir('clientes') as $c) {
    $campos[$c['campo']] = $c;
}
ok('un campo guardado de dos maneras se ve',
    \str_contains((string) ($campos['ciudad']['tipo'] ?? ''), '|'));
$db->delete('clientes', 'mixto');

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] estadisticas(): tamaño y forma');

$e = $db->estadisticas('clientes');

eq('cuenta los documentos',  3, $e['documentos']);
eq('dice el driver',      'fs', $e['driver']);
eq('la durabilidad',    'fast', $e['durabilidad']);
eq('si esta cifrada',    false, $e['cifrada']);
eq('los campos unicos', ['nombre'], $e['unicos']);
eq('los indices',       ['nombre'], $e['indices']);
eq('si tiene vectores',  false, $e['vectores']);
ok('lo que ocupa en disco', $e['bytes'] > 0);
eq('y cuanto sobra',      0.0, $e['proporcionMuerta']);

$db->declararCaducidad('sesiones', 3600);
eq('la caducidad de otra coleccion', 3600, $db->estadisticas('sesiones')['caducidad']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] revision(): un vistazo a todo');

$r = $db->revision();

ok('cuenta las colecciones', $r['colecciones'] >= 2);
ok('y los documentos',       $r['documentos'] >= 3);
ok('y los bytes',            $r['bytes'] > 0);
eq('con todo sano, ningun aviso', [], $r['avisos']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Un problema de verdad aparece, y dice como arreglarlo');

/*
 * Una reserva de un campo unico sin documento detras. Pasa si un proceso muere
 * entre reservar y escribir. No se pierde nada, pero ese valor queda bloqueado
 * y quien lo intente usar vera "ya existe" sobre algo que no existe.
 */
$db->indice()->reclamar('clientes', 'nombre', 'fantasma', 'nunca-escrito');

$avisos = $db->revision()['avisos'];
eq('sale un aviso', 1, \count($avisos));
eq('sobre la coleccion correcta', 'clientes', $avisos[0]['coleccion'] ?? null);
ok('explica que pasa', \str_contains((string) ($avisos[0]['que'] ?? ''), 'sin documento'));
ok('y dice que hacer', \str_contains((string) ($avisos[0]['hacer'] ?? ''), 'reindex'));

$db->reindex('clientes');
eq('y tras hacerlo, se apaga', [], $db->revision()['avisos']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Un indice al que le faltan documentos es GRAVE');

/*
 * Peor que sobrar: si al indice le faltan entradas, `by()` no encuentra
 * documentos que existen. Es invisible desde fuera y por eso tiene que salir
 * aqui con la gravedad mas alta.
 */
$db->indice()->remove('clientes', 'nombre', 'Ana', $db->by('clientes', 'nombre', 'Ana')[0]['id'] ?? 'x');

$avisos = $db->revision()['avisos'];
eq('sale como grave', 'grave', $avisos[0]['gravedad'] ?? null);
ok('y dice que by() no los encuentra',
    \str_contains((string) ($avisos[0]['que'] ?? ''), 'by()'));

$db->reindex('clientes');
eq('reindexar lo arregla', [], $db->revision()['avisos']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Espacio muerto en el formato empaquetado');

$dirP = tmpdir('salud_packed');
$p = new Db($dirP, ['durable' => false]);
$p->storage()->declararDriver('log', 'packed');
for ($i = 0; $i < 40; $i++) {
    $p->insert('log', ['n' => $i, 'relleno' => \str_repeat('x', 200)], 'd' . $i);
}
for ($i = 0; $i < 30; $i++) {
    $p->delete('log', 'd' . $i);
}

$e = $p->estadisticas('log');
ok('se ve que sobra espacio: ' . \round($e['proporcionMuerta'] * 100) . '%',
    $e['proporcionMuerta'] > 0.25);

$avisos = $p->revision()['avisos'];
ok('y sale el aviso', \count($avisos) >= 1);
ok('diciendo que compactar', \str_contains((string) ($avisos[0]['hacer'] ?? ''), 'compactar'));

$p->storage()->compactar('log');
eq('compactar lo baja a cero', 0.0, $p->estadisticas('log')['proporcionMuerta']);
eq('sin perder los que quedaban', 10, $p->count('log'));

$p->storage()->cerrar();
rmrf($dirP);

$db->storage()->cerrar();
rmrf($dir);
summary();
