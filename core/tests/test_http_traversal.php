<?php
/**
 * AxiDB - nada sale del directorio de datos, tampoco por HTTP.
 *
 * El puente convierte texto que llega de internet en nombres de archivo. Si esa
 * traduccion falla una sola vez, una peticion cualquiera lee `/etc/passwd` o el
 * archivo de configuracion con las claves.
 *
 * La validacion vive en Names y ya tiene su test. Lo que se comprueba aqui es
 * que el puente la aplica siempre, sobre coleccion e id, y que contesta 400 con
 * el motivo en vez de reventar con un 500 o, peor, devolver el archivo.
 */

declare(strict_types=1);

require_once __DIR__ . '/_http.php';

[$s, $db, $dir] = puente('http_traversal');
$db->insert('p', ['n' => 1], 'p1');

// Un archivo real fuera del directorio de datos: si alguna ruta se escapa, se
// nota porque el contenido acabaria en la respuesta.
$fuera = \dirname($dir) . '/secreto_' . \bin2hex(\random_bytes(3)) . '.json';
\file_put_contents($fuera, '{"clave":"NO_DEBE_SALIR"}');
$nombreFuera = \basename($fuera, '.json');

$maliciosos = [
    '../otro'                      => 'subir un nivel',
    '../../etc/passwd'             => 'subir dos y buscar un archivo del sistema',
    '..\\..\\windows\\win.ini'     => 'lo mismo con barras de Windows',
    '/etc/passwd'                  => 'una ruta absoluta',
    'C:/Windows/win.ini'           => 'una ruta absoluta de Windows',
    'p/../../fuera'                => 'colar el .. en medio',
    "p\0.json"                     => 'un byte nulo para cortar la cadena',
    '....//p'                      => 'doblar los puntos por si se filtra una vez',
    '%2e%2e%2fp'                   => 'los puntos codificados en URL',
    '.hidden'                      => 'empezar por punto',
    '_interno'                     => 'empezar por guion bajo, que es del sistema',
    ''                             => 'el vacio',
    '../' . $nombreFuera           => 'apuntar al archivo real que hay fuera',
];

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] En el nombre de la coleccion');

foreach ($maliciosos as $valor => $porque) {
    $r = pedir($s, ['accion' => 'get', 'coleccion' => (string) $valor, 'id' => 'p1']);
    respuesta("400 al intentar {$porque}", $r, 400, false);
    ok('  y no se filtra nada del archivo', !\str_contains($r->json(), 'NO_DEBE_SALIR'));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] En el id del documento');

foreach ($maliciosos as $valor => $porque) {
    $r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => (string) $valor]);
    respuesta("400 al intentar {$porque}", $r, 400, false);
    ok('  y no se filtra nada del archivo', !\str_contains($r->json(), 'NO_DEBE_SALIR'));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Tampoco escribiendo');

$r = pedir($s, ['accion' => 'insert', 'coleccion' => '../fuera', 'datos' => ['x' => 1]]);
respuesta('no se crea una coleccion fuera', $r, 400, false);
ok('y el directorio de al lado sigue limpio',
    !\is_dir(\dirname($dir) . '/fuera'));

$r = pedir($s, ['accion' => 'insert', 'coleccion' => 'p', 'id' => '../colado', 'datos' => ['x' => 1]]);
respuesta('ni un documento fuera de su coleccion', $r, 400, false);
ok('y no aparecio el archivo', !\is_file($dir . '/colado.json'));

$r = pedir($s, ['accion' => 'delete', 'coleccion' => 'p', 'id' => '../../importante']);
respuesta('ni se borra nada de fuera', $r, 400, false);
ok('el archivo de fuera sigue intacto', \is_file($fuera));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Tampoco por AxiSQL');

foreach (['SELECT * FROM ../otros', 'DROP COLLECTION ../p', 'INSERT INTO ../fuera (x) VALUES (1)'] as $sql) {
    $r = pedir($s, ['accion' => 'sql', 'sentencia' => $sql]);
    ok('rechazada: ' . \substr($sql, 0, 40), $r->codigo >= 400 && ($r->cuerpo['ok'] ?? null) === false);
}
ok('y sigue sin filtrarse el archivo de fuera', \is_file($fuera));

/*
 * Un valor con pinta de ruta NO es una ruta, y rechazarlo seria un error: hay
 * datos legitimos que contienen barras y puntos. Lo que tiene que pasar es que
 * se trate como texto. El indice lo guarda como 'h_<sha1>.json' —forValue()—,
 * asi que jamas se construye un archivo con lo que escribio el usuario.
 */
$r = pedir($s, ['accion' => 'sql', 'sentencia' => "SELECT * FROM p WHERE id = '../../etc/passwd'"]);
respuesta('un valor con pinta de ruta se consulta como texto', $r, 200, true);
eq('y no encuentra nada, claro', [], dato($r));

$db->index('p', 'ruta');
$r = pedir($s, ['accion' => 'insert', 'coleccion' => 'p', 'id' => 'r1',
    'datos' => ['ruta' => '../../etc/passwd']]);
respuesta('guardar ese texto en un campo indexado se permite', $r, 200, true);
eq('se guarda tal cual', '../../etc/passwd', $db->get('p', 'r1')['ruta']);

// Se mira el disco a pelo, que es de lo que va este test. Se descarta la
// anotacion del nombre del campo: no es un valor indexado, es la etiqueta que
// permite saber que campo guarda este directorio.
$entradas = \array_values(\array_filter(
    \array_map('basename', \glob($dir . '/p/_idx/ruta/*') ?: []),
    static fn(string $n) => $n !== '_campo.json'
));
eq('y el indice crea un solo archivo de valor', 1, \count($entradas));
ok('con el valor reducido a hash, no a ruta',
    \str_starts_with($entradas[0] ?? '', 'h_') && !\str_contains($entradas[0] ?? '', '..'));
ok('el archivo de fuera sigue sin tocarse', \is_file($fuera));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Los nombres legitimos no se rompen');

foreach (['pedidos', 'Pedidos', 'pedidos_2026', 'a-b.c', 'x'] as $bueno) {
    $r = pedir($s, ['accion' => 'insert', 'coleccion' => $bueno, 'id' => 'x1', 'datos' => ['n' => 1]]);
    respuesta("'{$bueno}' se acepta", $r, 200, true);
}

\unlink($fuera);
summary();
