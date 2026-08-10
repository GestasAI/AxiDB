<?php
/**
 * AxiDB - auditoria de seguridad: carreras, atomicidad y estados intermedios.
 *
 * Este archivo no comprueba que el motor haga lo que dice cuando esta solo.
 * Comprueba que lo siga haciendo cuando hay otro proceso escribiendo encima, y
 * cuando alguien mata un proceso en el peor instante posible. Son los fallos
 * que no se ven leyendo el codigo: cada uno de ellos necesita dos relojes.
 *
 * Regla de este archivo: cada aserto afirma el comportamiento SEGURO. Si un
 * ataque funciona, el test se queda ROJO. No se rebaja ningun aserto para que
 * pase; un test verde por rebaja es peor que no tenerlo.
 *
 * Nunca hay mas de seis procesos a la vez, y el total esta por debajo de dos
 * minutos: esta maquina la comparten otros.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

/* ─────────────────────────────── Utiles ─────────────────────────────── */

/** Espera a que aparezca un archivo. Devuelve false si se acaba el tiempo. */
function esperaArchivo(string $path, float $segundos = 15.0): bool
{
    $hasta = \microtime(true) + $segundos;
    while (\microtime(true) < $hasta) {
        if (\is_file($path)) {
            return true;
        }
        \usleep(2000);
    }
    return false;
}

/** Lo que el worker dejo escrito en su archivo de estado. */
function estado(string $path): array
{
    $json = \json_decode((string) @\file_get_contents($path), true);
    return \is_array($json) ? $json : [];
}

/** Deja un diario de transaccion a mano, tal y como lo dejaria un atacante. */
function plantarDiario(string $base, string $id, array $operaciones, bool $confirmado): string
{
    $dir = $base . '/_tx/' . $id;
    @\mkdir($dir, 0777, true);
    \file_put_contents(
        $dir . '/plan.json',
        \json_encode(['version' => 1, 'operaciones' => $operaciones])
    );
    if ($confirmado) {
        \file_put_contents($dir . '/_hecho', '');
    }
    return $dir;
}

$t0 = \microtime(true);

/* ═══════════════════════════════════════════════════════════════════════════
 * A] Salto de UNIQUE por carrera, repetido hasta que se note
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Una carrera que se pierde una vez de cada cincuenta es una brecha, no una
 * anecdota: en produccion son dos cuentas con el mismo correo. El test que ya
 * existia lanza la carrera UNA vez. Aqui se repite en rondas, y lo que se mide
 * es cuantas rondas admitieron mas de un documento.
 */
section('A] Seis procesos peleando por el mismo valor unico, diez rondas');

const RONDAS_UNICO   = 10;
const PROCESOS_UNICO = 6;

$colados = 0;
$vacias  = 0;

for ($ronda = 0; $ronda < RONDAS_UNICO; $ronda++) {
    $dir = tmpdir('sec_unico_' . $ronda);
    $db  = new Db($dir, ['durable' => false]);
    $db->unique('cuentas', 'correo');
    $db->storage()->close();

    $arranque = \microtime(true) + 0.9;      // margen para que arranquen los seis
    $handles  = [];
    for ($i = 0; $i < PROCESOS_UNICO; $i++) {
        $handles[] = spawn(__DIR__ . '/_worker_unico.php', [
            $dir, 'cuentas', 'correo', 'peleado@ejemplo.com', 'p' . $i, (string) $arranque,
        ]);
    }
    foreach ($handles as $h) {
        waitFor($h);
    }

    $final   = new Db($dir, ['durable' => false]);
    $cuantos = \count($final->all('cuentas'));
    $final->storage()->close();

    if ($cuantos > 1) {
        $colados++;
        echo "    ronda {$ronda}: ENTRARON {$cuantos} documentos con el mismo correo\n";
    } elseif ($cuantos === 0) {
        $vacias++;
    }
    rmrf($dir);
}

echo '    rondas con duplicado: ' . $colados . ' de ' . RONDAS_UNICO
   . ' | rondas en las que no entro nadie: ' . $vacias . "\n";

eq('ninguna ronda dejo entrar dos veces el mismo valor unico', 0, $colados);
eq('y en todas entro exactamente uno (ni cero)', 0, $vacias);

/* ───────────────────────────────────────────────────────────────────────── */
section('A2] Reconstruir el indice mientras otro proceso da de alta');

