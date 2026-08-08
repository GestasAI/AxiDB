<?php
/**
 * AxiDB - AxiSQL: seguridad del analizador.
 *
 * Un parser propio es superficie de ataque nueva. Aqui se comprueba lo unico
 * que de verdad importa: que un literal entre comillas NUNCA se convierta en
 * sintaxis, y que un nombre de coleccion no pueda salir del directorio de datos.
 *
 * Aun asi, la regla para quien integra sigue siendo la de siempre: no pegar
 * entrada de usuario dentro de una sentencia. Para eso esta find()->where(),
 * que recibe el valor como valor y no como texto que hay que analizar.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('sql_inyeccion'), ['durable' => false]);
$db->insert('p', ['nombre' => 'cafe',  'precio' => 2], 'd1');
$db->insert('p', ['nombre' => 'te',    'precio' => 3], 'd2');
$db->insert('p', ['nombre' => 'tarta', 'precio' => 5], 'd3');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Un literal es un valor, nunca sintaxis');

/*
 * Cada una de estas cadenas es un intento clasico. Todas tienen que comportarse
 * igual: buscar un producto que se llame exactamente asi, no encontrarlo, y
 * dejar los tres documentos intactos.
 */
$intentos = [
    "'; DROP COLLECTION p; --"        => 'punto y coma mas DROP',
    "' OR '1'='1"                     => 'OR siempre cierto',
    "' OR 1=1 --"                     => 'OR con comentario',
    "'; DELETE FROM p WHERE '1'='1"   => 'DELETE encadenado',
    "' UNION SELECT * FROM p --"      => 'UNION',
    "\\'; DROP COLLECTION p; --"      => 'comilla escapada con barra',
    "' ; UPDATE p SET precio = 0 --"  => 'UPDATE encadenado',
];

foreach ($intentos as $carga => $nombre) {
    // La carga se inserta como literal, doblando las comillas simples como
    // manda el estandar. Es lo que haria cualquier capa de escapado.
    $literal = "'" . \str_replace("'", "''", $carga) . "'";
    $r = $db->sql("SELECT * FROM p WHERE nombre = {$literal}");
    ok("{$nombre}: no encuentra nada y no ejecuta nada", $r === []);
}

eq('los tres documentos siguen ahi', 3, $db->count('p'));
eq('y con su precio original', 2, $db->get('p', 'd1')['precio']);
ok('la coleccion no se ha borrado', \in_array('p', $db->collections(), true));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Esas cadenas se guardan y recuperan tal cual');

foreach ($intentos as $carga => $nombre) {
    $literal = "'" . \str_replace("'", "''", $carga) . "'";
    $db->sql("INSERT INTO peligros (texto) VALUES ({$literal})");
}
eq('se guardaron todas', \count($intentos), $db->count('peligros'));

$guardados = \array_column($db->sql('SELECT * FROM peligros'), 'texto');
foreach (\array_keys($intentos) as $carga) {
    ok('vuelve del disco intacta: ' . \substr($carga, 0, 24), \in_array($carga, $guardados, true));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Una sentencia por llamada');

throws('dos sentencias separadas por punto y coma se rechazan',
    static fn() => $db->sql("SELECT * FROM p; DROP COLLECTION p"));
eq('y la coleccion sigue existiendo', 3, $db->count('p'));

throws('basura despues de la sentencia se rechaza',
    static fn() => $db->sql('SELECT * FROM p DROP'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] No se puede salir del directorio de datos');

$fuera = \dirname($db->path()) . '/FUGA.json';
@\unlink($fuera);

foreach ([
    'SELECT * FROM ../fuga'          => 'ruta relativa en FROM',
    "INSERT INTO ../fuga (a) VALUES (1)" => 'ruta relativa en INSERT',
    'SELECT * FROM /etc/passwd'      => 'ruta absoluta',
    'DROP COLLECTION ..'             => 'punto punto',
] as $sql => $motivo) {
    throws("rechaza: {$motivo}", static fn() => $db->sql($sql));
}
ok('no se escribio nada fuera del directorio', !\is_file($fuera));

// El nombre valido con caracteres raros lo para la validacion del nucleo, no el
// parser: son dos redes distintas y las dos tienen que estar.
throws('un nombre con byte nulo se rechaza', static fn() => $db->get("p\0x", 'd1'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Entradas malformadas no revientan el proceso');

foreach ([
    \str_repeat('(', 200)                 => 'parentesis anidados sin fin',
    \str_repeat('SELECT ', 100)           => 'keywords repetidas',
    "SELECT * FROM p WHERE n = '"         => 'comilla sin cerrar',
    "SELECT * FROM p WHERE n = \x00"      => 'byte nulo suelto',
    'SELECT * FROM p WHERE ' . \str_repeat('a = 1 AND ', 100) . 'a = 1' => 'cadena AND muy larga',
    '@@@'                                 => 'simbolos sin sentido',
    "\xC3\x28"                            => 'utf-8 invalido',
] as $sql => $motivo) {
    $lanzo = false;
    $murio = false;
    try {
        $db->sql($sql);
    } catch (\Axi\Core\Exception) {
        $lanzo = true;
    } catch (\Throwable) {
        $murio = true;
    }
    ok("{$motivo}: error controlado, no fatal", $lanzo || !$murio);
}

eq('tras todo el maltrato, los datos siguen intactos', 3, $db->count('p'));

rmrf($db->path());
summary();
