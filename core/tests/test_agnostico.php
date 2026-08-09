<?php
/**
 * AxiDB - test de frontera. Es el guardian de la regla de oro del proyecto:
 *
 *   WordPress es a MySQL lo que MyLocal es a AxiDB.
 *
 * MySQL no sabe que existe un wp_post. AxiDB no puede saber que existe un plato,
 * un local ni un alergeno. Si este test falla, el nucleo dejo de ser una base de
 * datos y volvio a ser el motor de una aplicacion concreta.
 *
 * Ver claude/planes/axidb-nucleo.md seccion 0.1.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

$core = \dirname(__DIR__);

/**
 * Todo el .php del nucleo, incluidos los subpaquetes. Recorrer solo el primer
 * nivel dejaba a core/Sql/ sin vigilar: la frontera se comprueba entera o no
 * se comprueba.
 */
function fuentesDe(string $dir, string $prefijo = ''): array
{
    $out = [];
    foreach (\scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..' || $e === 'tests') {
            continue;
        }
        $ruta = $dir . '/' . $e;
        if (\is_dir($ruta)) {
            $out += fuentesDe($ruta, $prefijo . $e . '/');
        } elseif (\str_ends_with($e, '.php')) {
            $out[$prefijo . $e] = (string) \file_get_contents($ruta);
        }
    }
    return $out;
}

$fuentes = fuentesDe($core);

ok('el nucleo tiene archivos que revisar', \count($fuentes) >= 5);
ok('y se revisa tambien el subpaquete Sql (' . \count($fuentes) . ' archivos)',
    \count(\array_filter(\array_keys($fuentes), static fn($f) => \str_starts_with($f, 'Sql/'))) >= 6);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Cero dominio de MyLocal en el nucleo');

