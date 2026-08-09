<?php
/**
 * AxiDB - test de indices secundarios.
 *
 * El indice es generico: un campo, un valor, sin que el motor sepa que
 * significan. Sustituye al patron habitual de clavar el indice a una columna
 * concreta —el identificador de inquilino, el del cliente— que obliga a tocar el
 * motor cada vez que hace falta indexar otra cosa.
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
eq('con el indice sano no falta nada ni sobra nada',
    ['g' => ['documentos' => 9, 'indexados' => 9, 'faltan' => 0, 'sobran' => 0]], $v);

/* ─────────────────────────────────────────────────────────────────────────── */
section('Campos con mayusculas: el indice que dejaba de mantenerse');

/*
 * El directorio del indice va en minusculas y con una marca —`createdAt` se
 * guarda como `createdat~4f2a1c9b`— para que la carpeta se pueda copiar entre
 * sistemas de archivos que no distinguen mayusculas.
 *
 * El problema era que `fields()` devolvia el nombre del DIRECTORIO, y put() lo
 * usa para mantener el indice: buscaba $documento['createdat~4f2a1c9b'], que no
 * existe en ningun documento. Resultado: el indice de cualquier campo con una
 * mayuscula se construia una vez y no se actualizaba nunca mas. Los documentos
 * nuevos no entraban y by() no los encontraba, sin un solo error por el camino.
 *
 * Y verifyIndexes() decia que todo estaba bien, porque contaba cero documentos
 * con ese campo: cero de cero indexados, cero faltan.
 */
$dirMay = tmpdir('index_mayusculas');
$dbMay  = new Db($dirMay, ['durable' => false]);

$dbMay->insert('c', ['localID' => 'A1'], 'd1');
$dbMay->index('c', 'localID');

eq('fields() devuelve el nombre real, no el del directorio', ['localID'], $dbMay->indexes('c'));

$dbMay->insert('c', ['localID' => 'A2'], 'd2');
eq('un documento nuevo SI entra en el indice', ['d2'],
    \array_column($dbMay->by('c', 'localID', 'A2'), 'id'));

$dbMay->update('c', 'd1', ['localID' => 'A3']);
eq('y al cambiar el valor, el viejo se suelta', [], $dbMay->by('c', 'localID', 'A1'));
eq('y el nuevo se coge', ['d1'], \array_column($dbMay->by('c', 'localID', 'A3'), 'id'));

$vMay = $dbMay->verifyIndexes('c')['localID'] ?? [];
eq('y ahora verifyIndexes de verdad los mira', 2, $vMay['documentos'] ?? -1);
eq('sin que falte ninguno', 0, $vMay['faltan'] ?? -1);

/*
 * Un indice creado por una version anterior no lleva anotado su campo, y si el
 * campo tenia mayusculas su nombre no se puede recuperar del directorio. Ese
 * indice NO se puede mantener. Lo que no se hace es callarlo: se avisa, porque
 * callarlo seria repetir exactamente el fallo que se acaba de corregir.
 */
\unlink($dirMay . '/c/_idx/localid~26cb4baf/_campo.json');

$heredado = $dbMay->verifyIndexes('c');
ok('un indice heredado sin nombre de campo no se cuela como bueno',
    !isset($heredado['localID']));
ok('se avisa de que hay que rehacerlo',
    ($heredado['localid~26cb4baf']['ilegible'] ?? false) === true);
ok('y el aviso dice como', \str_contains(
    (string) ($heredado['localid~26cb4baf']['aviso'] ?? ''), 'dropIndex'));

$dbMay->storage()->cerrar();
rmrf($dirMay);

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
