<?php
/**
 * AxiDB - gate de la ola A1: instalacion limpia.
 *
 * Es EL test que define el producto:
 *
 *   Copias la carpeta en un directorio vacio fuera del repositorio, escribes
 *   cinco lineas, y funciona. Sin CAPABILITIES, sin Composer, sin configurar
 *   nada, sin variables de entorno.
 *
 * Si este test falla, no hay nada que entregarle a un desarrollador ajeno.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

$origen  = \dirname(__DIR__);                        // axidb/core
$destino = tmpdir('instalacion') . '/vendor/axidb';

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Copiar el nucleo fuera del repositorio');

function copiarArbol(string $de, string $a): int
{
    @\mkdir($a, 0777, true);
    $n = 0;
    foreach (\scandir($de) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $src = $de . '/' . $e;
        $dst = $a . '/' . $e;
        if (\is_dir($src)) {
            $n += copiarArbol($src, $dst);
        } elseif (\copy($src, $dst)) {
            $n++;
        }
    }
    return $n;
}

$copiados = copiarArbol($origen, $destino);
ok("se copiaron {$copiados} archivos del nucleo", $copiados > 5);
ok('el destino esta fuera del repositorio de AxiDB',
    !\str_contains(\realpath($destino) ?: '', 'axidb-repo')
    && !\str_contains(\realpath($destino) ?: '', \dirname($origen)));
ok('no se copio nada de CAPABILITIES', !\is_dir($destino . '/CAPABILITIES'));
ok('no hace falta composer.json para arrancar', !\is_file($destino . '/composer.json'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Una aplicacion nueva, de cinco lineas');

$app  = \dirname($destino, 2);
$prog = $app . '/mi-almacen.php';

\file_put_contents($prog, <<<'PHP'
<?php
require __DIR__ . '/vendor/axidb/axidb.php';
$db = axidb(__DIR__ . '/datos');
$db->index('pedidos', 'cliente');
$db->insert('pedidos', ['cliente' => 'Ana', 'tipo' => 'perfil', 'total' => 421.20]);
$db->insert('pedidos', ['cliente' => 'Ana', 'tipo' => 'junta',   'total' => 76.00]);
$db->insert('pedidos', ['cliente' => 'Luis','tipo' => 'tornillo', 'total' => 252.00]);
$deAna = $db->by('pedidos', 'cliente', 'Ana');
echo count($deAna), '|', array_sum(array_column($deAna, 'total')), '|', $db->count('pedidos');
PHP);

// -n descarta cualquier php.ini del sistema: nada de extensiones cargadas.
$salida = \trim((string) \shell_exec(
    \escapeshellarg(PHP_BINARY) . ' -n ' . \escapeshellarg($prog) . ' 2>&1'
));

ok("la aplicacion se ejecuta sin errores: '{$salida}'", $salida === '2|497.2|3');

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Los datos quedan donde se dijo, y son legibles');

$datos = $app . '/datos';
ok('se creo el directorio de datos',            \is_dir($datos));
ok('se creo la coleccion',                      \is_dir($datos . '/pedidos'));
ok('hay tres documentos JSON',                  \count(\glob($datos . '/pedidos/*.json') ?: []) === 3);
ok('se creo el indice declarado',               \is_dir($datos . '/pedidos/_idx/cliente'));

$uno = \json_decode((string) \file_get_contents((\glob($datos . '/pedidos/*.json') ?: [])[0]), true);
ok('el documento es JSON valido y legible a ojo', \is_array($uno) && isset($uno['id'], $uno['_version']));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Segunda ejecucion: los datos persisten');

$prog2 = $app . '/leer.php';
\file_put_contents($prog2, <<<'PHP'
<?php
require __DIR__ . '/vendor/axidb/axidb.php';
$db = axidb(__DIR__ . '/datos');
echo $db->count('pedidos'), '|', count($db->by('pedidos', 'cliente', 'Ana'));
PHP);

$salida2 = \trim((string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' -n ' . \escapeshellarg($prog2) . ' 2>&1'));
ok("un proceso nuevo lee lo que escribio el anterior: '{$salida2}'", $salida2 === '3|2');

/* ─────────────────────────────────────────────────────────────────────────── */
section('D2] Requerir el punto de entrada dos veces');

/*
 * Caso muy real: dos archivos de la aplicacion hacen require del nucleo. PHP
 * eleva las declaraciones de funcion de nivel superior en tiempo de
 * compilacion, asi que un `return` de guardia NO las evita: hace falta que
 * esten dentro de un condicional. Sin eso, la segunda inclusion mata la app
 * con "Cannot redeclare axidb()".
 */
$doble = $app . '/doble.php';
\file_put_contents($doble, '<?php
require ' . \var_export($destino . '/axidb.php', true) . ';
require ' . \var_export($destino . '/axidb.php', true) . ';
$db = axidb(' . \var_export($app . '/datos', true) . ');
echo $db->count("pedidos");
');
$salidaDoble = \trim((string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' -n ' . \escapeshellarg($doble) . ' 2>&1'));
ok("dos require del punto de entrada no rompen nada: '{$salidaDoble}'", $salidaDoble === '3');

// Y con require_once, que es como lo haria la mitad de la gente.
$once = $app . '/once.php';
\file_put_contents($once, '<?php
require_once ' . \var_export($destino . '/axidb.php', true) . ';
require ' . \var_export($destino . '/axidb.php', true) . ';
echo axidb(' . \var_export($app . '/datos', true) . ')->count("pedidos");
');
$salidaOnce = \trim((string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' -n ' . \escapeshellarg($once) . ' 2>&1'));
ok("mezclar require_once y require tampoco: '{$salidaOnce}'", $salidaOnce === '3');

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Los ejemplos del repositorio funcionan');

/*
 * Se descubren, no se enumeran.
 *
 * Aqui habia una lista con dos nombres, y el repositorio llego a tener siete
 * ejemplos de los que cuatro no arrancaban: eran restos del motor anterior y
 * pedian un archivo que ya no existe. Esta seccion los daba por buenos sin
 * mirarlos, porque no estaban en la lista.
 */
$ejemplos = \array_map('basename', \array_filter(
    \glob(\dirname($origen) . '/examples/*') ?: [],
    static fn(string $d) => \is_dir($d) && \is_file($d . '/index.php')
));
\sort($ejemplos, SORT_STRING);
ok('hay ejemplos que ejecutar: ' . \implode(', ', $ejemplos), $ejemplos !== []);

foreach ($ejemplos as $ejemplo) {
    $ruta = \dirname($origen) . '/examples/' . $ejemplo . '/index.php';
    $out = (string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($ruta) . ' 2>&1');
    $mal = \stripos($out, 'fatal') !== false || \stripos($out, 'warning') !== false
        || \stripos($out, 'uncaught') !== false;
    ok("el ejemplo '{$ejemplo}' se ejecuta sin errores ni avisos", !$mal && \strlen($out) > 100);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Dos dominios distintos conviven sin tocar el nucleo');

$hash = static function (string $dir): string {
    $h = [];
    foreach (\glob($dir . '/*.php') ?: [] as $f) {
        $h[] = \md5_file($f);
    }
    return \md5(\implode('', $h));
};
$antes = $hash($origen);
\shell_exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg(\dirname($origen) . '/examples/02-empleados/index.php') . ' 2>&1');
ok('ejecutar un dominio nuevo no modifica un solo archivo del nucleo', $antes === $hash($origen));

rmrf(\dirname($app));
summary();
