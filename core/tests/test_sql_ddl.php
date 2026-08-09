<?php
/**
 * AxiDB - AxiSQL: estructura. CREATE y DROP de colecciones e indices.
 *
 * Lo importante de este archivo: CREATE INDEX tiene que CONSTRUIR el indice.
 * El motor anterior se limitaba a anotarlo en un metadato y devolver exito, asi
 * que la consulta siguiente seguia escaneando la coleccion entera y nadie se
 * enteraba de que la optimizacion no existia.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('sql_ddl'), ['durable' => false]);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] CREATE y DROP COLLECTION');

eq('CREATE COLLECTION informa del nombre', ['created' => 'clientes'],
    $db->sql('CREATE COLLECTION clientes'));
ok('la coleccion aparece en el listado', \in_array('clientes', $db->collections(), true));
eq('nace vacia', 0, $db->count('clientes'));

eq('repetirlo no rompe', ['created' => 'clientes'], $db->sql('CREATE COLLECTION clientes'));
eq('TABLE vale como sinonimo', ['created' => 'facturas'], $db->sql('CREATE TABLE facturas'));

$db->sql("INSERT INTO clientes (nombre) VALUES ('Ana')");
eq('DROP COLLECTION la elimina', ['dropped' => true], $db->sql('DROP COLLECTION clientes'));
ok('ya no esta en el listado', !\in_array('clientes', $db->collections(), true));
eq('DROP de una que no existe devuelve false', ['dropped' => false],
    $db->sql('DROP COLLECTION fantasma'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] CREATE INDEX construye de verdad');

$db->sql('CREATE COLLECTION pedidos');
for ($i = 0; $i < 30; $i++) {
    $db->insert('pedidos', ['cliente' => 'c' . ($i % 5), 'total' => $i]);
}

eq('sin indice, la consulta escanea', 'scan',
    $db->sql("EXPLAIN SELECT * FROM pedidos WHERE cliente = 'c1'")['estrategia']);

$r = $db->sql('CREATE INDEX ON pedidos (cliente)');
eq('informa del campo indexado', 'cliente', $r['indexed']);
eq('y de cuantos valores distintos', 5, $r['values']);

ok('el indice existe en disco', \is_dir($db->path() . '/pedidos/_idx/cliente'));
eq('el indice queda declarado', ['cliente'], $db->indexes('pedidos'));

eq('ahora la consulta usa el indice', 'index',
    $db->sql("EXPLAIN SELECT * FROM pedidos WHERE cliente = 'c1'")['estrategia']);
eq('y devuelve lo mismo que antes', 6, \count($db->sql("SELECT * FROM pedidos WHERE cliente = 'c1'")));
eq('el indice cuadra con los documentos', 0, $db->verifyIndexes('pedidos')['cliente']['faltan']);

eq('reconstruirlo es idempotente', 5, $db->sql('CREATE INDEX ON pedidos (cliente)')['values']);
eq('y sigue completo', 6, \count($db->sql("SELECT * FROM pedidos WHERE cliente = 'c1'")));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] DROP INDEX');

eq('DROP INDEX lo elimina', ['dropped' => true], $db->sql('DROP INDEX ON pedidos (cliente)'));
eq('ya no esta declarado', [], $db->indexes('pedidos'));
eq('la consulta vuelve a escanear', 'scan',
    $db->sql("EXPLAIN SELECT * FROM pedidos WHERE cliente = 'c1'")['estrategia']);
eq('pero el resultado no cambia', 6, \count($db->sql("SELECT * FROM pedidos WHERE cliente = 'c1'")));
eq('DROP de un indice inexistente', ['dropped' => false], $db->sql('DROP INDEX ON pedidos (nada)'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] UNIQUE se comprueba al crearlo Y se cumple despues');

$db->sql('CREATE COLLECTION usuarios');
$db->sql("INSERT INTO usuarios (email) VALUES ('ana@ejemplo.es')");
$db->sql("INSERT INTO usuarios (email) VALUES ('luis@ejemplo.es')");

$r = $db->sql('CREATE UNIQUE INDEX ON usuarios (email)');
eq('con valores unicos se crea', true, $r['unique']);
eq('e indexa los dos',            2, $r['values']);

// Lo que antes NO pasaba: la restriccion se declaraba y luego no se cumplia.
throws('y a partir de ahi un repetido se rechaza',
    static fn() => $db->sql("INSERT INTO usuarios (email) VALUES ('ana@ejemplo.es')"));
eq('el repetido no entro', 2, $db->count('usuarios'));

// Con repetidos ya dentro, declararlo unico se rechaza: la coleccion quedaria
// en un estado que ninguna escritura posterior podria arreglar.
$db->sql('CREATE COLLECTION sucia');
$db->sql("INSERT INTO sucia (email) VALUES ('ana@ejemplo.es')");
$db->sql("INSERT INTO sucia (email) VALUES ('ana@ejemplo.es')");

throws('con valores repetidos se rechaza',
    static fn() => $db->sql('CREATE UNIQUE INDEX ON sucia (email)'));

$mensaje = '';
try {
    $db->sql('CREATE UNIQUE INDEX ON sucia (email)');
} catch (\Axi\Core\Exception $e) {
    $mensaje = $e->getMessage();
}
ok('el error dice que valor se repite', \str_contains($mensaje, 'ana@ejemplo.es'));
ok('y en que documentos', \str_contains($mensaje, 'se repite en'));

throws('UNIQUE sin INDEX no tiene sentido',
    static fn() => $db->sql('CREATE UNIQUE COLLECTION x'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Errores de sintaxis del DDL');

foreach ([
    'CREATE'                       => 'CREATE a secas',
    'CREATE INDEX pedidos'         => 'falta ON',
    'CREATE INDEX ON pedidos'      => 'falta el campo entre parentesis',
    'CREATE INDEX ON pedidos (a'   => 'parentesis sin cerrar',
    'DROP'                         => 'DROP a secas',
    'DROP TRIGGER x'               => 'objeto no soportado',
    'CREATE COLLECTION'            => 'falta el nombre',
] as $sql => $motivo) {
    throws("rechaza: {$motivo}", static fn() => $db->sql($sql));
}

rmrf($db->path());
summary();
