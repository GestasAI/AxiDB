<?php
/**
 * AxiDB - transacciones: todo o nada, tambien tras un corte.
 *
 * La parte que de verdad prueba algo es la ultima: se mata el proceso una y
 * otra vez en mitad de una transferencia entre dos cuentas, y despues se
 * comprueba que la suma de los saldos sigue valiendo lo mismo.
 *
 * Esa invariante es la prueba entera. No hace falta saber en que fase del
 * diario cayo cada muerte: si el total cuadra, la transaccion fue atomica; si
 * no cuadra, se aplico media y esta roto. No hay termino medio.
 *
 * Lo que este test NO prueba, porque AxiDB no lo da: aislamiento. Un lector
 * concurrente puede ver la mitad de una transaccion mientras se aplica.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Tx\Diario;

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Todo o nada');

$dir = tmpdir('tx');
$db  = new Db($dir, ['durable' => false]);
$db->insert('stock', ['unidades' => 10], 'plato-1');

$saliopor = '';
try {
    $db->transaccion(static function ($tx) {
        $tx->update('stock', 'plato-1', ['unidades' => 4]);
        throw new RuntimeException('el pago fallo');
    });
} catch (RuntimeException $e) {
    $saliopor = $e->getMessage();
}
eq('una excepcion tuya sale tal cual, sin envolver', 'el pago fallo', $saliopor);

eq('y el documento se queda como estaba', 10, $db->get('stock', 'plato-1')['unidades']);
eq('sin subir de version siquiera', 1, $db->get('stock', 'plato-1')['_version']);

$fuera = $db->transaccion(static function ($tx) {
    $tx->update('stock', 'plato-1', ['unidades' => 7]);
    $tx->insert('pedidos', ['plato' => 'plato-1', 'n' => 3], 'p1');
    return 'listo';
});
eq('confirmar devuelve lo que devuelva la funcion', 'listo', $fuera);
eq('el stock baja', 7, $db->get('stock', 'plato-1')['unidades']);
eq('y el pedido existe, en otra coleccion', 1, $db->count('pedidos'));
eq('no queda ningun diario suelto', [], \glob($dir . '/_tx/*') ?: []);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Dentro de la transaccion se lee lo propio');

$db->transaccion(static function ($tx) {
    $tx->update('stock', 'plato-1', ['unidades' => 5]);
    eq('un get posterior ve el valor nuevo', 5, $tx->get('stock', 'plato-1')['unidades']);

    $tx->insert('stock', ['unidades' => 1], 'plato-2');
    ok('y un documento recien creado existe', $tx->exists('stock', 'plato-2'));
    eq('all() lo incluye', 2, $tx->count('stock'));

    $tx->delete('stock', 'plato-2');
    ok('borrarlo lo quita de la vista', !$tx->exists('stock', 'plato-2'));
    eq('y del recuento', 1, $tx->count('stock'));
});
eq('fuera queda lo confirmado', 5, $db->get('stock', 'plato-1')['unidades']);
ok('y lo creado y borrado dentro no llega', !$db->exists('stock', 'plato-2'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] La actualizacion perdida');

/*
 * El fallo de concurrencia que nadie ve: dos transacciones leen 10, cada una
 * resta 3, las dos escriben 7. Faltan tres unidades y no hay ningun error.
 * Se aborta comparando la version del documento al confirmar.
 */
$db->insert('c', ['n' => 10], 'x');

throws('si alguien toca por debajo lo que la transaccion leyo, se aborta',
    static fn () => $db->transaccion(static function ($tx) use ($db) {
        $v = $tx->get('c', 'x')['n'];
        $db->update('c', 'x', ['n' => 99]);       // otro proceso, por en medio
        $tx->update('c', 'x', ['n' => $v - 3]);
    }));

eq('y el cambio ajeno sigue en pie', 99, $db->get('c', 'x')['n']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Con indices, unicidad y borrados');

$db->sql('CREATE UNIQUE INDEX ON usuarios (correo)');
$db->transaccion(static function ($tx) {
    $tx->insert('usuarios', ['correo' => 'ana@ejemplo.com'], 'u1');
    $tx->insert('usuarios', ['correo' => 'juan@ejemplo.com'], 'u2');
});
eq('los indices se mantienen al aplicar', 'u1',
    $db->by('usuarios', 'correo', 'ana@ejemplo.com')[0]['id'] ?? null);

throws('una transaccion que repite un valor unico se rechaza entera',
    static fn () => $db->transaccion(static function ($tx) {
        $tx->insert('usuarios', ['correo' => 'nuevo@ejemplo.com'], 'u3');
        $tx->insert('usuarios', ['correo' => 'ana@ejemplo.com'], 'u4');
    }));
eq('y no entra ni el primero de los dos', 2, $db->count('usuarios'));
ok('el que iba antes del choque tampoco', !$db->exists('usuarios', 'u3'));

$revision = $db->verifyIndexes('usuarios')['correo'] ?? [];
eq('el indice no queda con reservas de mas', 0, $revision['sobran'] ?? -1);

$db->transaccion(static fn ($tx) => $tx->delete('usuarios', 'u1'));
eq('borrar dentro de una transaccion libera el valor unico', [],
    $db->by('usuarios', 'correo', 'ana@ejemplo.com'));

$db->storage()->cerrar();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D2] BEGIN / COMMIT / ROLLBACK en AxiSQL');

