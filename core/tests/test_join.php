<?php
/**
 * AxiDB - JOIN y subconsultas.
 *
 * Lo que se comprueba, ademas de que salgan las filas:
 *
 *   - INNER descarta lo que no casa; LEFT lo conserva con la otra parte a nulos
 *   - un null NUNCA casa, ni siquiera con otro null
 *   - los campos de la derecha SIEMPRE llevan prefijo, asi que dos colecciones
 *     con un campo del mismo nombre no se pisan
 *   - la API y AxiSQL dan exactamente el mismo resultado
 *   - un cruce de N por M no cuesta N x M
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir = tmpdir('join');
$db  = new Db($dir, ['durable' => false]);

$db->sql("INSERT INTO clientes (id, nombre, ciudad) VALUES
    ('c1', 'Ana',  'Murcia'),
    ('c2', 'Juan', 'Lorca'),
    ('c3', 'Eva',  'Murcia')");

$db->sql("INSERT INTO pedidos (cli, nombre, total) VALUES
    ('c1', 'pedido uno',    100.0),
    ('c1', 'pedido dos',     50.0),
    ('c2', 'pedido tres',   300.0),
    ('cX', 'pedido huerfano', 7.0)");

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] INNER y LEFT');

$inner = $db->sql("SELECT total, clientes.nombre FROM pedidos
                   JOIN clientes ON pedidos.cli = clientes.id ORDER BY total");
eq('INNER deja fuera el pedido sin cliente', [50.0, 100.0, 300.0],
    \array_column($inner, 'total'));
eq('y trae el nombre del cliente', ['Ana', 'Ana', 'Juan'],
    \array_column($inner, 'clientes.nombre'));

$left = $db->sql("SELECT total, clientes.nombre FROM pedidos
                  LEFT JOIN clientes ON pedidos.cli = clientes.id ORDER BY total");
eq('LEFT conserva el huerfano', [7.0, 50.0, 100.0, 300.0], \array_column($left, 'total'));
eq('con la parte del cliente a null', [null, 'Ana', 'Ana', 'Juan'],
    \array_column($left, 'clientes.nombre'));

eq('el ON se puede escribir al reves', 3,
    \count($db->sql("SELECT total FROM pedidos JOIN clientes ON clientes.id = pedidos.cli")));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Los nombres no se pisan');

/*
 * Las dos colecciones tienen un campo `nombre`. Sin una regla clara, uno
 * taparia al otro y nadie sabria cual esta viendo. La regla es: el de la
 * izquierda tal cual, el de la derecha siempre con prefijo.
 */