/*
 * Index::build() borra TODOS los archivos de valor del campo y los vuelve a
 * escribir escaneando la coleccion. No coge ningun cerrojo, ni el del campo ni
 * el de la coleccion. Mientras dura, el valor unico no esta reservado en ningun
 * sitio: cualquiera que reserve en esa ventana pasa la comprobacion.
 *
 * Esto importa el doble porque `reindex()` es justamente la reparacion que el
 * motor recomienda para las reservas huerfanas del apartado B. La cura del
 * problema anterior abre este.
 */
$dir = tmpdir('sec_reindex');
$db  = new Db($dir, ['durable' => false]);
$db->unique('cuentas', 'correo');
$db->storage()->close();

$h = spawn(__DIR__ . '/_worker_sec_plano.php', [$dir, 'cuentas', 'dup', 'd', '2.5']);
\usleep(300000);

$db      = new Db($dir, ['durable' => false]);
$repasos = 0;
$hasta   = \microtime(true) + 2.0;
while (\microtime(true) < $hasta) {
    $db->reindex('cuentas');
    $repasos++;
}
waitFor($h);

$intruso = estado($dir . '/_sec_plano_d.json');
$final   = new Db($dir, ['durable' => false]);
$conEse  = \count(\array_filter(
    $final->all('cuentas'),
    static fn(array $d): bool => ($d['correo'] ?? '') === 'peleado@ejemplo.com'
));

echo "    {$repasos} reconstrucciones | " . ($intruso['intentos'] ?? '?') . ' intentos de alta, '
   . ($intruso['aceptados'] ?? '?') . " aceptados\n";
echo "    documentos que acabaron con el mismo correo unico: {$conEse}\n";

eq('reconstruir el indice no deja colarse un duplicado del valor unico', 1, $conEse);

$final->storage()->close();
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * B] Reserva de unicidad huerfana: denegacion de servicio sobre los correos
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uniqueness reserva el valor ANTES de escribir el documento, y suelta la
 * reserva si la escritura falla. Lo que no puede soltar es lo que se queda a
 * medias porque el proceso MURIO: ahi no corre ningun `finally`.
 *
 * Aqui se mata un proceso de verdad en esa ventana. La pregunta que decide si
 * esto es un limite conocido o una brecha es la siguiente: ¿vuelve ese valor a
 * estar disponible alguna vez, por si solo? Si la respuesta es no, cualquiera
 * que pueda provocar muertes de proceso —un timeout de PHP, un OOM, un
 * despliegue— puede ir ocupando correos hasta que nadie pueda registrarse.
 */
section('B] Matar el proceso entre reservar el valor y escribir el documento');

$dir = tmpdir('sec_reserva');
$db  = new Db($dir, ['durable' => false]);
$db->unique('cuentas', 'correo');
$db->storage()->close();

$marca = $dir . '/_sec_reservado';
$h     = spawn(__DIR__ . '/_worker_sec_reserva.php', [
    $dir, 'cuentas', 'correo', 'victima@ejemplo.com', 'jamas-escrito', $marca,
]);
ok('el proceso llega a reservar el valor', esperaArchivo($marca, 15.0));
killNow($h);

$db = new Db($dir, ['durable' => false]);

eq('el documento no llego a escribirse', 0, $db->count('cuentas'));

$reclamado = true;
try {
    $db->insert('cuentas', ['correo' => 'victima@ejemplo.com'], 'legitimo');
} catch (\Throwable) {
    $reclamado = false;
}
ok('un alta legitima puede usar el valor que dejo huerfano un proceso muerto', $reclamado);

// Cuanto cuesta el ataque: cada muerte quema un valor y no se recupera solo.
for ($i = 0; $i < 40; $i++) {
    $db->indexer()->claim('cuentas', 'correo', "quemado{$i}@ejemplo.com", 'fantasma' . $i);
}
$db->storage()->sweep('cuentas', 0);                 // el mantenimiento que hay
$sobran = $db->verifyIndexes('cuentas')['correo']['sobran'] ?? -1;
echo "    valores quemados que siguen ocupados tras el barrido: {$sobran}\n";

$libre = true;
try {
    $db->insert('cuentas', ['correo' => 'quemado7@ejemplo.com'], 'otro');
} catch (\Throwable) {
    $libre = false;
}
ok('el mantenimiento normal (sweep) libera las reservas sin dueño', $libre);

