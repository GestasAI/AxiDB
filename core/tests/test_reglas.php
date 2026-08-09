<?php
/**
 * AxiDB - esquema opcional y caducidad.
 *
 * Las dos son declaraciones que la coleccion hace sobre si misma, y las dos
 * tienen la misma trampa posible: decir que se cumplen y no cumplirlas. Aqui se
 * comprueba por los dos lados, el que se ve y el que no.
 *
 * Para la caducidad, lo que de verdad importa es que un documento vencido no se
 * vea por NINGUNA puerta —get, all, count, exists, find— y no solo por la que
 * uno se acuerde de mirar. Una caducidad que se salta por find() no es una
 * caducidad.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Sin esquema, todo pasa');

$dir = tmpdir('reglas');
$db  = new Db($dir, ['durable' => false]);

$db->insert('libre', ['loquesea' => ['a', 1, null]], 'x');
eq('una coleccion sin esquema admite cualquier cosa', ['a', 1, null], $db->get('libre', 'x')['loquesea']);
eq('y no declara ninguna regla', [], $db->schema('libre'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Obligatorios, tipos y valores por defecto');

$db->defineSchema('clientes', [
    'correo' => ['tipo' => 'texto', 'obligatorio' => true],
    'edad'   => ['tipo' => 'entero'],
    'saldo'  => ['tipo' => 'decimal'],
    'activo' => ['tipo' => 'bool', 'defecto' => true],
    'etiquetas' => ['tipo' => 'lista'],
]);

$c1 = $db->insert('clientes', ['correo' => 'ana@ejemplo.com'], 'c1');
eq('el valor por defecto se pone solo', true, $c1['activo']);
eq('y queda guardado, no solo devuelto', true, $db->get('clientes', 'c1')['activo']);

$c2 = $db->insert('clientes', ['correo' => 'b@ejemplo.com', 'noDeclarado' => [1, 2]], 'c2');
eq('un campo no declarado se guarda igual: el esquema no cierra la coleccion',
    [1, 2], $c2['noDeclarado']);

throws('falta un obligatorio y se rechaza',
    static fn () => $db->insert('clientes', ['edad' => 30], 'c3'));
ok('y no se guardo nada', !$db->exists('clientes', 'c3'));

throws('un entero que llega como texto se rechaza',
    static fn () => $db->insert('clientes', ['correo' => 'x@y.es', 'edad' => '30'], 'c4'));
throws('un bool que llega como entero, tambien',
    static fn () => $db->insert('clientes', ['correo' => 'x@y.es', 'activo' => 1], 'c5'));
throws('una lista que llega como mapa, tambien',
    static fn () => $db->insert('clientes', ['correo' => 'x@y.es', 'etiquetas' => ['a' => 1]], 'c6'));

// Un entero vale como decimal: 3 es un decimal perfectamente valido. Al reves no.
$db->insert('clientes', ['correo' => 'd@ejemplo.com', 'saldo' => 3], 'c7');
eq('un entero se acepta donde se pide decimal', 3, $db->get('clientes', 'c7')['saldo']);
throws('pero un decimal no cuela donde se pide entero',
    static fn () => $db->insert('clientes', ['correo' => 'e@ejemplo.com', 'edad' => 3.5], 'c8'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] La actualizacion parcial se valida entera');

/*
 * Aqui esta el fallo facil: validar solo lo que cambia. Quitar un campo
 * obligatorio en un update parcial no se ve mirando el cambio, solo mirando
 * como queda el documento.
 */
$db->update('clientes', 'c1', ['edad' => 41]);
eq('cambiar un campo no toca los demas', 'ana@ejemplo.com', $db->get('clientes', 'c1')['correo']);
eq('y el cambiado cambia', 41, $db->get('clientes', 'c1')['edad']);

throws('vaciar el obligatorio en un update parcial se rechaza',
    static fn () => $db->update('clientes', 'c1', ['correo' => '']));
eq('y el documento se queda entero', 'ana@ejemplo.com', $db->get('clientes', 'c1')['correo']);

throws('reemplazar el documento sin el obligatorio, tambien',
    static fn () => $db->update('clientes', 'c1', ['edad' => 20], true));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] El esquema se valida al declararlo, no al usarlo');

