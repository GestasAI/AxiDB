<?php
/**
 * AxiDB - copias de seguridad, restauracion e intercambio.
 *
 * El test que importa es el de la seccion C: se hace una copia, se destrozan los
 * datos a proposito —borrando, metiendo basura y añadiendo cosas que no estaban—
 * y se restaura. Una copia solo vale lo que vale su restauracion.
 *
 * Y el de la D: una copia dañada tiene que detectarse ANTES de tocar los datos
 * vivos. Restaurar medio conjunto de datos corrupto encima de los buenos es peor
 * que no tener copia, porque destruye lo unico que quedaba.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Backup\Catalog;
use Axi\Core\Db;

function sembrarCopias(Db $db): void
{
    $db->insert('clientes', ['nombre' => 'Ana', 'saldo' => 100.5, 'tags' => ['a', 'b']], 'c1');
    $db->insert('clientes', ['nombre' => 'Juan', 'saldo' => 0.0], 'c2');
    $db->index('clientes', 'nombre');
    $db->defineSchema('clientes', ['nombre' => ['tipo' => 'texto', 'obligatorio' => true]]);
    $db->defineTtl('cache', 3600);
    $db->insert('cache', ['x' => 1], 'k1');
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Una copia completa');

$dir     = tmpdir('copias');
$carpeta = tmpdir('copias_guardadas');
$db      = new Db($dir, ['durable' => false]);
sembrarCopias($db);

$completa = $db->backup($carpeta);

eq('la copia es completa',      'completa', $completa['tipo']);
ok('guarda todos los archivos', $completa['guardados'] === $completa['archivos']);
ok('y son unos cuantos',        $completa['archivos'] >= 5);
ok('el archivo existe',         \is_file($completa['archivo']));
ok('y pesa algo',               $completa['bytes'] > 100);
ok('la extension es la suya',   \str_ends_with($completa['archivo'], Catalog::EXTENSION));

/*
 * Los cerrojos y las transacciones a medias no entran. Un diario copiado se
 * reaplicaria al restaurar, en un momento que ya no tiene nada que ver.
 */
$rutas = [];
\Axi\Core\Backup\Container::recorrer($completa['archivo'], static function (string $r) use (&$rutas): void {
    $rutas[] = $r;
});
eq('no se copia ningun cerrojo', [],
    \array_values(\array_filter($rutas, static fn($r) => \str_ends_with($r, '.lock'))));
eq('ni ningun diario de transaccion', [],
    \array_values(\array_filter($rutas, static fn($r) => \str_starts_with($r, '_tx/'))));
ok('si se copian los ajustes de la coleccion', \in_array('clientes/_axidb.json', $rutas, true));
ok('y los indices',
    \count(\array_filter($rutas, static fn($r) => \str_contains($r, '/_idx/'))) > 0);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Una incremental guarda solo lo que cambio');

$db->insert('clientes', ['nombre' => 'Eva'], 'c3');
$incremental = $db->backup($carpeta, incremental: true);

eq('es incremental', 'incremental', $incremental['tipo']);
eq('cuelga de la completa', $completa['id'], $incremental['base'] ?? null);
ok('sabe cuantos archivos hay en total', $incremental['archivos'] > $completa['archivos'] - 2);
ok('pero guarda muchos menos: ' . $incremental['guardados'],
    $incremental['guardados'] < $completa['guardados']);
ok('y ocupa menos', $incremental['bytes'] < $completa['bytes']);