$db->storage()->close();
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * C] Transacciones simultaneas: la actualizacion perdida
 * ═══════════════════════════════════════════════════════════════════════════
 */
section('C] Dos procesos incrementando el mismo contador con transacciones');

$dir = tmpdir('sec_tx');
$db  = new Db($dir, ['durable' => false]);
$db->put('contadores', 'c', ['n' => 0]);
$db->storage()->close();

$arranque = \microtime(true) + 0.9;
$handles  = [
    spawn(__DIR__ . '/_worker_sec_tx.php', [$dir, 'a', 40, (string) $arranque]),
    spawn(__DIR__ . '/_worker_sec_tx.php', [$dir, 'b', 40, (string) $arranque]),
];
foreach ($handles as $h) {
    waitFor($h);
}

$a = estado($dir . '/_sec_tx_a.json');
$b = estado($dir . '/_sec_tx_b.json');
$db = new Db($dir, ['durable' => false]);
$n  = (int) ($db->get('contadores', 'c')['n'] ?? -1);

$confirmadas = (int) ($a['ok'] ?? -1) + (int) ($b['ok'] ?? -1);
echo "    confirmadas {$confirmadas} | abortadas "
   . ((int) ($a['abortadas'] ?? 0) + (int) ($b['abortadas'] ?? 0))
   . " | contador {$n}\n";

eq('el contador vale exactamente las transacciones confirmadas', $confirmadas, $n);
ok('y alguna se confirmo de verdad (el escenario ocurrio)', $confirmadas > 0);

$db->storage()->close();
rmrf($dir);

/* ───────────────────────────────────────────────────────────────────────── */
section('C2] Una escritura normal contra una transaccion sobre el mismo documento');

/*
 * La comprobacion de versiones vive DENTRO del cerrojo de confirmacion, pero
 * una escritura normal —`put()`— no coge ese cerrojo. La ventana es corta: va
 * desde que la transaccion comprueba la version hasta que Applier termina de
 * escribir. Si un escritor normal cae dentro, su escritura se pierde entera y
 * sin un solo error, porque Applier escribe con replace = true.
 *
 * El detector es un contador que solo sube: el escritor normal escribe w = 0,
 * 1, 2, ... y nunca retrocede. Se mira el valor JUSTO antes de confirmar y otra
 * vez despues. Si el de despues es menor, esas escrituras existieron y ya no
 * estan; y como se leyeron antes del COMMIT, no hay forma de discutirlo.
 *
 * Se usa BEGIN/COMMIT a mano y con una pausa dentro para abrir la ventana de
 * par en par. Lo correcto es que la confirmacion ABORTE: el documento cambio
 * despues de que la transaccion lo leyera.
 */
$dir = tmpdir('sec_tx_plano');
$db  = new Db($dir, ['durable' => false]);
$db->put('mixto', 'x', ['w' => -1, 't' => -1]);
$db->storage()->close();

$h = spawn(__DIR__ . '/_worker_sec_plano.php', [$dir, 'mixto', 'mismo', 'p1', '2.8', 'x']);
\usleep(400000);

$db         = new Db($dir, ['durable' => false]);
$conflictos = 0;
$vueltas    = 0;
$retrocesos = 0;
$perdidas   = 0;
$hasta      = \microtime(true) + 2.0;

while (\microtime(true) < $hasta) {
    $db->begin();
    $db->currentTransaction()->update('mixto', 'x', ['t' => $vueltas]);
    \usleep(20000);                       // el escritor normal avanza aqui

    $justoAntes = (int) ($db->get('mixto', 'x')['w'] ?? -1);
    try {
        $db->commit();
    } catch (\Throwable) {
        $conflictos++;
        $vueltas++;
        continue;                         // abortar es la respuesta correcta
    }
    $despues = (int) ($db->get('mixto', 'x')['w'] ?? -1);
    if ($despues < $justoAntes) {
        $retrocesos++;
        $perdidas += $justoAntes - $despues;
    }
    $vueltas++;
}
waitFor($h);

$escritor = estado($dir . '/_sec_plano_p1.json');
echo '    el escritor normal hizo ' . ($escritor['escritos'] ?? '?') . ' escrituras;'
   . " {$vueltas} transacciones, {$conflictos} abortadas por conflicto de version\n";
echo "    confirmaciones que enterraron escrituras ajenas: {$retrocesos}\n";