$prohibidas = [
    'local_id', 'locales', 'carta', 'plato', 'alergeno', 'hostelero', 'mesa',
    'CAPABILITIES', 'LoginSanitize', 'LoginSessions', 'LoginRoles', 'LocalModel',
    'mylocal', 'acide', 'socola', 'STORAGE_ROOT', 'DATA_ROOT', 'CONFIG_ROOT',
];
// Frontera de palabra al inicio: 'carta' debe cazar "carta" y "cartas", pero no
// "descartan". Sin la frontera, cualquier palabra castellana que contenga un
// termino del dominio dispara un falso positivo.
foreach ($prohibidas as $palabra) {
    $patron = '/\b' . \preg_quote($palabra, '/') . '/iu';
    $donde  = [];
    foreach ($fuentes as $archivo => $codigo) {
        if (\preg_match($patron, $codigo) === 1) {
            $donde[] = $archivo;
        }
    }
    ok("sin rastro de '{$palabra}'" . ($donde ? ' — aparece en ' . \implode(', ', $donde) : ''),
        $donde === []);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Cero dependencias fuera de core/');

foreach ($fuentes as $archivo => $codigo) {
    // Solo los require reales del codigo. Un ejemplo de uso escrito en un
    // comentario no es una dependencia, y una busqueda por texto los confunde.
    $malos  = [];
    $tokens = \token_get_all($codigo);
    for ($i = 0, $total = \count($tokens); $i < $total; $i++) {
        $t = $tokens[$i];
        if (!\is_array($t)
            || !\in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $expr = '';
        for ($j = $i + 1; $j < $total && $tokens[$j] !== ';'; $j++) {
            $expr .= \is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        $expr = \trim($expr);
        // Vale si la ruta sale de __DIR__ o AXIDB_CORE, o si es la variable que
        // el autoloader ya derivo de AXIDB_CORE.
        $seguro = \str_contains($expr, '__DIR__')
               || \str_contains($expr, 'AXIDB_CORE')
               || \preg_match('/^\$[a-z_]+$/i', $expr) === 1;
        if (!$seguro) {
            $malos[] = $expr;
        }
    }
    ok("{$archivo} no incluye nada de fuera" . ($malos ? ': ' . \implode(' | ', $malos) : ''),
        $malos === []);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Cero clases de otros espacios de nombres');

foreach ($fuentes as $archivo => $codigo) {
    // La barra inicial de un nombre completo se descarta antes de juzgar:
    // `use \Axi\Core\X` es exactamente lo mismo que `use Axi\Core\X`. Sin esto
    // la regla castigaba escribir el nombre entero, que es lo mas explicito.
    \preg_match_all('/^\s*use\s+\\\\?([A-Za-z0-9_\\\\]+)/mi', $codigo, $m);
    $malos = \array_filter($m[1] ?? [], static fn($ns) => !\str_starts_with($ns, 'Axi\\Core'));
    ok("{$archivo} no importa espacios de nombres ajenos" . ($malos ? ': ' . \implode(', ', $malos) : ''),
        $malos === []);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Solo extensiones de PHP siempre presentes');

$permitidas = ['json'];
/*
 * `mb_` entro en la lista al escribir el generador de embeddings de prueba:
 * mbstring parece estar siempre y no lo esta —es opcional en muchas
 * distribuciones— y la regla lo habria dejado pasar en silencio. Una regla que
 * no vigila lo que dice vigilar es peor que no tenerla.
 */
$sospechosas = ['curl_', 'mysqli_', 'pdo_', 'PDO', 'gd_', 'imagick', 'sodium_',
                'gmp_', 'bcadd', 'apcu_', 'redis', 'mb_', 'iconv', 'intl'];

/*
 * Se miran solo las llamadas de verdad, no los comentarios. Un archivo que
 * explica "esto NO usa iconv" contiene la palabra iconv, y una busqueda por
 * texto lo daba por culpable. Es el mismo criterio que en la seccion B con los
 * require: lo que cuenta es el codigo, no lo que se dice sobre el codigo.
 */
foreach ($fuentes as $archivo => $codigo) {
    $soloCodigo = '';
    foreach (\token_get_all($codigo) as $t) {
        if (\is_array($t) && \in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
            continue;
        }
        $soloCodigo .= \is_array($t) ? $t[1] : $t;
    }

    $encontradas = [];
    foreach ($sospechosas as $fn) {
        if (\stripos($soloCodigo, $fn) !== false) {
            $encontradas[] = $fn;
        }
    }
    ok("{$archivo} no usa extensiones opcionales" . ($encontradas ? ': ' . \implode(', ', $encontradas) : ''),
        $encontradas === []);
}
ok('json_encode disponible (unica extension exigida)', \function_exists('json_encode'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Reglas de estilo del proyecto');

foreach ($fuentes as $archivo => $codigo) {
    $lineas = \substr_count($codigo, "\n") + 1;
    ok("{$archivo} no pasa de 250 lineas ({$lineas})", $lineas <= 250);
}

$emoji = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u';
foreach ($fuentes as $archivo => $codigo) {
    ok("{$archivo} sin emojis", !\preg_match($emoji, $codigo));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] El nucleo arranca aislado, en un proceso limpio');

$tmp    = tmpdir('agnostico');
$script = $tmp . '/aislado.php';
\file_put_contents($script, '<?php
require ' . \var_export($core . '/axidb.php', true) . ';
$db = axidb(' . \var_export($tmp . '/datos', true) . ');
$db->insert("cosas", ["a" => 1], "x");
echo $db->get("cosas", "x")["a"] === 1 ? "OK" : "MAL";
');

$salida = (string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' -n ' . \escapeshellarg($script) . ' 2>&1');
ok('funciona con php -n (sin ningun php.ini ni extension cargada): ' . \trim($salida),
    \str_contains($salida, 'OK'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Nada que necesite un PHP mas nuevo del que se promete');

/*
 * composer.json dice `php: >=8.1`, y esa promesa hay que poder comprobarla aqui.
 *
 * Esto nacio de un fallo real: se uso una constante dentro de un trait, que es
 * de PHP 8.2. En la maquina de desarrollo hay 8.2, asi que compilaba sin decir
 * nada; la CI lo caza porque corre las cuatro versiones, pero eso son diez
 * minutos y un rojo publico. Aqui cuesta un segundo.
 *
 * No pretende detectarlo todo —para eso esta la CI— sino las construcciones
 * concretas que uno escribe sin pensar viniendo de una version mas nueva.
 */
$minimo = '8.1';
$posteriores = [
    // [patron, version, que es]
    ['/\btrait\s+\w+[^{]*\{(?:[^{}]|\{[^{}]*\})*?\bconst\s+\w+/s', '8.2', 'constantes dentro de un trait'],
    ['/\breadonly\s+(?:final\s+)?class\b/',                        '8.2', 'clases readonly enteras'],
    ['/:\s*(?:true|false)\s*(?:\{|;)/',                            '8.2', 'true o false como tipo suelto'],
    ['/#\[\\\\?Override\]/',                                       '8.3', 'el atributo Override'],
    ['/\bjson_validate\s*\(/',                                     '8.3', 'json_validate()'],
    ['/\barray_(?:find|any|all)\s*\(/',                            '8.4', 'array_find, array_any o array_all'],
];

foreach ($posteriores as [$patron, $version, $que]) {
    $donde = [];
    foreach ($fuentes as $archivo => $codigo) {
        if (\preg_match($patron, $codigo) === 1) {
            $donde[] = $archivo;
        }
    }
    ok(
        "nada de PHP {$version} ({$que}), que composer promete {$minimo}"
        . ($donde === [] ? '' : ' -> ' . \implode(', ', $donde)),
        $donde === []
    );
}

ok("composer.json sigue pidiendo >={$minimo}",
    \str_contains(
        (string) @\file_get_contents(\dirname(AXIDB_CORE) . '/composer.json'),
        '">=' . $minimo . '"'
    ));

rmrf($tmp);
summary();
