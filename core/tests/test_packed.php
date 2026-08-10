<?php
/**
 * AxiDB - el driver empaquetado por dentro.
 *
 * La paridad con fs la cubre test_drivers_paridad.php. Aqui se comprueba lo que
 * es propio de este formato: que solo se añade al final, que el mapa de
 * desplazamientos sobrevive a reabrir la base, y que dos escritores no se
 * pisan.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

function packed(string $sufijo): Db
{
    $db = new Db(tmpdir('packed_' . $sufijo), ['durable' => false]);
    $db->storage()->declareDriver('p', 'packed');
    return $db;
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Forma en disco');

$db = packed('forma');
$db->insert('p', ['n' => 1], 'd1');

$dir = $db->path() . '/p';
ok('existe el archivo de datos',        \is_file($dir . '/data.axi'));
ok('y el log de desplazamientos',       \is_file($dir . '/offsets.log'));
ok('la coleccion declara sus ajustes',  \is_file($dir . '/_axidb.json'));
ok('no hay un archivo por documento',   !\is_file($dir . '/d1.json'));

$crudo = (string) \file_get_contents($dir . '/data.axi');
eq('un documento es exactamente una linea', 1, \substr_count($crudo, "\n"));
ok('la linea es JSON en un renglon', \is_array(\json_decode(\trim($crudo), true)));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Solo se añade al final');

$antes = \filesize($dir . '/data.axi');
$db->insert('p', ['n' => 2], 'd2');
$medio = \filesize($dir . '/data.axi');
ok('el archivo crece al dar de alta', $medio > $antes);

// Lo ya escrito no se toca: los primeros bytes siguen siendo los mismos.
$cabecera = \substr((string) \file_get_contents($dir . '/data.axi'), 0, $antes);
eq('los bytes anteriores no han cambiado', \substr($crudo, 0, $antes), $cabecera);

$db->put('p', 'd1', ['n' => 99]);
$tras = \filesize($dir . '/data.axi');
ok('modificar tambien añade, no reescribe', $tras > $medio);
eq('y sigue habiendo dos documentos', 2, $db->count('p'));
eq('con el valor nuevo', 99, $db->get('p', 'd1')['n']);
eq('tres lineas para dos documentos: la version vieja sigue ahi',
    3, \substr_count((string) \file_get_contents($dir . '/data.axi'), "\n"));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] El estado sobrevive a reabrir');

$ruta = $db->path();
unset($db);

$db2 = new Db($ruta, ['durable' => false]);
eq('el driver se recuerda',      'packed', $db2->storage()->driverDe('p'));
eq('los documentos siguen ahi',        2, $db2->count('p'));
eq('con su ultimo valor',             99, $db2->get('p', 'd1')['n']);
eq('y su version',                     2, $db2->get('p', 'd1')['_version']);

$db2->insert('p', ['n' => 3], 'd3');
eq('se puede seguir escribiendo tras reabrir', 3, $db2->count('p'));
eq('y lo anterior sigue intacto',             99, $db2->get('p', 'd1')['n']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Borrado: lapida, no reescritura');

$lineasAntes = \substr_count((string) \file_get_contents($db2->path() . '/p/data.axi'), "\n");
$db2->delete('p', 'd2');

eq('el documento desaparece de las consultas', 2, $db2->count('p'));
ok('get devuelve null',   $db2->get('p', 'd2') === null);
eq('el archivo de datos no se ha tocado', $lineasAntes,
    \substr_count((string) \file_get_contents($db2->path() . '/p/data.axi'), "\n"));
ok('el espacio muerto sube', $db2->storage()->deadRatio('p') > 0);

$db2->insert('p', ['n' => 'renacido'], 'd2');
eq('se puede reutilizar un id borrado', 'renacido', $db2->get('p', 'd2')['n']);
eq('y vuelve a contar', 3, $db2->count('p'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Volumen');

$db3 = packed('volumen');
$t = \microtime(true);
for ($i = 0; $i < 2000; $i++) {
    $db3->insert('p', ['n' => $i, 'txt' => 'documento numero ' . $i], 'd' . $i);
}
$ms = (\microtime(true) - $t) * 1000;
\printf("    2000 altas en %.0f ms (%.3f ms cada una)\n", $ms, $ms / 2000);

eq('estan las dos mil',        2000, $db3->count('p'));
eq('la primera se lee',           0, $db3->get('p', 'd0')['n']);
eq('la ultima tambien',        1999, $db3->get('p', 'd1999')['n']);
eq('una del medio',             999, $db3->get('p', 'd999')['n']);
eq('all las devuelve todas',   2000, \count($db3->all('p')));
ok('el alta media baja de 1 ms', $ms / 2000 < 1.0);

$t = \microtime(true);
for ($i = 0; $i < 500; $i++) {
    $db3->get('p', 'd' . ($i * 3 % 2000));
}
$msLectura = (\microtime(true) - $t) * 1000 / 500;
\printf("    lectura por id: %.3f ms\n", $msLectura);
ok('leer por id baja de 1 ms', $msLectura < 1.0);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Dos escritores a la vez');

$db4    = packed('concurrencia');
$worker = $db4->path() . '/w.php';
\file_put_contents($worker, '<?php
require ' . \var_export(\dirname(__DIR__) . '/axidb.php', true) . ';
$db = new Axi\Core\Db($argv[1], ["durable" => false]);
for ($i = 0; $i < 40; $i++) { $db->insert("p", ["quien" => $argv[2], "n" => $i], $argv[2] . "_" . $i); }
');

$procs = [];
for ($w = 1; $w <= 4; $w++) {
    $procs[] = spawn($worker, [$db4->path(), 'w' . $w]);
}
foreach ($procs as $p) {
    waitFor($p);
}

$db4->storage()->declareDriver('p', 'packed');   // olvida el mapa en memoria
eq('las 160 altas concurrentes estan todas', 160, $db4->count('p'));
eq('y se leen todas',                        160, \count($db4->all('p')));

$ilegibles = 0;
foreach ($db4->all('p') as $doc) {
    if (!isset($doc['quien'], $doc['n'])) {
        $ilegibles++;
    }
}
eq('ningun documento salio entrelazado', 0, $ilegibles);

$porEscritor = \array_count_values(\array_column($db4->all('p'), 'quien'));
eq('cada escritor puso sus cuarenta', [40, 40, 40, 40], \array_values($porEscritor));

rmrf($db2->path());
rmrf($db3->path());
rmrf($db4->path());
summary();