ok('ninguna escritura normal quedo enterrada en silencio por una transaccion'
   . " (perdidas contadas: {$perdidas})", $perdidas === 0);

$db->storage()->close();
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * D] Manipular el diario a mano
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * El diario es la unica estructura del motor que, al abrir la base, se APLICA
 * sola sin que nadie lo pida. Todo lo que se pueda meter ahi se ejecuta con los
 * permisos del proceso. Se prueban tres formas de envenenarlo.
 */
section('D] Diarios de transaccion envenenados');

$dir = tmpdir('sec_diario');
$db  = new Db($dir, ['durable' => false]);
$db->insert('admins', ['rol' => 'usuario'], 'a1');
$db->storage()->close();

// D1] Un plan que apunta fuera del directorio de datos.
plantarDiario($dir, 'txfuera', [
    ['coleccion' => '../fuera', 'id' => 'x', 'accion' => 'poner', 'datos' => ['p' => 1]],
], true);

$abrio = true;
$fallo = '';
try {
    $db = new Db($dir, ['durable' => false]);
    $db->storage()->close();
} catch (\Throwable $e) {
    $abrio = false;
    $fallo = $e::class;
}
ok('nada se escribio fuera del directorio de datos',
    !\is_dir(\dirname($dir) . '/fuera') && !\is_file(\dirname($dir) . '/fuera'));
ok("un diario con un nombre invalido no impide abrir la base (fallo: {$fallo})", $abrio);

$sigueRota = false;
if (!$abrio) {
    try {
        (new Db($dir, ['durable' => false]))->storage()->close();
    } catch (\Throwable) {
        $sigueRota = true;
    }
}
ok('y el intento siguiente tampoco se queda bloqueado', !$sigueRota);
rmrf($dir . '/_tx');

// D2] Un plan valido pero forjado: escribe donde le da la gana al abrir.
$dir2 = tmpdir('sec_diario2');
$db   = new Db($dir2, ['durable' => false]);
$db->insert('admins', ['rol' => 'usuario'], 'a1');
$db->storage()->close();

plantarDiario($dir2, 'txforjado', [
    ['coleccion' => 'admins', 'id' => 'a1', 'accion' => 'poner', 'datos' => ['rol' => 'admin']],
], true);

$db  = new Db($dir2, ['durable' => false]);
$rol = (string) ($db->get('admins', 'a1')['rol'] ?? '');
echo "    diario forjado sobre 'admins/a1': el rol quedo en '{$rol}'"
   . ($rol === 'admin' ? "  <- aplicado al abrir\n" : "  <- rechazado\n");

// D3] Marca de confirmacion sin plan legible: no puede aplicar nada.
plantarDiario($dir2, 'txroto', [], true);
\file_put_contents($dir2 . '/_tx/txroto/plan.json', '{esto no es json');

$abrio2 = true;
try {
    $db = new Db($dir2, ['durable' => false]);
} catch (\Throwable) {
    $abrio2 = false;
}
ok('un diario con el plan ilegible se descarta y la base abre', $abrio2);
ok('y el diario envenenado se retira del disco', !\is_dir($dir2 . '/_tx/txroto'));

// D4] Un plan con un id que intenta salirse.
plantarDiario($dir2, 'txid', [
    ['coleccion' => 'admins', 'id' => '../../fuera', 'accion' => 'poner', 'datos' => ['p' => 1]],
], true);

$abrio3 = true;
try {
    $db = new Db($dir2, ['durable' => false]);
    $db->storage()->close();
} catch (\Throwable) {
    $abrio3 = false;
}
ok('un id con traversal en el plan no impide abrir la base', $abrio3);
ok('ni deja archivos fuera', !\is_file(\dirname($dir2) . '/fuera.json'));

if (isset($db)) {
    $db->storage()->close();
}
rmrf($dir);
rmrf($dir2);

/* ═══════════════════════════════════════════════════════════════════════════
 * E] Leer mientras se confirma
 * ═══════════════════════════════════════════════════════════════════════════
 */
section('E] Un lector durante la aplicacion de la transaccion');

$dir = tmpdir('sec_lectura');
$db  = new Db($dir, ['durable' => false]);
$db->put('cuentas', 'a', ['saldo' => 500]);
$db->put('cuentas', 'b', ['saldo' => 500]);
$db->storage()->close();

