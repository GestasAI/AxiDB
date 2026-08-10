<?php
/**
 * Worker de ataque: reserva un valor unico y se queda vivo sin escribir el
 * documento, para que el padre lo mate justo en esa ventana.
 *
 * Es exactamente el estado en el que queda un proceso que muere entre el paso 1
 * (reservar en el indice) y el paso 2 (escribir el documento) de Uniqueness.
 * En un servidor web esa muerte no es hipotetica: max_execution_time, un OOM
 * del contenedor o un despliegue en caliente la provocan sin que nadie ataque.
 *
 *   argv: <dir> <coleccion> <campo> <valor> <id> <marca>
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

[, $dir, $coleccion, $campo, $valor, $id, $marca] = $argv;

$db = new Axi\Core\Db($dir, ['durable' => false]);
$db->indexer()->claim($coleccion, $campo, $valor, $id);

\file_put_contents($marca, '1');

// Vive hasta que lo maten. Tope de seguridad para no dejar procesos sueltos.
$hasta = \microtime(true) + 20;
while (\microtime(true) < $hasta) {
    \usleep(20000);
}
exit(0);