$dir2 = tmpdir('tx_sql');
$sql  = new Db($dir2, ['durable' => false]);
$sql->insert('cuentas', ['saldo' => 500], 'a');
$sql->insert('cuentas', ['saldo' => 500], 'b');

$sql->sql('BEGIN');
$sql->sql("UPDATE cuentas SET saldo = 470 WHERE id = 'a'");

/*
 * El segundo UPDATE sobre la MISMA coleccion. La primera version se negaba aqui
 * —"hay cambios sin confirmar"— y eso dejaba fuera la transferencia entre dos
 * cuentas, que es el caso mas normal que existe. Ahora el filtro se resuelve
 * sobre la vista de la transaccion.
 */
$sql->sql("UPDATE cuentas SET saldo = 530 WHERE id = 'b'");

eq('antes de COMMIT el disco no se ha tocado', 500, $sql->get('cuentas', 'a')['saldo']);
eq('COMMIT aplica las dos', ['aplicadas' => 2], $sql->sql('COMMIT'));
eq('y ahora si', 470, $sql->get('cuentas', 'a')['saldo']);
eq('las dos', 530, $sql->get('cuentas', 'b')['saldo']);

$sql->sql('BEGIN TRANSACTION');
$sql->sql("UPDATE cuentas SET saldo = 1 WHERE id = 'a'");
eq('ROLLBACK descarta lo acumulado', ['descartadas' => 1], $sql->sql('ROLLBACK'));
eq('y no llega nada al disco', 470, $sql->get('cuentas', 'a')['saldo']);

$sql->sql('BEGIN');
$sql->sql("UPDATE cuentas SET saldo = 9 WHERE id = 'a'");
eq('un UPDATE posterior ve lo pendiente', ['updated' => 1],
    $sql->sql("UPDATE cuentas SET saldo = 8 WHERE saldo = 9"));

/*
 * Y un SELECT tambien. La primera version se negaba aqui, y estaba mal: un
 * resultado desfasado en una web es un fallo, no una limitacion aceptable. Se
 * arreglo dandole a Query una fuente alternativa de documentos, no
 * reimplementandola: ordenar, recortar y proyectar siguen siendo los de siempre.
 */
$sql->sql("INSERT INTO cuentas (saldo) VALUES (700)");

$filas = $sql->sql('SELECT saldo FROM cuentas ORDER BY saldo DESC');
eq('un SELECT dentro de BEGIN ve lo pendiente y en orden',
    [700, 530, 8], \array_column($filas, 'saldo'));
eq('COUNT tambien', 2, $sql->sql('SELECT COUNT(*) FROM cuentas WHERE saldo > 100'));
eq('LIMIT tambien', [['saldo' => 8]], $sql->sql('SELECT saldo FROM cuentas ORDER BY saldo ASC LIMIT 1'));
eq('y WHERE sobre un valor que solo existe sin confirmar', 1,
    \count($sql->sql('SELECT * FROM cuentas WHERE saldo = 700')));
eq('EXPLAIN lo dice: no se usan indices ahi dentro', 'transaccion',
    $sql->sql("EXPLAIN SELECT * FROM cuentas WHERE id = 'a'")['estrategia']);

$sql->sql('ROLLBACK');
eq('y tras ROLLBACK el disco esta como estaba', 2, $sql->count('cuentas'));
eq('con su valor de antes', 470, $sql->get('cuentas', 'a')['saldo']);

throws('COMMIT sin BEGIN se rechaza', static fn () => $sql->sql('COMMIT'));
throws('ROLLBACK sin BEGIN tambien', static fn () => $sql->sql('ROLLBACK'));

$sql->sql('BEGIN');
throws('y no se anidan', static fn () => $sql->sql('BEGIN'));
$sql->sql('ROLLBACK');

eq('no queda ningun diario', [], \glob($dir2 . '/_tx/*') ?: []);
$sql->storage()->cerrar();
rmrf($dir2);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] La marca de confirmacion decide');