throws('un tipo que no existe se rechaza al declarar',
    static fn () => $db->defineSchema('x', ['a' => ['tipo' => 'entergo']]));
throws('un valor por defecto del tipo equivocado, tambien',
    static fn () => $db->defineSchema('x', ['a' => ['tipo' => 'entero', 'defecto' => 'hola']]));
eq('y la coleccion no queda con un esquema a medias', [], $db->schema('x'));

eq('las reglas se pueden consultar', ['tipo' => 'entero'], $db->schema('clientes')['edad'] ?? null);

$db->storage()->cerrar();
$otro = new Db($dir, ['durable' => false]);
eq('y sobreviven a cerrar y reabrir', true, $otro->schema('clientes')['correo']['obligatorio'] ?? false);
throws('la coleccion sigue rechazando lo que no cumple',
    static fn () => $otro->insert('clientes', ['edad' => 1], 'z'));
$otro->storage()->cerrar();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Caducidad: un vencido no se ve por ninguna puerta');

$dir = tmpdir('caducidad');
$db  = new Db($dir, ['durable' => false]);

$db->defineTtl('sesiones', 60);
$db->insert('sesiones', ['usuario' => 'viva'], 'viva');

// Se fabrica una vencida escribiendole una fecha vieja por debajo, en vez de
// esperar sesenta segundos: el test tiene que durar milisegundos.
$db->defineTtl('sesiones', 0);
$db->insert('sesiones', ['usuario' => 'vieja'], 'vieja');
$archivo = $dir . '/sesiones/vieja.json';
$doc = \json_decode((string) \file_get_contents($archivo), true);
$doc['_updatedAt'] = \date('c', \time() - 3600);
\file_put_contents($archivo, \json_encode($doc));
$db->defineTtl('sesiones', 60);

ok('get() no la devuelve',        $db->get('sesiones', 'vieja') === null);
ok('exists() dice que no esta',   !$db->exists('sesiones', 'vieja'));
eq('count() no la cuenta',        1, $db->count('sesiones'));
eq('all() no la incluye',         1, \count($db->all('sesiones')));
eq('find() tampoco',              1, \count($db->find('sesiones')->get()));
eq('ni buscando por su valor',    [], $db->find('sesiones')->where('usuario', '=', 'vieja')->get());
eq('ids() tampoco',               ['viva'], $db->ids('sesiones'));

ok('y la viva se ve por todas', $db->get('sesiones', 'viva') !== null);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Vencer no es lo mismo que borrar');

ok('el archivo sigue en el disco hasta que se barre', \is_file($archivo));

$db->storage()->sweep('sesiones', 0);
ok('el barrido si lo borra', !\is_file($archivo));
ok('y no se lleva por delante la viva', \is_file($dir . '/sesiones/viva.json'));
eq('el recuento no cambia por barrer', 1, $db->count('sesiones'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Escribir da cuerda');

$db->update('sesiones', 'viva', ['usuario' => 'viva otra vez']);
ok('tras modificarla sigue viva', $db->get('sesiones', 'viva') !== null);

$db->defineTtl('sesiones', 0);
eq('con caducidad cero no vence nada', 1, $db->count('sesiones'));

$db->insert('sinreglas', ['x' => 1], 'a');
eq('y una coleccion que no la declara tampoco', 0, $db->ttl('sinreglas'));
ok('sus documentos no vencen', $db->get('sinreglas', 'a') !== null);

throws('una caducidad negativa se rechaza',
    static fn () => $db->defineTtl('sesiones', -5));

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] Las dos cosas juntas, y con los dos drivers');

foreach (['fs', 'packed'] as $driver) {
    $d = tmpdir('reglas_' . $driver);
    $x = new Db($d, ['durable' => false]);
    if ($driver !== 'fs') {
        $x->storage()->declararDriver('t', $driver);
    }
    $x->defineSchema('t', ['nombre' => ['tipo' => 'texto', 'obligatorio' => true]]);
    $x->defineTtl('t', 60);

    $x->insert('t', ['nombre' => 'uno'], 'a');
    throws("{$driver}: el esquema se cumple", static fn () => $x->insert('t', [], 'b'));
    eq("{$driver}: y el vivo se ve", 1, $x->count('t'));

    $x->storage()->cerrar();
    rmrf($d);
}

$db->storage()->cerrar();
rmrf($dir);
summary();
