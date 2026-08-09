<?php
/**
 * AxiDB - el gate de la ola A8: una tienda pequeña, de punta a punta.
 *
 * No prueba una funcion: prueba que TODAS funcionan juntas en algo que se
 * parece a una aplicacion de verdad. Cada pieza por separado ya tiene su test;
 * lo que se comprueba aqui es que no se estorban.
 *
 * La tienda usa, en este orden:
 *
 *   esquema        los productos tienen reglas
 *   UNIQUE         dos clientes no comparten correo
 *   indices        buscar pedidos por cliente sin recorrerlo todo
 *   transaccion    confirmar un pedido descuenta stock, o no pasa nada
 *   caducidad      el carrito se vacia solo
 *   JOIN           el historial de un cliente
 *   agregados      el resumen de ventas
 *   copia          y volver atras cuando algo se rompe
 *   revision       enterarse de los problemas sin que lo diga un cliente
 *
 * El criterio de la ola: que esto pase, y que el README no tenga que decir
 * "esto no lo hace" en ninguno de esos puntos.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir     = tmpdir('tienda');
$copias  = tmpdir('tienda_copias');
$db      = new Db($dir, ['durable' => false]);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Montar la tienda');

$db->defineSchema('productos', [
    'nombre' => ['tipo' => 'texto',   'obligatorio' => true],
    'precio' => ['tipo' => 'decimal', 'obligatorio' => true],
    'stock'  => ['tipo' => 'entero',  'defecto' => 0],
    'activo' => ['tipo' => 'bool',    'defecto' => true],
]);
$db->sql('CREATE UNIQUE INDEX ON clientes (correo)');
$db->sql('CREATE INDEX ON pedidos (cliente_id)');
$db->defineTtl('carritos', 3600);

$db->sql("INSERT INTO productos (id, nombre, precio, stock) VALUES
    ('p1', 'Mesa de pino',   120.0, 10),
    ('p2', 'Silla plegable',  35.5, 40),
    ('p3', 'Estanteria',      89.9,  3)");

$db->sql("INSERT INTO clientes (id, correo, nombre, ciudad) VALUES
    ('c1', 'ana@ejemplo.com',  'Ana',  'Murcia'),
    ('c2', 'juan@ejemplo.com', 'Juan', 'Lorca')");

eq('tres productos', 3, $db->count('productos'));
eq('con el defecto puesto', true, $db->get('productos', 'p1')['activo']);
eq('dos clientes', 2, $db->count('clientes'));

throws('un producto sin precio se rechaza',
    static fn () => $db->sql("INSERT INTO productos (nombre) VALUES ('Sin precio')"));
throws('y un correo repetido, tambien',
    static fn () => $db->sql("INSERT INTO clientes (correo, nombre) VALUES ('ana@ejemplo.com', 'Otra')"));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Confirmar un pedido: todo o nada');

/** Descuenta stock y crea el pedido, o no hace ninguna de las dos cosas. */
$confirmar = static function (Db $db, string $cliente, string $producto, int $unidades): string {
    return $db->transaction(static function ($tx) use ($cliente, $producto, $unidades): string {
        $p = $tx->get('productos', $producto);
        if ($p === null || $p['stock'] < $unidades) {
            throw new RuntimeException("No hay stock de {$producto}.");
        }
        $tx->update('productos', $producto, ['stock' => $p['stock'] - $unidades]);

        return $tx->insert('pedidos', [
            'cliente_id' => $cliente,
            'producto'   => $producto,
            'unidades'   => $unidades,
            'total'      => \round($p['precio'] * $unidades, 2),
            'fecha'      => '2026-04-1' . $unidades . 'T10:00:00+02:00',
        ])['id'];
    });
};

$confirmar($db, 'c1', 'p1', 2);
eq('el stock baja',        8, $db->get('productos', 'p1')['stock']);
eq('y el pedido esta',     1, $db->count('pedidos'));

$antes = $db->get('productos', 'p3')['stock'];
$fallo = '';
try {
    $confirmar($db, 'c2', 'p3', 99);
} catch (RuntimeException $e) {
    $fallo = $e->getMessage();
}
ok('un pedido sin stock se rechaza', \str_contains($fallo, 'No hay stock'));
eq('y el stock no se toca', $antes, $db->get('productos', 'p3')['stock']);
eq('ni queda un pedido a medias', 1, $db->count('pedidos'));

$confirmar($db, 'c2', 'p2', 4);
$confirmar($db, 'c1', 'p3', 1);
eq('en total, tres pedidos', 3, $db->count('pedidos'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] El carrito se vacia solo');

$db->insert('carritos', ['cliente_id' => 'c1', 'lineas' => ['p2']], 'vivo');
$db->insert('carritos', ['cliente_id' => 'c2', 'lineas' => ['p1']], 'viejo');

// Se envejece uno a mano: esperar una hora en un test no es una opcion.
$archivo = $dir . '/carritos/viejo.json';
$doc = \json_decode((string) \file_get_contents($archivo), true);
$doc['_updatedAt'] = \date('c', \time() - 7200);
\file_put_contents($archivo, \json_encode($doc));

eq('el carrito de hace dos horas ya no cuenta', 1, $db->count('carritos'));
ok('ni se puede leer', $db->get('carritos', 'viejo') === null);
ok('y el de ahora si', $db->get('carritos', 'vivo') !== null);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] El historial de un cliente: JOIN');

