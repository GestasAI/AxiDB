<?php
/**
 * AxiDB - test_sec_recursos: agotamiento de recursos y denegacion de servicio.
 *
 * AxiDB corre en alojamiento compartido, donde PHP suele tener 128 MB y un
 * tiempo maximo de ejecucion. Un documento, una consulta o una copia que tumbe
 * el proceso no es solo "va lento": es una brecha de DISPONIBILIDAD, y en un
 * motor de datos ademas puede dejar estado a medias.
 *
 * Este test dispara ataques reales y MIDE (tiempo y pico de memoria). Las
 * aserciones afirman el comportamiento SEGURO: si el ataque tiene exito
 * (fatal por memoria, escritura fuera del destino, tiempo desbocado), el test
 * se queda ROJO a proposito. No se debe debilitar para que pase; la marca roja
 * ES el hallazgo.
 *
 * Metodo para los ataques que MATAN el proceso (fatal por memoria):
 * no se pueden lanzar dentro del propio test, porque un fatal se lleva por
 * delante al arnes. Se lanzan en un proceso hijo con un `memory_limit`
 * acotado, y se observa si el hijo murio con "Allowed memory size" (VULNERABLE)
 * o si contesto con una excepcion limpia (SEGURO). Los hijos usan escalas
 * pequenas y limites de 96 MB para no acaparar la maquina: cada uno dura
 * segundos y el conjunto se mantiene por debajo de dos minutos.
 *
 * Los ataques que NO matan (traversal, ReDoS, k gigante) se ejecutan en
 * proceso y se comprueba el efecto directamente.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Http\Request;
use Axi\Core\Sweeper;

/**
 * Lanza un worker PHP con un memory_limit acotado y devuelve como acabo.
 *
 * @return array{fatalMem:bool, code:int, out:string, ms:float}
 */
function hijo(string $codigo, int $memMB = 96, int $timeoutMs = 30000): array
{
    $script = \sys_get_temp_dir() . '/axi_sec_worker_' . \getmypid() . '_' . \bin2hex(\random_bytes(3)) . '.php';
    \file_put_contents($script, "<?php\nrequire_once " . \var_export(\dirname(__DIR__) . '/axidb.php', true) . ";\n" . $codigo);

    $cmd = \escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $memMB . 'M ' . \escapeshellarg($script);
    if (\stripos(PHP_OS_FAMILY, 'Windows') === false) {
        $cmd = 'exec ' . $cmd;
    }
    $pipes = [];
    $t0 = \microtime(true);
    $proc = \proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $out = '';
    if (\is_resource($proc)) {
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);
        while (true) {
            $st = \proc_get_status($proc);
            $out .= (string) \stream_get_contents($pipes[1]);
            $out .= (string) \stream_get_contents($pipes[2]);
            if (!$st['running']) {
                break;
            }
            if ((\microtime(true) - $t0) * 1000 > $timeoutMs) {
                \proc_terminate($proc, 9);
                break;
            }
            \usleep(10000);
        }
        $code = \proc_get_status($proc)['exitcode'] ?? -1;
        foreach ($pipes as $p) {
            @\fclose($p);
        }
        \proc_close($proc);
    } else {
        $code = -1;
    }
    @\unlink($script);

    $fatalMem = \str_contains($out, 'Allowed memory size') || \str_contains($out, 'Out of memory');
    return ['fatalMem' => $fatalMem, 'code' => (int) $code, 'out' => $out, 'ms' => (\microtime(true) - $t0) * 1000];
}

