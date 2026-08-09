<?php
/**
 * AxiDB - el cliente de navegador contra un servidor de verdad.
 *
 * Los demas tests del puente llaman a Server::responder() en proceso, que es
 * mas rapido y mas fiable. Este no puede: lo que demuestra es justamente lo que
 * pasa por el cable —cabeceras, codigos, JSON de ida y de vuelta— entre el
 * fetch del cliente y el endpoint.
 *
 * Levanta el servidor de desarrollo de PHP en un puerto libre, lanza el guion
 * de Node y lee sus lineas. Si no hay Node, se dice y no se falla: quien no
 * tiene Node instalado no puede probar un cliente de navegador, y eso no
 * significa que el nucleo este roto.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

/** Puerto libre de verdad: se pide al sistema y se suelta justo antes de usarlo. */
function puertoLibre(): int
{
    $sock = @\stream_socket_server('tcp://127.0.0.1:0', $err, $msg);
    if (!$sock) {
        return 8730 + \random_int(0, 200);
    }
    $nombre = \stream_socket_get_name($sock, false);
    \fclose($sock);
    return (int) \substr((string) $nombre, \strrpos((string) $nombre, ':') + 1);
}

/**
 * Node visible DESDE ESTE PROCESO. No es lo mismo que estar instalado.
 *
 * Lo distinguio un evaluador externo: en un shell donde node existia pero no
 * estaba en el PATH que heredaba PHP, el test decia "Node no esta instalado" y
 * se saltaba la comprobacion. Un mensaje que apunta al sitio equivocado hace
 * perder mas tiempo que no decir nada.
 */
function hayNode(): bool
{
    $salida = [];
    $codigo = 1;
    @\exec('node --version 2>&1', $salida, $codigo);
    return $codigo === 0;
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Preparar el sitio: un endpoint y nada mas');

$sitio = tmpdir('js_sitio');
$nucleo = \str_replace('\\', '/', \dirname(__DIR__));

// Exactamente lo que escribiria el desarrollador: cuatro lineas.
\file_put_contents($sitio . '/api.php', <<<PHP
    <?php
    require '{$nucleo}/axidb.php';
    axidb_http(__DIR__ . '/datos', ['origenes' => ['http://127.0.0.1']]);
    PHP);

ok('el endpoint del desarrollador cabe en cuatro lineas',
    \count(\file($sitio . '/api.php')) <= 4);

$puerto = puertoLibre();
$url    = "http://127.0.0.1:{$puerto}/api.php";

/*
 * La salida del servidor va a un ARCHIVO, no a una tuberia.
 *
 * Con tuberias el test se quedaba colgado: el servidor de desarrollo escribe una
 * linea de registro por peticion, en Windows nadie vacia ese buffer mientras el
 * test espera, y al llenarse el servidor se bloquea a mitad de la tanda. Se ve
 * como un cuelgue misterioso a la peticion numero veintitantos. Es la tercera
 * vez que este mismo detalle nos cuesta una tarde.
 */
$registro = $sitio . '/servidor.log';
$cmd = \escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $puerto . ' -t ' . \escapeshellarg($sitio);
$pipes = [];
$srv = \proc_open(
    $cmd,
    [1 => ['file', $registro, 'w'], 2 => ['file', $registro, 'a']],
    $pipes,
    $sitio,
    null,
    ['bypass_shell' => true]
);
$pid = \is_resource($srv) ? (\proc_get_status($srv)['pid'] ?? 0) : 0;
ok('el servidor de pruebas arranca', \is_resource($srv));

// Esperar a que responda de verdad, no a un numero de segundos inventado.
$vivo  = false;
$hasta = \microtime(true) + 10.0;
while (\microtime(true) < $hasta) {
    $c = @\stream_socket_client("tcp://127.0.0.1:{$puerto}", $e, $m, 0.2);
    if ($c) {
        \fclose($c);
        $vivo = true;
        break;
    }
    \usleep(100000);
}
ok('y acepta conexiones', $vivo);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] El cliente JavaScript, ejecutado en Node');

if (!$vivo) {
    ok('sin servidor no se puede probar el cliente', false);
} elseif (!hayNode()) {
    echo "  (Este proceso no ve 'node' en su PATH, asi que el cliente JavaScript no se\n";
    echo "   prueba. Ojo: puede estar instalado y no estar en el PATH de ESTE shell;\n";
    echo "   comprueba con 'node --version' desde el mismo sitio donde lanzas el test.)\n";
    ok('el modulo del cliente existe', \is_file(\dirname(__DIR__) . '/axi.js'));
} else {
    $guion  = \escapeshellarg(__DIR__ . '/_cliente.mjs');
    $salida = [];
    $codigo = 1;
    @\exec('node ' . $guion . ' ' . \escapeshellarg($url) . ' 2>&1', $salida, $codigo);

    $lineas = 0;
    foreach ($salida as $linea) {
        if (\str_starts_with($linea, 'ok ') || \str_starts_with($linea, 'ko ')) {
            ok(\substr($linea, 3), \str_starts_with($linea, 'ok '));
            $lineas++;
        }
    }
    $completo = $lineas >= 20;
    ok('el cliente ejecuto todas sus comprobaciones', $completo);
    ok('y termino sin fallos', $codigo === 0);

    // Si algo se torcio hay que poder verlo aqui mismo. La primera version de
    // este test solo enseñaba la salida de node cuando no habia NINGUNA linea,
    // asi que un corte a mitad se quedaba sin explicacion.
    if ($codigo !== 0 || !$completo) {
        echo "  --- salida de node ---\n  " . \implode("\n  ", \array_slice($salida, -15)) . "\n";
        if (\is_file($registro)) {
            echo "  --- ultimas lineas del servidor ---\n  "
                . \implode("\n  ", \array_slice(\file($registro, FILE_IGNORE_NEW_LINES) ?: [], -8)) . "\n";
        }
    }
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Los datos quedaron en el disco del sitio');

ok('se creo el directorio de datos', \is_dir($sitio . '/datos'));
ok('con la coleccion dentro',        \is_dir($sitio . '/datos/piezas'));
ok('y el blindaje puesto',           \is_file($sitio . '/datos/.htaccess'));

if (\is_resource($srv)) {
    @\proc_terminate($srv, 9);
    foreach ($pipes as $p) {
        @\fclose($p);
    }
    @\proc_close($srv);
    if ($pid > 0) {
        forceKill($pid);
    }
}

summary();
