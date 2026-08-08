<?php
/**
 * AxiDB - las siete acciones del puente, por HTTP.
 *
 * Lo que hay que demostrar: que desde el otro lado del cable se puede hacer lo
 * mismo que en PHP, y que lo que vuelve es el mismo documento, no una version
 * aplanada por el camino.
 */

declare(strict_types=1);

require_once __DIR__ . '/_http.php';

[$s, $db] = puente('http_crud');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Alta');

$r = pedir($s, ['accion' => 'insert', 'coleccion' => 'p', 'id' => 'v1',
    'datos' => ['nombre' => 'Vaso', 'centimos' => 350, 'activo' => true, 'etiquetas' => ['a', 'b']]]);

respuesta('insert responde 200', $r, 200, true);
eq('devuelve el documento con su id', 'v1', dato($r)['id'] ?? null);
eq('y con la version puesta',            1, dato($r)['_version'] ?? null);
eq('el motor lo tiene de verdad',   'Vaso', $db->get('p', 'v1')['nombre'] ?? null);

$r = pedir($s, ['accion' => 'insert', 'coleccion' => 'p', 'datos' => ['nombre' => 'Sin id']]);
respuesta('sin id se genera uno', $r, 200, true);
ok('y es un id utilizable', \strlen((string) (dato($r)['id'] ?? '')) > 10);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Lectura');

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'v1']);
respuesta('get responde 200', $r, 200, true);
eq('el texto vuelve igual',    'Vaso', dato($r)['nombre']);
eq('el entero sigue siendo entero', 350, dato($r)['centimos']);
ok('y del tipo correcto',    \is_int(dato($r)['centimos']));
eq('el booleano',                true, dato($r)['activo']);
eq('la lista',           ['a', 'b'], dato($r)['etiquetas']);

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'no_existe']);
respuesta('un id que no existe no es un error', $r, 200, true);
eq('devuelve nulo', null, dato($r));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Modificacion y borrado');

$r = pedir($s, ['accion' => 'update', 'coleccion' => 'p', 'id' => 'v1', 'datos' => ['centimos' => 400]]);
respuesta('update responde 200', $r, 200, true);
eq('el campo cambia',          400, dato($r)['centimos']);
eq('los demas siguen ahi',  'Vaso', dato($r)['nombre']);
eq('y sube la version',            2, dato($r)['_version']);

$r = pedir($s, ['accion' => 'update', 'coleccion' => 'p', 'id' => 'v1',
    'datos' => ['nombre' => 'Vaso alto'], 'reemplazar' => true]);
respuesta('update con reemplazar responde 200', $r, 200, true);
ok('y ahora si se van los demas campos', !isset(dato($r)['centimos']));

$r = pedir($s, ['accion' => 'delete', 'coleccion' => 'p', 'id' => 'v1']);
respuesta('delete responde 200', $r, 200, true);
eq('confirma que borro',  true, dato($r));
eq('y ya no esta',        null, $db->get('p', 'v1'));

$r = pedir($s, ['accion' => 'delete', 'coleccion' => 'p', 'id' => 'v1']);
eq('borrar dos veces devuelve false, no error', false, dato($r));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Consultas');

for ($i = 1; $i <= 12; $i++) {
    $db->insert('q', ['n' => $i, 'grupo' => 'g' . ($i % 3), 'nombre' => 'Pieza ' . $i], 'q' . $i);
}

$r = pedir($s, ['accion' => 'find', 'coleccion' => 'q', 'donde' => [['grupo', '=', 'g1']]]);
respuesta('find responde 200', $r, 200, true);
eq('filtra',  4, \count(dato($r)));

$r = pedir($s, ['accion' => 'find', 'coleccion' => 'q',
    'donde' => [['n', '>', 6]], 'orden' => ['n', 'desc'], 'limite' => 2]);
eq('ordena y limita',        2, \count(dato($r)));
eq('el primero es el mayor', 12, dato($r)[0]['n']);

$r = pedir($s, ['accion' => 'find', 'coleccion' => 'q',
    'donde' => [], 'orden' => ['n', 'asc'], 'limite' => 3, 'salto' => 2]);
eq('salta',          3, dato($r)[0]['n']);

$r = pedir($s, ['accion' => 'find', 'coleccion' => 'q',
    'donde' => [['n', '=', 5]], 'campos' => ['nombre']]);
eq('proyecta un solo campo', ['nombre' => 'Pieza 5'], dato($r)[0]);

$r = pedir($s, ['accion' => 'count', 'coleccion' => 'q', 'donde' => [['grupo', '=', 'g0']]]);
respuesta('count responde 200', $r, 200, true);
eq('y cuenta',  4, dato($r));

$r = pedir($s, ['accion' => 'find', 'coleccion' => 'q', 'donde' => [['n', 'IN', [1, 2, 3]]]]);
eq('IN con lista funciona por HTTP', 3, \count(dato($r)));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] AxiSQL');

$r = pedir($s, ['accion' => 'sql', 'sentencia' => "SELECT nombre FROM q WHERE n = 7"]);
respuesta('select responde 200', $r, 200, true);
eq('y trae la fila', 'Pieza 7', dato($r)[0]['nombre']);

$r = pedir($s, ['accion' => 'sql', 'sentencia' => "SELECT COUNT(*) FROM q"]);
eq('count por sql', 12, dato($r));

$r = pedir($s, ['accion' => 'sql', 'sentencia' => "INSERT INTO q (nombre, n) VALUES ('Trece', 13)"]);
respuesta('insert por sql responde 200', $r, 200, true);
eq('y la coleccion crece', 13, $db->count('q'));

$r = pedir($s, ['accion' => 'sql', 'sentencia' => "EXPLAIN SELECT * FROM q WHERE grupo = 'g1'"]);
respuesta('explain responde 200', $r, 200, true);
ok('y devuelve un plan', \is_array(dato($r)) && dato($r) !== []);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] La respuesta tiene siempre la misma forma');

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'q', 'id' => 'q1']);
ok('exito: ok verdadero y dato',  ($r->cuerpo['ok'] ?? null) === true && \array_key_exists('dato', $r->cuerpo));
ok('exito: sin campo error',      !isset($r->cuerpo['error']));

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'q']);
ok('error: ok falso y error',     ($r->cuerpo['ok'] ?? null) === false && isset($r->cuerpo['error']));
ok('error: sin campo dato',       !\array_key_exists('dato', $r->cuerpo));
respuesta('falta el id: 400',     $r, 400, false);

$r = pedir($s, ['accion' => 'get', 'coleccion' => 'q', 'id' => 'q1'], null, null, 'OPTIONS');
respuesta('OPTIONS se contesta sin trabajo', $r, 200, true);

summary();