/** Codigo de worker que abre una BD temporal propia y ejecuta $cuerpo con $db a mano. */
function conBD(string $cuerpo): string
{
    return <<<PHP
        use Axi\\Core\\Db;
        use Axi\\Core\\Sweeper;
        \$raiz = sys_get_temp_dir() . '/axi_secw_' . getmypid();
        Sweeper::rmrf(\$raiz);
        \$db = new Db(\$raiz, ['durable' => false]);
        try {
            {$cuerpo}
        } catch (\\Throwable \$e) {
            echo 'EXC:' . get_class(\$e) . "\\n";
        }
        Sweeper::rmrf(\$raiz);
        PHP;
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Parser AxiSQL: recursion sin limite de profundidad');
/*
 * El parser de AxiSQL es descenso recursivo puro y NO acota la profundidad.
 * Una sentencia con muchos parentesis anidados crece la pila de PHP hasta
 * agotar la memoria del proceso. Lo grave: el tamano de sentencia que lo
 * consigue (~48 KB) esta MUY por debajo del limite de cuerpo HTTP (256 KB),
 * asi que una sola peticion legitima en tamano tumba el proceso.
 *
 * SEGURO = el parser rechaza la sentencia con una excepcion (profundidad
 * maxima). VULNERABLE = el hijo muere por memoria.
 */

// Escala de referencia medida en esta maquina, con memory_limit de 96 MB:
//   n=18000 (36 KB)  sobrevive, pico ~84 MB
//   n=24000 (48 KB)  FATAL por memoria
$parenAtaque = conBD(<<<'PHP'
    $n = 26000;
    $sql = 'SELECT * FROM p WHERE ' . str_repeat('(', $n) . 'a = 1' . str_repeat(')', $n);
    $db->insert('p', ['a' => 1], 'd1');
    $r = $db->sql($sql);
    echo 'SINLIMITE:' . (is_array($r) ? count($r) : 'x') . "\n";
PHP);
$rp = hijo($parenAtaque, 96);
printf("  parentesis anidados x26000 (~52 KB SQL, limite 96 MB): %.0f ms, fatalMem=%s, code=%d\n",
    $rp['ms'], $rp['fatalMem'] ? 'SI' : 'no', $rp['code']);
ok('SQL con parentesis anidados NO agota la memoria del proceso', !$rp['fatalMem']);

// Misma falta de limite por el lado de las expresiones-valor: (((...1...)))
$valorAtaque = conBD(<<<'PHP'
    $n = 40000;
    $sql = 'SELECT ' . str_repeat('(', $n) . '1' . str_repeat(')', $n) . ' AS x FROM p';
    $db->insert('p', ['a' => 1], 'd1');
    $r = $db->sql($sql);
    echo 'SINLIMITE\n';
PHP);
$rv = hijo($valorAtaque, 96);
printf("  parentesis-valor anidados x40000 (limite 96 MB): %.0f ms, fatalMem=%s\n",
    $rv['ms'], $rv['fatalMem'] ? 'SI' : 'no');
ok('SQL con expresion-valor anidada NO agota la memoria', !$rv['fatalMem']);

// Control: una sentencia normal se resuelve sin problema en el mismo worker.
$control = hijo(conBD("\$db->insert('p', ['a' => 1], 'd1'); \$r = \$db->sql('SELECT * FROM p'); echo 'OK:' . count(\$r) . \"\\n\";"), 96);
ok('control: una consulta normal responde sin morir', \str_contains($control['out'], 'OK:1'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Copia manipulada: bomba por tamano declarado (zip-bomb de cabecera)');
/*
 * El formato de copia declara en cada entrada cuantos bytes ocupa, y el lector
 * hace `fread($fp, bytes)` con ESE numero antes de comprobar el sha1. `fread`
 * pre-reserva el buffer del tamano pedido. Un archivo de copia de 30 bytes que
 * declare `bytes: 300000000` obliga a reservar 300 MB. Copia diminuta, memoria
 * gigante: es una bomba.
 *
 * SEGURO = la restauracion rechaza el tamano disparatado sin reservarlo (por
 * ejemplo, comparandolo con el tamano real del archivo). VULNERABLE = fatal.
 */
$bombaCabecera = <<<'PHP'
    use Axi\Core\Db;
    use Axi\Core\Sweeper;
    $raiz = sys_get_temp_dir() . '/axi_secbomba_' . getmypid();
    Sweeper::rmrf($raiz);
    @mkdir($raiz . '/datos', 0777, true);
    @mkdir($raiz . '/copias', 0777, true);
    $db = new Db($raiz . '/datos', ['durable' => false]);
    $db->insert('c', ['n' => 1], 'c1');

    $bytesReales = '{"id":"c1"}';
    $copia = $raiz . '/copias/bomba.axicopia';
    $fp = fopen($copia, 'wb');
    fwrite($fp, "AXIDB-COPIA-1\n");
    fwrite($fp, json_encode(['version'=>1,'id'=>'falsa','momento'=>date('c'),'tipo'=>'completa','base'=>null,
        'huellas'=>['c/c1.json'=>sha1($bytesReales)]]) . "\n");
    // La MENTIRA: declara 300 MB para 11 bytes reales.
    fwrite($fp, json_encode(['ruta'=>'c/c1.json','bytes'=>300000000,'sha1'=>sha1($bytesReales)]) . "\n");
    fwrite($fp, $bytesReales . "\n");
    fclose($fp);
    echo 'copia_en_disco_bytes:' . filesize($copia) . "\n";
    try {
        $db->restore($copia);
        echo "RESTAURO\n";
    } catch (\Throwable $e) {
        echo 'EXC:' . get_class($e) . "\n";
    }
    Sweeper::rmrf($raiz);
PHP;
$rb = hijo($bombaCabecera, 96);
printf("  copia de ~90 bytes declara 300 MB (limite 96 MB): %.0f ms, fatalMem=%s\n",
    $rb['ms'], $rb['fatalMem'] ? 'SI' : 'no');
ok('una copia que MIENTE su tamano no reserva memoria a ciegas', !$rb['fatalMem']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Restauracion con ruta manipulada: escritura fuera del destino');
/*
 * La restauracion escribe cada entrada en `destino . "/" . ruta` sin pasar la
 * ruta por Names ni validar traversal. Una entrada con `../../` escapa del
 * directorio de datos y escribe donde quiera el atacante. No es solo un problema
 * de recursos: es escritura arbitraria de ficheros con un archivo de copia
 * fabricado.
 *
 * SEGURO = la ruta con `..` se rechaza y NO aparece el fichero fuera del
 * destino. VULNERABLE = el fichero se escribe fuera.
 */
$sandbox = tmpdir('sec_traversal');
$destino = $sandbox . '/datos';
$carpeta = $sandbox . '/copias';
@\mkdir($destino, 0777, true);
@\mkdir($carpeta, 0777, true);

$dbT = new Db($destino, ['durable' => false]);
$dbT->insert('c', ['n' => 1], 'c1');

// El testigo se intenta plantar en el sandbox, un nivel POR ENCIMA de destino/.
$testigo = $sandbox . '/ESCAPADO_' . \bin2hex(\random_bytes(3)) . '.txt';
$rutaMaliciosa = '../' . \basename($testigo);
$contenido = 'fuera del destino';

$copiaTrav = $carpeta . '/trav.axicopia';
$fp = \fopen($copiaTrav, 'wb');
\fwrite($fp, "AXIDB-COPIA-1\n");
\fwrite($fp, (string) \json_encode(['version' => 1, 'id' => 'trav', 'momento' => \date('c'),
    'tipo' => 'completa', 'base' => null, 'huellas' => [$rutaMaliciosa => \sha1($contenido)]]) . "\n");
\fwrite($fp, (string) \json_encode(['ruta' => $rutaMaliciosa, 'bytes' => \strlen($contenido), 'sha1' => \sha1($contenido)]) . "\n");
\fwrite($fp, $contenido . "\n");
\fclose($fp);

$excTrav = false;
try {
    $dbT->restore($copiaTrav);
} catch (\Throwable $e) {
    $excTrav = true;
}
\clearstatcache();
$escapo = \is_file($testigo);
printf("  restaurando ruta '%s': escribio_fuera=%s, lanzo=%s\n",
    $rutaMaliciosa, $escapo ? 'SI' : 'no', $excTrav ? 'si' : 'no');
ok('una copia con ../ NO escribe fuera del directorio de datos', !$escapo);
@\unlink($testigo);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Subconsultas anidadas: coste desbocado');
/*
 * Cada subconsulta se resuelve reejecutando `$db->sql()` sobre el texto
 * recortado, que a su vez reejecuta la de dentro. Anidarlas hace crecer el
 * tiempo de forma cuadratica (medido: 300 niveles ~0,38 s; 500 ~1,08 s) con
 * sentencias de pocos KB. No mata el proceso tan rapido como el parser, pero
 * ata la CPU con una entrada pequena.
 *
 * Aqui NO se afirma un limite estricto (no existe): se MIDE y se deja el numero.
 * El umbral de la asercion es holgado (2 s para 400 niveles); si un dia esto se
 * dispara, salta.
 */
$raiz = tmpdir('sec_sub');
$dbS = new Db($raiz, ['durable' => false]);
$dbS->insert('p', ['a' => 1], 'd1');
$niveles = 400;
$sqlSub = 'SELECT * FROM p WHERE a IN '
    . \str_repeat('(SELECT a FROM p WHERE a IN ', $niveles) . '(SELECT a FROM p)' . \str_repeat(')', $niveles);
$t = \microtime(true);
$dbS->sql($sqlSub);
$msSub = (\microtime(true) - $t) * 1000;
printf("  %d subconsultas anidadas (%d bytes SQL): %.0f ms\n", $niveles, \strlen($sqlSub), $msSub);
ok('400 subconsultas anidadas se resuelven en menos de 2 s', $msSub < 2000);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Documento gigante: amplificacion de memoria');
/*
 * Un documento con un campo enorme se decodifica, se le aplican metadatos y se
 * vuelve a codificar: varias copias en memoria del mismo texto. Medido, un campo
 * de 50 MB deja el pico en ~106 MB. En un PHP de 128 MB, un unico documento
 * grande por la via directa (no HTTP) puede rozar el limite.
 *
 * Por HTTP esto NO llega: Request corta el cuerpo en 256 KB (se comprueba en la
 * seccion G). Aqui se mide la via directa y se afirma lo que SI se cumple: un
 * documento del tamano del limite HTTP se maneja de sobra. El numero del pico
 * con 50 MB queda impreso como aviso de la amplificacion ~2x.
 */
$medida = hijo(conBD(<<<'PHP'
    $mb = 50;
    $db->insert('g', ['t' => str_repeat('a', $mb * 1048576)], 'g1');
    echo 'pico_MB:' . round(memory_get_peak_usage(true) / 1048576) . "\n";
PHP), 256);
if (\preg_match('/pico_MB:(\d+)/', $medida['out'], $mm)) {
    printf("  documento de 50 MB por API directa: pico=%s MB (amplificacion ~%.1fx)\n",
        $mm[1], (int) $mm[1] / 50);
}
ok('un documento de 50 MB por API directa no mata un proceso de 256 MB', !$medida['fatalMem']);

// Lo que SI se garantiza: un documento del tamano del limite HTTP va sobrado.
$raizG = tmpdir('sec_gig');
$dbG = new Db($raizG, ['durable' => false]);
$antes = \memory_get_peak_usage(true);
$dbG->insert('g', ['t' => \str_repeat('a', Request::LIMITE_BYTES)], 'g1');
$doc = $dbG->get('g', 'g1');
eq('un documento del tamano del limite HTTP se guarda y se lee entero',
    Request::LIMITE_BYTES, \strlen((string) $doc['t']));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] JSON con anidamiento profundisimo');
/*
 * Al SERIALIZAR, json_encode tiene un tope de profundidad (~512) y lanza. El
 * motor lo envuelve en una excepcion propia: no es un fatal ni un desbordamiento
 * de pila del interprete. Es el comportamiento correcto, y aqui se fija para que
 * no se degrade a un fatal en el futuro.
 */
$hondo = hijo(conBD(<<<'PHP'
    $doc = ['fin' => 1];
    for ($i = 0; $i < 5000; $i++) { $doc = ['n' => $doc]; }
    try {
        $db->insert('h', $doc, 'h1');
        echo "ACEPTADO\n";
    } catch (\Axi\Core\Exception $e) {
        echo "RECHAZO_LIMPIO\n";
    }
PHP), 128);
printf("  documento con 5000 niveles de anidamiento: fatalMem=%s\n", $hondo['fatalMem'] ? 'SI' : 'no');
ok('un documento anidadisimo se rechaza con excepcion, sin fatal por memoria',
    !$hondo['fatalMem'] && \str_contains($hondo['out'], 'RECHAZO_LIMPIO'));

// Por HTTP, json_decode corta antes: profundidad > 512 no es "JSON valido".
$profundo = \str_repeat('[', 600) . '1' . \str_repeat(']', 600);
$cuerpo = '{"accion":"x","datos":' . $profundo . '}';
$rechazado = false;
try {
    Request::desde(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1'], $cuerpo);
} catch (\Axi\Core\Http\BadRequest) {
    $rechazado = true;
}
ok('por HTTP, un JSON con 600 niveles se rechaza como invalido (no se procesa)', $rechazado);

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Limites que SI existen');

// El cuerpo HTTP se corta en 256 KB, y mentir en Content-Length no cuela.
$sobrado = '{"accion":"insert","datos":{"t":"' . \str_repeat('a', Request::LIMITE_BYTES) . '"}}';
$corta413 = false;
try {
    Request::desde(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_LENGTH' => '10'], $sobrado);
} catch (\Axi\Core\Http\BadRequest $e) {
    $corta413 = $e->httpCode() === 413;
}
ok('el cuerpo HTTP por encima de 256 KB se rechaza con 413 (mide lo que llega)', $corta413);

// La dimension de un vector esta acotada (16384): no se puede pedir un ancho absurdo.
$raizV = tmpdir('sec_vecdim');
$dbV = new Db($raizV, ['durable' => false]);
throws('una dimension vectorial disparatada (100000) se rechaza',
    static fn() => $dbV->enableVectors('v', ['dims' => 100000]));

// k gigante en la busqueda vectorial queda acotado a los vivos, no reserva de mas.
$raizK = tmpdir('sec_veck');
$dbK = new Db($raizK, ['durable' => false]);
$dbK->enableVectors('vk');                     // usa las dims por defecto del embedder
$dims = $dbK->vectorIndex('vk')->manifest()->dims;
for ($i = 0; $i < 20; $i++) {
    $vec = \array_fill(0, $dims, 0.0);
    $vec[$i % $dims] = 1.0;
    $dbK->insert('vk', ['embedding' => $vec], 'k' . $i);
}
$t = \microtime(true);
$consulta = \array_fill(0, $dims, 0.0);
$consulta[0] = 1.0;
$res = $dbK->similar('vk', $consulta, 1000000000);
printf("  similar() con k=1.000.000.000 sobre 20 vectores: %.1f ms, devueltos=%d\n",
    (\microtime(true) - $t) * 1000, \count($res));
eq('k gigante se acota al numero real de vectores vivos', 20, \count($res));

// El nombre de un valor indexado larguisimo se reduce a un hash (no explota la ruta).
$raizI = tmpdir('sec_idx');
$dbI = new Db($raizI, ['durable' => false]);
$dbI->index('doc', 'campo');
$dbI->insert('doc', ['campo' => \str_repeat('Z', 100000)], 'd1');
$ficheros = \glob($raizI . '/doc/_idx/campo/*.json') ?: [];
$nombreLen = $ficheros === [] ? 0 : \strlen(\basename((string) $ficheros[0]));
printf("  valor indexado de 100 KB -> nombre de archivo de %d caracteres\n", $nombreLen);
ok('un valor indexado gigante no se convierte en un nombre de archivo gigante', $nombreLen < 100 && $ficheros !== []);

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] ReDoS en LIKE');
/*
 * LIKE compila el patron a una regex con `.*` por cada `%`. Un patron con muchos
 * `%a` intercalados frente a un sujeto largo es la receta clasica del retroceso
 * catastrofico. Se comprueba que PHP (con su pcre.backtrack_limit por defecto)
 * corta el retroceso y responde en milisegundos en vez de colgarse.
 */
$raizL = tmpdir('sec_like');
$dbL = new Db($raizL, ['durable' => false]);
$dbL->insert('re', ['n' => \str_repeat('a', 60) . 'bc'], 're1');
$patron = \str_repeat('%a', 12) . '%b';
$t = \microtime(true);
$dbL->sql("SELECT * FROM re WHERE n LIKE '{$patron}'");
$msLike = (\microtime(true) - $t) * 1000;
printf("  LIKE '%s...' sobre sujeto de 62 chars: %.1f ms\n", \substr($patron, 0, 12), $msLike);
ok('un patron LIKE catastrofico responde en menos de 200 ms', $msLike < 200);

/* ─────────────────────────────────────────────────────────────────────────── */
// Los workers que MUEREN por memoria no llegan a limpiar su carpeta: un proceso
// matado a mitad deja restos en disco. Se recogen aqui para no acumularlos entre
// ejecuciones (y de paso es una muestra del efecto: la muerte deja estado).
foreach (['axi_secw_*', 'axi_secbomba_*', 'axi_sec_worker_*'] as $patron) {
    foreach (\glob(\sys_get_temp_dir() . '/' . $patron) ?: [] as $resto) {
        Sweeper::rmrf($resto);
    }
}

summary();
