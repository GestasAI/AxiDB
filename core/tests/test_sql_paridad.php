<?php
/**
 * AxiDB - AxiSQL: paridad con la API.
 *
 * AxiSQL no es un motor paralelo: es otra forma de escribir lo que ya hace
 * find(). Este test lo comprueba ejecutando las dos vias sobre los mismos datos
 * y exigiendo el mismo resultado.
 *
 * Si algun dia divergen, es que alguien duplico logica en vez de reutilizarla.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('sql_paridad'), ['durable' => false]);

$datos = [
    ['n' => 'cafe',     'precio' => 2,  'cat' => 'bebida', 'stock' => 50,   'vegano' => true],
    ['n' => 'te',       'precio' => 3,  'cat' => 'bebida', 'stock' => 10,   'vegano' => true],
    ['n' => 'tarta',    'precio' => 5,  'cat' => 'postre', 'stock' => 0,    'vegano' => false],
    ['n' => 'helado',   'precio' => 4,  'cat' => 'postre', 'stock' => 7,    'vegano' => false],
    ['n' => 'ensalada', 'precio' => 9,  'cat' => 'plato',  'stock' => 20,   'vegano' => true],
    ['n' => 'guiso',    'precio' => 12, 'cat' => 'plato',  'stock' => null, 'vegano' => false],
];
foreach ($datos as $i => $d) {
    $db->insert('p', $d, 'd' . $i);
}

/** Compara dos resultados sin depender del orden ni de los metadatos. */
function mismos(array $a, array $b): bool
{
    $limpiar = static function (array $filas): array {
        $out = [];
        foreach ($filas as $f) {
            unset($f['_createdAt'], $f['_updatedAt'], $f['_version']);
            \ksort($f);
            $out[] = \json_encode($f);
        }
        \sort($out);
        return $out;
    };
    return $limpiar($a) === $limpiar($b);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Cada operador da lo mismo por las dos vias');

$casos = [
    ['=',           "SELECT * FROM p WHERE cat = 'bebida'",       ['cat', '=', 'bebida']],
    ['!=',          "SELECT * FROM p WHERE cat != 'bebida'",      ['cat', '!=', 'bebida']],
    ['>',           'SELECT * FROM p WHERE precio > 4',           ['precio', '>', 4]],
    ['>=',          'SELECT * FROM p WHERE precio >= 4',          ['precio', '>=', 4]],
    ['<',           'SELECT * FROM p WHERE precio < 4',           ['precio', '<', 4]],
    ['<=',          'SELECT * FROM p WHERE precio <= 4',          ['precio', '<=', 4]],
    ['IN',          "SELECT * FROM p WHERE cat IN ('bebida','postre')", ['cat', 'IN', ['bebida', 'postre']]],
    ['NOT IN',      "SELECT * FROM p WHERE cat NOT IN ('bebida')", ['cat', 'NOT IN', ['bebida']]],
    ['LIKE',        "SELECT * FROM p WHERE n LIKE 'ca%'",         ['n', 'LIKE', 'ca%']],
    ['CONTAINS',    "SELECT * FROM p WHERE n CONTAINS 'uis'",     ['n', 'CONTAINS', 'uis']],
    ['IS NULL',     'SELECT * FROM p WHERE stock IS NULL',        ['stock', 'IS NULL']],
    ['IS NOT NULL', 'SELECT * FROM p WHERE stock IS NOT NULL',    ['stock', 'IS NOT NULL']],
    ['booleano',    'SELECT * FROM p WHERE vegano = TRUE',        ['vegano', '=', true]],
];

foreach ($casos as [$nombre, $sql, $args]) {
    $porSql = $db->sql($sql);
    $porApi = \count($args) === 2
        ? $db->find('p')->where($args[0], $args[1])->get()
        : $db->find('p')->where($args[0], $args[1], $args[2])->get();
    ok("operador {$nombre}: mismo resultado (" . \count($porSql) . ' documentos)', mismos($porSql, $porApi));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Orden, limite y proyeccion');

ok('ORDER BY DESC', mismos(
    $db->sql('SELECT * FROM p ORDER BY precio DESC'),
    $db->find('p')->orderBy('precio', 'desc')->get()
));
ok('LIMIT con OFFSET', mismos(
    $db->sql('SELECT * FROM p ORDER BY precio LIMIT 3 OFFSET 2'),
    $db->find('p')->orderBy('precio')->limit(3)->offset(2)->get()
));
ok('proyeccion de campos', mismos(
    $db->sql("SELECT n, precio FROM p WHERE cat = 'bebida'"),
    $db->find('p')->where('cat', '=', 'bebida')->select(['n', 'precio'])->get()
));
ok('dos claves de orden', mismos(
    $db->sql('SELECT * FROM p ORDER BY cat ASC, precio DESC'),
    $db->find('p')->orderBy('cat', 'asc')->orderBy('precio', 'desc')->get()
));
eq('COUNT coincide con count()',
    $db->sql("SELECT COUNT(*) FROM p WHERE cat = 'bebida'"),
    $db->find('p')->where('cat', '=', 'bebida')->count()
);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Condiciones encadenadas');

ok('dos AND equivalen a dos where()', mismos(
    $db->sql("SELECT * FROM p WHERE cat = 'bebida' AND precio > 2"),
    $db->find('p')->where('cat', '=', 'bebida')->where('precio', '>', 2)->get()
));
ok('tres AND equivalen a tres where()', mismos(
    $db->sql('SELECT * FROM p WHERE vegano = TRUE AND precio > 2 AND stock > 5'),
    $db->find('p')->where('vegano', '=', true)->where('precio', '>', 2)->where('stock', '>', 5)->get()
));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] El indice no cambia el resultado, solo el camino');

$sinIndice = $db->sql("SELECT * FROM p WHERE cat = 'postre'");
$apiSin    = $db->find('p')->where('cat', '=', 'postre')->get();

$db->sql('CREATE INDEX ON p (cat)');

$conIndice = $db->sql("SELECT * FROM p WHERE cat = 'postre'");
$apiCon    = $db->find('p')->where('cat', '=', 'postre')->get();

eq('antes escaneaba', 'scan', ($db->find('p')->where('cat', '=', 'x'))->plan()['strategy'] ?? 'scan');
ok('con indice, SQL da lo mismo que sin el', mismos($sinIndice, $conIndice));
ok('con indice, la API da lo mismo que sin el', mismos($apiSin, $apiCon));
ok('y SQL y API siguen coincidiendo', mismos($conIndice, $apiCon));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Escritura: SQL y API dejan el mismo documento');

$porApi = $db->insert('via', ['nombre' => 'Ana', 'total' => 421.20, 'activo' => true], 'api');
$db->sql("INSERT INTO via (nombre, total, activo) VALUES ('Ana', 421.20, TRUE)");

$porSql = null;
foreach ($db->all('via') as $d) {
    if ($d['id'] !== 'api') {
        $porSql = $d;
    }
}
ok('el INSERT por SQL creo un documento', $porSql !== null);

foreach (['nombre', 'total', 'activo'] as $campo) {
    eq("el campo '{$campo}' es identico", $porApi[$campo], $porSql[$campo]);
    ok("y del mismo tipo: {$campo}", \gettype($porApi[$campo]) === \gettype($porSql[$campo]));
}
eq('los dos arrancan en _version 1', $porApi['_version'], $porSql['_version']);

$db->sql("UPDATE via SET total = 500 WHERE id = 'api'");
eq('el UPDATE por SQL hace lo mismo que update()', 500, $db->get('via', 'api')['total']);
eq('y sube la version igual', 2, $db->get('via', 'api')['_version']);

rmrf($db->path());
summary();
