<?php
/**
 * AxiDB - la base de datos atendiendo por HTTP, desde otro proceso.
 *
 * El objetivo declarado era que quien conecta desde fuera no tenga que escribir
 * un backend. Esto lo mide: se levanta el ejemplo tal y como dice su README, se
 * ejerce el puente entero, y se cuenta cuanto PHP tuvo que escribir el
 * desarrollador para tenerlo en pie.
 *
 * Y se comprueba lo otro, que importa igual: que los datos NO se pueden
 * descargar por HTTP saltandose el puente.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

$ejemplo = \dirname(__DIR__, 2) . '/examples/04-puente-http';

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Lo que el desarrollador tuvo que escribir');

ok('hay un endpoint',       \is_file($ejemplo . '/api.php'));
ok('un cliente de ejemplo', \is_file($ejemplo . '/cliente.php'));
ok('y su README',           \is_file($ejemplo . '/README.md'));

$php = (string) \file_get_contents($ejemplo . '/api.php');

// Lineas de codigo de verdad: sin comentarios, sin llaves sueltas, sin vacias.
$codigo = \array_values(\array_filter(
    \array_map('trim', \explode("\n", $php)),
    static fn(string $l) => $l !== ''
        && !\str_starts_with($l, '*')
        && !\str_starts_with($l, '/*')
        && !\str_starts_with($l, '//')
        && !\in_array($l, ['<?php', '*/', ']);'], true)
));
\printf("    PHP escrito por el desarrollador: %d lineas -> %s\n", \count($codigo), \implode(' | ', $codigo));

ok('el backend cabe en cinco lineas de PHP', \count($codigo) <= 5);
ok('y ninguna es un controlador, una ruta ni un modelo',
    !\preg_match('/\b(class|function|switch|Controller|Route)\b/i', $php));

/*
 * Y del lado del cliente, lo que se demuestra es lo contrario: que NO necesita
 * nada de AxiDB. Si el ejemplo hiciera `require` del nucleo estaria haciendo
 * trampa —seria la misma maquina fingiendo ser otra— y lo que enseña dejaria de
 * ser cierto.
 */
