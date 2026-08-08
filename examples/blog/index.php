<?php
/**
 * AxiDB - ejemplo: un blog.
 *
 * Segundo dominio, sin tocar una sola linea del nucleo. Es la prueba de que
 * AxiDB no esta hecho para una aplicacion concreta.
 *
 *   php examples/blog/index.php
 */

declare(strict_types=1);

require __DIR__ . '/../../core/axidb.php';
$db = axidb(__DIR__ . '/datos');

foreach (['posts', 'comentarios'] as $c) {
    $db->dropCollection($c);
}

echo "=== Mi blog ===\n\n";

$db->index('posts', 'categoria');
$db->index('posts', 'estado');
$db->index('comentarios', 'post_id');

/* ─── Entradas ──────────────────────────────────────────────────────────── */

$posts = [
    ['titulo' => 'Como elegir un vidrio templado', 'categoria' => 'guias',   'estado' => 'publicado', 'visitas' => 1240],
    ['titulo' => 'Precios de mamparas en 2026',    'categoria' => 'precios', 'estado' => 'publicado', 'visitas' => 3180],
    ['titulo' => 'Borrador sobre aislamiento',      'categoria' => 'guias',   'estado' => 'borrador',  'visitas' => 0],
    ['titulo' => 'Reformas de bano paso a paso',    'categoria' => 'guias',   'estado' => 'publicado', 'visitas' => 890],
];
$ids = [];
foreach ($posts as $p) {
    $p['slug_url'] = \strtolower(\str_replace(' ', '-', $p['titulo']));
    $ids[] = $db->insert('posts', $p)['id'];
}

$db->insert('comentarios', ['post_id' => $ids[1], 'autor' => 'Ana',  'texto' => 'Muy util, gracias']);
$db->insert('comentarios', ['post_id' => $ids[1], 'autor' => 'Luis', 'texto' => 'Falta el IVA']);
$db->insert('comentarios', ['post_id' => $ids[0], 'autor' => 'Marta','texto' => 'Buen resumen']);

/* ─── Portada ───────────────────────────────────────────────────────────── */

echo "-- Portada: publicados, mas leidos primero --\n";
foreach ($db->find('posts')->where('estado', 'publicado')->orderBy('visitas', 'desc')->get() as $p) {
    $n = \count($db->by('comentarios', 'post_id', $p['id']));
    \printf("   %-38s %5d visitas  %d comentarios\n", $p['titulo'], $p['visitas'], $n);
}

echo "\n-- Categoria 'guias' (por indice) --\n";
foreach ($db->by('posts', 'categoria', 'guias') as $p) {
    \printf("   [%-9s] %s\n", $p['estado'], $p['titulo']);
}

echo "\n-- Busqueda por texto en el titulo --\n";
foreach ($db->find('posts')->where('titulo', 'CONTAINS', 'mampara')->get() as $p) {
    echo "   " . $p['titulo'] . "\n";
}

/* ─── Publicar un borrador ──────────────────────────────────────────────── */

$borrador = $db->find('posts')->where('estado', 'borrador')->first();
$db->update('posts', $borrador['id'], ['estado' => 'publicado']);

echo "\nBorrador publicado. Publicados: "
   . \count($db->by('posts', 'estado', 'publicado'))
   . " | Borradores: " . \count($db->by('posts', 'estado', 'borrador')) . "\n";

echo "\nMismo motor que la cristaleria. Colecciones distintas, campos distintos,\n";
echo "indices distintos. Cero cambios en axidb/core.\n";