$listado = $db->backups($carpeta);
eq('el catalogo ve las dos', 2, \count($listado));
eq('la mas reciente primero', 'incremental', $listado[0]['tipo']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Romper los datos y restaurarlos');

$db->delete('clientes', 'c1');                              // borrar uno
$db->insert('clientes', ['nombre' => 'Intruso'], 'c9');     // meter uno que no estaba
\file_put_contents($dir . '/clientes/c2.json', 'esto no es json');   // corromper otro

$vuelta = $db->restore($incremental['archivo']);

eq('se aplica la cadena entera', 2, \count($vuelta['cadena']));
ok('y se borra lo que sobraba', $vuelta['borrados'] > 0);

$tras = new Db($dir, ['durable' => false]);
eq('el documento borrado vuelve',       'Ana', $tras->get('clientes', 'c1')['nombre'] ?? null);
eq('con su decimal intacto',            100.5, $tras->get('clientes', 'c1')['saldo'] ?? null);
eq('y su lista',                  ['a', 'b'], $tras->get('clientes', 'c1')['tags'] ?? null);
eq('el corrupto vuelve sano',          'Juan', $tras->get('clientes', 'c2')['nombre'] ?? null);
eq('el que se creo entre copias sigue', 'Eva', $tras->get('clientes', 'c3')['nombre'] ?? null);
ok('y el intruso desaparece',          !$tras->exists('clientes', 'c9'));

eq('los indices vuelven y funcionan', 'c1',
    $tras->by('clientes', 'nombre', 'Ana')[0]['id'] ?? null);
eq('el esquema vuelve', true, $tras->schema('clientes')['nombre']['obligatorio'] ?? false);
eq('y la caducidad', 3600, $tras->ttl('cache'));
throws('la coleccion sigue rechazando lo que no cumple el esquema',
    static fn () => $tras->insert('clientes', ['saldo' => 1], 'sinNombre'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Una copia dañada no se restaura a medias');

$rota = $carpeta . '/rota' . Catalog::EXTENSION;
\copy($completa['archivo'], $rota);
$bytes = (string) \file_get_contents($rota);
$bytes[\strlen($bytes) - 40] = $bytes[\strlen($bytes) - 40] === 'X' ? 'Y' : 'X';
\file_put_contents($rota, $bytes);

throws('un byte cambiado hace que la restauracion se niegue',
    static fn () => $tras->restore($rota));

$sano = new Db($dir, ['durable' => false]);
eq('y los datos vivos no se tocaron', 'Ana', $sano->get('clientes', 'c1')['nombre'] ?? null);
eq('ni el que venia despues',         'Eva', $sano->get('clientes', 'c3')['nombre'] ?? null);

throws('restaurar un archivo que no existe se rechaza',
    static fn () => $sano->restore($carpeta . '/no-existe' . Catalog::EXTENSION));

// Una incremental sin su completa no se puede restaurar, y se dice claro.
$sueltas = tmpdir('copias_sueltas');
\copy($incremental['archivo'], $sueltas . '/' . \basename($incremental['archivo']));
throws('una incremental sin la copia de la que cuelga se rechaza',
    static fn () => $sano->restore($sueltas . '/' . \basename($incremental['archivo'])));

/*
 * La copia de arriba tiene una ENTRADA dañada, no la cabecera, asi que el
 * catalogo la lista con normalidad: leer las cabeceras es lo unico que hace, y
 * asi listar veinte copias de un gigabyte cuesta lo mismo que listar veinte
 * archivos vacios. El daño se descubre al restaurar, que es cuando se leen los
 * contenidos.
 */
eq('una copia con una entrada dañada se lista igual: el catalogo solo lee cabeceras',
    0, \count(\array_filter($sano->backups($carpeta), static fn($c) => $c['tipo'] === 'ilegible')));

$sinCabecera = $carpeta . '/sincabecera' . Catalog::EXTENSION;
\file_put_contents($sinCabecera, "esto no es una copia\n");

ok('el catalogo aguanta un archivo que no es una copia sin romperse',
    \count($sano->backups($carpeta)) === 4);
eq('y lo marca como ilegible', 1,
    \count(\array_filter($sano->backups($carpeta), static fn($c) => $c['tipo'] === 'ilegible')));
ok('las buenas se siguen viendo, que es cuando mas falta hace',
    \count(\array_filter($sano->backups($carpeta), static fn($c) => $c['tipo'] !== 'ilegible')) === 3);

$sano->storage()->cerrar();
rmrf($sueltas);
rmrf($carpeta);
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Copias con los dos drivers y con cifrado');

foreach (['fs', 'packed'] as $driver) {
    $d = tmpdir('copias_' . $driver);
    $g = tmpdir('copias_' . $driver . '_g');
    $x = new Db($d, ['durable' => false]);
    if ($driver !== 'fs') {
        $x->storage()->declararDriver('t', $driver);
    }
    for ($i = 0; $i < 20; $i++) {
        $x->insert('t', ['n' => $i, 'txt' => 'documento ' . $i], 'd' . $i);
    }
    $c = $x->backup($g);
    $x->delete('t', 'd5');
    $x->restore($c['archivo']);

    $y = new Db($d, ['durable' => false]);
    eq("{$driver}: vuelven los veinte", 20, $y->count('t'));
    eq("{$driver}: y el borrado esta",  'documento 5', $y->get('t', 'd5')['txt'] ?? null);
    $y->storage()->cerrar();
    rmrf($d);
    rmrf($g);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Exportar e importar');

$dir = tmpdir('intercambio');
$db  = new Db($dir, ['durable' => false]);

// Los casos que rompen un exportador escrito a mano.
$db->insert('p', [
    'nombre' => 'Mesa, con coma',
    'precio' => 12.5,
    'stock'  => 3,
    'activo' => true,
    'tags'   => ['a', 'b'],
], 'p1');
$db->insert('p', [
    'nombre' => "Con \"comillas\"\ny un salto",
    'precio' => 8.0,
    'soloAqui' => 'este campo no lo tiene el primero',
], 'p2');

eq('exporta a json', 2, $db->export('p', $dir . '/p.json'));
eq('exporta a csv',  2, $db->export('p', $dir . '/p.csv'));

eq('importa el json', 2, $db->import('q', $dir . '/p.json'));
eq('el texto con coma vuelve entero', 'Mesa, con coma', $db->get('q', 'p1')['nombre']);
eq('el decimal sigue siendo decimal', 12.5, $db->get('q', 'p1')['precio']);
ok('y del tipo correcto', \is_float($db->get('q', 'p1')['precio']));
eq('la lista sigue siendo lista', ['a', 'b'], $db->get('q', 'p1')['tags']);

eq('importa el csv', 2, $db->import('r', $dir . '/p.csv'));
eq('la coma dentro de una celda no parte la fila', 'Mesa, con coma', $db->get('r', 'p1')['nombre']);
eq('las comillas y el salto de linea tampoco', "Con \"comillas\"\ny un salto",
    $db->get('r', 'p2')['nombre']);
eq('los numeros vuelven como numeros', 3, $db->get('r', 'p1')['stock']);
eq('los decimales tambien', 12.5, $db->get('r', 'p1')['precio']);
eq('los booleanos tambien', true, $db->get('r', 'p1')['activo']);
eq('y las listas', ['a', 'b'], $db->get('r', 'p1')['tags']);

/*
 * La cabecera del CSV es la union de TODOS los campos, no los del primer
 * documento. Mirar solo el primero es el error clasico: un campo que aparece
 * mas abajo se perderia sin avisar.
 */
eq('un campo que solo tiene el segundo documento no se pierde',
    'este campo no lo tiene el primero', $db->get('r', 'p2')['soloAqui'] ?? null);
ok('y en el primero queda vacio, no en blanco', $db->get('r', 'p1')['soloAqui'] === null);

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Importar no es una puerta trasera');

$db->defineSchema('estricta', ['nombre' => ['tipo' => 'texto', 'obligatorio' => true]]);
$db->insert('suelta', ['sinNombre' => 1], 'x');
$db->export('suelta', $dir . '/suelta.json');

throws('un documento que no cumple el esquema se rechaza al importar',
    static fn () => $db->import('estricta', $dir . '/suelta.json'));

$db->sql('CREATE UNIQUE INDEX ON unica (correo)');
$db->insert('conRepes', ['correo' => 'a@b.c'], 'r1');
$db->insert('conRepes', ['correo' => 'a@b.c'], 'r2');
$db->export('conRepes', $dir . '/repes.json');
throws('y la unicidad tambien se respeta',
    static fn () => $db->import('unica', $dir . '/repes.json'));

throws('un formato que no se conoce se rechaza',
    static fn () => $db->export('p', $dir . '/p.txt'));
eq('pero se puede decir a mano', 2, $db->export('p', $dir . '/p.txt', 'json'));

$db->storage()->cerrar();
rmrf($dir);
summary();