/*
 * Se fabrican los dos estados a mano, que es mas fiable que esperar a que un
 * corte caiga justo ahi: un diario sin marca y otro con ella.
 */
$dir = tmpdir('tx_recuperacion');
$db  = new Db($dir, ['durable' => false]);
$db->insert('c', ['n' => 1], 'd1');
$db->storage()->cerrar();

$plan = [['coleccion' => 'c', 'id' => 'd1', 'accion' => 'poner', 'datos' => ['n' => 42]]];

$sinMarca = new Diario($dir, 'tx_sin_marca');
$sinMarca->anotar($plan);

$conMarca = new Diario($dir, 'tx_con_marca');
$conMarca->anotar($plan);
$conMarca->confirmar();

$reabierto = new Db($dir, ['durable' => false, 'recuperar' => false]);
eq('abrir sin recuperar deja los dos diarios donde estaban', 2, \count(Diario::pendientes($dir)));

$hecho = $reabierto->recuperar();

eq('el diario sin marca se descarta', 1, $hecho['descartadas']);
eq('el que tiene marca se termina de aplicar', 1, $hecho['aplicadas']);
eq('y el documento queda con el valor de la transaccion confirmada',
    42, $reabierto->get('c', 'd1')['n']);
eq('no queda ningun diario', [], \glob($dir . '/_tx/*') ?: []);

// Aplicar dos veces el mismo plan tiene que dar el mismo resultado: si no, una
// recuperacion que se repita —dos procesos abriendo a la vez— haria daño.
$otra = new Diario($dir, 'tx_repetido');
$otra->anotar($plan);
$otra->confirmar();
$reabierto->recuperar();
$otra2 = new Diario($dir, 'tx_repetido_2');
$otra2->anotar($plan);
$otra2->confirmar();
$reabierto->recuperar();
eq('aplicar el mismo plan dos veces deja lo mismo', 42, $reabierto->get('c', 'd1')['n']);

$reabierto->storage()->cerrar();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Matando el proceso en mitad de la transferencia');

/*
 * Dos cuentas con 500 cada una. Un worker mueve una unidad de ida y vuelta sin
 * parar, dentro de una transaccion, y se le mata en un momento cualquiera.
 *
 * Al reabrir, la suma tiene que seguir valiendo 1000. Si una transaccion se
 * aplicara a medias —resta hecha, suma no— el total bajaria y no volveria.
 */
const RONDAS_TX = 12;
const TOTAL     = 1000;

$dir = tmpdir('tx_tortura');
$db  = new Db($dir, ['durable' => false]);
$db->insert('cuentas', ['saldo' => 500], 'a');
$db->insert('cuentas', ['saldo' => 500], 'b');
$db->storage()->cerrar();

$muertes  = 0;
$descuadres = [];
$recuperadas = 0;

for ($ronda = 0; $ronda < RONDAS_TX; $ronda++) {
    $h = spawn(__DIR__ . '/_worker_tx.php', [$dir, (string) $ronda]);

    // Entre 0,25 y 0,75 s: pasado el arranque del interprete, y con margen para
    // que la muerte caiga dentro de una confirmacion y no entre dos.
    \usleep(250000 + ($ronda * 7919) % 500000);
    killNow($h);
    $muertes++;

    $lector = new Db($dir, ['durable' => false, 'recuperar' => false]);
    $pendientes = \count(Diario::pendientes($dir));
    $lector->storage()->cerrar();

    $tras = new Db($dir, ['durable' => false]);          // recupera al abrir
    $suma = ($tras->get('cuentas', 'a')['saldo'] ?? 0) + ($tras->get('cuentas', 'b')['saldo'] ?? 0);
    if ($suma !== TOTAL) {
        $descuadres[] = "ronda {$ronda}: la suma vale {$suma}";
    }
    $recuperadas += $pendientes;
    $tras->storage()->cerrar();
}

$final = new Db($dir, ['durable' => false]);
\printf("    %d muertes, %d diarios encontrados a medias, %d movimientos escritos\n",
    $muertes, $recuperadas, $final->count('movimientos'));

eq('la suma de los saldos nunca se descuadro', [], \array_slice($descuadres, 0, 5));
ok('se llego a matar dentro de alguna transaccion (' . $recuperadas . ' diarios)', $recuperadas > 0);
ok('y se escribieron transferencias de verdad', $final->count('movimientos') > 20);

$suma = $final->get('cuentas', 'a')['saldo'] + $final->get('cuentas', 'b')['saldo'];
eq('el total sigue siendo el de partida', TOTAL, $suma);
eq('y no queda ningun diario sin resolver', [], \glob($dir . '/_tx/*') ?: []);

$final->storage()->cerrar();
rmrf($dir);

summary();
