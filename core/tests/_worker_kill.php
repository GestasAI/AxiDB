<?php
/**
 * Worker del test de durabilidad: escribe hasta que lo maten.
 * Uso: php _worker_kill.php <dataPath> <modo:axidb|viejo>
 *
 * Dos salvaguardas obligatorias: tope de iteraciones y tope de tiempo. Si el
 * kill del padre falla (o el test se interrumpe), el worker muere solo. Un
 * worker de bucle infinito que sobreviva al test llena el disco.
 *
 * Carga util grande a proposito: ensancha la ventana de escritura para que la
 * muerte caiga dentro de ella con probabilidad alta.
 */

declare(strict_types=1);

const MAX_ITER     = 20000;
const MAX_SEGUNDOS = 8;

$path  = $argv[1];
$modo  = $argv[2] ?? 'axidb';
$carga = \str_repeat('A', 64000);
$hasta = \microtime(true) + MAX_SEGUNDOS;

if ($modo === 'axidb') {
    require_once \dirname(__DIR__) . '/axidb.php';
    $db = new Axi\Core\Db($path, ['durable' => false]);
    for ($i = 0; $i < MAX_ITER && \microtime(true) < $hasta; $i++) {
        $db->put('docs', 'estable', ['n' => $i, 'carga' => $carga], true);
        $db->put('docs', 'd' . ($i % 20), ['n' => $i, 'carga' => $carga], true);
    }
    exit(0);
}

// Modo control: reproduce data_put() de spa/server/lib.php (ftruncate + fwrite).
@\mkdir($path . '/docs', 0777, true);
for ($i = 0; $i < MAX_ITER && \microtime(true) < $hasta; $i++) {
    foreach (['estable', 'd' . ($i % 20)] as $id) {
        $file = $path . '/docs/' . $id . '.json';
        $fp   = \fopen($file, 'c+');
        if (!$fp) {
            continue;
        }
        if (\flock($fp, LOCK_EX)) {
            \ftruncate($fp, 0);
            \rewind($fp);
            \fwrite($fp, \json_encode(['n' => $i, 'carga' => $carga]));
            \fflush($fp);
            \flock($fp, LOCK_UN);
        }
        \fclose($fp);
    }
}
exit(0);
