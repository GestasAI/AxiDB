<?php
/**
 * AxiDB - la compactacion del driver empaquetado.
 *
 * Como solo se añade al final, cada modificacion deja atras la version anterior
 * y cada borrado deja el documento entero. Eso es lo que hace rapida la
 * escritura; la compactacion es lo que evita que el archivo crezca sin fin.
 *
 * Lo que hay que demostrar: que recupera espacio de verdad y que no pierde ni
 * un documento vivo por el camino.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Drivers\Packed\Compactador;

function packedC(string $sufijo): Db
{
    $db = new Db(tmpdir('compactacion_' . $sufijo), ['durable' => false]);
    $db->storage()->declareDriver('p', 'packed');
    return $db;
}

function logSize(Db $db): int
{
    return (int) @\filesize($db->path() . '/p/data.axi');
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] El espacio muerto se mide bien');

$db = packedC('medida');
for ($i = 0; $i < 100; $i++) {
    $db->insert('p', ['n' => $i, 'txt' => \str_repeat('x', 100)], 'd' . $i);
}
ok('recien escrito no hay espacio muerto', $db->storage()->deadRatio('p') < 0.01);

for ($i = 0; $i < 50; $i++) {
    $db->put('p', 'd' . $i, ['n' => $i * 10]);
}
$muerto = $db->storage()->deadRatio('p');
\printf("    tras modificar 50 de 100: %.0f%% muerto\n", $muerto * 100);
ok('modificar genera espacio muerto', $muerto > 0.2);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Compactar recupera espacio sin perder nada');

$antesTamaño = logSize($db);
$antesDocs   = $db->all('p');
\usort($antesDocs, static fn($a, $b) => \strcmp($a['id'], $b['id']));

$recuperados = $db->storage()->sweep('p');

\printf("    %d bytes recuperados de %d\n", $recuperados, $antesTamaño);
ok('se recupero espacio',          $recuperados > 0);
ok('el archivo es mas pequeño',    logSize($db) < $antesTamaño);
ok('y queda casi sin espacio muerto', $db->storage()->deadRatio('p') < 0.01);

$despuesDocs = $db->all('p');
\usort($despuesDocs, static fn($a, $b) => \strcmp($a['id'], $b['id']));

eq('siguen los cien documentos', 100, \count($despuesDocs));
eq('y son exactamente los mismos', $antesDocs, $despuesDocs);
eq('se leen por id igual que antes', 0, $db->get('p', 'd0')['n']);
eq('conservando su version',         2, $db->get('p', 'd0')['_version']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Mil borrados');

$db2 = packedC('borrados');
for ($i = 0; $i < 1200; $i++) {
    $db2->insert('p', ['n' => $i, 'txt' => \str_repeat('y', 80)], 'd' . $i);
}
$conTodos = logSize($db2);

for ($i = 0; $i < 1000; $i++) {
    $db2->delete('p', 'd' . $i);
}
eq('quedan doscientos vivos', 200, $db2->count('p'));
ok('el archivo aun no ha encogido', logSize($db2) >= $conTodos);

$db2->storage()->sweep('p');

\printf("    de %d a %d bytes tras compactar\n", $conTodos, logSize($db2));
ok('ahora si ha encogido mucho', logSize($db2) < $conTodos / 3);
eq('los doscientos vivos siguen',   200, $db2->count('p'));
eq('y se leen',                    1000, $db2->get('p', 'd1000')['n']);
ok('los borrados siguen borrados',  $db2->get('p', 'd0') === null);

$vivos = \array_column($db2->all('p'), 'n');
\sort($vivos);
eq('son exactamente los que quedaban', \range(1000, 1199), $vivos);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] El umbral decide cuando compactar');

$db3 = packedC('umbral');
for ($i = 0; $i < 40; $i++) {
    $db3->insert('p', ['n' => $i, 'txt' => \str_repeat('z', 200)], 'd' . $i);
}
eq('el umbral es el 30 por ciento', 0.30, Compactador::UMBRAL);

$sinNecesidad = $db3->storage()->sweep('p');
eq('sin espacio muerto no compacta', 0, $sinNecesidad);

// Modificar diez de cuarenta deja alrededor de un 20%: por debajo del umbral.
for ($i = 0; $i < 10; $i++) {
    $db3->put('p', 'd' . $i, ['n' => 1000 + $i]);
}
\printf("    tras 10 de 40: %.0f%% muerto\n", $db3->storage()->deadRatio('p') * 100);
eq('por debajo del umbral no compacta', 0, $db3->storage()->sweep('p'));

// Modificando el resto se pasa de largo.
for ($i = 10; $i < 40; $i++) {
    $db3->put('p', 'd' . $i, ['n' => 1000 + $i]);
}
\printf("    tras 40 de 40: %.0f%% muerto\n", $db3->storage()->deadRatio('p') * 100);
ok('por encima del umbral si compacta', $db3->storage()->sweep('p') > 0);
eq('sin perder documentos', 40, $db3->count('p'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Compactar es seguro de repetir y no deja restos');

$db3->storage()->sweep('p');
$db3->storage()->sweep('p');
eq('compactar dos veces mas no rompe nada', 40, $db3->count('p'));
eq('los documentos siguen intactos', 1039, $db3->get('p', 'd39')['n']);

$restos = \glob($db3->path() . '/p/*.tmp.*') ?: [];
eq('no quedan temporales de compactacion', 0, \count($restos));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Se puede seguir escribiendo despues');

$db3->insert('p', ['n' => 'nuevo'], 'despues');
eq('el alta posterior funciona', 'nuevo', $db3->get('p', 'despues')['n']);
eq('y cuenta',                        41, $db3->count('p'));

$db3->put('p', 'd0', ['n' => 'tocado']);
eq('modificar tambien',     'tocado', $db3->get('p', 'd0')['n']);
eq('sin perder a los demas',      41, $db3->count('p'));

// Y tras reabrir la base, todo sigue donde debe.
$ruta = $db3->path();
unset($db3);
$db4 = new Db($ruta, ['durable' => false]);
eq('tras reabrir siguen los 41',   41, $db4->count('p'));
eq('con sus valores',        'tocado', $db4->get('p', 'd0')['n']);
eq('y el ultimo alta',        'nuevo', $db4->get('p', 'despues')['n']);

rmrf($db->path());
rmrf($db2->path());
rmrf($db4->path());
summary();
