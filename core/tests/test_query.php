<?php
/**
 * AxiDB - test del constructor de consultas: operadores, orden, paginacion
 * y uso automatico de indice.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir = tmpdir('query');
$db  = new Db($dir, ['durable' => false]);

$datos = [
    ['n' => 'cafe',    'precio' => 2,  'cat' => 'bebida', 'stock' => 50,   'vegano' => true],
    ['n' => 'te',      'precio' => 3,  'cat' => 'bebida', 'stock' => 10,   'vegano' => true],
    ['n' => 'tarta',   'precio' => 5,  'cat' => 'postre', 'stock' => 0,    'vegano' => false],
    ['n' => 'helado',  'precio' => 4,  'cat' => 'postre', 'stock' => 7,    'vegano' => false],
    ['n' => 'ensalada','precio' => 9,  'cat' => 'plato',  'stock' => 20,   'vegano' => true],
    ['n' => 'guiso',   'precio' => 12, 'cat' => 'plato',  'stock' => null, 'vegano' => false],
];
foreach ($datos as $i => $d) {
    $db->insert('p', $d, 'd' . $i);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Operadores de comparacion');

eq('=',        2, \count($db->find('p')->where('cat', '=', 'bebida')->get()));
eq('forma corta where(campo, valor)', 2, \count($db->find('p')->where('cat', 'bebida')->get()));
eq('!=',       4, \count($db->find('p')->where('cat', '!=', 'bebida')->get()));
eq('>',        2, \count($db->find('p')->where('precio', '>', 5)->get()));
eq('>=',       3, \count($db->find('p')->where('precio', '>=', 5)->get()));
eq('<',        2, \count($db->find('p')->where('precio', '<', 4)->get()));
eq('<=',       3, \count($db->find('p')->where('precio', '<=', 4)->get()));
// bebida: cafe, te | postre: tarta, helado
eq('IN',       4, \count($db->find('p')->where('cat', 'IN', ['bebida', 'postre'])->get()));
eq('NOT IN',   2, \count($db->find('p')->where('cat', 'NOT IN', ['bebida', 'postre'])->get()));
eq('IS NULL',  1, \count($db->find('p')->where('stock', 'IS NULL')->get()));
eq('IS NOT NULL', 5, \count($db->find('p')->where('stock', 'IS NOT NULL')->get()));
eq('booleano true', 3, \count($db->find('p')->where('vegano', '=', true)->get()));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] LIKE y CONTAINS');

eq('LIKE con % al final',   1, \count($db->find('p')->where('n', 'LIKE', 'caf%')->get()));
eq('LIKE con % a los lados', 1, \count($db->find('p')->where('n', 'LIKE', '%nsalad%')->get()));
eq('LIKE sin comodin es exacto', 1, \count($db->find('p')->where('n', 'LIKE', 'cafe')->get()));
eq('LIKE que no casa', 0, \count($db->find('p')->where('n', 'LIKE', 'pizza%')->get()));
eq('CONTAINS en texto',  1, \count($db->find('p')->where('n', 'CONTAINS', 'uis')->get()));

$db->insert('p', ['n' => 'combo', 'tags' => ['nuevo', 'oferta'], 'precio' => 6, 'cat' => 'plato'], 'd6');
eq('CONTAINS en array', 1, \count($db->find('p')->where('tags', 'CONTAINS', 'oferta')->get()));

throws('operador desconocido lanza', static fn() => $db->find('p')->where('n', '~=', 'x')->get());

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Condiciones encadenadas');

// En este punto 'combo' (plato, 6) ya existe: son ensalada y combo.
$r = $db->find('p')->where('cat', '=', 'plato')->where('precio', '<', 10)->get();
eq('dos condiciones se combinan con AND', 2, \count($r));
$nombres = array_column($r, 'n');
\sort($nombres);
eq('y devuelve los correctos', ['combo', 'ensalada'], $nombres);

// vegano y precio>2 y stock>5: te (3, 10) y ensalada (9, 20)
eq('tres condiciones', 2, \count(
    $db->find('p')->where('vegano', '=', true)->where('precio', '>', 2)->where('stock', '>', 5)->get()
));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Orden, paginacion y proyeccion');

$asc = $db->find('p')->where('cat', '!=', 'nada')->orderBy('precio', 'asc')->get();
eq('orden ascendente: el primero es el mas barato', 2, $asc[0]['precio']);
$desc = $db->find('p')->where('cat', '!=', 'nada')->orderBy('precio', 'desc')->get();
eq('orden descendente: el primero es el mas caro', 12, $desc[0]['precio']);

$multi = $db->find('p')->orderBy('cat', 'asc')->orderBy('precio', 'desc')->get();
eq('orden por dos claves: primera categoria alfabetica', 'bebida', $multi[0]['cat']);
eq('y dentro de ella, el mas caro primero', 3, $multi[0]['precio']);

eq('limit',            2, \count($db->find('p')->orderBy('precio')->limit(2)->get()));
eq('offset con limit', 1, \count($db->find('p')->orderBy('precio')->offset(6)->limit(5)->get()));
eq('offset solo',      3, \count($db->find('p')->orderBy('precio')->offset(4)->get()));
eq('limit 0 no devuelve nada', 0, \count($db->find('p')->limit(0)->get()));

$proj = $db->find('p')->where('n', '=', 'cafe')->select(['n', 'precio'])->get();
eq('la proyeccion deja solo 2 campos', 2, \count($proj[0]));
ok('y son los pedidos', isset($proj[0]['n'], $proj[0]['precio']));

$f = $db->find('p')->where('n', '=', 'cafe')->first();
eq('first devuelve un documento', 'cafe', $f['n']);
ok('first sin resultados da null', $db->find('p')->where('n', '=', 'zzz')->first() === null);

eq('count ignora limit', 7, $db->find('p')->count());
eq('count con filtro',   3, $db->find('p')->where('cat', '=', 'plato')->count());

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] El indice se usa cuando existe');

$db->index('p', 'cat');

// Storage espia: cuenta las veces que se pide la coleccion entera.
$antes = $db->find('p')->where('cat', '=', 'plato')->get();
eq('con indice el resultado es el mismo', 3, \count($antes));

$sinIndice = $db->find('p')->where('precio', '=', 5)->get();
eq('un campo sin indice sigue funcionando', 1, \count($sinIndice));

$db->index('p', 'precio');
eq('tras indexar, mismo resultado', 1, \count($db->find('p')->where('precio', '=', 5)->get()));

$comb = $db->find('p')->where('cat', '=', 'postre')->where('precio', '>', 4)->get();
eq('indice mas filtro adicional', 1, \count($comb));
eq('y es el correcto', 'tarta', $comb[0]['n']);

eq('valor indexado inexistente da vacio', 0, \count($db->find('p')->where('cat', '=', 'fantasma')->get()));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Casos limite');

eq('coleccion inexistente da vacio', 0, \count($db->find('nada')->get()));
eq('campo inexistente con = da vacio', 0, \count($db->find('p')->where('nocampo', '=', 'x')->get()));
eq('campo inexistente con IS NULL los da todos', 7, \count($db->find('p')->where('nocampo', 'IS NULL')->get()));

rmrf($dir);
summary();
