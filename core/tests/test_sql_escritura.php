<?php
/**
 * AxiDB - AxiSQL: escritura. INSERT, UPDATE y DELETE.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('sql_escritura'), ['durable' => false]);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] INSERT');

$r = $db->sql("INSERT INTO p (nombre, precio) VALUES ('cafe', 2)");
ok('devuelve el documento con su id', \is_array($r) && !empty($r['id']));
eq('el texto se guarda',      'cafe', $r['nombre']);
eq('el numero se guarda',          2, $r['precio']);
eq('_version arranca en 1',        1, $r['_version']);
eq('hay un documento',             1, $db->count('p'));

$db->sql("INSERT INTO p (nombre, precio, disponible, notas) VALUES ('te', 3.5, TRUE, NULL)");
$te = $db->sql("SELECT * FROM p WHERE nombre = 'te'")[0];
ok('el decimal se guarda como decimal', \is_float($te['precio']));
eq('el booleano se guarda',       true, $te['disponible']);
ok('el nulo se guarda',                 \array_key_exists('notas', $te) && $te['notas'] === null);

$db->sql("INSERT INTO p (nombre) VALUES ('Cañon con \"comillas\" y ''apostrofes''')");
eq('el texto con comillas y acentos viaja intacto',
    'Cañon con "comillas" y \'apostrofes\'',
    $db->sql("SELECT * FROM p WHERE precio IS NULL")[0]['nombre']);

throws('INSERT con columnas y valores descuadrados',
    static fn() => $db->sql("INSERT INTO p (a, b) VALUES (1)"));
throws('INSERT sin VALUES',
    static fn() => $db->sql('INSERT INTO p (a)'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] UPDATE');

$db->dropCollection('p');
foreach ([['cafe', 2, 'bebida'], ['te', 3, 'bebida'], ['tarta', 5, 'postre']] as $i => [$n, $pr, $c]) {
    $db->insert('p', ['nombre' => $n, 'precio' => $pr, 'cat' => $c], 'd' . $i);
}

$r = $db->sql("UPDATE p SET precio = 99 WHERE nombre = 'cafe'");
eq('informa de cuantos ha cambiado', ['updated' => 1], $r);
eq('el valor cambio',            99, $db->get('p', 'd0')['precio']);
eq('los demas campos se conservan', 'bebida', $db->get('p', 'd0')['cat']);
eq('_version sube',               2, $db->get('p', 'd0')['_version']);

eq('UPDATE de varios a la vez', ['updated' => 2],
    $db->sql("UPDATE p SET cat = 'liquido' WHERE cat = 'bebida'"));
eq('los dos cambiaron', 2, \count($db->sql("SELECT * FROM p WHERE cat = 'liquido'")));

eq('UPDATE de varios campos', ['updated' => 1],
    $db->sql("UPDATE p SET precio = 7, cat = 'dulce' WHERE nombre = 'tarta'"));
$t = $db->sql("SELECT * FROM p WHERE nombre = 'tarta'")[0];
eq('el primero',   7, $t['precio']);
eq('y el segundo', 'dulce', $t['cat']);

eq('UPDATE sin coincidencias no cambia nada', ['updated' => 0],
    $db->sql("UPDATE p SET precio = 1 WHERE nombre = 'zzz'"));

// Sin WHERE afecta a toda la coleccion. Es lo que dice la sentencia, y se cumple.
eq('UPDATE sin WHERE toca todos', ['updated' => 3], $db->sql('UPDATE p SET revisado = TRUE'));
eq('y todos quedan marcados', 3, \count($db->sql('SELECT * FROM p WHERE revisado = TRUE')));

throws('UPDATE sin SET', static fn() => $db->sql('UPDATE p WHERE nombre = 1'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] DELETE');

eq('borra los que coinciden', ['deleted' => 1], $db->sql("DELETE FROM p WHERE nombre = 'tarta'"));
eq('queda uno menos', 2, $db->count('p'));
eq('DELETE sin coincidencias', ['deleted' => 0], $db->sql("DELETE FROM p WHERE nombre = 'zzz'"));
eq('DELETE con condicion compuesta', ['deleted' => 1],
    $db->sql("DELETE FROM p WHERE cat = 'liquido' AND precio > 50"));
eq('queda el ultimo', 1, $db->count('p'));
eq('DELETE sin WHERE vacia la coleccion', ['deleted' => 1], $db->sql('DELETE FROM p'));
eq('no queda nada', 0, $db->count('p'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] La escritura mantiene los indices');

$db->dropCollection('q');
$db->sql('CREATE COLLECTION q');
$db->sql('CREATE INDEX ON q (cliente)');

for ($i = 0; $i < 6; $i++) {
    $db->sql("INSERT INTO q (cliente, total) VALUES ('c" . ($i % 2) . "', " . (10 + $i) . ')');
}
eq('las altas entran en el indice', 3, \count($db->by('q', 'cliente', 'c0')));
eq('y el otro valor tambien',       3, \count($db->by('q', 'cliente', 'c1')));

$db->sql("UPDATE q SET cliente = 'c2' WHERE total = 10");
eq('el UPDATE saca del indice viejo', 2, \count($db->by('q', 'cliente', 'c0')));
eq('y mete en el nuevo',              1, \count($db->by('q', 'cliente', 'c2')));

$db->sql("DELETE FROM q WHERE cliente = 'c1'");
eq('el DELETE limpia el indice', 0, \count($db->by('q', 'cliente', 'c1')));
eq('sin tocar los demas',        2, \count($db->by('q', 'cliente', 'c0')));

eq('el indice sigue cuadrando con los documentos', 0, $db->verifyIndexes('q')['cliente']['faltan']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Los afectados se resuelven antes de escribir');

/*
 * Un UPDATE que cambia el mismo campo por el que filtra no puede quedarse a
 * medias ni entrar en bucle: la lista de documentos se resuelve entera antes de
 * tocar el primero.
 */
$db->dropCollection('r');
for ($i = 0; $i < 5; $i++) {
    $db->insert('r', ['estado' => 'pendiente', 'n' => $i]);
}
eq('cambiar el campo del filtro afecta a todos una vez', ['updated' => 5],
    $db->sql("UPDATE r SET estado = 'hecho' WHERE estado = 'pendiente'"));
eq('ninguno se queda sin cambiar', 5, \count($db->sql("SELECT * FROM r WHERE estado = 'hecho'")));

// _version 2 en todos = un alta y exactamente una modificacion. Si la lista se
// hubiera reevaluado sobre la marcha, alguno tendria una version mas alta.
$versiones = \array_unique(\array_column($db->sql('SELECT * FROM r'), '_version'));
eq('cada documento se escribio exactamente una vez', [2], \array_values($versiones));
eq('repetir la sentencia ya no afecta a nadie', ['updated' => 0],
    $db->sql("UPDATE r SET estado = 'hecho' WHERE estado = 'pendiente'"));

rmrf($db->path());
summary();
