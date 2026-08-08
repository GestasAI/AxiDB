<?php
/**
 * Worker del test de concurrencia: añade N ids al mismo indice.
 * Uso: php _worker_index.php <dataPath> <prefijo> <cuantos>
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

$path   = $argv[1];
$prefix = $argv[2];
$n      = (int) $argv[3];

$db = new Axi\Core\Db($path, ['durable' => false]);

for ($i = 0; $i < $n; $i++) {
    $id = "{$prefix}-{$i}";
    // put() mantiene el indice de 'grupo' automaticamente (ya declarado por el test).
    $db->put('items', $id, ['grupo' => 'g1', 'n' => $i]);
    \usleep(200);
}