$h  = spawn(__DIR__ . '/_worker_sec_lector.php', [$dir, '2.5', '1000']);
\usleep(300000);

$db = new Db($dir, ['durable' => false]);
$hasta = \microtime(true) + 2.0;
while (\microtime(true) < $hasta) {
    try {
        $db->transaction(static function ($tx): void {
            $a = $tx->get('cuentas', 'a');
            $b = $tx->get('cuentas', 'b');
            $tx->update('cuentas', 'a', ['saldo' => (int) $a['saldo'] - 1]);
            $tx->update('cuentas', 'b', ['saldo' => (int) $b['saldo'] + 1]);
        });
    } catch (\Throwable) {
    }
}
waitFor($h);

$lector = estado($dir . '/_sec_lector.json');
$leidas = (int) ($lector['leidas'] ?? 0);
$partidos = (int) ($lector['partidos'] ?? -1);
echo "    {$leidas} lecturas | documentos rotos: " . ($lector['rotos'] ?? '?')
   . " | sumas descuadradas: {$partidos}"
   . ($leidas > 0 ? ' (' . \round($partidos * 100 / \max(1, $leidas), 2) . '%)' : '') . "\n";

eq('ningun lector vio un documento a medias o ilegible', 0, (int) ($lector['rotos'] ?? -1));
ok('el saldo total al terminar sigue siendo 1000',
    1000 === (int) ($db->get('cuentas', 'a')['saldo'] ?? 0) + (int) ($db->get('cuentas', 'b')['saldo'] ?? 0));

$db->storage()->close();
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * F] Driver empaquetado: el mapa de desplazamientos se cachea y no se relee
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * PackedDriver coge un cerrojo exclusivo por coleccion antes de escribir, asi
 * que parece a salvo de dos escritores. Pero Offsets carga el mapa una sola vez
 * (`$this->mapa ??= ...`) y no lo vuelve a leer nunca, ni siquiera despues de
 * coger el cerrojo. Cada proceso trabaja sobre la foto que hizo al arrancar.
 *
 * Tres consecuencias, de menos a mas grave.
 */
section('F1] Modificar un documento que otro proceso acaba de cambiar');

$dir = tmpdir('sec_packed1');
$db  = new Db($dir, ['durable' => false]);
$db->storage()->declareDriver('p', 'packed');
$db->put('p', 'x', ['a' => 1]);                     // el mapa queda cacheado aqui

waitFor(spawn(__DIR__ . '/_worker_sec_packed.php', [$dir, 'p', 'merge', 'x', 'b']));

$db->put('p', 'x', ['a' => 2]);                     // fusiona sobre su foto vieja
$db->storage()->close();

$final = new Db($dir, ['durable' => false]);
$doc   = $final->get('p', 'x');
echo '    documento final: ' . \json_encode(\array_diff_key($doc ?? [], \array_flip(['_createdAt', '_updatedAt']))) . "\n";

eq('la modificacion del otro proceso sigue en el documento', 1, (int) ($doc['b'] ?? -1));
eq('y la propia tambien', 2, (int) ($doc['a'] ?? -1));

$final->storage()->close();
rmrf($dir);

/* ───────────────────────────────────────────────────────────────────────── */
section('F2] La consolidacion del indice de desplazamientos con el mapa viejo');

/*
 * Cuando el log suelto pasa de 500 apuntes, el proceso que escribe vuelca su
 * mapa a la instantanea y TRUNCA el log. Si su mapa esta desfasado, el volcado
 * es una foto vieja y el truncado borra los apuntes de los demas. Los
 * documentos siguen en data.axi, pero ya no los apunta nadie: invisibles.
 */
$dir = tmpdir('sec_packed2');
$db  = new Db($dir, ['durable' => false]);
$db->storage()->declareDriver('q', 'packed');
for ($i = 0; $i < 450; $i++) {
    $db->put('q', 'base' . $i, ['n' => $i]);
}
$db->storage()->close();

$h = spawn(__DIR__ . '/_worker_sec_packed.php', [$dir, 'q', 'carga', 60, 'w']);
ok('el segundo proceso carga su mapa antes de que el primero siga escribiendo',
    esperaArchivo($dir . '/_sec_listo_w', 20.0));

