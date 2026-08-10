<?php
/**
 * Worker de ataque: incrementa un contador dentro de transacciones.
 *
 * Dos procesos haciendo esto a la vez son el caso de libro de la actualizacion
 * perdida: los dos leen n, los dos escriben n+1, y falta un incremento sin que
 * nadie se entere. El motor promete abortar en vez de perderlo, comparando
 * versiones bajo el cerrojo de confirmacion. Esto lo pone a prueba de verdad.
 *
 *   argv: <dir> <prefijo> <vueltas> <arranque>
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

[, $dir, $prefijo, $vueltas, $arranque] = $argv;

$db = new Axi\Core\Db($dir, ['durable' => false]);

while (\microtime(true) < (float) $arranque) {
    \usleep(200);
}

$ok = 0;
$abortadas = 0;

for ($i = 0; $i < (int) $vueltas; $i++) {
    try {
        $db->transaction(static function ($tx): void {
            $doc = $tx->get('contadores', 'c');
            $tx->update('contadores', 'c', ['n' => (int) $doc['n'] + 1]);
        });
        $ok++;
    } catch (\Throwable) {
        $abortadas++;                     // choque de versiones: es lo correcto
    }
}

\file_put_contents(
    $dir . '/_sec_tx_' . $prefijo . '.json',
    \json_encode(['ok' => $ok, 'abortadas' => $abortadas])
);
exit(0);
