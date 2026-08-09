<?php
/**
 * AxiDB - test de durabilidad: que un corte no se lleve un documento por delante.
 *
 * El patron ingenuo trunca el archivo y luego escribe encima. Si el proceso
 * muere en medio, el documento queda vacio o a medias y al leerlo devuelve null:
 * el dato ha desaparecido, sin un solo error por ninguna parte.
 *
 * Core\Storage escribe en un temporal y hace rename(), que es atomico. Un
 * lector ve el contenido viejo o el nuevo, nunca uno a medias.
 *
 * El test mata el proceso escritor en momentos aleatorios y comprueba que no
 * queda ni un documento corrupto. El bloque de control hace lo mismo contra el
 * patron viejo para que la diferencia sea visible.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

/**
 * Lanza el worker, lo deja escribir un rato y lo mata a lo bruto.
 * Devuelve [documentos revisados, documentos corruptos, temporales huerfanos].
 */
function matarYRevisar(string $dir, string $modo, int $rondas): array
{
    $worker = __DIR__ . '/_worker_kill.php';
    for ($r = 0; $r < $rondas; $r++) {
        $h = spawn($worker, [$dir, $modo]);
        \usleep(\random_int(40000, 260000));   // entre 40 y 260 ms escribiendo
        killNow($h);
        \usleep(20000);
    }

    $revisados = 0;
    $corruptos = 0;
    $tmp       = 0;
    foreach (\glob($dir . '/docs/*') ?: [] as $f) {
        $base = \basename($f);
        if (\str_contains($base, '.tmp.')) {
            $tmp++;
            continue;
        }
        if (!\str_ends_with($base, '.json')) {
            continue;
        }
        $revisados++;
        $raw = (string) @\file_get_contents($f);
        $doc = \json_decode($raw, true);
        if (!\is_array($doc) || !isset($doc['n'])) {
            $corruptos++;
        }
    }
    return [$revisados, $corruptos, $tmp];
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] AxiDB: 12 muertes durante la escritura');

$dir = tmpdir('durabilidad');
[$rev, $malos, $tmp] = matarYRevisar($dir, 'axidb', 12);

echo "    revisados {$rev} documentos | corruptos {$malos} | temporales huerfanos {$tmp}\n";

ok('se escribieron documentos antes de cada muerte', $rev > 0);
eq('cero documentos corruptos tras 12 muertes', 0, $malos);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Todos los documentos se leen con la API');

$db = new Db($dir, ['durable' => false]);
$ilegibles = 0;
foreach ($db->ids('docs') as $id) {
    if ($db->get('docs', $id) === null) {
        $ilegibles++;
    }
}
eq('ningun documento devuelve null al leerlo', 0, $ilegibles);
ok('el documento sometido a mas escrituras sobrevivio', $db->get('docs', 'estable') !== null);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Limpieza de temporales huerfanos');

// Matar entre fopen(tmp) y rename() deja un temporal. Es inofensivo — no se
// lista ni se lee — pero el motor debe saber barrerlo.
ok('los temporales no aparecen en el listado de ids',
    \count(\array_filter($db->ids('docs'), static fn($id) => \str_contains($id, '.tmp.'))) === 0);

$barridos = $db->storage()->sweep('docs', 0);
ok("sweep() elimina los {$tmp} temporales huerfanos", $barridos === $tmp);
eq('tras el barrido no queda ningun temporal', 0,
    \count(\glob($dir . '/docs/*.tmp.*') ?: []));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Lectura simultanea a la escritura: el documento roto en vivo');

/*
 * El escenario que no necesita matar a nadie para romperse.
 *
 * El patron ingenuo —el que escribe casi todo el mundo— trunca el archivo bajo
 * LOCK_EX y luego escribe encima, mientras el lector hace file_get_contents SIN
 * pedir el cerrojo. Quien llega entre el truncado y la escritura lee un archivo
 * vacio o a medias, `json_decode` devuelve null y, para la aplicacion, el
 * documento ha dejado de existir. Sin errores, sin avisos: simplemente no esta.
 *
 * Es el fallo que aparece un dia sin explicacion y no se reproduce nunca cuando
 * lo buscas, porque solo pasa si dos procesos coinciden en unos microsegundos.
 *
 * Con tmp+rename no hay ventana: el destino siempre contiene un documento
 * completo, el viejo o el nuevo.
 */
function lecturasRotas(string $dir, string $modo, int $intentos): array
{
    $h    = spawn(__DIR__ . '/_worker_kill.php', [$dir, $modo]);
    $file = $dir . '/docs/estable.json';

    $leidas = 0;
    $rotas  = 0;
    $vacias = 0;

    /*
     * Se para por muestras, no por reloj.
     *
     * Antes eran tres segundos y ya. En esta maquina daban 106 lecturas con el
     * patron viejo, contra un minimo exigido de 100: al filo. Con la maquina
     * cargada bajaba de 100 y el test se ponia rojo diciendo "no llego a leer
     * durante la escritura", que no es un fallo del motor sino de la prueba,
     * que no habia tenido tiempo de mirar.
     *
     * Ahora sigue hasta reunir muestras suficientes. Los tres segundos quedan
     * como lo normal, y el techo duro esta para que una maquina imposible no
     * cuelgue la suite.
     */
    $suficientes = 300;
    $blando      = \microtime(true) + 3.0;
    $duro        = \microtime(true) + 25.0;

    while ($leidas < $intentos && \microtime(true) < $duro
        && ($leidas < $suficientes || \microtime(true) < $blando)) {
        if (!\is_file($file)) {
            \usleep(200);
            continue;
        }
        $raw = @\file_get_contents($file);      // igual que data_get(): sin lock
        $leidas++;
        if ($raw === '' || $raw === false) {
            $vacias++;
            $rotas++;
            continue;
        }
        if (!\is_array(\json_decode($raw, true))) {
            $rotas++;
        }
    }
    killNow($h);
    return [$leidas, $rotas, $vacias];
}

