<?php
/**
 * AxiDB - almacen: articulos, existencias y movimientos.
 *
 * Lo mas parecido a lo primero que hace cualquiera con una base de datos:
 * meter filas, indexarlas, consultarlas y sacar totales.
 *
 *   php examples/01-almacen/index.php
 */

declare(strict_types=1);

// ─── Las unicas dos lineas de instalacion ───────────────────────────────────
require __DIR__ . '/../../core/axidb.php';
$db = axidb(__DIR__ . '/datos');
// ────────────────────────────────────────────────────────────────────────────

foreach (['articulos', 'movimientos'] as $c) {
    $db->dropCollection($c);
}

echo "=== Almacen ===\n\n";

/*
 * Dos indices y una regla. El indice hace que buscar por familia no recorra la
 * coleccion entera; el UNIQUE impide que entren dos articulos con la misma
 * referencia, que es el error que nadie ve hasta que descuadra el inventario.
 */
$db->index('articulos', 'familia');
$db->index('movimientos', 'sku');
$db->unique('articulos', 'sku');

/* ─── Articulos ─────────────────────────────────────────────────────────── */

$articulos = [
    ['sku' => 'TOR-M6',  'nombre' => 'Tornillo M6',        'familia' => 'tornilleria', 'precio' => 0.12, 'minimo' => 500],
    ['sku' => 'TOR-M8',  'nombre' => 'Tornillo M8',        'familia' => 'tornilleria', 'precio' => 0.18, 'minimo' => 500],
    ['sku' => 'TUE-M6',  'nombre' => 'Tuerca M6',          'familia' => 'tornilleria', 'precio' => 0.07, 'minimo' => 800],
    ['sku' => 'BRO-6',   'nombre' => 'Broca 6 mm',         'familia' => 'herramienta', 'precio' => 3.40, 'minimo' => 20],
    ['sku' => 'BRO-8',   'nombre' => 'Broca 8 mm',         'familia' => 'herramienta', 'precio' => 3.95, 'minimo' => 20],
    ['sku' => 'GUA-L',   'nombre' => 'Guantes talla L',    'familia' => 'proteccion',  'precio' => 2.75, 'minimo' => 50],
];
foreach ($articulos as $a) {
    $db->insert('articulos', $a, $a['sku']);
}
echo 'Articulos en catalogo: ' . $db->count('articulos') . "\n";

// La regla se cumple: repetir una referencia no entra.
try {
    $db->insert('articulos', ['sku' => 'TOR-M6', 'nombre' => 'Tornillo M6 (duplicado)']);
    echo "ERROR: ha entrado un duplicado.\n";
} catch (\Axi\Core\Exception $e) {
    echo "Referencia repetida rechazada: {$e->getMessage()}\n";
}

/* ─── Movimientos ───────────────────────────────────────────────────────── */

$movimientos = [
    ['sku' => 'TOR-M6', 'tipo' => 'entrada', 'cantidad' => 2000],
    ['sku' => 'TOR-M6', 'tipo' => 'salida',  'cantidad' => 1750],
    ['sku' => 'TOR-M8', 'tipo' => 'entrada', 'cantidad' => 1200],
    ['sku' => 'TUE-M6', 'tipo' => 'entrada', 'cantidad' => 3000],
    ['sku' => 'TUE-M6', 'tipo' => 'salida',  'cantidad' => 2400],
    ['sku' => 'BRO-6',  'tipo' => 'entrada', 'cantidad' => 60],
    ['sku' => 'BRO-6',  'tipo' => 'salida',  'cantidad' => 48],
    ['sku' => 'BRO-8',  'tipo' => 'entrada', 'cantidad' => 40],
    ['sku' => 'GUA-L',  'tipo' => 'entrada', 'cantidad' => 200],
    ['sku' => 'GUA-L',  'tipo' => 'salida',  'cantidad' => 165],
];
foreach ($movimientos as $m) {
    $db->insert('movimientos', $m);
}
echo 'Movimientos registrados: ' . $db->count('movimientos') . "\n\n";

/* ─── Existencias ───────────────────────────────────────────────────────── */

echo "-- Existencias por articulo --\n";
foreach ($db->all('articulos') as $art) {
    $suyos = $db->by('movimientos', 'sku', $art['sku']);       // por indice
    $stock = 0;
    foreach ($suyos as $m) {
        $stock += $m['tipo'] === 'entrada' ? $m['cantidad'] : -$m['cantidad'];
    }
    $db->update('articulos', $art['id'], ['stock' => $stock]);
    \printf("   %-8s %-18s %6d uds\n", $art['sku'], $art['nombre'], $stock);
}

echo "\n-- Por debajo del minimo: hay que reponer --\n";
foreach ($db->all('articulos') as $art) {
    if ($art['stock'] < $art['minimo']) {
        \printf("   %-8s %6d uds (minimo %d)\n", $art['sku'], $art['stock'], $art['minimo']);
    }
}

/* ─── Consultas y totales ───────────────────────────────────────────────── */

echo "\n-- Tornilleria, de mas caro a mas barato --\n";
foreach ($db->find('articulos')->where('familia', 'tornilleria')->orderBy('precio', 'desc')->get() as $a) {
    \printf("   %-18s %5.2f EUR\n", $a['nombre'], $a['precio']);
}

echo "\n-- Valor del inventario por familia (AxiSQL) --\n";
$filas = $db->sql(
    'SELECT familia, COUNT(*) AS referencias, ROUND(SUM(stock * precio), 2) AS valor
     FROM articulos GROUP BY familia ORDER BY valor DESC'
);
foreach ($filas as $f) {
    \printf("   %-12s %2d referencias %9.2f EUR\n", $f['familia'], $f['referencias'], $f['valor']);
}

$total = $db->sql('SELECT ROUND(SUM(stock * precio), 2) AS t FROM articulos')[0]['t'];
\printf("\nValor total del almacen: %.2f EUR\n", $total);

echo "\nLos datos estan en " . $db->path() . ": un archivo JSON por documento,\n";
echo "legibles y editables a mano. Sin servidor, sin instalar nada.\n";
