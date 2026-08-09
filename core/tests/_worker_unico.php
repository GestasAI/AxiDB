<?php
/**
 * Worker de test: intenta insertar el mismo valor unico que los demas.
 *
 * Todos esperan al mismo instante antes de escribir. Sin esa espera cada
 * proceso arranca cuando le toca —el interprete tarda unos 100 ms— y las
 * escrituras se reparten en el tiempo en vez de chocar, que es justo lo que hay
 * que provocar.
 *
 *   argv: <dir> <coleccion> <campo> <valor> <id> <arrancar_en>
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

[, $dir, $coleccion, $campo, $valor, $id, $cuando] = $argv;

$db = new Axi\Core\Db($dir, ['durable' => false]);

while (\microtime(true) < (float) $cuando) {
    \usleep(200);
}

try {
    $db->insert($coleccion, [$campo => $valor], $id);
    echo "ok\n";
} catch (\Throwable $e) {
    echo "rechazado\n";
}
