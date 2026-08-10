<?php
/**
 * AxiDB - los ejemplos se ejecutan de verdad, y sus numeros cuadran.
 *
 * Este test existe por un fallo concreto: se entregaban siete ejemplos y cuatro
 * no arrancaban. Eran restos del motor anterior, pedian un archivo que ya no
 * existe, y nadie se dio cuenta porque ningun test los ejecutaba. Alguien que
 * clonara el repositorio se topaba con eso en el primer minuto.
 *
 * Asi que aqui se lanzan como los lanzaria esa persona —el binario de PHP, el
 * archivo, nada mas— y despues se abre la base de datos que han dejado y se
 * comprueba que los numeros son los que tienen que ser. Que no revienten es el
 * minimo; lo que se mide es que hagan lo que dicen.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$raiz = \dirname(\dirname(__DIR__));

/**
 * Ejecuta un ejemplo y devuelve [salida, codigo de salida].
 *
 * @return array{0: string, 1: int}
 */
function execute(string $script): array
{
    $salida = [];
    $codigo = 0;
    \exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($script) . ' 2>&1', $salida, $codigo);
    return [\implode("\n", $salida), $codigo];
}

/** Comprueba que un ejemplo arranca limpio. */
function arranca(string $nombre, string $salida, int $codigo): void
{
    ok("{$nombre} termina sin error", $codigo === 0);
    ok("{$nombre} no suelta avisos de PHP",
        !\str_contains($salida, 'Fatal error')
        && !\str_contains($salida, 'Warning:')
        && !\str_contains($salida, 'Deprecated:'));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Estan los que dice el indice, y solo esos');

$indice = (string) \file_get_contents($raiz . '/examples/README.md');
$carpetas = \array_map('basename', \array_filter(\glob($raiz . '/examples/*') ?: [], 'is_dir'));
\sort($carpetas, SORT_STRING);

eq('cuatro ejemplos', ['01-almacen', '02-empleados', '03-pedidos', '04-puente-http'], $carpetas);
foreach ($carpetas as $c) {
    ok("el indice nombra {$c}", \str_contains($indice, $c));
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Almacen: existencias, regla de unicidad y valor');

[$salida, $codigo] = execute($raiz . '/examples/01-almacen/index.php');
arranca('almacen', $salida, $codigo);

ok('rechaza la referencia repetida',  \str_contains($salida, 'Referencia repetida rechazada'));
ok('y no la deja entrar',            !\str_contains($salida, 'ERROR: ha entrado un duplicado'));
ok('avisa de lo que hay que reponer', \str_contains($salida, 'Por debajo del minimo'));

$db = new Db($raiz . '/examples/01-almacen/datos');
eq('quedan los seis articulos del catalogo', 6, $db->count('articulos'));
eq('y los diez movimientos',                10, $db->count('movimientos'));

// 2000 entradas menos 1750 salidas: el ejemplo tiene que haber escrito 250.
eq('las existencias salen de los movimientos', 250, $db->get('articulos', 'TOR-M6')['stock']);
eq('un articulo sin salidas conserva su entrada', 1200, $db->get('articulos', 'TOR-M8')['stock']);

$valor = $db->sql('SELECT ROUND(SUM(stock * precio), 2) AS t FROM articulos')[0]['t'];
ok(\sprintf('el valor del inventario cuadra con lo impreso: %.2f', $valor),
    \str_contains($salida, \number_format($valor, 2, '.', '')));
$db->storage()->close();

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Empleados: esquema, unicidad y agrupaciones');

[$salida, $codigo] = execute($raiz . '/examples/02-empleados/index.php');
arranca('empleados', $salida, $codigo);

ok('el esquema para el alta incompleta', \str_contains($salida, 'Alta incompleta rechazada'));
ok('y el UNIQUE el correo repetido',     \str_contains($salida, 'Correo repetido rechazado'));
ok('ninguna de las dos entra',
    !\str_contains($salida, 'ERROR: ha entrado'));
ok('el HAVING devuelve el departamento caro', \str_contains($salida, 'tec'));

$db = new Db($raiz . '/examples/02-empleados/datos');
eq('los seis empleados, ni uno mas', 6, $db->count('empleados'));

$tec = $db->sql("SELECT COUNT(*) AS n, SUM(salario) AS coste FROM empleados WHERE depto = 'tec'")[0];
eq('tres en el departamento tecnico', 3, $tec['n']);
ok('y la subida del 5% quedo escrita', $tec['coste'] > 103500);

$join = $db->sql('SELECT nombre, departamentos.centro AS centro FROM empleados
                  JOIN departamentos ON empleados.depto = departamentos.id');
eq('el JOIN devuelve una fila por empleado', 6, \count($join));
ok('y cada una trae su centro', \array_column($join, 'centro') === \array_filter(\array_column($join, 'centro')));
$db->storage()->close();

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Pedidos: la transaccion no deja medias tintas');

[$salida, $codigo] = execute($raiz . '/examples/03-pedidos/index.php');
arranca('pedidos', $salida, $codigo);

ok('el pedido imposible se aborta', \str_contains($salida, 'Pedido abortado a mitad'));

$db = new Db($raiz . '/examples/03-pedidos/datos');
eq('quedan los tres pedidos buenos', 3, $db->count('pedidos'));
eq('y sus seis lineas',              6, $db->count('lineas'));

/*
 * Lo que de verdad demuestra la transaccion: el pedido que fallo insertaba la
 * cabecera ANTES de reventar. Si no fuera atomico, aqui habria una cabecera del
 * cliente c3 sin una sola linea.
 */
eq('el cliente del pedido abortado no tiene cabecera huerfana', 0,
    \count($db->find('pedidos')->where('cliente', 'c3')->get()));

foreach ($db->all('pedidos') as $p) {
    ok("el pedido {$p['fecha']} tiene lineas", \count($db->by('lineas', 'pedido', $p['id'])) > 0);
}

$sinPedidos = $db->sql("SELECT nombre FROM clientes
                        WHERE id NOT IN (SELECT cliente FROM pedidos)");
eq('el LEFT JOIN enseña al cliente sin pedidos', 'Obras Gil', $sinPedidos[0]['nombre'] ?? null);
$db->storage()->close();

summary();
