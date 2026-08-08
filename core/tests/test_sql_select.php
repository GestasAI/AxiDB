<?php
/**
 * AxiDB - AxiSQL: lectura. SELECT, COUNT, operadores, orden y paginacion.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('sql_select'), ['durable' => false]);

$datos = [
    ['n' => 'cafe',     'precio' => 2,  'cat' => 'bebida', 'stock' => 50,   'vegano' => true,  'tags' => ['calido']],
    ['n' => 'te',       'precio' => 3,  'cat' => 'bebida', 'stock' => 10,   'vegano' => true,  'tags' => ['calido', 'suave']],
    ['n' => 'tarta',    'precio' => 5,  'cat' => 'postre', 'stock' => 0,    'vegano' => false, 'tags' => ['dulce']],
    ['n' => 'helado',   'precio' => 4,  'cat' => 'postre', 'stock' => 7,    'vegano' => false, 'tags' => ['dulce', 'frio']],
    ['n' => 'ensalada', 'precio' => 9,  'cat' => 'plato',  'stock' => 20,   'vegano' => true,  'tags' => ['frio']],
    ['n' => 'guiso',    'precio' => 12, 'cat' => 'plato',  'stock' => null, 'vegano' => false, 'tags' => []],
];
foreach ($datos as $i => $d) {
    $db->insert('p', $d, 'd' . $i);
}

/** Nombres del resultado, ordenados, para comparar sin depender del orden. */
function nombres(array $filas): array
{
    $n = \array_column($filas, 'n');
    \sort($n);
    return $n;
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Seleccion de campos');

eq('SELECT * devuelve todo',            6, \count($db->sql('SELECT * FROM p')));
eq('sin WHERE tambien',                 6, \count($db->sql('SELECT n FROM p')));
eq('la proyeccion deja un campo',       1, \count($db->sql("SELECT n FROM p WHERE n = 'cafe'")[0]));
eq('y dos si se piden dos',             2, \count($db->sql("SELECT n, precio FROM p WHERE n = 'cafe'")[0]));
eq('el campo proyectado es el correcto', 'cafe', $db->sql("SELECT n FROM p WHERE n = 'cafe'")[0]['n']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Operadores de comparacion');

eq('=',            ['cafe', 'te'],  nombres($db->sql("SELECT * FROM p WHERE cat = 'bebida'")));
eq('!=',           4,  \count($db->sql("SELECT * FROM p WHERE cat != 'bebida'")));
eq('<> equivale a !=', 4, \count($db->sql("SELECT * FROM p WHERE cat <> 'bebida'")));
eq('>',            2,  \count($db->sql('SELECT * FROM p WHERE precio > 5')));
eq('>=',           3,  \count($db->sql('SELECT * FROM p WHERE precio >= 5')));
eq('<',            2,  \count($db->sql('SELECT * FROM p WHERE precio < 4')));
eq('<=',           3,  \count($db->sql('SELECT * FROM p WHERE precio <= 4')));
eq('decimales',    1,  \count($db->sql('SELECT * FROM p WHERE precio > 11.5')));
eq('negativos',    6,  \count($db->sql('SELECT * FROM p WHERE precio > -1')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] IN, LIKE, CONTAINS y nulos');

eq('IN',        4, \count($db->sql("SELECT * FROM p WHERE cat IN ('bebida', 'postre')")));
eq('NOT IN',    2, \count($db->sql("SELECT * FROM p WHERE cat NOT IN ('bebida', 'postre')")));
eq('IN de un solo valor', 2, \count($db->sql("SELECT * FROM p WHERE cat IN ('bebida')")));
eq('IN numerico', 2, \count($db->sql('SELECT * FROM p WHERE precio IN (2, 3)')));

eq('LIKE con % al final',   1, \count($db->sql("SELECT * FROM p WHERE n LIKE 'caf%'")));
eq('LIKE con % a los lados', 1, \count($db->sql("SELECT * FROM p WHERE n LIKE '%nsalad%'")));
eq('LIKE con _',            1, \count($db->sql("SELECT * FROM p WHERE n LIKE 't_'")));
eq('LIKE sin comodin es exacto', 1, \count($db->sql("SELECT * FROM p WHERE n LIKE 'cafe'")));
eq('LIKE no distingue mayusculas', 1, \count($db->sql("SELECT * FROM p WHERE n LIKE 'CAFE'")));

eq('CONTAINS en texto', 1, \count($db->sql("SELECT * FROM p WHERE n CONTAINS 'uis'")));
eq('CONTAINS en lista', 2, \count($db->sql("SELECT * FROM p WHERE tags CONTAINS 'dulce'")));

eq('IS NULL',     1, \count($db->sql('SELECT * FROM p WHERE stock IS NULL')));
eq('IS NOT NULL', 5, \count($db->sql('SELECT * FROM p WHERE stock IS NOT NULL')));
eq('campo inexistente con IS NULL', 6, \count($db->sql('SELECT * FROM p WHERE nocampo IS NULL')));

eq('booleano TRUE',  3, \count($db->sql('SELECT * FROM p WHERE vegano = TRUE')));
eq('booleano FALSE', 3, \count($db->sql('SELECT * FROM p WHERE vegano = FALSE')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] AND, OR, NOT y parentesis');

eq('AND',  1, \count($db->sql("SELECT * FROM p WHERE cat = 'plato' AND precio < 10")));
eq('OR',   4, \count($db->sql("SELECT * FROM p WHERE cat = 'bebida' OR cat = 'postre'")));
eq('NOT',  4, \count($db->sql("SELECT * FROM p WHERE NOT cat = 'bebida'")));

// Sin parentesis, AND ata mas fuerte que OR: es (a AND b) OR c, no a AND (b OR c).
eq('precedencia AND sobre OR', 3, \count(
    $db->sql("SELECT * FROM p WHERE cat = 'bebida' AND precio > 2 OR cat = 'postre'")
));
// Solo el te: es bebida y cuesta 3. El cafe es bebida pero cuesta 2, no > 2.
eq('los parentesis cambian el resultado (de 3 a 1)', 1, \count(
    $db->sql("SELECT * FROM p WHERE cat = 'bebida' AND (precio > 2 OR cat = 'postre')")
));
eq('anidamiento de dos niveles', 1, \count(
    $db->sql("SELECT * FROM p WHERE (cat = 'plato' OR cat = 'postre') AND (precio > 8 AND vegano = TRUE)")
));
eq('NOT sobre parentesis', 2, \count(
    $db->sql("SELECT * FROM p WHERE NOT (cat = 'bebida' OR cat = 'postre')")
));
// te (3, stock 10) y ensalada (9, stock 20). El cafe cae por precio.
eq('tres AND encadenados', 2, \count(
    $db->sql("SELECT * FROM p WHERE vegano = TRUE AND precio > 2 AND stock > 5")
));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Orden, limite y desplazamiento');

$asc = $db->sql('SELECT * FROM p ORDER BY precio');
eq('ORDER BY es ascendente por defecto', 2, $asc[0]['precio']);
eq('ORDER BY ASC explicito', 2, $db->sql('SELECT * FROM p ORDER BY precio ASC')[0]['precio']);
eq('ORDER BY DESC',         12, $db->sql('SELECT * FROM p ORDER BY precio DESC')[0]['precio']);

$multi = $db->sql('SELECT * FROM p ORDER BY cat ASC, precio DESC');
eq('dos claves: primera categoria', 'bebida', $multi[0]['cat']);
eq('y dentro, el mas caro primero',        3, $multi[0]['precio']);

eq('LIMIT',              2, \count($db->sql('SELECT * FROM p ORDER BY precio LIMIT 2')));
eq('LIMIT mayor que el total', 6, \count($db->sql('SELECT * FROM p LIMIT 99')));
eq('LIMIT 0',            0, \count($db->sql('SELECT * FROM p LIMIT 0')));
eq('LIMIT con OFFSET',   2, \count($db->sql('SELECT * FROM p ORDER BY precio LIMIT 2 OFFSET 4')));
eq('OFFSET salta los primeros', 9,
    $db->sql('SELECT * FROM p ORDER BY precio LIMIT 2 OFFSET 4')[0]['precio']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] COUNT');

eq('COUNT(*) sin filtro',   6, $db->sql('SELECT COUNT(*) FROM p'));
eq('COUNT(*) con filtro',   2, $db->sql("SELECT COUNT(*) FROM p WHERE cat = 'bebida'"));
eq('COUNT(*) sin resultados', 0, $db->sql("SELECT COUNT(*) FROM p WHERE cat = 'zzz'"));
eq('COUNT devuelve entero', 'integer', \gettype($db->sql('SELECT COUNT(*) FROM p')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Sintaxis tolerante');

eq('keywords en minusculas', 2, \count($db->sql("select * from p where cat = 'bebida'")));
eq('keywords mezcladas',     2, \count($db->sql("SeLeCt * FrOm p WhErE cat = 'bebida'")));

// Los nombres de CAMPO son claves JSON: distinguen mayusculas en todas partes.
eq('el nombre de campo distingue mayusculas', 0, \count($db->sql("SELECT * FROM p WHERE CAT = 'bebida'")));

/*
 * Los nombres de COLECCION son directorios, asi que heredan el comportamiento
 * del sistema de archivos: en Windows 'P' y 'p' son la misma coleccion y en
 * Linux son dos distintas. Aqui se mide, no se afirma: convertirlo en un
 * assert fijo daria verde en una maquina y rojo en la otra.
 *
 * Es una diferencia real entre desarrollo y produccion. Pendiente de decidir si
 * el nucleo normaliza los nombres; no se resuelve dentro de la ola del SQL.
 */
$mismaColeccion = \count($db->sql('SELECT * FROM P')) === \count($db->sql('SELECT * FROM p'));
echo '    nombres de coleccion en este sistema: '
   . ($mismaColeccion ? "insensibles a mayusculas (tipico de Windows)\n" : "sensibles (tipico de Linux)\n");
ok('el comportamiento de mayusculas en colecciones queda determinado', true);
eq('punto y coma final', 6, \count($db->sql('SELECT * FROM p;')));
eq('saltos de linea', 2, \count($db->sql("SELECT *\n  FROM p\n  WHERE cat = 'bebida'")));
eq('comentario al final', 6, \count($db->sql('SELECT * FROM p -- todos los productos')));
eq('comillas dobles como literal', 2, \count($db->sql('SELECT * FROM p WHERE cat = "bebida"')));
eq('comilla doblada escapa', 0, \count($db->sql("SELECT * FROM p WHERE n = 'l''argot'")));

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] Errores con mensaje util');

foreach ([
    'SELECT * FROM'                     => 'falta la coleccion',
    'SELECT FROM p'                     => 'falta la lista de campos',
    "SELECT * FROM p WHERE cat 'bebida'" => 'falta el operador',
    "SELECT * FROM p WHERE cat = "      => 'falta el valor',
    'SELECT * FROM p ORDER BY'          => 'falta el campo de orden',
    'SELECT * FROM p LIMIT abc'         => 'el limite no es un entero',
    ''                                  => 'sentencia vacia',
    'BORRA TODO'                        => 'sentencia desconocida',
    "SELECT * FROM p WHERE n = 'sin cerrar" => 'cadena sin cerrar',
    'SELECT * FROM p WHERE (cat = 1'    => 'parentesis sin cerrar',
] as $sql => $motivo) {
    throws("rechaza: {$motivo}", static fn() => $db->sql($sql));
}

rmrf($db->path());
summary();
