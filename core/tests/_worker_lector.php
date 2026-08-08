<?php
/**
 * Worker lector: no para de leer el mismo documento hasta que lo maten.
 *
 *   php _worker_lector.php <dataPath> <coleccion> <id>
 *
 * Existe para provocar a proposito la colision que en Windows hace fallar un
 * `rename()`: mientras este proceso tiene el archivo abierto, aunque sea por
 * microsegundos, el escritor no puede renombrar sobre el.
 *
 * Imprime al final cuantas lecturas hizo y cuantas trajeron algo roto.
 */

declare(strict_types=1);

const MAX_SEGUNDOS = 20;

require_once \dirname(__DIR__) . '/axidb.php';

$path = (string) ($argv[1] ?? '');
$col  = (string) ($argv[2] ?? 'docs');
$id   = (string) ($argv[3] ?? 'x');

$db = new Axi\Core\Db($path, ['durable' => false]);

$leidas = 0;
$rotas  = 0;
$hasta  = \microtime(true) + MAX_SEGUNDOS;

while (\microtime(true) < $hasta) {
    $doc = $db->get($col, $id);
    $leidas++;
    if ($doc !== null && (!isset($doc['firma'], $doc['carga']) || \sha1($doc['carga']) !== $doc['firma'])) {
        $rotas++;
    }
}

echo "{$leidas} {$rotas}\n";
exit(0);
