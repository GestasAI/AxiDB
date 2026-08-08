<?php
/**
 * AxiDB - quien puede hacer que sobre el puente.
 *
 * El test que importa de toda la ola A5. Exponer la base de datos por HTTP es
 * lo que convierte un fallo de permisos en una fuga de datos, asi que aqui se
 * prueba cada negativa por separado, y tambien que los permisos legitimos no
 * estorban.
 */

declare(strict_types=1);

require_once __DIR__ . '/_http.php';

const TOKEN_PEDIDOS = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
const TOKEN_LECTURA = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
const FUERA         = '203.0.113.7';

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Sin configurar: local si, fuera no');

[$s, $db] = puente('http_seg_local');
$db->insert('cosas', ['n' => 1], 'c1');

respuesta('desde localhost se atiende',
    pedir($s, ['accion' => 'get', 'coleccion' => 'cosas', 'id' => 'c1']), 200, true);

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'cosas', 'id' => 'c1'], null, null, 'POST', FUERA);
respuesta('desde fuera se niega', $r, 401, false);
ok('y el motivo explica que hacer', \str_contains((string) $r->cuerpo['error'], 'tokens'));

$r = pedir($s, ['accion' => 'insert', 'coleccion' => 'cosas', 'datos' => ['n' => 2]], null, null, 'POST', FUERA);
respuesta('tampoco se escribe desde fuera', $r, 401, false);
eq('y no se escribio nada', 1, $db->count('cosas'));

// Quien conecta puede decir lo que quiera en las cabeceras; la unica direccion
// que cuenta es la que ve el servidor.
$server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => FUERA,
    'HTTP_X_FORWARDED_FOR' => '127.0.0.1', 'HTTP_CLIENT_IP' => '127.0.0.1'];
$r = $s->responder($server, (string) \json_encode(['accion' => 'get', 'coleccion' => 'cosas', 'id' => 'c1']));
respuesta('fingir ser localhost con X-Forwarded-For no cuela', $r, 401, false);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Con tokens: hasta en local hace falta llave');

[$s, $db] = puente('http_seg_tokens', [
    'tokens' => [
        TOKEN_PEDIDOS => ['colecciones' => ['pedidos'], 'escribir' => true],
        TOKEN_LECTURA => ['colecciones' => '*',         'escribir' => false],
    ],
]);
$db->insert('pedidos', ['total' => 10], 'p1');
$db->insert('usuarios', ['correo' => 'ana@ejemplo.es'], 'u1');

respuesta('sin token no se lee, ni en localhost',
    pedir($s, ['accion' => 'get', 'coleccion' => 'pedidos', 'id' => 'p1']), 401, false);

respuesta('con un token inventado tampoco',
    pedir($s, ['accion' => 'get', 'coleccion' => 'pedidos', 'id' => 'p1'], 'noexiste'), 401, false);

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'pedidos', 'id' => 'p1'], TOKEN_PEDIDOS);
respuesta('con su token, si', $r, 200, true);
eq('y trae el documento', 10, dato($r)['total']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] El ambito del token se respeta');

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'usuarios', 'id' => 'u1'], TOKEN_PEDIDOS);
respuesta('un token de pedidos no abre usuarios', $r, 403, false);
ok('y lo dice sin rodeos', \str_contains((string) $r->cuerpo['error'], 'usuarios'));

respuesta('ni escribiendo',
    pedir($s, ['accion' => 'insert', 'coleccion' => 'usuarios', 'datos' => ['correo' => 'x']], TOKEN_PEDIDOS),
    403, false);
eq('nada se colo', 1, $db->count('usuarios'));

respuesta('el token global si lee usuarios',
    pedir($s, ['accion' => 'get', 'coleccion' => 'usuarios', 'id' => 'u1'], TOKEN_LECTURA), 200, true);

respuesta('pero no escribe',
    pedir($s, ['accion' => 'update', 'coleccion' => 'usuarios', 'id' => 'u1', 'datos' => ['correo' => 'z']],
        TOKEN_LECTURA), 403, false);
eq('y el documento no cambio', 'ana@ejemplo.es', $db->get('usuarios', 'u1')['correo']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] SQL no es la puerta de atras');

respuesta('un token de solo lectura no borra por SQL',
    pedir($s, ['accion' => 'sql', 'sentencia' => 'DELETE FROM usuarios'], TOKEN_LECTURA), 403, false);
