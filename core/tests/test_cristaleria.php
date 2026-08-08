<?php
/**
 * AxiDB - test del dominio de la cristaleria.
 *
 * No comprueba que el ejemplo "no reviente": comprueba que los numeros salen.
 * Un dominio inventado, con sus reglas propias, sobre un motor que no sabe
 * nada de mamparas.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('cristaleria'), ['durable' => false]);
$db->index('presupuestos', 'cliente_id');
$db->index('presupuestos', 'estado');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Alta de clientes y presupuestos');

$ana  = $db->insert('clientes', ['nombre' => 'Ana Ruiz',    'ciudad' => 'Murcia']);
$luis = $db->insert('clientes', ['nombre' => 'Luis Mendez', 'ciudad' => 'Cartagena']);

/** Precio segun metros cuadrados: la regla de negocio vive en la aplicacion. */
function presupuestar(Db $db, string $cliente, string $tipo, int $ancho, int $alto, int $precioM2): array
{
    $m2 = ($ancho / 100) * ($alto / 100);
    return $db->insert('presupuestos', [
        'cliente_id' => $cliente,
        'tipo'       => $tipo,
        'm2'         => \round($m2, 2),
        'total'      => \round($m2 * $precioM2, 2),
        'estado'     => 'pendiente',
    ]);
}

$p1 = presupuestar($db, $ana['id'],  'mampara', 120, 195, 180);
$p2 = presupuestar($db, $ana['id'],  'espejo',   80, 100,  95);
$p3 = presupuestar($db, $luis['id'], 'ventana', 150, 120, 140);

eq('dos clientes',        2, $db->count('clientes'));
eq('tres presupuestos',   3, $db->count('presupuestos'));
eq('mampara 1,20 x 1,95 = 2,34 m2',   2.34, $p1['m2']);
eq('a 180 EUR/m2 son 421,20 EUR',   421.20, $p1['total']);
eq('espejo 0,80 m2',                  0.80, $p2['m2']);
eq('ventana 1,80 m2 a 140 = 252 EUR', 252.0, $p3['total']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Consulta por cliente, resuelta por indice');

$deAna = $db->by('presupuestos', 'cliente_id', $ana['id']);
eq('Ana tiene dos presupuestos', 2, \count($deAna));
eq('suman 497,20 EUR', 497.20, \round(\array_sum(\array_column($deAna, 'total')), 2));
eq('Luis tiene uno', 1, \count($db->by('presupuestos', 'cliente_id', $luis['id'])));
eq('un cliente sin presupuestos da vacio', 0, \count($db->by('presupuestos', 'cliente_id', 'nadie')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Filtros del dia a dia del taller');

// De los tres (421,20 / 76,00 / 252,00) solo la mampara pasa de 300.
$caros = $db->find('presupuestos')->where('estado', 'pendiente')->where('total', '>', 300)
            ->orderBy('total', 'desc')->get();
eq('pendientes de mas de 300 EUR', 1, \count($caros));
eq('y es la mampara',        421.20, $caros[0]['total']);

$medianos = $db->find('presupuestos')->where('total', '>', 200)->orderBy('total', 'desc')->get();
eq('de mas de 200 EUR hay dos', 2, \count($medianos));
eq('el mas caro encabeza',  421.20, $medianos[0]['total']);
eq('y el segundo es la ventana', 252.0, $medianos[1]['total']);

eq('solo mamparas', 1, \count($db->find('presupuestos')->where('tipo', 'mampara')->get()));
eq('trabajos de mas de 2 m2', 1, \count($db->find('presupuestos')->where('m2', '>', 2)->get()));

$resumen = $db->find('presupuestos')->select(['tipo', 'total'])->orderBy('total')->get();
eq('la proyeccion deja dos campos', 2, \count($resumen[0]));
eq('y ordena de menor a mayor', 76.0, $resumen[0]['total']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Ciclo de vida: aceptar, rechazar, borrar');

$db->update('presupuestos', $p1['id'], ['estado' => 'aceptado']);
$db->update('presupuestos', $p3['id'], ['estado' => 'aceptado']);
$db->update('presupuestos', $p2['id'], ['estado' => 'rechazado']);

eq('dos aceptados',   2, \count($db->by('presupuestos', 'estado', 'aceptado')));
eq('un rechazado',    1, \count($db->by('presupuestos', 'estado', 'rechazado')));
eq('ningun pendiente', 0, \count($db->by('presupuestos', 'estado', 'pendiente')));

$facturado = \array_sum(\array_column($db->by('presupuestos', 'estado', 'aceptado'), 'total'));
eq('facturacion aceptada', 673.20, \round($facturado, 2));

$db->delete('presupuestos', $p2['id']);
eq('quedan dos tras borrar el rechazado', 2, $db->count('presupuestos'));
eq('el indice de estado se limpio', 0, \count($db->by('presupuestos', 'estado', 'rechazado')));
eq('el indice de cliente tambien', 1, \count($db->by('presupuestos', 'cliente_id', $ana['id'])));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Persistencia entre procesos');

$ruta = $db->path();
unset($db);
$db2 = new Db($ruta, ['durable' => false]);
eq('los presupuestos siguen ahi', 2, $db2->count('presupuestos'));
eq('y los indices tambien',       2, \count($db2->by('presupuestos', 'estado', 'aceptado')));
eq('los importes no se degradaron', 673.20,
    \round(\array_sum(\array_column($db2->all('presupuestos'), 'total')), 2));

rmrf($ruta);
summary();