$db = new Db($dir, ['durable' => false]);
for ($i = 0; $i < 100; $i++) {
    $db->put('q', 'tarde' . $i, ['n' => $i]);
}
$db->storage()->close();
\file_put_contents($dir . '/_sec_go_w', '1');
waitFor($h);

$final = new Db($dir, ['durable' => false]);
$hay   = $final->count('q');
echo "    esperados 610 documentos (450 + 100 + 60), hay {$hay}\n";
eq('no se perdio ni un documento al consolidar con el mapa desfasado', 610, $hay);

$final->storage()->close();
rmrf($dir);

/* ───────────────────────────────────────────────────────────────────────── */
section('F3] Compactar con el mapa desfasado');

/*
 * Compactar reescribe data.axi dejando solo lo que el mapa dice que esta vivo,
 * y despues BORRA offsets.log. Si el mapa no incluye lo que otro proceso
 * escribio mientras tanto, esos documentos no se copian al archivo nuevo y su
 * unica referencia se borra. Es la unica operacion del driver que destruye
 * bytes, y por eso es la que mas duele.
 */
$dir = tmpdir('sec_packed3');
$db  = new Db($dir, ['durable' => false]);
$db->storage()->declareDriver('c', 'packed');
for ($i = 0; $i < 20; $i++) {
    $db->put('c', 'd' . $i, ['n' => $i]);
}
$db->count('c');                                   // el mapa del padre, cacheado

waitFor(spawn(__DIR__ . '/_worker_sec_packed.php', [$dir, 'c', 'inserta', 10, 'nuevo']));

$db->storage()->compact('c');                      // compacta sin ver los 10 nuevos
$db->storage()->close();

$final = new Db($dir, ['durable' => false]);
$hay   = $final->count('c');
echo "    esperados 30 documentos (20 + 10), hay {$hay}\n";
eq('compactar no borro los documentos escritos por el otro proceso', 30, $hay);

$final->storage()->close();
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * G] Migrar de driver mientras otro escribe
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Migration lee todo con el driver de origen, lo escribe con el de destino, y
 * despues borra los archivos del origen. No coge ningun cerrojo en ningun
 * momento. Lo que entre entre el paso 1 y el paso 3 se borra sin haberse
 * copiado, y lo que el otro proceso escriba despues va a un formato que ya
 * nadie lee.
 */
section('G] Migracion de driver con un escritor en marcha');

$dir = tmpdir('sec_migracion');
$db  = new Db($dir, ['durable' => false]);
for ($i = 0; $i < 50; $i++) {
    $db->put('m', 'base' . $i, ['n' => $i]);
}
$db->storage()->close();

$h = spawn(__DIR__ . '/_worker_sec_plano.php', [$dir, 'm', 'nuevos', 'mig', '2.0']);
\usleep(700000);

$db = new Db($dir, ['durable' => false]);
$migrados = $db->storage()->migrateTo('m', 'packed');
$db->storage()->close();
waitFor($h);

$escritor = estado($dir . '/_sec_plano_mig.json');
$esperados = 50 + (int) ($escritor['escritos'] ?? 0);

$final = new Db($dir, ['durable' => false]);
$hay   = $final->count('m');
echo "    el escritor metio {$escritor['escritos']} documentos; migrados {$migrados};"
   . " esperados {$esperados}, quedan {$hay}\n";

eq('migrar mientras otro escribe no pierde documentos', $esperados, $hay);

$final->storage()->close();
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * H] Copia de seguridad mientras se escribe
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * La copia inventaria los archivos —y les calcula el sha1— antes de meterlos en
 * el contenedor, uno detras de otro y en orden alfabetico. Con el driver
 * empaquetado eso significa copiar data.axi primero y offsets.log despues: si
 * entre medias alguien escribe, los desplazamientos copiados apuntan a bytes
 * que no estan en la copia del data.axi.
 *
 * Lo que se exige no es que la copia sea de un instante exacto —eso pide
 * congelar la base—, sino que sea COHERENTE: que todo lo que la copia dice
 * tener se pueda leer al restaurarla.
 */
section('H] Copia con el driver empaquetado bajo escritura');

$dir = tmpdir('sec_copia');
$db  = new Db($dir, ['durable' => false]);
$db->storage()->declareDriver('b', 'packed');
for ($i = 0; $i < 200; $i++) {
    $db->put('b', 'base' . $i, ['n' => $i, 'carga' => \str_repeat('x', 200)]);
}
$db->storage()->close();

