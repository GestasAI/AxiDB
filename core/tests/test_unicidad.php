<?php
/**
 * AxiDB - campos unicos que se cumplen de verdad.
 *
 * Este test nace de un fallo concreto: `CREATE UNIQUE INDEX` se aceptaba,
 * comprobaba los documentos que ya habia... y no impedia un duplicado despues.
 * La restriccion se declaraba y no se cumplia, que es peor que no tenerla,
 * porque quien la declara cree estar protegido.
 *
 * Lo primero que se comprueba aqui es exactamente ese caso.
 *
 * Y lo ultimo es lo que de verdad distingue una restriccion de una comprobacion
 * de cortesia: varios procesos insertando el mismo valor a la vez. Si solo se
 * mirase el indice antes de escribir, los dos pasarian y los dos escribirian.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] El fallo que dio origen a esto');

$dir = tmpdir('unicidad');
$db  = new Db($dir, ['durable' => false]);

$db->sql('CREATE UNIQUE INDEX ON usuarios (correo)');
$db->insert('usuarios', ['correo' => 'ana@ejemplo.com', 'nombre' => 'Ana'], 'u1');

throws('un segundo documento con el mismo correo se rechaza',
    static fn () => $db->insert('usuarios', ['correo' => 'ana@ejemplo.com'], 'u2'));

eq('y el rechazado no se guardo a medias', 1, $db->count('usuarios'));
eq('el original sigue entero', 'Ana', $db->get('usuarios', 'u1')['nombre']);
ok('no quedo rastro del rechazado', $db->get('usuarios', 'u2') === null);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Modificar');

$db->update('usuarios', 'u1', ['nombre' => 'Ana Lopez']);
eq('cambiar otro campo no choca con su propio valor', 'Ana Lopez', $db->get('usuarios', 'u1')['nombre']);
eq('y el correo sigue ahi', 'ana@ejemplo.com', $db->get('usuarios', 'u1')['correo']);

$db->insert('usuarios', ['correo' => 'juan@ejemplo.com'], 'u3');
throws('cambiar el correo a uno ocupado se rechaza',
    static fn () => $db->update('usuarios', 'u3', ['correo' => 'ana@ejemplo.com']));
eq('y el suyo no se toca', 'juan@ejemplo.com', $db->get('usuarios', 'u3')['correo']);

$db->update('usuarios', 'u3', ['correo' => 'juan.nuevo@ejemplo.com']);
eq('cambiarlo a uno libre si funciona', 'juan.nuevo@ejemplo.com', $db->get('usuarios', 'u3')['correo']);
$db->insert('usuarios', ['correo' => 'juan@ejemplo.com'], 'u4');
eq('y el anterior queda libre para otro', 'juan@ejemplo.com', $db->get('usuarios', 'u4')['correo']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Sin valor no es compartir valor');

/*
 * Mismo criterio que SQL con NULL: dos documentos sin correo no chocan. Si
 * chocaran, una coleccion con un campo unico opcional solo admitiria un
 * documento sin ese campo, que no es lo que nadie espera.
 */
