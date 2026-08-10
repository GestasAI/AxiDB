<?php
/**
 * AxiDB - que navegadores pueden llamar al puente.
 *
 * El fallo que este test existe para impedir es siempre el mismo: validar el
 * origen con strpos. Con esa comprobacion, `https://miweb.com.attacker.net`
 * pasa por buena, y a partir de ahi la pagina del atacante hace peticiones con
 * las credenciales del usuario.
 */

declare(strict_types=1);

require_once __DIR__ . '/_http.php';

use Axi\Core\Http\Cors;

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Sin origenes declarados no hay CORS');

[$s, $db] = puente('http_cors_ninguno');
$db->insert('p', ['n' => 1], 'p1');

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1']);
respuesta('la peticion del propio sitio funciona', $r, 200, true);
ok('y no lleva cabecera de origen', !isset($r->cabeceras['Access-Control-Allow-Origin']));

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'], null, 'https://otra.com');
respuesta('desde otra pagina se rechaza', $r, 403, false);
ok('sin cabecera que la autorice', !isset($r->cabeceras['Access-Control-Allow-Origin']));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Con origenes declarados');

[$s, $db] = puente('http_cors', ['origenes' => ['https://miweb.com', 'http://localhost:3000']]);
$db->insert('p', ['n' => 1], 'p1');

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'], null, 'https://miweb.com');
respuesta('el origen declarado pasa', $r, 200, true);
eq('y se le responde con su propio origen',
    'https://miweb.com', $r->cabeceras['Access-Control-Allow-Origin'] ?? null);
eq('nunca con el comodin',
    false, ($r->cabeceras['Access-Control-Allow-Origin'] ?? '') === '*');
eq('y se avisa a las caches', 'Origin', $r->cabeceras['Vary'] ?? null);

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'], null, 'http://localhost:3000');
respuesta('el segundo origen tambien', $r, 200, true);
eq('con su puerto', 'http://localhost:3000', $r->cabeceras['Access-Control-Allow-Origin'] ?? null);

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'],
    null, 'https://miweb.com', 'OPTIONS');
respuesta('la consulta previa del navegador se contesta', $r, 200, true);
ok('con los metodos', \str_contains($r->cabeceras['Access-Control-Allow-Methods'] ?? '', 'POST'));
ok('y las cabeceras que va a mandar',
    \str_contains($r->cabeceras['Access-Control-Allow-Headers'] ?? '', 'Authorization'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Los origenes que se parecen NO pasan');

$enganos = [
    'https://miweb.com.attacker.net' => 'el dominio con el nuestro de prefijo',
    'https://attacker.net'           => 'otro dominio cualquiera',
    'https://notmiweb.com'           => 'el nuestro con algo delante',
    'http://miweb.com'               => 'el mismo dominio sin cifrar',
    'https://miweb.com:8443'         => 'el mismo dominio en otro puerto',
    'https://sub.miweb.com'          => 'un subdominio',
    'https://MIWEB.COM.evil.net'     => 'en mayusculas para despistar',
    'null'                           => 'el origen nulo de un iframe aislado',
];
foreach ($enganos as $origen => $porque) {
    $r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'], null, $origen);
    respuesta("se rechaza {$porque}", $r, 403, false);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Los que si son el mismo origen, pasan');

$iguales = [
    'https://miweb.com'      => 'tal cual',
    'https://MiWeb.com'      => 'con mayusculas, que en un dominio dan igual',
    'https://miweb.com:443'  => 'con el puerto por defecto escrito',
    'https://miweb.com/'     => 'con la barra final',
];
foreach ($iguales as $origen => $porque) {
    $r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'], null, $origen);
    respuesta("se acepta {$porque}", $r, 200, true);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] La normalizacion, por separado');

eq('quita el puerto por defecto de https', 'https://a.com', Cors::normalizar('https://a.com:443'));
eq('y el de http',                          'http://a.com',  Cors::normalizar('http://a.com:80'));
eq('conserva un puerto de verdad',     'http://a.com:8080',  Cors::normalizar('http://a.com:8080'));
eq('baja el host a minusculas',             'https://a.com', Cors::normalizar('https://A.COM'));
eq('descarta lo que no es un origen',                    '', Cors::normalizar('miweb.com'));
eq('descarta el vacio',                                  '', Cors::normalizar(''));
eq('descarta la cadena null',                            '', Cors::normalizar('null'));
eq('descarta un javascript:',                            '', Cors::normalizar('javascript:alert(1)'));

$cors = new Cors(['https://a.com']);
ok('un origen sin declarar no pasa',      !$cors->allows('https://b.com'));
ok('el declarado si',                      $cors->allows('https://a.com'));
ok('y un origen vacio nunca',             !$cors->allows(''));
eq('sin permiso no hay ni una cabecera', [], $cors->headersFor('https://b.com'));

summary();