$copias = $dir . '_copias';
@\mkdir($copias, 0777, true);

// Tres copias mientras el escritor trabaja: una sola sale coherente de vez en
// cuando y eso no dice nada. Lo que importa es cuantas de tres salen rotas.
$h = spawn(__DIR__ . '/_worker_sec_plano.php', [$dir, 'b', 'nuevos', 'cop', '3.0']);
\usleep(400000);

$db      = new Db($dir, ['durable' => false]);
$archivos = [];
for ($i = 0; $i < 3; $i++) {
    $archivos[] = $db->backup($copias)['archivo'];
    \usleep(600000);
}
$db->storage()->close();
waitFor($h);

$incoherentes = 0;
foreach ($archivos as $n => $archivo) {
    $destino = $dir . '_restaurado' . $n;
    @\mkdir($destino, 0777, true);
    $rest = new Db($destino, ['durable' => false]);
    $rest->restore($archivo);

    $dice   = $rest->count('b');
    $leidos = \count($rest->all('b'));
    if ($dice !== $leidos) {
        $incoherentes++;
    }
    echo "    copia {$n}: dice tener {$dice} documentos y se pueden leer {$leidos}"
       . ($dice === $leidos ? "\n" : "  <- " . ($dice - $leidos) . " sin bytes detras\n");

    $rest->storage()->close();
    rmrf($destino);
}

eq('ninguna de las tres copias salio incoherente', 0, $incoherentes);

rmrf($dir);
rmrf($copias);

/* ═══════════════════════════════════════════════════════════════════════════
 * I] Cerrojos: que pasa si alguien no lo suelta, y si muere teniendolo
 * ═══════════════════════════════════════════════════════════════════════════
 */
section('I] Cerrojo de confirmacion retenido y proceso muerto con el cogido');

$dir = tmpdir('sec_cerrojo');
$db  = new Db($dir, ['durable' => false]);
$db->put('cosas', 'x', ['n' => 0]);
$db->storage()->close();

// Un proceso cualquiera que coja el cerrojo de confirmacion y no lo suelte.
$retenedor = $dir . '_retenedor.php';
\file_put_contents($retenedor, <<<'PHP'
<?php
$fp = fopen($argv[1] . '/_tx.lock', 'c');
flock($fp, LOCK_EX);
file_put_contents($argv[2], '1');
$hasta = microtime(true) + (float) $argv[3];
while (microtime(true) < $hasta) { usleep(20000); }
PHP);

$aviso = $dir . '_cogido';
$h     = spawn($retenedor, [$dir, $aviso, '2.0']);
ok('el retenedor coge el cerrojo de confirmacion', esperaArchivo($aviso, 15.0));

$db      = new Db($dir, ['durable' => false]);
$empezo  = \microtime(true);
$db->transaction(static function ($tx): void {
    $tx->update('cosas', 'x', ['n' => 1]);
});
$espera = \microtime(true) - $empezo;
waitFor($h);

echo '    la transaccion espero ' . \round($espera, 2) . " s a que soltaran el cerrojo (no hay tope de espera)\n";
eq('la transaccion se aplica en cuanto se suelta el cerrojo', 1, (int) ($db->get('cosas', 'x')['n'] ?? -1));
ok('esperar al cerrojo no rompe nada, solo cuesta tiempo', $espera > 0.5);

// Ahora se mata al retenedor con el cerrojo cogido: el sistema tiene que soltarlo.
@\unlink($aviso);
$h = spawn($retenedor, [$dir, $aviso, '20']);
ok('el segundo retenedor coge el cerrojo', esperaArchivo($aviso, 15.0));
killNow($h);

$empezo = \microtime(true);
$db->transaction(static function ($tx): void {
    $tx->update('cosas', 'x', ['n' => 2]);
});
$tras = \microtime(true) - $empezo;
echo '    tras matar al retenedor, la siguiente transaccion tardo ' . \round($tras, 2) . " s\n";
eq('matar a quien tiene el cerrojo no lo deja cogido para siempre', 2, (int) ($db->get('cosas', 'x')['n'] ?? -1));
ok('y no hubo que esperar', $tras < 3.0);

$db->storage()->close();
rmrf($dir);
@\unlink($retenedor);
@\unlink($aviso);

echo "\n  (tiempo total: " . \round(\microtime(true) - $t0, 1) . " s)\n";

summary();
