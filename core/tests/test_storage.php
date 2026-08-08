<?php
/**
 * AxiDB - test de Storage y de la fachada Db: CRUD, metadatos y seguridad.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir = tmpdir('storage');
$db  = new Db($dir, ['durable' => false]);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Alta y lectura');

$doc = $db->insert('notas', ['titulo' => 'Hola', 'cuerpo' => 'Mundo']);
ok('insert devuelve el documento',        \is_array($doc));
ok('el motor genera un id',               !empty($doc['id']));
eq('el titulo se guardo',                 'Hola', $doc['titulo']);
eq('_version arranca en 1',               1, $doc['_version']);
ok('_createdAt presente',                 !empty($doc['_createdAt']));
ok('_updatedAt presente',                 !empty($doc['_updatedAt']));

$leido = $db->get('notas', $doc['id']);
eq('lo leido coincide con lo escrito',    $doc, $leido);
ok('exists() lo encuentra',               $db->exists('notas', $doc['id']));
ok('get() de un id inexistente da null',  $db->get('notas', 'noexiste') === null);
ok('exists() de un inexistente da false', !$db->exists('notas', 'noexiste'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Id explicito y ids ordenables');

$db->insert('notas', ['x' => 1], 'mi-id-propio');
ok('acepta un id explicito', $db->exists('notas', 'mi-id-propio'));

$ids = [];
for ($i = 0; $i < 5; $i++) {
    $ids[] = Db::newId();
    \usleep(1500);
}
$ordenados = $ids;
\sort($ordenados);
eq('los ids generados son ordenables por tiempo', $ids, $ordenados);
eq('todos los ids son distintos', 5, \count(\array_unique($ids)));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Modificacion: fusion y reemplazo');

$db->insert('notas', ['a' => 1, 'b' => 2], 'fus');
$m = $db->update('notas', 'fus', ['b' => 20, 'c' => 3]);
eq('la clave no tocada se conserva',  1, $m['a']);
eq('la clave modificada cambia',     20, $m['b']);
eq('la clave nueva se añade',         3, $m['c']);
eq('_version sube a 2',               2, $m['_version']);

$r = $db->update('notas', 'fus', ['solo' => 'esto'], true);
ok('replace elimina las claves viejas', !isset($r['a']) && !isset($r['b']));
eq('replace conserva la clave nueva',   'esto', $r['solo']);
eq('_version sigue subiendo',           3, $r['_version']);

$creado = $db->get('notas', 'fus')['_createdAt'];
$db->update('notas', 'fus', ['z' => 1]);
eq('_createdAt no cambia nunca', $creado, $db->get('notas', 'fus')['_createdAt']);

throws('update sobre un id inexistente lanza', static fn() => $db->update('notas', 'fantasma', ['a' => 1]));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Borrado');

$db->insert('notas', ['tmp' => true], 'borrame');
ok('delete devuelve true',           $db->delete('notas', 'borrame'));
ok('el documento ya no existe',      !$db->exists('notas', 'borrame'));
ok('delete de inexistente da false', !$db->delete('notas', 'borrame'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Listados y colecciones');

$db2 = new Db(tmpdir('storage2'), ['durable' => false]);
for ($i = 0; $i < 7; $i++) {
    $db2->insert('cosas', ['n' => $i]);
}
eq('count cuenta bien',              7, $db2->count('cosas'));
eq('all devuelve todos',             7, \count($db2->all('cosas')));
eq('ids devuelve todos',             7, \count($db2->ids('cosas')));
eq('coleccion vacia devuelve cero',  0, $db2->count('nada'));
eq('all de coleccion vacia da []',   [], $db2->all('nada'));

$db2->insert('otra', ['x' => 1]);
$cols = $db2->collections();
ok('collections lista las dos', \in_array('cosas', $cols, true) && \in_array('otra', $cols, true));

ok('dropCollection borra',           $db2->dropCollection('otra'));
ok('la coleccion ya no aparece',     !\in_array('otra', $db2->collections(), true));
ok('drop de inexistente da false',   !$db2->dropCollection('otra'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Seguridad: nombres y path traversal');

foreach (['../fuera', '..', 'a/b', 'a\\b', '', 'con espacio', '-empieza-mal', '.oculto'] as $malo) {
    throws("coleccion rechazada: '" . ($malo === '' ? '(vacia)' : $malo) . "'",
        static fn() => $db->get($malo, 'x'));
}
throws('id con byte nulo rechazado', static fn() => $db->get('notas', "a\0b"));
throws('nombre de mas de 128 caracteres rechazado',
    static fn() => $db->get('notas', \str_repeat('a', 129)));

$db->insert('notas', ['ok' => 1], 'valido.con-puntos_y-guiones');
ok('acepta puntos, guiones y subrayados', $db->exists('notas', 'valido.con-puntos_y-guiones'));

$fuera = \dirname($dir) . '/ESCAPE.json';
@\unlink($fuera);
try {
    $db->insert('notas', ['x' => 1], '../ESCAPE');
} catch (\Throwable) {
}
ok('no se escribio nada fuera del directorio de datos', !\is_file($fuera));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Contenido: unicode y datos anidados');

$raro = [
    'texto'    => 'Cañón, jamón y "comillas" con \'apostrofes\'',
    'emoji'    => "sin emojis en el codigo, pero el DATO puede traerlos",
    'anidado'  => ['a' => ['b' => ['c' => [1, 2, 3]]]],
    'numeros'  => [0, -1, 3.14159, 1e10],
    'nulo'     => null,
    'booleano' => false,
    'vacio'    => [],
];
$db->insert('raros', $raro, 'r1');
$v = $db->get('raros', 'r1');
eq('unicode intacto',      $raro['texto'],   $v['texto']);
eq('anidamiento intacto',  $raro['anidado'], $v['anidado']);
eq('numeros intactos',     $raro['numeros'], $v['numeros']);
ok('null se conserva',     \array_key_exists('nulo', $v) && $v['nulo'] === null);
eq('false se conserva',    false, $v['booleano']);
eq('array vacio intacto',  [], $v['vacio']);

$largo = \str_repeat('x', 500000);
$db->insert('raros', ['grande' => $largo], 'r2');
eq('documento de 500 KB intacto', 500000, \strlen($db->get('raros', 'r2')['grande']));

rmrf($dir);
rmrf($db2->path());
summary();
