<?php
/**
 * Worker de test: confirma una transaccion y se deja matar por el camino.
 *
 * Escribe una transferencia entre dos cuentas: resta de una y suma a la otra.
 * La suma de las dos tiene que valer siempre lo mismo, haya muerto donde haya
 * muerto. Esa invariante es toda la prueba: no hace falta saber en que fase
 * cayo, basta con que el total cuadre.
 *
 *   argv: <dir> <ronda>
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

[, $dir, $ronda] = $argv;

$db = new Axi\Core\Db($dir, ['durable' => false]);

// Vuelta y vuelta hasta que lo maten. Cada pasada mueve una unidad de ida y
// otra de vuelta, asi que el total no cambia nunca.
for ($i = 0; ; $i++) {
    $desde = $i % 2 === 0 ? 'a' : 'b';
    $hasta = $i % 2 === 0 ? 'b' : 'a';

    try {
        $db->transaccion(static function ($tx) use ($desde, $hasta, $ronda, $i) {
            $origen  = $tx->get('cuentas', $desde);
            $destino = $tx->get('cuentas', $hasta);

            $tx->update('cuentas', $desde, ['saldo' => $origen['saldo'] - 1]);
            $tx->update('cuentas', $hasta, ['saldo' => $destino['saldo'] + 1]);
            $tx->insert('movimientos', [
                'de' => $desde, 'a' => $hasta, 'ronda' => (int) $ronda,
            ], "m{$ronda}-{$i}");
        });
    } catch (\Throwable) {
        // Un choque con otra transaccion no es el objeto de este test.
    }
}
