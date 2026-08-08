<?php
/**
 * AxiDB - test de indices secundarios.
 *
 * El indice generico sustituye al "indice por inquilino" que MyLocal tenia
 * clavado a local_id. Aqui se comprueba que funciona con cualquier campo y
 * cualquier valor, sin que el motor sepa nada del dominio.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir = tmpdir('index');
$db  = new Db($dir, ['durable' => false]);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Declarar y mantener');

$db->index('presupuestos', 'cliente_id');
eq('el indice queda declarado', ['cliente_id'], $db->indexes('presupuestos'));

$db->insert('presupuestos', ['cliente_id' => 'c1', 'total' => 100], 'p1');
$db->insert('presupuestos', ['cliente_id' => 'c1', 'total' => 200], 'p2');
$db->insert('presupuestos', ['cliente_id' => 'c2', 'total' => 300], 'p3');

eq('by() del cliente c1 devuelve 2', 2, \count($db->by('presupuestos', 'cliente_id', 'c1')));
eq('by() del cliente c2 devuelve 1', 1, \count($db->by('presupuestos', 'cliente_id', 'c2')));
eq('by() de un valor sin datos da 0', 0, \count($db->by('presupuestos', 'cliente_id', 'c9')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] El indice se actualiza al cambiar el valor');

$db->update('presupuestos', 'p1', ['cliente_id' => 'c2']);
eq('c1 pierde el documento movido',  1, \count($db->by('presupuestos', 'cliente_id', 'c1')));
eq('c2 lo gana',                     2, \count($db->by('presupuestos', 'cliente_id', 'c2')));

$db->update('presupuestos', 'p1', ['total' => 999]);
eq('cambiar otro campo no altera el indice', 2, \count($db->by('presupuestos', 'cliente_id', 'c2')));

$db->delete('presupuestos', 'p1');
eq('borrar saca el id del indice', 1, \count($db->by('presupuestos', 'cliente_id', 'c2')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Construir sobre datos preexistentes');

$db2 = new Db(tmpdir('index2'), ['durable' => false]);
for ($i = 0; $i < 30; $i++) {
    $db2->insert('items', ['grupo' => 'g' . ($i % 3), 'n' => $i]);
}
eq('sin indice, by() escanea y acierta', 10, \count($db2->by('items', 'grupo', 'g1')));

$valores = $db2->index('items', 'grupo');
eq('build indexa los 3 valores distintos', 3, $valores);
eq('con indice, by() sigue acertando',    10, \count($db2->by('items', 'grupo', 'g1')));
eq('el indice tiene los 10 ids',          10, \count($db2->indexer()->ids('items', 'grupo', 'g1') ?? []));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Reparacion de un indice dañado');

$idx = $db2->path() . '/items/_idx/grupo/g1.json';
\file_put_contents($idx, \json_encode(['solo-uno']));
eq('el indice dañado devuelve datos incompletos', 0, \count($db2->by('items', 'grupo', 'g1')));

$db2->index('items', 'grupo');
eq('reconstruir lo repara', 10, \count($db2->by('items', 'grupo', 'g1')));

\file_put_contents($idx, 'esto no es json');
$db2->index('items', 'grupo');
eq('reconstruir repara tambien un indice corrupto', 10, \count($db2->by('items', 'grupo', 'g1')));

eq('reindex devuelve el recuento por campo', ['grupo' => 3], $db2->reindex('items'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D2] ensureIndex: declarar sin reconstruir');

$db4 = new Db(tmpdir('index4'), ['durable' => false]);
ok('la primera llamada lo crea',      $db4->ensureIndex('c', 'campo'));
ok('la segunda no hace nada',        !$db4->ensureIndex('c', 'campo'));
eq('y queda declarado', ['campo'], $db4->indexes('c'));

$db4->insert('c', ['campo' => 'v1'], 'a');
eq('la primera alta ya entra en el indice', 1, \count($db4->by('c', 'campo', 'v1')));

// Sobre datos preexistentes, ensureIndex construye una vez y luego se aparta.
$db5 = new Db(tmpdir('index5'), ['durable' => false]);
for ($i = 0; $i < 12; $i++) {
    $db5->insert('c', ['campo' => 'g' . ($i % 4)]);
}
ok('lo crea sobre datos que ya estaban',    $db5->ensureIndex('c', 'campo'));
eq('e indexa los existentes',            3, \count($db5->by('c', 'campo', 'g0')));
ok('la siguiente llamada no reconstruye', !$db5->ensureIndex('c', 'campo'));

// Un indice vaciado a mano no se reconstruye solo: para eso esta index().
$db5->indexer()->drop('c', 'campo');
ok('tras borrarlo, ensureIndex vuelve a crearlo', $db5->ensureIndex('c', 'campo'));
eq('y queda completo otra vez',               3, \count($db5->by('c', 'campo', 'g0')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D3] verify: detectar un indice que se quedo corto');

/*
 * Los documentos se sincronizan a disco con fsync; los indices no, porque son
 * reconstruibles. Un corte de corriente puede por tanto dejar el indice corto.
 * verify() es como se detecta, y reindex() como se repara.
 */
