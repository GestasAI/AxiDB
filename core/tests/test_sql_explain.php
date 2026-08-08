<?php
/**
 * AxiDB - AxiSQL: EXPLAIN.
 *
 * Un indice que no se usa es peor que no tenerlo: ocupa, hay que mantenerlo y
 * da una falsa sensacion de rapidez. EXPLAIN es la forma de comprobar que una
 * consulta se apoya en el indice de verdad, y con cuantos documentos arranca.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('sql_explain'), ['durable' => false]);

for ($i = 0; $i < 40; $i++) {
    $db->insert('p', [
        'cliente' => 'c' . ($i % 4),
        'estado'  => $i % 2 ? 'abierto' : 'cerrado',
        'total'   => $i,
    ]);
}
$db->sql('CREATE INDEX ON p (cliente)');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Forma de la respuesta');

$e = $db->sql("EXPLAIN SELECT * FROM p WHERE cliente = 'c1'");
ok('EXPLAIN no devuelve documentos, devuelve un plan', ($e['explain'] ?? false) === true);
eq('dice la operacion',   'select', $e['operacion']);
eq('y la coleccion',           'p', $e['coleccion']);
ok('trae estrategia, candidatos y total',
    isset($e['estrategia'], $e['candidatos'], $e['total']));
ok('y una explicacion en castellano', \is_string($e['detalle']) && $e['detalle'] !== '');

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Indice frente a escaneo');

$conIndice = $db->sql("EXPLAIN SELECT * FROM p WHERE cliente = 'c1'");
eq('igualdad sobre campo indexado usa el indice', 'index', $conIndice['estrategia']);
eq('e informa del campo',                      'cliente', $conIndice['campo']);
eq('y del valor buscado',                           'c1', $conIndice['valor']);
eq('lee solo los 10 del indice',                      10, $conIndice['candidatos']);
eq('de un total de 40',                               40, $conIndice['total']);

$sinIndice = $db->sql("EXPLAIN SELECT * FROM p WHERE estado = 'abierto'");
eq('un campo sin indice escanea', 'scan', $sinIndice['estrategia']);
eq('y lee los 40',                    40, $sinIndice['candidatos']);
ok('el detalle lo dice claro', \str_contains($sinIndice['detalle'], 'escaneada'));

eq('sin WHERE tambien escanea', 'scan', $db->sql('EXPLAIN SELECT * FROM p')['estrategia']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Cuando el indice NO puede ayudar');

// Un OR no permite descartar: la otra rama puede cumplirse igualmente.
eq('OR no puede usar el indice', 'scan',
    $db->sql("EXPLAIN SELECT * FROM p WHERE cliente = 'c1' OR estado = 'abierto'")['estrategia']);

// Un NOT invierte la condicion: los que estan en el indice son justo los que sobran.
eq('NOT tampoco', 'scan',
    $db->sql("EXPLAIN SELECT * FROM p WHERE NOT cliente = 'c1'")['estrategia']);

// Un rango no es una igualdad: el indice es por valor exacto.
eq('un rango sobre campo indexado tampoco', 'scan',
    $db->sql("EXPLAIN SELECT * FROM p WHERE cliente > 'c1'")['estrategia']);

// Pero dentro de un AND si, aunque la igualdad no sea la primera condicion.
eq('AND si aprovecha la igualdad indexada', 'index',
    $db->sql("EXPLAIN SELECT * FROM p WHERE total > 5 AND cliente = 'c1'")['estrategia']);
eq('y arranca solo con los del indice', 10,
    $db->sql("EXPLAIN SELECT * FROM p WHERE total > 5 AND cliente = 'c1'")['candidatos']);

eq('AND anidado en profundidad tambien', 'index',
    $db->sql("EXPLAIN SELECT * FROM p WHERE total > 5 AND (estado = 'abierto' AND cliente = 'c1')")['estrategia']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] EXPLAIN no ejecuta');

$antes = $db->count('p');

$db->sql("EXPLAIN INSERT INTO p (cliente) VALUES ('nuevo')");
eq('EXPLAIN INSERT no da de alta', $antes, $db->count('p'));

$e = $db->sql("EXPLAIN DELETE FROM p WHERE cliente = 'c1'");
eq('EXPLAIN DELETE no borra',      $antes, $db->count('p'));
eq('pero dice a cuantos afectaria',    10, $e['documentos']);

$e = $db->sql("EXPLAIN UPDATE p SET estado = 'x' WHERE cliente = 'c2'");
eq('EXPLAIN UPDATE no modifica', 0, \count($db->sql("SELECT * FROM p WHERE estado = 'x'")));
eq('pero dice a cuantos afectaria', 10, $e['documentos']);
eq('y que campos tocaria', ['estado'], $e['campos']);

$e = $db->sql('EXPLAIN DROP COLLECTION p');
eq('EXPLAIN DROP no borra la coleccion', $antes, $db->count('p'));
eq('pero avisa de cuantos se perderian', $antes, $e['documentos']);

$e = $db->sql('EXPLAIN CREATE INDEX ON p (estado)');
eq('EXPLAIN CREATE INDEX no lo construye', ['cliente'], $db->indexes('p'));
eq('pero dice sobre que campo iria', 'estado', $e['campo']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] COUNT tambien se puede explicar');

$e = $db->sql("EXPLAIN SELECT COUNT(*) FROM p WHERE cliente = 'c3'");
eq('la operacion es count', 'count', $e['operacion']);
eq('y usa el indice',       'index', $e['estrategia']);

rmrf($db->path());
summary();
