<?php
/**
 * AxiDB - pedidos: cabecera, lineas y una transaccion que no deja medias tintas.
 *
 * Un pedido sin sus lineas no es medio pedido: es un dato corrupto. Aqui se ve
 * como se escriben las dos colecciones a la vez, todo o nada, y como se cruzan
 * despues para sacar los totales.
 *
 *   php examples/03-pedidos/index.php
 */

declare(strict_types=1);

// ─── Las unicas dos lineas de instalacion ───────────────────────────────────
require __DIR__ . '/../../core/axidb.php';
$db = axidb(__DIR__ . '/datos');
// ────────────────────────────────────────────────────────────────────────────

foreach (['clientes', 'pedidos', 'lineas'] as $c) {
    $db->dropCollection($c);
}

echo "=== Pedidos ===\n\n";

$db->index('pedidos', 'cliente');
$db->index('lineas', 'pedido');

/* ─── Clientes ──────────────────────────────────────────────────────────── */

foreach ([
    ['id' => 'c1', 'nombre' => 'Talleres Ruiz',   'ciudad' => 'Murcia'],
    ['id' => 'c2', 'nombre' => 'Montajes Mendez', 'ciudad' => 'Cartagena'],
    ['id' => 'c3', 'nombre' => 'Obras Gil',       'ciudad' => 'Murcia'],
] as $c) {
    $db->insert('clientes', $c, $c['id']);
}

/* ─── Un pedido entero, o ninguno ───────────────────────────────────────── */

/**
 * Graba la cabecera y sus lineas dentro de una transaccion.
 *
 * Si algo falla por el camino —o se va la luz a mitad— no queda un pedido
 * huerfano sin lineas: al abrir la base de datos otra vez, no hay nada de eso.
 */
$grabarPedido = static function (array $cabecera, array $lineas) use ($db): string {
    return $db->transaccion(static function ($tx) use ($cabecera, $lineas): string {
        $pedido = $tx->insert('pedidos', $cabecera);
        foreach ($lineas as $l) {
            $l['pedido'] = $pedido['id'];
            $l['total']  = \round($l['cantidad'] * $l['precio'], 2);
            $tx->insert('lineas', $l);
        }
        return $pedido['id'];
    });
};

$p1 = $grabarPedido(
    ['cliente' => 'c1', 'fecha' => '2026-03-04', 'estado' => 'servido'],
    [
        ['articulo' => 'Perfil aluminio 2 m', 'cantidad' => 12, 'precio' => 14.50],
        ['articulo' => 'Escuadra refuerzo',   'cantidad' => 48, 'precio' => 0.95],
    ]
);
$p2 = $grabarPedido(
    ['cliente' => 'c2', 'fecha' => '2026-03-07', 'estado' => 'pendiente'],
    [
        ['articulo' => 'Junta goma 10 m',     'cantidad' => 4,  'precio' => 22.00],
        ['articulo' => 'Tornillo inox caja',  'cantidad' => 6,  'precio' => 8.40],
        ['articulo' => 'Silicona neutra',     'cantidad' => 10, 'precio' => 4.75],
    ]
);
$grabarPedido(
    ['cliente' => 'c1', 'fecha' => '2026-03-11', 'estado' => 'pendiente'],
    [['articulo' => 'Perfil aluminio 3 m', 'cantidad' => 8, 'precio' => 19.90]]
);

\printf("Pedidos: %d, con %d lineas en total.\n\n", $db->count('pedidos'), $db->count('lineas'));

/* ─── Todo o nada, demostrado ───────────────────────────────────────────── */

$antesPedidos = $db->count('pedidos');
$antesLineas  = $db->count('lineas');
try {
    $db->transaccion(static function ($tx): void {
        $tx->insert('pedidos', ['cliente' => 'c3', 'fecha' => '2026-03-12', 'estado' => 'pendiente']);
        throw new \RuntimeException('el almacen dice que no hay genero');
    });
} catch (\RuntimeException $e) {
    echo "Pedido abortado a mitad: {$e->getMessage()}\n";
}
\printf("Y no ha quedado rastro: %d pedidos y %d lineas, los mismos que antes de intentarlo (%d y %d).\n\n",
    $db->count('pedidos'), $db->count('lineas'), $antesPedidos, $antesLineas);

/* ─── Cruzar las tres colecciones ───────────────────────────────────────── */

echo "-- Detalle del primer pedido (JOIN de lineas con su cabecera) --\n";
foreach ($db->by('lineas', 'pedido', $p1) as $l) {
    \printf("   %-22s %3d x %6.2f = %8.2f EUR\n", $l['articulo'], $l['cantidad'], $l['precio'], $l['total']);
}

echo "\n-- Importe de cada pedido, con su cliente --\n";
foreach ($db->all('pedidos') as $pedido) {
    $lineas  = $db->by('lineas', 'pedido', $pedido['id']);
    $importe = \array_sum(\array_column($lineas, 'total'));
    $cliente = $db->get('clientes', $pedido['cliente']);
    \printf("   %s  %-18s %2d lineas %9.2f EUR  [%s]\n",
        $pedido['fecha'], $cliente['nombre'], \count($lineas), $importe, $pedido['estado']);
}

echo "\n-- Clientes con algun pedido pendiente (subconsulta) --\n";
$conPendientes = $db->sql(
    "SELECT nombre, ciudad FROM clientes
     WHERE id IN (SELECT cliente FROM pedidos WHERE estado = 'pendiente')
     ORDER BY nombre"
);
foreach ($conPendientes as $c) {
    \printf("   %-18s %s\n", $c['nombre'], $c['ciudad']);
}

echo "\n-- Todos los clientes, tengan pedidos o no (LEFT JOIN) --\n";
$todos = $db->sql(
    'SELECT clientes.nombre AS cliente, pedidos.fecha AS fecha, pedidos.estado AS estado
     FROM clientes LEFT JOIN pedidos ON clientes.id = pedidos.cliente
     ORDER BY cliente'
);
foreach ($todos as $f) {
    \printf("   %-18s %s\n", $f['cliente'], $f['fecha'] ?? 'sin pedidos');
}

/* ─── Servir el pendiente ───────────────────────────────────────────────── */

$db->update('pedidos', $p2, ['estado' => 'servido']);
$servidos = \count($db->find('pedidos')->where('estado', 'servido')->get());
\printf("\nPedido de Montajes Mendez servido. Servidos: %d de %d.\n", $servidos, $db->count('pedidos'));
