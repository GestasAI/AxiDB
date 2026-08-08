<?php
/**
 * AxiDB - test del dominio de un blog.
 *
 * Segundo dominio, elegido por no parecerse en nada al primero: aqui hay
 * relaciones entre colecciones y busqueda por texto. El motor es el mismo.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('blog'), ['durable' => false]);
$db->index('posts', 'categoria');
$db->index('posts', 'estado');
$db->index('comentarios', 'post_id');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Entradas y comentarios');

$p1 = $db->insert('posts', ['titulo' => 'Como elegir un vidrio templado', 'categoria' => 'guias',   'estado' => 'publicado', 'visitas' => 1240]);
$p2 = $db->insert('posts', ['titulo' => 'Precios de mamparas en 2026',    'categoria' => 'precios', 'estado' => 'publicado', 'visitas' => 3180]);
$p3 = $db->insert('posts', ['titulo' => 'Borrador sobre aislamiento',     'categoria' => 'guias',   'estado' => 'borrador',  'visitas' => 0]);
$p4 = $db->insert('posts', ['titulo' => 'Reformas de bano paso a paso',   'categoria' => 'guias',   'estado' => 'publicado', 'visitas' => 890]);

$db->insert('comentarios', ['post_id' => $p2['id'], 'autor' => 'Ana',   'texto' => 'Muy util']);
$db->insert('comentarios', ['post_id' => $p2['id'], 'autor' => 'Luis',  'texto' => 'Falta el IVA']);
$db->insert('comentarios', ['post_id' => $p1['id'], 'autor' => 'Marta', 'texto' => 'Buen resumen']);

eq('cuatro entradas',    4, $db->count('posts'));
eq('tres comentarios',   3, $db->count('comentarios'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Portada: publicados por visitas');

$portada = $db->find('posts')->where('estado', 'publicado')->orderBy('visitas', 'desc')->get();
eq('tres publicados, el borrador fuera', 3, \count($portada));
eq('el mas leido encabeza', 3180, $portada[0]['visitas']);
eq('y el menos leido cierra', 890, $portada[2]['visitas']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Relacion entre colecciones');

eq('la entrada mas comentada tiene dos', 2, \count($db->by('comentarios', 'post_id', $p2['id'])));
eq('otra tiene uno',                     1, \count($db->by('comentarios', 'post_id', $p1['id'])));
eq('el borrador no tiene ninguno',       0, \count($db->by('comentarios', 'post_id', $p3['id'])));

$autores = \array_column($db->by('comentarios', 'post_id', $p2['id']), 'autor');
\sort($autores);
eq('y los autores son los correctos', ['Ana', 'Luis'], $autores);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Categorias y busqueda');

eq('tres entradas en guias', 3, \count($db->by('posts', 'categoria', 'guias')));
eq('una en precios',         1, \count($db->by('posts', 'categoria', 'precios')));

eq('busqueda por texto en el titulo', 1,
    \count($db->find('posts')->where('titulo', 'CONTAINS', 'mampara')->get()));
eq('busqueda que no encuentra nada',  0,
    \count($db->find('posts')->where('titulo', 'CONTAINS', 'zzzz')->get()));
eq('LIKE con comodin', 1,
    \count($db->find('posts')->where('titulo', 'LIKE', 'Reformas%')->get()));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Publicar un borrador');

eq('hay un borrador', 1, \count($db->by('posts', 'estado', 'borrador')));
$db->update('posts', $p3['id'], ['estado' => 'publicado']);
eq('ya no queda ninguno',   0, \count($db->by('posts', 'estado', 'borrador')));
eq('y hay cuatro publicados', 4, \count($db->by('posts', 'estado', 'publicado')));
eq('sigue en su categoria',  4, \count($db->by('posts', 'categoria', 'guias')) + 1);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Borrar una entrada y sus comentarios');

foreach ($db->by('comentarios', 'post_id', $p2['id']) as $c) {
    $db->delete('comentarios', $c['id']);
}
$db->delete('posts', $p2['id']);

eq('quedan tres entradas',   3, $db->count('posts'));
eq('y un solo comentario',   1, $db->count('comentarios'));
eq('el indice de la entrada borrada esta vacio', 0, \count($db->by('comentarios', 'post_id', $p2['id'])));
eq('la categoria precios se quedo sin entradas', 0, \count($db->by('posts', 'categoria', 'precios')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Dos dominios en la misma instalacion');

$db->insert('presupuestos', ['cliente' => 'Ana', 'total' => 421.20]);
eq('un dominio ajeno convive sin interferir', 1, $db->count('presupuestos'));
eq('y el blog sigue intacto',                 3, $db->count('posts'));
$cols = $db->collections();
\sort($cols);
eq('las cuatro colecciones conviven', ['comentarios', 'posts', 'presupuestos'], $cols);

rmrf($db->path());
summary();
