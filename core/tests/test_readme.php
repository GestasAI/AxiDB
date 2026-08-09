<?php
/**
 * AxiDB - test de la documentacion.
 *
 * Extrae los bloques de codigo PHP de las guias y los ejecuta. Si la
 * documentacion promete un metodo que no existe, o un ejemplo que ya no
 * compila, este test falla.
 *
 * Es el test mas barato de escribir y el que evita la vergüenza mas comun de un
 * proyecto abierto: un README que no funciona.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$raiz = \dirname(\dirname(__DIR__));          // axidb/
/*
 * Las guias se descubren solas, no se listan a mano.
 *
 * Antes iban en una lista escrita aqui, y bastaba con añadir una guia y no
 * acordarse de apuntarla para que sus ejemplos no se ejecutaran nunca. Una guia
 * sin comprobar es exactamente la que se queda desfasada, porque nada avisa.
 *
 * El README de las guias es un indice, no una guia: no lleva codigo.
 */
$docs = [];
foreach (\glob($raiz . '/docs/guide/*.md') ?: [] as $guia) {
    if (\basename($guia) !== 'README.md') {
        $docs[] = 'docs/guide/' . \basename($guia);
    }
}
\sort($docs, SORT_STRING);
$docs[] = 'examples/README.md';

$tmp = tmpdir('readme');