$fila = $db->sql("SELECT nombre, clientes.nombre, pedidos.nombre FROM pedidos
                  JOIN clientes ON pedidos.cli = clientes.id WHERE total = 100")[0];

eq('sin prefijo es el de la izquierda',       'pedido uno', $fila['nombre']);
eq('con el prefijo de la izquierda, tambien', 'pedido uno', $fila['pedidos.nombre']);
eq('y el de la derecha necesita el suyo',            'Ana', $fila['clientes.nombre']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Alias, filtros y agregados sobre el cruce');

eq('con alias de coleccion', ['Ana'],
    \array_column($db->sql("SELECT c.nombre FROM pedidos p JOIN clientes c ON p.cli = c.id
                            WHERE total = 100"), 'c.nombre'));

eq('se puede filtrar por un campo de la unida', [50.0, 100.0],
    \array_column($db->sql("SELECT total FROM pedidos p JOIN clientes c ON p.cli = c.id
                            WHERE c.ciudad = 'Murcia' ORDER BY total"), 'total'));

$gasto = $db->sql("SELECT c.nombre, SUM(total) AS gastado FROM pedidos p
                   JOIN clientes c ON p.cli = c.id GROUP BY c.nombre ORDER BY gastado DESC");
eq('agrupar por un campo de la unida', ['Juan', 'Ana'], \array_column($gasto, 'c.nombre'));
eq('y sumar el de la de aqui',         [300.0, 150.0],  \array_column($gasto, 'gastado'));

eq('EXPLAIN avisa de que ahi no hay indices', 'cruce',
    $db->sql("EXPLAIN SELECT total FROM pedidos p JOIN clientes c ON p.cli = c.id")['estrategia']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Un null no casa con nada');

/*
 * Es lo que hace SQL y lo unico razonable: "no se sabe" no es igual a otro "no
 * se sabe". Si casaran, dos documentos incompletos apareceran emparejados y
 * nadie entenderia por que.
 */
$db->sql("INSERT INTO a (k, x) VALUES ('tiene', 1)");
$db->put('a', 'sinK', ['x' => 2], true);
$db->sql("INSERT INTO b (k, y) VALUES ('tiene', 'si')");
$db->put('b', 'sinK', ['y' => 'no'], true);

eq('los dos que tienen valor casan', 1,
    \count($db->sql("SELECT x FROM a JOIN b ON a.k = b.k")));
eq('y los dos con el campo vacio NO casan entre si', 1,
    \count($db->sql("SELECT x FROM a JOIN b ON a.k = b.k")));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Subconsultas');

eq('IN con una subconsulta', [50.0, 100.0],
    \array_column($db->sql("SELECT total FROM pedidos
                            WHERE cli IN (SELECT id FROM clientes WHERE ciudad = 'Murcia')
                            ORDER BY total"), 'total'));

eq('NOT IN con una subconsulta', [7.0],
    \array_column($db->sql("SELECT total FROM pedidos
                            WHERE cli NOT IN (SELECT id FROM clientes)
                            ORDER BY total"), 'total'));

eq('EXISTS cuando hay', 4,
    $db->sql("SELECT COUNT(*) FROM pedidos WHERE EXISTS (SELECT id FROM clientes WHERE ciudad = 'Lorca')"));
eq('y cuando no hay', 0,
    $db->sql("SELECT COUNT(*) FROM pedidos WHERE EXISTS (SELECT id FROM clientes WHERE ciudad = 'Nadie')"));

eq('una subconsulta se combina con lo demas', [100.0],
    \array_column($db->sql("SELECT total FROM pedidos
                            WHERE cli IN (SELECT id FROM clientes WHERE ciudad = 'Murcia')
                              AND total > 60 ORDER BY total"), 'total'));

// La subconsulta admite lo mismo que una consulta suelta, porque se ejecuta
// con el mismo motor: aqui, un agregado dentro.
eq('con un agregado dentro', 1,
    \count($db->sql("SELECT total FROM pedidos WHERE total IN
                     (SELECT MAX(total) AS m FROM pedidos)")));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Lo mismo desde la API');

$api = $db->find('pedidos')->join('clientes', 'cli', 'id')->orderBy('total')->get();
eq('join() da lo mismo que JOIN', [50.0, 100.0, 300.0], \array_column($api, 'total'));
eq('con los mismos nombres de campo', ['Ana', 'Ana', 'Juan'],
    \array_column($api, 'clientes.nombre'));

$apiIzq = $db->find('pedidos')->join('clientes', 'cli', 'id', izquierdo: true)->orderBy('total')->get();
eq('y el izquierdo tambien', [7.0, 50.0, 100.0, 300.0], \array_column($apiIzq, 'total'));

eq('se puede filtrar por la unida', 2,
    \count($db->find('pedidos')->join('clientes', 'cli', 'id')
        ->where('clientes.ciudad', '=', 'Murcia')->get()));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Un cruce de N por M no cuesta N x M');

/*
 * La diferencia entre un hash join y comparar todos contra todos no se ve en
 * los resultados, se ve en el reloj. Con 400 x 400, todos contra todos son
 * 160.000 comparaciones; el hash join, 800 pasos.
 */
$grande = tmpdir('join_grande');
$g = new Db($grande, ['durable' => false]);
for ($i = 0; $i < 400; $i++) {
    $g->insert('izq', ['k' => 'k' . $i, 'n' => $i], 'i' . $i);
    $g->insert('der', ['k' => 'k' . $i, 'txt' => 'fila ' . $i], 'd' . $i);
}

$t = \microtime(true);
$r = $g->sql("SELECT n, der.txt FROM izq JOIN der ON izq.k = der.k");
$ms = (\microtime(true) - $t) * 1000;

eq('cruza las cuatrocientas', 400, \count($r));
\printf("    400 x 400 en %.0f ms\n", $ms);
ok(\sprintf('y no tarda como si fueran 160.000 comparaciones: %.0f ms', $ms), $ms < 2000.0);

$g->storage()->cerrar();
rmrf($grande);

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] Lo que se rechaza, y con que mensaje');

throws('un ON que no compara con = se rechaza',
    static fn () => $db->sql("SELECT total FROM pedidos JOIN clientes ON pedidos.cli > clientes.id"));
throws('LEFT sin JOIN, tambien',
    static fn () => $db->sql("SELECT total FROM pedidos LEFT clientes"));
throws('una subconsulta que no empieza por SELECT',
    static fn () => $db->sql("SELECT total FROM pedidos WHERE cli IN (DELETE FROM clientes)"));

$db->storage()->cerrar();
rmrf($dir);
summary();
