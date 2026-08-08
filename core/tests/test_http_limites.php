<?php
/**
 * AxiDB - lo que llega mal se contesta mal, pero limpio.
 *
 * Un 500 es una respuesta inutil: quien llama no sabe si arreglar su peticion o
 * avisar a sistemas. Y ademas suele traer dentro la ruta del archivo y la linea
 * del codigo, que es exactamente el mapa que busca quien esta hurgando.
 *
 * Aqui se maltrata el puente a proposito y se exige que conteste 4xx con un
 * motivo entendible, y que no se caiga.
 */

declare(strict_types=1);

require_once __DIR__ . '/_http.php';

use Axi\Core\Http\Peticion;

[$s, $db, $dir] = puente('http_limites');
$db->insert('p', ['n' => 1], 'p1');

/** Que la respuesta no lleve rastros del disco ni del codigo. */
function sinTripas(string $etiqueta, string $json): bool
{
    $rastros = ['.php', 'Stack trace', '#0 ', '\\core\\', '/core/', 'Fatal error', $GLOBALS['dirDatos']];
    foreach ($rastros as $rastro) {
        if ($rastro !== '' && \str_contains($json, $rastro)) {
            return ok($etiqueta . " (se filtro: {$rastro})", false);
        }
    }
    return ok($etiqueta, true);
}
$GLOBALS['dirDatos'] = $dir;

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Cuerpos que no son JSON');

$basura = [
    '{roto'                        => 'JSON a medias',
    '{"accion":}'                  => 'JSON con un valor que falta',
    'no soy json'                  => 'texto suelto',
    '[1,2,3]'                      => 'una lista en vez de un objeto',
    '"solo una cadena"'            => 'una cadena suelta',
    '42'                           => 'un numero suelto',
    'null'                         => 'el literal null',
    ''                             => 'el vacio',
    '   '                          => 'solo espacios',
    "{\"accion\":\"get\",}"        => 'una coma de mas',
];
foreach ($basura as $cuerpo => $porque) {
    $r = pedir($s, (string) $cuerpo);
    respuesta("400 con {$porque}", $r, 400, false);
    sinTripas('  sin enseñar las tripas', $r->json());
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Cuerpos demasiado grandes');

$grande = (string) \json_encode(['accion' => 'insert', 'coleccion' => 'p',
    'datos' => ['texto' => \str_repeat('a', Peticion::LIMITE_BYTES)]]);

$r = pedir($s, $grande);
respuesta('413 con un cuerpo por encima del limite', $r, 413, false);
eq('y no se guardo nada', 1, $db->count('p'));

// Mentir en Content-Length no sirve: se vuelve a medir lo que llega de verdad.
$server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_LENGTH' => '10'];
$r = $s->responder($server, $grande);
respuesta('mentir en Content-Length no cuela', $r, 413, false);

$justo = (string) \json_encode(['accion' => 'insert', 'coleccion' => 'p', 'id' => 'ok1',
    'datos' => ['texto' => \str_repeat('a', 1000)]]);
respuesta('un cuerpo normal pasa', pedir($s, $justo), 200, true);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Peticiones con la forma equivocada');

$malformadas = [
    ['sin accion',                    []],
    ['accion que no existe',          ['accion' => 'inventada', 'coleccion' => 'p']],
    ['accion que no es texto',        ['accion' => ['get'], 'coleccion' => 'p']],
    ['sin coleccion',                 ['accion' => 'get']],
    ['sin id',                        ['accion' => 'get', 'coleccion' => 'p']],
    ['insert sin datos',              ['accion' => 'insert', 'coleccion' => 'p']],
    ['datos que no son objeto',       ['accion' => 'insert', 'coleccion' => 'p', 'datos' => 'x']],
    ['datos que son una lista',       ['accion' => 'insert', 'coleccion' => 'p', 'datos' => [1]]],
    ['sql sin sentencia',             ['accion' => 'sql']],
    ['sql que no se puede analizar',  ['accion' => 'sql', 'sentencia' => 'ESTO NO ES SQL']],
    ['condicion incompleta',          ['accion' => 'find', 'coleccion' => 'p', 'donde' => [['n']]]],
    ['donde que no es lista',         ['accion' => 'find', 'coleccion' => 'p', 'donde' => 'x']],
];
foreach ($malformadas as [$porque, $cuerpo]) {
    $r = pedir($s, $cuerpo);
    ok("4xx con {$porque}", $r->codigo >= 400 && $r->codigo < 500);
    sinTripas('  sin enseñar las tripas', $r->json());
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Cosas que no existen');

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'no_existe', 'id' => 'x']);
respuesta('leer una coleccion que no existe no es un error', $r, 200, true);
eq('devuelve nulo', null, dato($r));

$r = pedir($s, ['accion' => 'find', 'coleccion' => 'no_existe', 'donde' => []]);
respuesta('consultarla tampoco', $r, 200, true);
eq('devuelve lista vacia', [], dato($r));

$r = pedir($s, ['accion' => 'count', 'coleccion' => 'no_existe']);
eq('y contarla da cero', 0, dato($r));

ok('ninguna de las tres la creo',
    !\is_dir($dir . '/no_existe'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Metodos y cabeceras');

foreach (['GET', 'PUT', 'DELETE', 'PATCH', 'HEAD'] as $metodo) {
    $r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'], null, null, $metodo);
    respuesta("{$metodo} se rechaza con 405", $r, 405, false);
    eq('  y dice que admite', 'POST, OPTIONS', $r->cabeceras['Allow'] ?? null);
}

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1']);
eq('la respuesta se declara JSON en la cabecera de tipo',
    true, \str_contains($r->json(), '"ok"'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Despues de todo esto, el puente sigue vivo');

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1']);
respuesta('responde igual que al principio', $r, 200, true);
eq('con el documento intacto', 1, dato($r)['n']);
eq('y la coleccion sin basura', 2, $db->count('p'));

summary();