/** Devuelve los bloques ```php de un markdown. */
function bloquesPhp(string $markdown): array
{
    \preg_match_all('/```php\s*\n(.*?)```/s', $markdown, $m);
    return $m[1] ?? [];
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Los documentos existen y tienen ejemplos');

$todos = [];
foreach ($docs as $rel) {
    $path = $raiz . '/' . $rel;
    if (!ok("existe {$rel}", \is_file($path))) {
        continue;
    }
    $bloques = bloquesPhp((string) \file_get_contents($path));
    ok("{$rel} trae ejemplos de codigo (" . \count($bloques) . ')', $bloques !== []);
    foreach ($bloques as $i => $b) {
        $todos[] = [$rel, $i, $b];
    }
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Cada bloque se ejecuta de verdad');

/*
 * Casi todos los bloques son fragmentos que dan por supuesto un $db abierto.
 * Se les antepone el preambulo que la propia guia enseña, se ejecutan en un
 * proceso aparte y se exige que no haya error fatal ni aviso.
 */
/*
 * Cada bloque se ejecuta con el directorio de trabajo en un rincon temporal.
 *
 * Hace falta porque las guias usan rutas relativas —`./copias`, `./datos.csv`—
 * que es lo natural para quien lee. Sin el chdir, ejecutar la documentacion
 * dejaba una carpeta `copias/` y un `proveedores.csv` en la raiz del proyecto,
 * que ademas hace saltar al test de publicacion. El ejemplo esta bien; lo que
 * habia que cambiar era donde corre.
 */
\mkdir($tmp . '/cwd', 0777, true);
$aRincon = 'chdir(' . \var_export($tmp . '/cwd', true) . ');' . "\n";

$preambulo = '<?php' . "\n"
    . $aRincon
    . 'require ' . \var_export($raiz . '/core/axidb.php', true) . ';' . "\n"
    . '$db = axidb(' . \var_export($tmp . '/datos', true) . ', ["durable" => false]);' . "\n"
    . '$id = $db->insert("presupuestos", ["cliente" => "Ana", "total" => 1.0])["id"];' . "\n";

foreach ($todos as [$rel, $i, $codigo]) {
    $etiqueta = "{$rel} bloque " . ($i + 1);

    $script = $tmp . '/b' . \md5($rel . $i) . '.php';

    // Las rutas de la documentacion son relativas al proyecto del lector. Se
    // reescriben a la ubicacion real del nucleo, tanto en bloques autonomos
    // como en fragmentos: cualquiera de los dos puede traer su propio require.
    //
    // Se cubren las dos formas de escribir un require —con __DIR__ y con ruta
    // relativa— y las dos funciones de entrada. Que la guia use rutas de
    // ejemplo como '/var/www/datos' es correcto para el lector; aqui hay que
    // sustituirlas o el test intentaria crear ese directorio de verdad.
    $codigo = \preg_replace(
        ['/__DIR__\s*\.\s*\'[^\']*axidb\.php\'/', '/\'[^\']*axidb\/core\/axidb\.php\'/'],
        \var_export($raiz . '/core/axidb.php', true),
        $codigo
    );
    foreach (['axidb', 'axidb_http'] as $entrada) {
        $codigo = \preg_replace(
            '/' . $entrada . '\(\s*(?:__DIR__\s*\.\s*)?\'[^\']*\'/',
            $entrada . '(' . \var_export($tmp . '/d2', true),
            $codigo
        );
    }

    // Un fragmento da por supuesto un $db abierto; uno autonomo lo abre el mismo.
    // Al autonomo se le cuela el chdir justo detras de su etiqueta de apertura.
    $completo = \str_contains($codigo, '<?php');
    \file_put_contents($script, $completo
        ? \preg_replace('/<\?php\s*\n/', "<?php\n" . $aRincon, $codigo, 1)
        : $preambulo . $codigo);

    $salida = (string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($script) . ' 2>&1');
    $malo   = '';
    foreach (['Parse error', 'Fatal error', 'Uncaught', 'Warning', 'Undefined method', 'Call to undefined'] as $senal) {
        if (\stripos($salida, $senal) !== false) {
            $malo = \trim(\explode("\n", \trim($salida))[0]);
            break;
        }
    }
    ok($etiqueta . ' se ejecuta sin errores' . ($malo !== '' ? " — {$malo}" : ''), $malo === '');
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Todo metodo que promete la guia existe de verdad');

$guia = (string) \file_get_contents($raiz . '/docs/guide/00-cinco-minutos.md');
\preg_match_all('/\$db->([a-zA-Z_]+)\s*\(/', $guia, $m);
$metodos = \array_unique($m[1] ?? []);
ok('la guia menciona metodos de $db (' . \count($metodos) . ')', $metodos !== []);

foreach ($metodos as $metodo) {
    ok("Db::{$metodo}() existe", \method_exists(Db::class, $metodo));
}

\preg_match_all('/->([a-zA-Z_]+)\s*\(/', $guia, $m2);
$encadenados = \array_intersect(
    \array_unique($m2[1] ?? []),
    ['where', 'orderBy', 'limit', 'offset', 'select', 'get', 'first', 'count']
);
foreach ($encadenados as $metodo) {
    ok("Query::{$metodo}() existe", \method_exists(\Axi\Core\Query::class, $metodo));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Los operadores que anuncia la guia funcionan');

\preg_match('/^```\s*\n(=\s+!=.*?)```/ms', $guia, $mo);
// Los operadores de varias palabras van primero: partir por espacios convertiria
// 'NOT IN' en 'NOT' e 'IN', y el test se quejaria de operadores inexistentes.
\preg_match_all(
    '/IS NOT NULL|IS NULL|NOT IN|[<>!]=|[=<>]|IN|LIKE|CONTAINS/',
    \trim($mo[1] ?? ''),
    $mop
);
$anunciados = \array_values(\array_unique($mop[0] ?? []));
ok('la guia anuncia una lista de operadores (' . \count($anunciados) . ')', \count($anunciados) >= 8);

$db = new Db($tmp . '/ops', ['durable' => false]);
$db->insert('c', ['n' => 5, 'txt' => 'hola'], 'x');

$muestra = [
    '='  => ['n', 5], '!=' => ['n', 9], '>' => ['n', 1], '>=' => ['n', 5],
    '<'  => ['n', 9], '<=' => ['n', 5],
    'IN' => ['n', [5, 6]], 'NOT IN' => ['n', [1, 2]],
    'LIKE' => ['txt', 'hol%'], 'CONTAINS' => ['txt', 'ol'],
];
foreach ($anunciados as $op) {
    if (\str_starts_with($op, 'IS')) {
        continue;                       // unarios, cubiertos aparte
    }
    if (!isset($muestra[$op])) {
        ok("el operador anunciado '{$op}' esta cubierto por el test", false);
        continue;
    }
    [$campo, $valor] = $muestra[$op];
    $n = \count($db->find('c')->where($campo, $op, $valor)->get());
    ok("el operador '{$op}' anunciado en la guia funciona", $n === 1);
}
eq('IS NULL anunciado y funcionando',     1, \count($db->find('c')->where('otro', 'IS NULL')->get()));
eq('IS NOT NULL anunciado y funcionando', 1, \count($db->find('c')->where('n', 'IS NOT NULL')->get()));

rmrf($tmp);
summary();
