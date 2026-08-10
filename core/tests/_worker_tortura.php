<?php
/**
 * Worker del test de tortura: escribe sin parar hasta que lo maten.
 *
 *   php _worker_tortura.php <dataPath> <driver> <prefijo> [semilla]
 *
 * Cada documento lleva su propia firma: sha1 de la carga, guardado dentro. Un
 * documento escrito a medias, o mezclado con el anterior, no cuadra con su
 * firma. Sin ese detalle "no esta corrupto" solo querria decir "es JSON
 * valido", que es una prueba mucho mas floja: un JSON perfectamente parseable
 * puede tener dentro los bytes de otro documento.
 *
 * Dos salvaguardas obligatorias, tope de iteraciones y tope de tiempo: si el
 * padre no consigue matarlo, el worker se muere solo. Un bucle infinito que
 * sobreviva al test llena el disco.
 */

declare(strict_types=1);

/*
 * El tope de iteraciones acota el trabajo del test, no la prueba.
 *
 * En Windows una ronda da para unas 15 vueltas antes de que llegue la muerte; en
 * Linux, sin ese lastre del sistema de archivos, daba para miles: la primera
 * ejecucion en la CI creo 56.000 documentos y la revision posterior tardaba mas
 * de un minuto. Con el tope, la muerte sigue cayendo casi siempre a mitad de una
 * escritura —que es lo que se quiere probar— y el coste del test es parecido en
 * los dos sistemas.
 */
const MAX_ITER     = 400;
const MAX_SEGUNDOS = 20;

require_once \dirname(__DIR__) . '/axidb.php';

$path    = (string) ($argv[1] ?? '');
$driver  = (string) ($argv[2] ?? 'fs');
$prefijo = (string) ($argv[3] ?? 'd');
$semilla = (int) ($argv[4] ?? 0);

$db = new Axi\Core\Db($path, ['durable' => false]);
if ($driver !== 'fs' && $db->storage()->driverDe('docs') !== $driver) {
    $db->storage()->declareDriver('docs', $driver);
}

$hasta = \microtime(true) + MAX_SEGUNDOS;

for ($i = 0; $i < MAX_ITER && \microtime(true) < $hasta; $i++) {
    // Tamaños muy distintos a proposito: ensancha la ventana de escritura y
    // hace que la muerte caiga unas veces en un documento pequeño y otras en
    // uno de decenas de kilobytes.
    $largo = 1 + (($semilla + $i) * 7919) % 400;
    $carga = \str_repeat(\chr(97 + ($i % 26)), $largo * 16);

    $doc = [
        'ronda' => $semilla,
        'n'     => $i,
        'largo' => \strlen($carga),
        'firma' => \sha1($carga),
        'carga' => $carga,
    ];

    // Uno que se reescribe siempre —la peor situacion para la atomicidad— y
    // otro nuevo cada vez, que prueba el camino del alta.
    $db->put('docs', $prefijo . '_estable', $doc, true);
    $db->put('docs', $prefijo . '_' . $i, $doc, true);
}

exit(0);
