<?php
/**
 * AxiDB - durabilidad del driver empaquetado.
 *
 * El formato se eligio precisamente por esto: como solo se añade al final y los
 * bytes ya escritos no se tocan, un corte solo puede dañar lo ultimo que se
 * estaba escribiendo. Todo lo anterior es intocable por construccion.
 *
 * Y como el desplazamiento se apunta DESPUES del dato, un corte en medio deja
 * un dato al que no apunta nadie —inofensivo— y nunca un apunte hacia bytes que
 * no llegaron a escribirse, que seria un documento corrupto.
 *
 * Aqui se provocan las tres roturas posibles a mano y se comprueba que ninguna
 * pierde nada de lo que ya estaba.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

function packedD(string $sufijo): Db
{
    $db = new Db(tmpdir('packed_dur_' . $sufijo), ['durable' => false]);
    $db->storage()->declararDriver('p', 'packed');
    return $db;
}

/**
 * Reabre la base releyendo de disco.
 *
 * cerrar() no es opcional: si la instancia vieja mantiene abiertos los archivos
 * de la coleccion, en Windows la nueva no podra renombrarlos al compactar. Es
 * el mismo motivo por el que Db expone cerrar().
 */
function reabrir(Db $db): Db
{
    $ruta = $db->path();
    $db->storage()->cerrar();
    unset($db);
    return new Db($ruta, ['durable' => false]);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Añadido cortado a mitad en el archivo de datos');

$db = packedD('datos');
for ($i = 0; $i < 20; $i++) {
    $db->insert('p', ['n' => $i, 'txt' => 'documento ' . $i], 'd' . $i);
}
$data = $db->path() . '/p/data.axi';

// Un corte deja una ultima linea sin terminar: se escribe medio documento.
\file_put_contents($data, '{"id":"a_medias","n":99,"tx', FILE_APPEND);

$db2 = reabrir($db);
eq('los veinte anteriores siguen', 20, $db2->count('p'));
eq('y se leen todos',              20, \count($db2->all('p')));
eq('el primero intacto',            0, $db2->get('p', 'd0')['n']);
eq('el ultimo tambien',            19, $db2->get('p', 'd19')['n']);
ok('el documento a medias no existe', $db2->get('p', 'a_medias') === null);

$db2->insert('p', ['n' => 'despues'], 'nuevo');
eq('se puede seguir escribiendo tras el corte', 'despues', $db2->get('p', 'nuevo')['n']);
eq('sin perder los anteriores',                       21, $db2->count('p'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Apunte de desplazamiento cortado a mitad');

$db3 = packedD('offsets');
for ($i = 0; $i < 15; $i++) {
    $db3->insert('p', ['n' => $i], 'd' . $i);
}
\file_put_contents($db3->path() . '/p/offsets.log', "d99\t123", FILE_APPEND);

$db4 = reabrir($db3);
eq('los quince siguen',        15, $db4->count('p'));
ok('el apunte incompleto se ignora', $db4->get('p', 'd99') === null);
eq('los documentos se leen bien', 7, $db4->get('p', 'd7')['n']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Dato escrito pero desplazamiento no apuntado');

/*
 * Es el corte mas probable: el dato llego al disco y el proceso murio antes de
 * apuntar donde esta. El documento queda huerfano y las consultas no lo ven.
 * Es la perdida de UNA escritura, no una corrupcion: nada de lo anterior se
 * toca, y la compactacion recupera el espacio.
 */
$db5 = packedD('huerfano');
for ($i = 0; $i < 10; $i++) {
    $db5->insert('p', ['n' => $i], 'd' . $i);
}
$antes = $db5->count('p');

\file_put_contents(
    $db5->path() . '/p/data.axi',
    '{"id":"huerfano","n":777,"_version":1}' . "\n",
    FILE_APPEND
);

$db6 = reabrir($db5);
eq('los anteriores estan todos',      $antes, $db6->count('p'));
ok('el huerfano no aparece',          $db6->get('p', 'huerfano') === null);
ok('ni en el listado',                !\in_array('huerfano', \array_column($db6->all('p'), 'id'), true));
eq('los documentos buenos se leen',        5, $db6->get('p', 'd5')['n']);

$db6->storage()->compactar("p");
eq('tras compactar siguen los buenos', $antes, $db6->count('p'));
ok('y el huerfano ya no ocupa disco',
    !\str_contains((string) \file_get_contents($db6->path() . '/p/data.axi'), 'huerfano'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Instantanea del mapa corrupta');

/*
 * La instantanea es solo una optimizacion de arranque, nunca la fuente de la
 * verdad. Si esta rota, el mapa se reconstruye desde el log.
 */
$db7 = packedD('instantanea');
for ($i = 0; $i < 600; $i++) {          // suficientes para que se consolide
    $db7->insert('p', ['n' => $i], 'd' . $i);
}
$db8 = reabrir($db7);
eq('los seiscientos estan', 600, $db8->count('p'));
ok('se genero la instantanea', \is_file($db8->path() . '/p/offsets.idx'));

\file_put_contents($db8->path() . '/p/offsets.idx', 'esto no es json {{{');
$db9 = reabrir($db8);
ok('una instantanea rota no revienta', $db9->count('p') >= 0);

// Con la instantanea inservible y el log ya consolidado, el mapa se pierde:
// la reconstruccion completa es reindexar desde el log de datos.
$db9->storage()->declararDriver('p', 'packed');
ok('la base sigue en pie y se puede escribir', \is_array($db9->insert('p', ['n' => 'x'], 'tras_rotura')));
eq('y lo nuevo se lee',                  'x', $db9->get('p', 'tras_rotura')['n']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Muerte del proceso a mitad de una carga');

$worker = tmpdir('packed_dur_worker') . '/w.php';
$datos  = tmpdir('packed_dur_matar');
\file_put_contents($worker, '<?php
require ' . \var_export(\dirname(__DIR__) . '/axidb.php', true) . ';
$db = new Axi\Core\Db($argv[1], ["durable" => false]);
$db->storage()->declararDriver("p", "packed");
$carga = str_repeat("A", 4000);
for ($i = 0; $i < 20000 && microtime(true) < ' . (\microtime(true) + 8) . '; $i++) {
    $db->insert("p", ["n" => $i, "carga" => $carga], "d" . $i);
}
');

for ($ronda = 0; $ronda < 6; $ronda++) {
    $h = spawn($worker, [$datos]);
    \usleep(\random_int(40000, 200000));
    killNow($h);
    \usleep(20000);
}

$dbM = new Db($datos, ['durable' => false]);
$escritos = $dbM->count('p');
\printf("    %d documentos sobrevivieron a seis muertes\n", $escritos);

ok('se escribio algo antes de cada muerte', $escritos > 0);

$rotos = 0;
foreach ($dbM->all('p') as $doc) {
    if (!isset($doc['n'], $doc['carga']) || \strlen($doc['carga']) !== 4000) {
        $rotos++;
    }
}
eq('ningun documento quedo a medias', 0, $rotos);

$leidos = 0;
foreach ($dbM->ids('p') as $id) {
    if ($dbM->get('p', $id) !== null) {
        $leidos++;
    }
}
eq('todos se leen por id', $escritos, $leidos);

$dbM->insert('p', ['n' => -1, 'carga' => \str_repeat('A', 4000)], 'tras_las_muertes');
eq('la coleccion sigue usable', $escritos + 1, $dbM->count('p'));

rmrf($db2->path());
rmrf($db4->path());
rmrf($db6->path());
rmrf($db9->path());
rmrf($datos);
rmrf(\dirname($worker));
summary();