eq('el usuario sigue ahi', 1, $db->count('usuarios'));

respuesta('ni crea colecciones',
    pedir($s, ['accion' => 'sql', 'sentencia' => 'CREATE COLLECTION secretos'], TOKEN_LECTURA), 403, false);

respuesta('ni el de pedidos toca usuarios por SQL',
    pedir($s, ['accion' => 'sql', 'sentencia' => "UPDATE usuarios SET correo = 'x'"], TOKEN_PEDIDOS), 403, false);
eq('sigue sin cambiar', 'ana@ejemplo.es', $db->get('usuarios', 'u1')['correo']);

respuesta('leer lo suyo por SQL si',
    pedir($s, ['accion' => 'sql', 'sentencia' => 'SELECT total FROM pedidos'], TOKEN_PEDIDOS), 200, true);

respuesta('y escribir lo suyo tambien',
    pedir($s, ['accion' => 'sql', 'sentencia' => "INSERT INTO pedidos (total) VALUES (99)"], TOKEN_PEDIDOS),
    200, true);
eq('el pedido entro', 2, $db->count('pedidos'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Colecciones publicas: se leen, no se escriben');

[$s, $db] = puente('http_seg_publicas', [
    'tokens'   => [TOKEN_PEDIDOS => ['colecciones' => '*', 'escribir' => true]],
    'publicas' => ['catalogo'],
]);
$db->insert('catalogo', ['nombre' => 'Vaso'], 'v1');
$db->insert('privada', ['secreto' => 1], 's1');

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'catalogo', 'id' => 'v1'], null, null, 'POST', FUERA);
respuesta('cualquiera lee la publica, desde donde sea', $r, 200, true);
eq('y le llega el dato', 'Vaso', dato($r)['nombre']);

respuesta('tambien puede consultarla',
    pedir($s, ['accion' => 'find', 'coleccion' => 'catalogo', 'donde' => []], null, null, 'POST', FUERA),
    200, true);

respuesta('pero no escribir en ella',
    pedir($s, ['accion' => 'insert', 'coleccion' => 'catalogo', 'datos' => ['nombre' => 'Falso']],
        null, null, 'POST', FUERA), 401, false);
eq('el catalogo no crecio', 1, $db->count('catalogo'));

respuesta('y la privada sigue cerrada',
    pedir($s, ['accion' => 'get', 'coleccion' => 'privada', 'id' => 's1'], null, null, 'POST', FUERA),
    401, false);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Modo solo lectura');

[$s, $db] = puente('http_seg_ro', [
    'tokens'      => [TOKEN_PEDIDOS => ['colecciones' => '*', 'escribir' => true]],
    'soloLectura' => true,
]);
$db->insert('cosas', ['n' => 1], 'c1');

respuesta('se lee con token que podria escribir',
    pedir($s, ['accion' => 'get', 'coleccion' => 'cosas', 'id' => 'c1'], TOKEN_PEDIDOS), 200, true);

$r = pedir($s, ['accion' => 'update', 'coleccion' => 'cosas', 'id' => 'c1', 'datos' => ['n' => 2]], TOKEN_PEDIDOS);
respuesta('pero el modo manda por encima del token', $r, 403, false);
eq('nada cambio', 1, $db->get('cosas', 'c1')['n']);

respuesta('y por SQL tampoco',
    pedir($s, ['accion' => 'sql', 'sentencia' => "UPDATE cosas SET n = 3"], TOKEN_PEDIDOS), 403, false);

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] El token no se acepta por la URL');

[$s] = puente('http_seg_url', ['tokens' => [TOKEN_PEDIDOS => ['colecciones' => '*', 'escribir' => true]]]);

$server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => FUERA,
    'QUERY_STRING' => 'token=' . TOKEN_PEDIDOS];
$_GET['token'] = TOKEN_PEDIDOS;
$r = $s->responder($server, (string) \json_encode(['accion' => 'get', 'coleccion' => 'p', 'id' => 'x']));
respuesta('un token en la URL no vale', $r, 401, false);
unset($_GET['token']);

$server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => FUERA,
    'HTTP_AUTHORIZATION' => 'Basic ' . \base64_encode('u:' . TOKEN_PEDIDOS)];
$r = $s->responder($server, (string) \json_encode(['accion' => 'get', 'coleccion' => 'p', 'id' => 'x']));
respuesta('ni en un Basic', $r, 401, false);

summary();