$cliente = (string) \file_get_contents($ejemplo . '/cliente.php');
ok('el cliente no carga el nucleo',   !\str_contains($cliente, 'axidb.php'));
ok('ni sabe donde estan los datos',   !\str_contains($cliente, "/datos"));
ok('solo habla por el cable',          \str_contains($cliente, 'application/json'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Arranca como dice su README');

// Empezar de cero: el ejemplo crea sus datos al vuelo.
rmrf($ejemplo . '/datos');

$puerto = (static function (): int {
    $s = @\stream_socket_server('tcp://127.0.0.1:0', $e, $m);
    if (!$s) {
        return 8760 + \random_int(0, 200);
    }
    $n = (string) \stream_socket_get_name($s, false);
    \fclose($s);
    return (int) \substr($n, \strrpos($n, ':') + 1);
})();

$base = "http://127.0.0.1:{$puerto}";
$log  = tmpdir('puente_http') . '/servidor.log';

$pipes = [];
$srv = \proc_open(
    \escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $puerto . ' servidor.php',
    [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
    $pipes,
    $ejemplo,
    null,
    ['bypass_shell' => true]
);
$pid = \is_resource($srv) ? (\proc_get_status($srv)['pid'] ?? 0) : 0;

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
ok('el servidor del ejemplo responde', $vivo);

/** @return array{0:int, 1:string} codigo HTTP y cuerpo */
function traer(string $url, ?array $json = null): array
{
    $opciones = ['http' => ['ignore_errors' => true, 'timeout' => 10]];
    if ($json !== null) {
        $opciones['http']['method']  = 'POST';
        $opciones['http']['header']  = "Content-Type: application/json\r\n";
        $opciones['http']['content'] = (string) \json_encode($json);
    }
    $cuerpo = @\file_get_contents($url, false, \stream_context_create($opciones));
    $codigo = 0;
    foreach ($http_response_header ?? [] as $cabecera) {
        if (\preg_match('#^HTTP/\S+\s+(\d+)#', $cabecera, $m)) {
            $codigo = (int) $m[1];
        }
    }
    return [$codigo, (string) $cuerpo];
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] El puente atiende todo el ciclo desde fuera');

if (!$vivo) {
    ok('sin servidor no hay nada que probar', false);
} else {
    // El cliente del ejemplo, ejecutado tal cual, contra este servidor.
    $salida = [];
    $codigo = 0;
    // putenv y no `set`/`export`: el hijo hereda el entorno del proceso, y eso
    // funciona igual en Windows que en Linux. La CI corre en los dos.
    \putenv('AXI_URL=' . $base . '/api.php');
    \exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($ejemplo . '/cliente.php') . ' 2>&1',
        $salida, $codigo);
    $texto = \implode("\n", $salida);
    ok('el cliente del ejemplo se ejecuta entero', $codigo === 0);
    ok('sin avisos de PHP', !\str_contains($texto, 'Warning:') && !\str_contains($texto, 'Fatal error'));
    ok('consulta por SQL a traves del puente', \str_contains($texto, 'Media por sensor'));
    ok('y el puente le niega borrar la coleccion', \str_contains($texto, 'rechazado'));

    [$c] = traer($base . '/axi.js');
    eq('el cliente JavaScript se sirve para quien lo quiera', 200, $c);

    [$c, $cuerpo] = traer($base . '/api.php', ['accion' => 'insert', 'coleccion' => 'clientes',
        'datos' => ['nombre' => 'Ana Ruiz', 'ciudad' => 'Murcia']]);
    eq('se da de alta un cliente', 200, $c);
    $ana = \json_decode($cuerpo, true)['dato'] ?? [];
    ok('y vuelve con su id', !empty($ana['id']));

    [$c, $cuerpo] = traer($base . '/api.php', ['accion' => 'insert', 'coleccion' => 'lecturas',
        'datos' => ['cliente_id' => $ana['id'], 'sensor' => 'nave-1', 'grados' => 21.4,
            'humedad' => 48, 'centimos' => 42120, 'estado' => 'pendiente']]);
    eq('y una lectura suya', 200, $c);
    $presu = \json_decode($cuerpo, true)['dato'] ?? [];
    eq('con el importe en centimos enteros', 42120, $presu['centimos'] ?? null);

    [$c, $cuerpo] = traer($base . '/api.php', ['accion' => 'find', 'coleccion' => 'lecturas',
        'donde' => [['estado', '=', 'pendiente']], 'orden' => ['_createdAt', 'desc']]);
    eq('se consultan las pendientes', 200, $c);
    eq('y sale el que acabamos de crear', 1, \count(\json_decode($cuerpo, true)['dato'] ?? []));

    [$c] = traer($base . '/api.php', ['accion' => 'update', 'coleccion' => 'lecturas',
        'id' => $presu['id'], 'datos' => ['estado' => 'aceptado']]);
    eq('se marca como aceptada', 200, $c);

    [, $cuerpo] = traer($base . '/api.php', ['accion' => 'count', 'coleccion' => 'lecturas',
        'donde' => [['estado', '=', 'pendiente']]]);
    eq('y ya no queda ninguno pendiente', 0, \json_decode($cuerpo, true)['dato'] ?? null);

    /* ───────────────────────────────────────────────────────────────────── */
    section('D] Los datos no se descargan por HTTP');

    $documentos = \glob($ejemplo . '/datos/clientes/*.json') ?: [];
    ok('hay documentos en el disco', $documentos !== []);

    foreach (['/datos/', '/datos/clientes/', '/datos/clientes/' . \basename($documentos[0] ?? 'x.json')] as $ruta) {
        [$c, $cuerpo] = traer($base . $ruta);
        ok("{$ruta} no se sirve (HTTP {$c})", $c === 404 || $c === 403);
        ok('  y no se filtra el contenido', !\str_contains($cuerpo, 'Ana Ruiz'));
    }

    [$c, $cuerpo] = traer($base . '/datos/.htaccess');
    ok('ni el propio .htaccess', ($c === 404 || $c === 403) && !\str_contains($cuerpo, 'denied'));

    ok('el blindaje del motor esta puesto de todos modos',
        \is_file($ejemplo . '/datos/.htaccess'));
}

if (\is_resource($srv)) {
    @\proc_terminate($srv, 9);
    @\proc_close($srv);
    if ($pid > 0) {
        forceKill($pid);
    }
}
rmrf($ejemplo . '/datos');

summary();