$db->insert('usuarios', ['nombre' => 'sin correo'], 's1');
$db->insert('usuarios', ['nombre' => 'tampoco'], 's2');
$db->insert('usuarios', ['correo' => '', 'nombre' => 'vacio'], 's3');
eq('varios documentos sin el campo conviven', 6, $db->count('usuarios'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Borrar libera el valor');

$db->delete('usuarios', 'u1');
$db->insert('usuarios', ['correo' => 'ana@ejemplo.com'], 'u9');
eq('el correo de un documento borrado se puede reutilizar',
    'ana@ejemplo.com', $db->get('usuarios', 'u9')['correo']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] La restriccion vive en la coleccion');

eq('se puede consultar que campos son unicos', ['correo'], $db->uniques('usuarios'));
$db->storage()->close();

$otro = new Db($dir, ['durable' => false]);
eq('sobrevive a cerrar y reabrir', ['correo'], $otro->uniques('usuarios'));
throws('y sigue rechazando duplicados',
    static fn () => $otro->insert('usuarios', ['correo' => 'ana@ejemplo.com'], 'uX'));

ok('esta escrita en el _axidb.json de la coleccion, no en un archivo central',
    \str_contains((string) \file_get_contents($dir . '/usuarios/_axidb.json'), 'correo'));

$otro->dropIndex('usuarios', 'correo');
eq('quitar el indice quita la unicidad', [], $otro->uniques('usuarios'));
$otro->insert('usuarios', ['correo' => 'ana@ejemplo.com'], 'uY');
eq('y entonces si se admite el repetido', 'ana@ejemplo.com', $otro->get('usuarios', 'uY')['correo']);
$otro->storage()->close();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] No se declara sobre datos que ya la incumplen');

$dir = tmpdir('unicidad_sucia');
$db  = new Db($dir, ['durable' => false]);
$db->insert('c', ['e' => 'repetido'], 'a');
$db->insert('c', ['e' => 'repetido'], 'b');

throws('declarar unico con repetidos dentro se rechaza',
    static fn () => $db->sql('CREATE UNIQUE INDEX ON c (e)'));
eq('y la coleccion no queda marcada a medias', [], $db->uniques('c'));

$db->delete('c', 'b');
$db->sql('CREATE UNIQUE INDEX ON c (e)');
eq('una vez limpia, se declara sin problema', ['e'], $db->uniques('c'));
$db->storage()->close();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Varios procesos a la vez: gana uno solo');

/*
 * La prueba de fuego. Ocho procesos intentan insertar el MISMO correo en el
 * mismo instante, cada uno con un id distinto.
 *
 * Si la comprobacion se hiciera leyendo el indice y escribiendo despues, los
 * ocho leerian "libre" y los ocho escribirian. Reservando bajo el cerrojo del
 * propio valor, solo uno puede reservar y los otros siete se encuentran el
 * valor cogido.
 */
const PROCESOS = 8;

$dir = tmpdir('unicidad_carrera');
$db  = new Db($dir, ['durable' => false]);
$db->sql('CREATE UNIQUE INDEX ON cuentas (correo)');
$db->storage()->close();

$arranque = \microtime(true) + 1.2;          // margen para que arranquen los ocho
$handles  = [];
for ($i = 0; $i < PROCESOS; $i++) {
    $handles[] = spawn(__DIR__ . '/_worker_unico.php', [
        $dir, 'cuentas', 'correo', 'peleado@ejemplo.com', 'p' . $i, (string) $arranque,
    ]);
}
foreach ($handles as $h) {
    waitFor($h);
}

$final = new Db($dir, ['durable' => false]);
$docs  = $final->all('cuentas');

eq('de ' . PROCESOS . ' intentos simultaneos, entro exactamente uno', 1, \count($docs));

$ids = \array_column($docs, 'id');
eq('y el indice apunta a ese y solo a ese',
    $ids, $final->by('cuentas', 'correo', 'peleado@ejemplo.com') ? \array_column(
        $final->by('cuentas', 'correo', 'peleado@ejemplo.com'), 'id'
    ) : []);

$revision = $final->verifyIndexes('cuentas')['correo'] ?? [];
eq('el indice no quedo con entradas de mas', 0, $revision['sobran'] ?? -1);
eq('ni le falta ninguna', 0, $revision['faltan'] ?? -1);

$final->storage()->close();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] La reserva huerfana se ve, y se arregla');

/*
 * Reservar antes de escribir tiene un precio, y aqui se paga a la vista: si el
 * proceso muere entre la reserva y la escritura del documento, el valor queda
 * cogido sin dueño. No se pierde nada, pero ese correo diria estar ocupado sin
 * estarlo.
 *
 * Se simula reclamando a mano y no escribiendo el documento, que es exactamente
 * el estado en el que quedaria.
 */
$dir = tmpdir('unicidad_huerfana');
$db  = new Db($dir, ['durable' => false]);
$db->sql('CREATE UNIQUE INDEX ON cuentas (correo)');
$db->insert('cuentas', ['correo' => 'real@ejemplo.com'], 'c1');

eq('de partida el indice esta limpio', 0, $db->verifyIndexes('cuentas')['correo']['sobran'] ?? -1);

$db->indexer()->claim('cuentas', 'correo', 'fantasma@ejemplo.com', 'nunca-escrito');

eq('la reserva sin documento se cuenta como sobrante',
    1, $db->verifyIndexes('cuentas')['correo']['sobran'] ?? -1);
eq('y no se confunde con una que falte', 0, $db->verifyIndexes('cuentas')['correo']['faltan'] ?? -1);

throws('mientras tanto, ese valor esta cogido',
    static fn () => $db->insert('cuentas', ['correo' => 'fantasma@ejemplo.com'], 'c2'));

$db->reindex('cuentas');
eq('reconstruir el indice la limpia', 0, $db->verifyIndexes('cuentas')['correo']['sobran'] ?? -1);

$db->insert('cuentas', ['correo' => 'fantasma@ejemplo.com'], 'c2');
eq('y el valor vuelve a estar libre', 2, $db->count('cuentas'));

$db->storage()->close();
rmrf($dir);

summary();
