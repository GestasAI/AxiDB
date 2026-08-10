<?php
/**
 * Worker de ataque: lector que no para mientras otro confirma transacciones.
 *
 * Busca dos cosas distintas y hay que no confundirlas:
 *
 *   rotos    un documento que no se puede leer o que llega a medias. Eso seria
 *            corrupcion y no puede pasar nunca.
 *   partidos la suma de las dos cuentas no cuadra. Eso es falta de aislamiento:
 *            el lector ha entrado justo mientras se aplicaba la transaccion y
 *            ha visto un lado del apunte y no el otro. El motor lo declara como
 *            limite conocido; aqui se mide cuanto de real es.
 *
 *   argv: <dir> <segundos> <total>
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

[, $dir, $segundos, $total] = $argv;

$db = new Axi\Core\Db($dir, ['durable' => false]);

$leidas = $rotos = $partidos = 0;
$hasta  = \microtime(true) + (float) $segundos;

while (\microtime(true) < $hasta) {
    $a = $db->get('cuentas', 'a');
    $b = $db->get('cuentas', 'b');
    $leidas++;

    if (!\is_array($a) || !\is_array($b) || !isset($a['saldo'], $b['saldo'])) {
        $rotos++;
        continue;
    }
    if ((int) $a['saldo'] + (int) $b['saldo'] !== (int) $total) {
        $partidos++;
    }
}

\file_put_contents(
    $dir . '/_sec_lector.json',
    \json_encode(['leidas' => $leidas, 'rotos' => $rotos, 'partidos' => $partidos])
);
exit(0);
