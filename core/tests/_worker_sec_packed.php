<?php
/**
 * Worker de ataque contra el driver empaquetado.
 *
 * El driver packed guarda en memoria el mapa de desplazamientos y lo carga UNA
 * sola vez por proceso. El cerrojo de escritura serializa a los procesos, pero
 * nadie vuelve a leer el mapa despues de cogerlo: cada proceso sigue creyendo
 * la foto que hizo al arrancar. Estos modos existen para provocar ese desfase
 * de forma determinista, con barreras de archivo en vez de esperas al azar.
 *
 *   merge    modifica un documento que el padre tambien va a modificar
 *   carga    carga el mapa, avisa, espera, y luego escribe N documentos
 *   inserta  escribe N documentos y se va
 *
 *   argv: <dir> <coleccion> <modo> <arg1> <arg2>
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

[, $dir, $coleccion, $modo, $arg1, $arg2] = $argv;

$db = new Axi\Core\Db($dir, ['durable' => false]);

if ($modo === 'merge') {
    // arg1 = id del documento, arg2 = clave que añade este proceso
    $db->put($coleccion, $arg1, [$arg2 => 1]);
    $db->storage()->close();
    exit(0);
}

$n       = (int) $arg1;
$prefijo = (string) $arg2;

if ($modo === 'carga') {
    $db->count($coleccion);                         // fuerza la carga del mapa
    \file_put_contents($dir . '/_sec_listo_' . $prefijo, '1');

    $hasta = \microtime(true) + 20;
    while (!\is_file($dir . '/_sec_go_' . $prefijo) && \microtime(true) < $hasta) {
        \usleep(2000);
    }
}

for ($i = 0; $i < $n; $i++) {
    $db->put($coleccion, $prefijo . $i, ['n' => $i, 'quien' => $prefijo]);
}

$db->storage()->close();
\file_put_contents($dir . '/_sec_fin_' . $prefijo, (string) $n);
exit(0);