$db6 = new Db(tmpdir('index6'), ['durable' => false]);
$db6->index('c', 'g');
for ($i = 0; $i < 9; $i++) {
    $db6->insert('c', ['g' => 'g' . ($i % 3)]);
}
$v = $db6->verifyIndexes('c');
eq('con el indice sano no falta nada',
    ['g' => ['documentos' => 9, 'indexados' => 9, 'faltan' => 0]], $v);

// Simular la perdida: vaciar el bucket de un valor.
\file_put_contents($db6->path() . '/c/_idx/g/g0.json', \json_encode([]));
$v = $db6->verifyIndexes('c');
eq('verify detecta los que faltan', 3, $v['g']['faltan']);
eq('y cuantos si estan',            6, $v['g']['indexados']);
eq('by() efectivamente no los ve',  0, \count($db6->by('c', 'g', 'g0')));

$db6->reindex('c');
eq('reindex los recupera', 0, $db6->verifyIndexes('c')['g']['faltan']);
eq('y by() vuelve a verlos', 3, \count($db6->by('c', 'g', 'g0')));

eq('verify de una coleccion sin indices da vacio', [], $db6->verifyIndexes('otra'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D4] Un indice que no se puede escribir tiene que gritar');

/*
 * El peor fallo posible del motor: el documento se guarda y el indice no lo
 * recoge. El documento existe en disco y es invisible para las consultas, y
 * nadie se entera hasta que alguien reclama un dato que ya no aparece.
 *
 * Paso en produccion de verdad: la migracion se ejecuto como root, dejo el
 * directorio de indices con dueño root, y el servidor web (www-data) ya no
 * podia escribir. Las altas por HTTP se aceptaban y desaparecian.
 *
 * Un indice que no se puede mantener es un error, no un silencio.
 */
$db7 = new Db(tmpdir('index7'), ['durable' => false]);
$db7->index('c', 'g');
$db7->insert('c', ['g' => 'ok'], 'd1');
eq('con el indice sano se ve', 1, \count($db7->by('c', 'g', 'ok')));

// Se ocupa el sitio del archivo del indice con un directorio: fopen no podra.
$bloqueado = $db7->path() . '/c/_idx/g/bloqueado.json';
@\mkdir($bloqueado, 0777, true);

throws('escribir un indice imposible lanza excepcion, no se calla',
    static fn() => $db7->put('c', 'd2', ['g' => 'bloqueado']));

// El documento si llego a disco: es recuperable reconstruyendo el indice.
ok('el documento se habia guardado antes del fallo', $db7->get('c', 'd2') !== null);

@\rmdir($bloqueado);
$db7->reindex('c');
eq('tras liberar y reconstruir, el documento aparece', 1, \count($db7->by('c', 'g', 'bloqueado')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Varios indices en la misma coleccion');

$db2->index('items', 'n');
$campos = $db2->indexes('items');
\sort($campos);
eq('dos campos indexados', ['grupo', 'n'], $campos);

$db2->insert('items', ['grupo' => 'g9', 'n' => 777], 'nuevo');
eq('el alta mantiene el indice de grupo', 1, \count($db2->by('items', 'grupo', 'g9')));
eq('y tambien el de n',                   1, \count($db2->by('items', 'n', '777')));

ok('dropIndex elimina uno',            $db2->dropIndex('items', 'n'));
eq('queda solo el otro',                ['grupo'], $db2->indexes('items'));
ok('dropIndex de inexistente da false', !$db2->dropIndex('items', 'n'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Valores dificiles');

$db3 = new Db(tmpdir('index3'), ['durable' => false]);
$db3->index('c', 'v');

$dificiles = [
    'email'      => 'ana.ruiz+test@dominio.es',
    'espacios'   => 'Cristaleria Los Angeles',
    'acentos'    => 'Mampara de bano con niveles',
    'largo'      => \str_repeat('v', 500),
    'simbolos'   => 'a/b\\c:d*e?f"g<h>i|j',
    'puntos'     => '../../../etc/passwd',
    'numerico'   => '12345',
];
foreach ($dificiles as $clave => $valor) {
    $db3->insert('c', ['v' => $valor, 'k' => $clave], 'doc_' . $clave);
}
foreach ($dificiles as $clave => $valor) {
    $r = $db3->by('c', 'v', $valor);
    eq("valor '{$clave}' indexado y recuperado", 1, \count($r));
}
ok('un valor con ../ no escribio fuera del directorio',
    !\is_file(\dirname($db3->path()) . '/passwd') && !\is_file('/etc/passwd.axi'));

$db3->insert('c', ['sin_v' => 1], 'sinvalor');
eq('un documento sin el campo indexado no rompe nada', 1, \count($db3->by('c', 'v', '12345')));

rmrf($dir);
rmrf($db2->path());
rmrf($db3->path());
summary();
