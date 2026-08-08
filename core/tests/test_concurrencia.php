<?php
/**
 * AxiDB - test de concurrencia. Cubre el fallo F2.
 *
 * F2 (en spa/server/lib.php): data_index_add() lee el indice FUERA del lock.
 * Con dos procesos a la vez se pierde entre el 76% y el 98% de las entradas,
 * y la perdida es permanente.
 *
 * Core\Index hace lectura-modificacion-escritura dentro de un unico flock.
 * Este test lo demuestra y ademas reproduce el patron viejo como control,
 * para que quede visible por que el cambio era necesario.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir    = tmpdir('concurrencia');
$worker = __DIR__ . '/_worker_index.php';

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Ocho procesos escribiendo el mismo indice');

$db = new Db($dir, ['durable' => false]);
$db->index('items', 'grupo');            // declara el indice antes de escribir

$procs = [];
for ($w = 1; $w <= 8; $w++) {
    $procs[] = spawn($worker, [$dir, "w{$w}", 25]);
}
foreach ($procs as $p) {
    waitFor($p);
}

$esperados = 8 * 25;
$enDisco   = \count($db->ids('items'));
$enIndice  = \count($db->indexer()->ids('items', 'grupo', 'g1') ?? []);
$porBusqueda = \count($db->by('items', 'grupo', 'g1'));

echo "    esperados {$esperados} | en disco {$enDisco} | en indice {$enIndice} | via by() {$porBusqueda}\n";

eq('los 200 documentos estan en disco',            $esperados, $enDisco);
eq('los 200 ids estan en el indice, cero perdidos', $esperados, $enIndice);
eq('by() devuelve los 200',                        $esperados, $porBusqueda);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Dos procesos, el escenario realista de dos empleados');

$dir2 = tmpdir('concurrencia2');
$db2  = new Db($dir2, ['durable' => false]);
$db2->index('items', 'grupo');

$procs = [spawn($worker, [$dir2, 'a', 25]), spawn($worker, [$dir2, 'b', 25])];
foreach ($procs as $p) {
    waitFor($p);
}
eq('50 ids en el indice con dos escritores', 50, \count($db2->indexer()->ids('items', 'grupo', 'g1') ?? []));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Control: el patron viejo de lib.php sobre el mismo escenario');

// Reproduce data_index_add(): lectura fuera del lock, escritura dentro.
$ctrl = tmpdir('concurrencia_ctrl');
\file_put_contents($ctrl . '/w.php', <<<'PHP'
<?php
$path = $argv[1]; $prefix = $argv[2]; $n = (int)$argv[3];
function _idx_write(string $p, array $ids): void {
    $fp=@fopen($p,'c+'); if(!$fp) return;
    try { if(!flock($fp,LOCK_EX)) return; ftruncate($fp,0); rewind($fp);
          fwrite($fp,json_encode(array_values(array_unique($ids)))); fflush($fp); }
    finally { flock($fp,LOCK_UN); fclose($fp); }
}
for ($i=0; $i<$n; $i++) {
    $ids = is_file($path) ? (json_decode((string)@file_get_contents($path),true) ?: []) : [];
    $id = "$prefix-$i";
    if (!in_array($id,$ids,true)) { $ids[]=$id; _idx_write($path,$ids); }
    usleep(200);
}
PHP);

$idxViejo = $ctrl . '/idx.json';
$procs = [spawn($ctrl . '/w.php', [$idxViejo, 'a', 25]), spawn($ctrl . '/w.php', [$idxViejo, 'b', 25])];
foreach ($procs as $p) {
    waitFor($p);
}
$viejo     = \count(\json_decode((string) @\file_get_contents($idxViejo), true) ?: []);
$perdidos  = 50 - $viejo;
echo "    patron viejo: esperados 50, guardados {$viejo} -> {$perdidos} perdidos\n";

/*
 * Esto es una DEMOSTRACION, no una garantia, y por eso no hace fallar la suite.
 *
 * El patron viejo esta roto siempre —lee fuera del lock, asi que dos procesos
 * pueden partir de la misma lista y uno pisar al otro—, pero que la carrera se
 * pierda en una ejecucion concreta depende de como reparta el sistema. En
 * Windows pierde entradas casi siempre; en la CI de Linux hubo tandas donde las
 * 50 entraron por los pelos. Exigirlo pondria rojo el gate por haber tenido
 * suerte, que es lo contrario de lo que hace un test util.
 *
 * Lo que si es una garantia, y va como assert, es la seccion B: el patron nuevo
 * no pierde ni una NUNCA.
 */
if ($perdidos > 0) {
    ok("el patron viejo pierde entradas ({$perdidos} de 50) — por eso se cambio", true);
} else {
    echo "    (esta vez el patron viejo salvo las 50: la carrera no se perdio en esta\n";
    echo "     maquina. Sigue estando roto; lo que se demuestra es aleatorio, no el fallo.)\n";
    ok('demostracion del patron viejo: no concluyente en esta ejecucion', true);
}
ok('el patron nuevo no pierde ninguna', \count($db2->indexer()->ids('items', 'grupo', 'g1') ?? []) === 50);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Ocho procesos actualizando el MISMO documento');

$dir3 = tmpdir('concurrencia3');
$db3  = new Db($dir3, ['durable' => false]);
$db3->put('uno', 'doc', ['n' => 0]);

\file_put_contents($dir3 . '/w.php', '<?php require ' . \var_export(\dirname(__DIR__) . '/axidb.php', true) . ';'
    . '$db = new Axi\Core\Db($argv[1], ["durable"=>false]);'
    . 'for($i=0;$i<10;$i++){ $db->put("uno","doc",["t".$argv[2].$i => 1]); usleep(100); }');

$procs = [];
for ($w = 1; $w <= 8; $w++) {
    $procs[] = spawn($dir3 . '/w.php', [$dir3, $w]);
}
foreach ($procs as $p) {
    waitFor($p);
}

$doc = $db3->get('uno', 'doc');
ok('el documento sigue siendo JSON valido tras 80 escrituras concurrentes', \is_array($doc));
eq('_version refleja las 81 escrituras', 81, $doc['_version'] ?? -1);
$marcas = \count(\array_filter(\array_keys($doc ?? []), static fn($k) => \str_starts_with($k, 't')));
eq('las 80 claves de los 8 procesos sobrevivieron al merge', 80, $marcas);

rmrf($dir);
rmrf($dir2);
rmrf($dir3);
rmrf($ctrl);
summary();
