<?php
/**
 * AxiDB - ejemplo completo: gestion de una cristaleria.
 *
 * Dominio que AxiDB no conoce de nada: clientes, presupuestos y medidas.
 * Ni una linea del nucleo sabe que existe una mampara.
 *
 *   php examples/cristaleria/index.php
 */

declare(strict_types=1);

// ─── Las unicas dos lineas de instalacion ───────────────────────────────────
require __DIR__ . '/../../core/axidb.php';
$db = axidb(__DIR__ . '/datos');
// ────────────────────────────────────────────────────────────────────────────

// Empezar limpio en cada ejecucion del ejemplo.
foreach (['clientes', 'presupuestos'] as $c) {
    $db->dropCollection($c);
}

echo "=== Cristaleria Los Arcos ===\n\n";

// Un indice por cliente: buscar sus presupuestos deja de escanear la coleccion.
$db->index('presupuestos', 'cliente_id');
$db->index('presupuestos', 'estado');

/* ─── Clientes ──────────────────────────────────────────────────────────── */

$ana  = $db->insert('clientes', ['nombre' => 'Ana Ruiz',      'tel' => '600111222', 'ciudad' => 'Murcia']);
$luis = $db->insert('clientes', ['nombre' => 'Luis Mendez',   'tel' => '600333444', 'ciudad' => 'Cartagena']);
$db->insert('clientes', ['nombre' => 'Marta Gil', 'tel' => '600555666', 'ciudad' => 'Murcia']);

echo "Clientes dados de alta: " . $db->count('clientes') . "\n\n";

/* ─── Presupuestos ──────────────────────────────────────────────────────── */

$presupuestos = [
    ['cliente_id' => $ana['id'],  'tipo' => 'mampara',  'ancho' => 120, 'alto' => 195, 'precio_m2' => 180, 'estado' => 'pendiente'],
    ['cliente_id' => $ana['id'],  'tipo' => 'espejo',   'ancho' => 80,  'alto' => 100, 'precio_m2' => 95,  'estado' => 'pendiente'],
    ['cliente_id' => $luis['id'], 'tipo' => 'ventana',  'ancho' => 150, 'alto' => 120, 'precio_m2' => 140, 'estado' => 'aceptado'],
    ['cliente_id' => $luis['id'], 'tipo' => 'mampara',  'ancho' => 90,  'alto' => 195, 'precio_m2' => 180, 'estado' => 'rechazado'],
];

foreach ($presupuestos as $p) {
    $m2 = ($p['ancho'] / 100) * ($p['alto'] / 100);
    $p['m2']    = \round($m2, 2);
    $p['total'] = \round($m2 * $p['precio_m2'], 2);
    $db->insert('presupuestos', $p);
}

echo "Presupuestos emitidos: " . $db->count('presupuestos') . "\n\n";

/* ─── Consultas ─────────────────────────────────────────────────────────── */

echo "-- Presupuestos de Ana Ruiz (por indice, no escanea) --\n";
foreach ($db->by('presupuestos', 'cliente_id', $ana['id']) as $p) {
    \printf("   %-9s %5.2f m2 %10.2f EUR  [%s]\n", $p['tipo'], $p['m2'], $p['total'], $p['estado']);
}

echo "\n-- Pendientes de mas de 300 EUR, del mas caro al mas barato --\n";
$caros = $db->find('presupuestos')
            ->where('estado', 'pendiente')
            ->where('total', '>', 300)
            ->orderBy('total', 'desc')
            ->get();
foreach ($caros as $p) {
    $cliente = $db->get('clientes', $p['cliente_id']);
    \printf("   %-12s %-9s %10.2f EUR\n", $cliente['nombre'], $p['tipo'], $p['total']);
}

echo "\n-- Mamparas, con proyeccion de campos --\n";
foreach ($db->find('presupuestos')->where('tipo', 'mampara')->select(['tipo', 'total'])->get() as $p) {
    \printf("   %s: %.2f EUR\n", $p['tipo'], $p['total']);
}

/* ─── Modificar y borrar ────────────────────────────────────────────────── */

$pendiente = $db->find('presupuestos')->where('estado', 'pendiente')->first();
$db->update('presupuestos', $pendiente['id'], ['estado' => 'aceptado']);
echo "\nPresupuesto {$pendiente['id']} aceptado.\n";

echo "Ahora hay " . \count($db->by('presupuestos', 'estado', 'aceptado')) . " aceptados y "
   . \count($db->by('presupuestos', 'estado', 'pendiente')) . " pendientes.\n";

$rechazado = $db->find('presupuestos')->where('estado', 'rechazado')->first();
$db->delete('presupuestos', $rechazado['id']);
echo "Rechazado borrado. Quedan " . $db->count('presupuestos') . " presupuestos.\n";

/* ─── Totales ───────────────────────────────────────────────────────────── */

$aceptados = $db->by('presupuestos', 'estado', 'aceptado');
$facturado = \array_sum(\array_column($aceptados, 'total'));
\printf("\nFacturacion aceptada: %.2f EUR en %d trabajos.\n", $facturado, \count($aceptados));

echo "\nLos datos estan en " . $db->path() . " — un archivo JSON por documento,\n";
echo "legibles y editables a mano. Sin servidor, sin SQL, sin Composer.\n";
