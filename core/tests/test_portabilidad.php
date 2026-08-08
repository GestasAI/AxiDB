<?php
/**
 * AxiDB - test de portabilidad entre sistemas operativos.
 *
 * La promesa del proyecto es una carpeta que funciona igual en todas partes.
 * El enemigo silencioso: un directorio en Windows no distingue mayusculas y en
 * Linux si. Sin cuidado, la coleccion 'Users' creada en el portatil se convierte
 * en DOS colecciones al desplegar en Linux, o dos documentos distintos se funden
 * en uno al volver a Windows. Solo se descubre en produccion.
 *
 * Names::toPath resuelve las dos caras: la ruta de un nombre es siempre la
 * misma, y dos nombres distintos nunca comparten ruta.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Names;

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] La transformacion es estable e inyectiva');

eq('un nombre en minusculas no cambia',       'presupuestos', Names::toPath('presupuestos'));
eq('con guion bajo tampoco',                  'carta_productos', Names::toPath('carta_productos'));
eq('con numeros y guiones tampoco',           'l_4440c33-1', Names::toPath('l_4440c33-1'));
ok('un nombre con mayusculas si cambia',      Names::toPath('Users') !== 'Users');
ok('y baja a minusculas con marca',           \str_starts_with(Names::toPath('Users'), 'users~'));

eq('la misma entrada da siempre la misma ruta', Names::toPath('Users'), Names::toPath('Users'));

// Inyectiva: si dos nombres distintos dieran la misma ruta, un documento
// pisaria al otro y se perderian datos sin aviso.
$nombres = ['users', 'Users', 'USERS', 'UsErS', 'uSers', 'user', 'Users2'];
$rutas   = \array_map([Names::class, 'toPath'], $nombres);
eq('siete nombres distintos dan siete rutas distintas',
    \count($nombres), \count(\array_unique($rutas)));

// El caracter de marca esta reservado: ningun nombre valido puede contenerlo,
// asi que nadie puede fabricar a mano la forma codificada de otro.
throws('el caracter de marca esta prohibido en los nombres de entrada',
    static fn() => Names::check('users~1234abcd', 'coleccion'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Colecciones que solo difieren en mayusculas');

$db = new Db(tmpdir('portabilidad'), ['durable' => false]);

$db->insert('clientes', ['de' => 'minusculas'], 'x');
$db->insert('Clientes', ['de' => 'Mayuscula'], 'x');
$db->insert('CLIENTES', ['de' => 'TODO MAYUSCULAS'], 'x');

eq("'clientes' conserva su documento",  'minusculas',      $db->get('clientes', 'x')['de']);
eq("'Clientes' el suyo",                'Mayuscula',       $db->get('Clientes', 'x')['de']);
eq("'CLIENTES' el suyo",                'TODO MAYUSCULAS', $db->get('CLIENTES', 'x')['de']);
eq('son tres colecciones, no una', 3, \count($db->collections()));

$db->dropCollection('Clientes');
eq('borrar una no toca a las otras', 'minusculas', $db->get('clientes', 'x')['de']);
ok("y la borrada ya no esta", $db->get('Clientes', 'x') === null);
eq('quedan dos', 2, \count($db->collections()));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Documentos cuyo id solo difiere en mayusculas');

$db->insert('c', ['quien' => 'minuscula'], 'l_la');
$db->insert('c', ['quien' => 'Mayuscula'], 'l_lA');

eq('el de minusculas se lee bien', 'minuscula', $db->get('c', 'l_la')['quien']);
eq('el de mayusculas tambien',     'Mayuscula', $db->get('c', 'l_lA')['quien']);
eq('son dos documentos, no uno',   2, $db->count('c'));

// El id ORIGINAL se conserva dentro del documento, aunque el archivo se llame
// de otra forma. Es el dato que devuelven las consultas.
$ids = \array_column($db->all('c'), 'id');
\sort($ids);
eq('el documento conserva su id original', ['l_lA', 'l_la'], $ids);

$db->delete('c', 'l_lA');
eq('borrar uno no borra el otro', 1, $db->count('c'));
eq('y sobrevive el correcto', 'minuscula', $db->get('c', 'l_la')['quien']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Indices sobre campos y valores con mayusculas');

$db2 = new Db(tmpdir('portabilidad2'), ['durable' => false]);
$db2->index('t', 'local_id');
$db2->index('t', 'Local_Id');
eq('dos campos que solo difieren en mayusculas son dos indices', 2, \count($db2->indexes('t')));

$db2->insert('t', ['local_id' => 'l_la', 'n' => 1]);
$db2->insert('t', ['local_id' => 'l_lA', 'n' => 2]);
$db2->insert('t', ['local_id' => 'l_la', 'n' => 3]);

eq('el valor en minusculas agrupa dos', 2, \count($db2->by('t', 'local_id', 'l_la')));
eq('el de mayusculas, uno',             1, \count($db2->by('t', 'local_id', 'l_lA')));
eq('y no se mezclan', [1, 3], \array_column($db2->by('t', 'local_id', 'l_la'), 'n'));
eq('el indice cuadra', 0, $db2->verifyIndexes('t')['local_id']['faltan']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Los datos ya existentes no se mueven');

/*
 * Practicamente todo dato real usa minusculas. Para esos nombres toPath es la
 * identidad, asi que la portabilidad no obliga a migrar nada: los archivos
 * siguen llamandose igual y el disco sigue siendo legible a ojo.
 */
$db3 = new Db(tmpdir('portabilidad3'), ['durable' => false]);
$db3->insert('carta_productos', ['nombre' => 'Paella'], 'prod_e2338a74c0397398');

ok('el archivo se llama exactamente como el id',
    \is_file($db3->path() . '/carta_productos/prod_e2338a74c0397398.json'));
ok('y el directorio como la coleccion',
    \is_dir($db3->path() . '/carta_productos'));
ok('sin ninguna marca de codificacion',
    !\str_contains(\implode('', \scandir($db3->path() . '/carta_productos') ?: []), '~'));

foreach (['users', 'carta_productos', 'l_4440c33155ca1c49', 'prod_e2338a74c0397398', 'cat_0bf6'] as $real) {
    eq("nombre real de produccion sin cambios: {$real}", $real, Names::toPath($real));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] SQL hereda la misma garantia');

$db4 = new Db(tmpdir('portabilidad4'), ['durable' => false]);
$db4->sql("INSERT INTO Pedidos (cliente) VALUES ('Ana')");
$db4->sql("INSERT INTO pedidos (cliente) VALUES ('Luis')");

eq("'Pedidos' tiene uno", 1, $db4->sql('SELECT COUNT(*) FROM Pedidos'));
eq("'pedidos' tiene el suyo", 1, $db4->sql('SELECT COUNT(*) FROM pedidos'));
eq('y son distintos', 'Ana', $db4->sql('SELECT * FROM Pedidos')[0]['cliente']);

rmrf($db->path());
rmrf($db2->path());
rmrf($db3->path());
rmrf($db4->path());
summary();