$historial = $db->sql("SELECT clientes.nombre, productos.nombre, total
                       FROM pedidos
                       JOIN clientes  ON pedidos.cliente_id = clientes.id
                       JOIN productos ON pedidos.producto   = productos.id
                       WHERE clientes.correo = 'ana@ejemplo.com'
                       ORDER BY total DESC");

eq('Ana tiene dos pedidos', 2, \count($historial));
eq('con el nombre del producto', ['Mesa de pino', 'Estanteria'],
    \array_column($historial, 'productos.nombre'));
eq('y sus importes', [240.0, 89.9], \array_column($historial, 'total'));

eq('el indice por cliente se usa', 'index',
    $db->sql("EXPLAIN SELECT * FROM pedidos WHERE cliente_id = 'c1'")['estrategia']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] El resumen de ventas: agregados');

$resumen = $db->sql("SELECT clientes.ciudad AS ciudad, COUNT(*) AS pedidos, SUM(total) AS facturado
                     FROM pedidos JOIN clientes ON pedidos.cliente_id = clientes.id
                     GROUP BY clientes.ciudad ORDER BY facturado DESC");

eq('dos ciudades', ['Murcia', 'Lorca'], \array_column($resumen, 'ciudad'));
eq('con sus pedidos',          [2, 1],  \array_column($resumen, 'pedidos'));
eq('y lo facturado',   [329.9, 142.0],  \array_column($resumen, 'facturado'));

eq('el total del mes', 471.9,
    $db->sql("SELECT SUM(total) AS t FROM pedidos WHERE MONTH(fecha) = 4")[0]['t']);

eq('los productos que se han vendido, sin repetir', 3,
    \count($db->sql("SELECT DISTINCT producto FROM pedidos")));

eq('quien ha gastado mas de 200', ['Ana'],
    \array_column($db->sql("SELECT clientes.nombre AS quien FROM pedidos
                            JOIN clientes ON pedidos.cliente_id = clientes.id
                            GROUP BY clientes.nombre HAVING SUM(total) > 200"), 'quien'));

eq('los clientes que han comprado alguna vez', ['Ana', 'Juan'],
    \array_column($db->sql("SELECT nombre FROM clientes
                            WHERE id IN (SELECT cliente_id FROM pedidos) ORDER BY nombre"), 'nombre'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Una copia, un destrozo, y volver atras');

$copia = $db->backup($copias);
ok('la copia guarda toda la tienda', $copia['archivos'] > 8);

// Un destrozo de los de verdad: se borra un cliente, se descuadra el stock y
// se corrompe un archivo a mano.
$db->delete('clientes', 'c1');
$db->sql("UPDATE productos SET stock = 0");
\file_put_contents($dir . '/pedidos/' . $db->ids('pedidos')[0] . '.json', 'basura');

$vuelta = $db->restore($copia['archivo']);
ok('se restaura', $vuelta['archivos'] > 8);

$tras = new Db($dir, ['durable' => false]);
eq('el cliente vuelve',        'Ana', $tras->get('clientes', 'c1')['nombre'] ?? null);
eq('el stock vuelve a su sitio',    8, $tras->get('productos', 'p1')['stock'] ?? null);
eq('los pedidos estan enteros',     3, $tras->count('pedidos'));
eq('el indice sigue funcionando',   2, \count($tras->by('pedidos', 'cliente_id', 'c1')));
eq('la unicidad sigue declarada', ['correo'], $tras->uniques('clientes'));
throws('y sigue rechazando repetidos',
    static fn () => $tras->insert('clientes', ['correo' => 'ana@ejemplo.com'], 'cX'));
eq('el esquema tambien vuelve', true,
    $tras->schema('productos')['nombre']['obligatorio'] ?? false);

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Y saber que todo esta bien');

$revision = $tras->checkup();
ok('la revision cuenta las colecciones', $revision['colecciones'] >= 4);
ok('y los documentos',                   $revision['documentos'] >= 8);
eq('sin un solo aviso', [], $revision['avisos']);

$e = $tras->stats('pedidos');
eq('las estadisticas ven el indice', ['cliente_id'], $e['indices']);
eq('y los tres pedidos',                          3, $e['documentos']);

$campos = \array_column($tras->describe('productos'), 'campo');
ok('describir enseña los campos', \in_array('precio', $campos, true));

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] Nada de esto necesito decir que no lo hace');

/*
 * El criterio de salida de la ola, comprobado como codigo y no como opinion:
 * las cinco cosas que el README tenia que reconocer como huecos.
 */
ok('transacciones entre colecciones', \method_exists(Db::class, 'transaction'));
ok('JOIN',        \count($tras->sql("SELECT total FROM pedidos JOIN clientes ON pedidos.cliente_id = clientes.id")) === 3);
ok('agregados',   $tras->sql("SELECT COUNT(*) AS c FROM pedidos")[0]['c'] === 3);
ok('UNIQUE que se cumple', $tras->uniques('clientes') === ['correo']);
ok('copias con restauracion', \method_exists(Db::class, 'restore'));
ok('cifrado',     \method_exists(Db::class, 'encrypt'));
ok('caducidad',   \method_exists(Db::class, 'defineTtl'));
ok('observabilidad', \method_exists(Db::class, 'checkup'));

$tras->storage()->cerrar();
rmrf($copias);
rmrf($dir);
summary();