$ctrl = tmpdir('durabilidad_ctrl');
[$leidasV, $rotasV, $vaciasV] = lecturasRotas($ctrl, 'viejo', 4000);
\printf("    patron viejo (ftruncate+fwrite): %d lecturas, %d rotas (%d vacias) -> %.1f%%\n",
    $leidasV, $rotasV, $vaciasV, $leidasV > 0 ? $rotasV * 100 / $leidasV : 0);

$dir3 = tmpdir('durabilidad_lectura');
[$leidasN, $rotasN, ] = lecturasRotas($dir3, 'axidb', 4000);
\printf("    patron nuevo (tmp+rename):       %d lecturas, %d rotas -> %.1f%%\n",
    $leidasN, $rotasN, $leidasN > 0 ? $rotasN * 100 / $leidasN : 0);

ok("la prueba reunio muestras suficientes: {$leidasV} y {$leidasN} lecturas", $leidasV >= 300 && $leidasN >= 300);
ok("el patron viejo entrega documentos rotos al lector ({$rotasV} de {$leidasV})", $rotasV > 0);
eq('el patron nuevo no entrega ni una lectura rota', 0, $rotasN);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Escritura durable con fsync');

$dir2 = tmpdir('durabilidad_fsync');
$db2  = new Db($dir2, ['durable' => true]);
$t = \microtime(true);
for ($i = 0; $i < 50; $i++) {
    $db2->put('c', 'd' . $i, ['n' => $i]);
}
$ms = (\microtime(true) - $t) * 1000 / 50;
\printf("    coste medio con fsync: %.2f ms por escritura\n", $ms);

ok('fsync esta disponible en este PHP (8.1+)', \function_exists('fsync'));
eq('las 50 escrituras durables se leen correctamente', 50, \count($db2->ids('c')));

/*
 * Techo muy holgado a proposito. Aqui no se mide rendimiento —de eso se ocupa
 * test_rendimiento.php contra su linea base— sino que no haya pasado algo
 * estructuralmente catastrofico, del orden de reescribir la coleccion entera en
 * cada alta.
 *
 * El limite estaba en 150 ms y la CI lo rebaso en un runner de Windows: un
 * fsync en disco compartido y virtualizado puede tardar cientos de milisegundos
 * sin que nada este roto. Un test que se pone rojo por eso enseña a ignorar los
 * rojos, que es peor que no tenerlo.
 */
\printf("    (el techo es 800 ms por escritura; aqui: %.2f ms)\n", $ms);
ok('sin regresion estructural: por debajo de 800 ms por escritura', $ms < 800);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Un lector no puede tumbar una escritura');

/*
 * En Windows, `rename()` sobre un archivo que otro proceso tiene abierto falla,
 * aunque lo tenga abierto solo para leer y solo unos microsegundos. Sin
 * reintento, un lector insistente hace que las escrituras lancen: dos procesos
 * legitimos y ninguno haciendo nada raro.
 *
 * Salio del registro de errores de PHP, no de un test: el worker del test de
 * durabilidad moria asi de cuando en cuando, y como el test lo mataba igual,
 * el fatal pasaba desapercibido. Ahora se provoca a proposito.
 */
$dir4 = tmpdir('durabilidad_lector');
$db4  = new Db($dir4, ['durable' => false]);

$carga = \str_repeat('z', 20000);
$db4->put('docs', 'disputado', ['firma' => \sha1($carga), 'carga' => $carga], true);

$lector = spawn(__DIR__ . '/_worker_lector.php', [$dir4, 'docs', 'disputado']);
\usleep(250000);                        // que arranque y este leyendo de verdad

$fallos = 0;
$exitos = 0;
for ($i = 0; $i < 150; $i++) {
    $nueva = \str_repeat(\chr(97 + ($i % 26)), 20000);
    try {
        $db4->put('docs', 'disputado', ['firma' => \sha1($nueva), 'carga' => $nueva], true);
        $exitos++;
    } catch (\Axi\Core\Exception $e) {
        $fallos++;
    }
}
killNow($lector);

\printf("    %d escrituras con un lector encima: %d completadas, %d fallidas\n",
    $exitos + $fallos, $exitos, $fallos);

eq('ninguna escritura falla por tener un lector delante', 0, $fallos);
eq('y todas se completaron',                            150, $exitos);

$final = $db4->get('docs', 'disputado');
ok('el documento final esta entero', \sha1((string) $final['carga']) === $final['firma']);
eq('sin temporales tirados', [], \array_map('basename', \glob($dir4 . '/docs/*.tmp.*') ?: []));

rmrf($dir);
rmrf($dir3);
rmrf($dir2);
rmrf($dir4);
rmrf($ctrl);
summary();
