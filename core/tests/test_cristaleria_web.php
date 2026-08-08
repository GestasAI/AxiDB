<?php
/**
 * AxiDB - el gate de la ola A5: la cristaleria, desde el navegador.
 *
 * El objetivo declarado era "que quien haga la web no escriba backend". Esto lo
 * mide: se levanta el ejemplo tal y como dice su README, se comprueba que la
 * aplicacion funciona entera contra el puente, y se cuenta cuanto PHP tuvo que
 * escribir el desarrollador.
 *
 * Y se comprueba lo otro, que importa igual: que los datos NO se pueden
 * descargar por HTTP saltandose el puente.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

$ejemplo = \dirname(__DIR__, 2) . '/examples/cristaleria-web';

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Lo que el desarrollador tuvo que escribir');

ok('hay una pagina',        \is_file($ejemplo . '/index.html'));
ok('hay un endpoint',       \is_file($ejemplo . '/api.php'));
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

$html = (string) \file_get_contents($ejemplo . '/index.html');
ok('la pagina usa el cliente del nucleo', \str_contains($html, "from './axi.js'"));
ok('sin empaquetador ni dependencias',   !\str_contains($html, 'node_modules')
    && !\str_contains($html, 'cdn.') && !\str_contains($html, 'unpkg'));

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
$log  = tmpdir('cristaleria_web') . '/servidor.log';

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
section('C] La aplicacion funciona entera desde el navegador');

if (!$vivo) {
    ok('sin servidor no hay nada que probar', false);
} else {
    [$c, $cuerpo] = traer($base . '/');
    eq('la pagina se sirve', 200, $c);
    ok('y trae la aplicacion', \str_contains($cuerpo, 'Cristaleria Los Arcos'));

    [$c] = traer($base . '/axi.js');
    eq('el cliente JavaScript se sirve', 200, $c);

    [$c, $cuerpo] = traer($base . '/api.php', ['accion' => 'insert', 'coleccion' => 'clientes',
        'datos' => ['nombre' => 'Ana Ruiz', 'ciudad' => 'Murcia']]);
    eq('se da de alta un cliente', 200, $c);
    $ana = \json_decode($cuerpo, true)['dato'] ?? [];
    ok('y vuelve con su id', !empty($ana['id']));

    [$c, $cuerpo] = traer($base . '/api.php', ['accion' => 'insert', 'coleccion' => 'presupuestos',
        'datos' => ['cliente_id' => $ana['id'], 'tipo' => 'mampara', 'ancho' => 120,
            'alto' => 195, 'precio_m2' => 180, 'centimos' => 42120, 'estado' => 'pendiente']]);
    eq('y un presupuesto suyo', 200, $c);
    $presu = \json_decode($cuerpo, true)['dato'] ?? [];
    eq('con el importe en centimos enteros', 42120, $presu['centimos'] ?? null);

    [$c, $cuerpo] = traer($base . '/api.php', ['accion' => 'find', 'coleccion' => 'presupuestos',
        'donde' => [['estado', '=', 'pendiente']], 'orden' => ['_createdAt', 'desc']]);
    eq('se consultan los pendientes', 200, $c);
    eq('y sale el que acabamos de crear', 1, \count(\json_decode($cuerpo, true)['dato'] ?? []));

    [$c] = traer($base . '/api.php', ['accion' => 'update', 'coleccion' => 'presupuestos',
        'id' => $presu['id'], 'datos' => ['estado' => 'aceptado']]);
    eq('se acepta el presupuesto', 200, $c);

    [, $cuerpo] = traer($base . '/api.php', ['accion' => 'count', 'coleccion' => 'presupuestos',
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
