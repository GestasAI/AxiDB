<?php
/**
 * AxiDB - auditoria ofensiva del cifrado en reposo.
 *
 * test_cifrado.php comprueba que el cifrado FUNCIONA. Esto comprueba que no se
 * puede DESHACER desde fuera. Son dos preguntas distintas y la segunda es la
 * que decide si el cifrado sirve de algo: un atacante que se lleva el disco, o
 * que consigue escribir en el directorio de datos, no va a pedirle el dato a la
 * API, va a tocar los archivos.
 *
 * El modelo de atacante de todo este archivo es siempre el mismo y conviene
 * tenerlo delante: LEE Y ESCRIBE EL DIRECTORIO DE DATOS, PERO NO SABE LA
 * CONTRASEÑA. No hay red por medio. Es el caso del portatil robado, la copia de
 * seguridad en un bucket abierto, el hosting compartido y el proceso vecino.
 *
 * Todo lo que se afirma aqui se afirma como comportamiento SEGURO. Un [FAIL] es
 * un ataque que ha funcionado, no un test mal escrito.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Crypto\Box;
use Axi\Core\Db;

const CLAVE   = 'una contraseña larga y con espacios';
const SECRETO = 'ZZSECRETOZZ';

/** Todos los bytes escritos bajo un directorio. Sin pasar por AxiDB. */
function todosLosBytes(string $dir): string
{
    $todo = '';
    foreach (recorrer($dir) as $f) {
        $todo .= (string) @\file_get_contents($f);
    }
    return $todo;
}

/** Todas las rutas de archivo. El NOMBRE tambien es un canal de fuga. */
function todasLasRutas(string $dir): array
{
    $rutas = [];
    foreach (recorrer($dir) as $f) {
        $rutas[] = \str_replace('\\', '/', $f);
    }
    return $rutas;
}

/** @return list<string> */
function recorrer(string $dir): array
{
    if (!\is_dir($dir)) {
        return [];
    }
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

function leerDoc(string $path): array
{
    return (array) \json_decode((string) \file_get_contents($path), true);
}

function escribirDoc(string $path, array $doc): void
{
    \file_put_contents($path, \json_encode($doc));
}

/** El IV de un bloque cerrado, en hexadecimal. Los 12 primeros bytes. */
function ivDe(string $bloque): string
{
    return \bin2hex(\substr((string) \base64_decode(\substr($bloque, 5), true), 0, 12));
}

/** Una base recien hecha, cifrada, con la clave puesta. */
function baseCifrada(string $nombre, string $coleccion = 'fichas'): array
{
    $dir = tmpdir($nombre);
    $db  = new Db($dir, ['durable' => false, 'key' => CLAVE]);
    $db->encrypt($coleccion);
    return [$dir, $db];
}

if (!Box::haySoporte()) {
    section('Cripto');
    ok('sin openssl no hay nada que auditar aqui', true);
    summary();
}

/* ═══════════════════════════════════════════════════════════════════════════
 * 1. NONCE
 *
 * Con GCM, repetir el nonce con la misma clave no es una debilidad teorica: el
 * XOR de dos textos cifrados con el mismo par (clave, nonce) revela el XOR de
 * los textos en claro, y ademas se filtra la clave de autenticacion, lo que
 * permite FALSIFICAR bloques. Un solo nonce repetido rompe las dos garantias.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('1. Nonce: cada bloque con el suyo');

[$dir, $db] = baseCifrada('sec_nonce');
for ($i = 0; $i < 200; $i++) {
    $db->insert('fichas', ['texto' => 'exactamente el mismo contenido'], 'd' . $i);
}
$ivs = $bloques = [];
for ($i = 0; $i < 200; $i++) {
    $doc       = leerDoc($dir . '/fichas/d' . $i . '.json');
    $bloques[] = (string) $doc['_cif'];
    $ivs[]     = ivDe((string) $doc['_cif']);
}
eq('200 documentos identicos dan 200 nonces distintos', 200, \count(\array_unique($ivs)));
eq('y 200 textos cifrados distintos', 200, \count(\array_unique($bloques)));
ok('el nonce ocupa los 12 bytes que pide GCM', \strlen($ivs[0]) === 24);
$db->storage()->close();
rmrf($dir);

// Y a pelo sobre la caja, por si el motor solo cifra una vez por documento.
$caja = new Box(\random_bytes(32));
$ivs  = [];
for ($i = 0; $i < 500; $i++) {
    $ivs[] = ivDe($caja->seal('mismo texto', 'mismo contexto'));
}
eq('500 sellados seguidos de la misma caja: 500 nonces distintos', 500, \count(\array_unique($ivs)));

/* ═══════════════════════════════════════════════════════════════════════════
 * 2. AUTENTICACION DEL BLOQUE
 * ═══════════════════════════════════════════════════════════════════════════ */
section('2. El bloque esta autenticado');

$bloque = $caja->seal('{"saldo":100}', 'ctx');
$crudo  = (string) \base64_decode(\substr($bloque, 5), true);

throws('un byte cambiado no abre', static function () use ($caja, $crudo) {
    $tocado = $crudo;
    $tocado[\strlen($tocado) - 1] = \chr(\ord($tocado[\strlen($tocado) - 1]) ^ 1);
    $caja->open('axi1:' . \base64_encode($tocado), 'ctx');
});
throws('un bloque truncado no abre', static fn () =>
    $caja->open('axi1:' . \base64_encode(\substr($crudo, 0, -3)), 'ctx'));
throws('el tag cambiado no abre', static function () use ($caja, $crudo) {
    $tocado = \substr($crudo, 0, 12) . \str_repeat("\x00", 16) . \substr($crudo, 28);
    $caja->open('axi1:' . \base64_encode($tocado), 'ctx');
});
throws('con otro contexto no abre', static fn () => $caja->open($bloque, 'otro'));
throws('sin contexto tampoco', static fn () => $caja->open($bloque, ''));

/* ═══════════════════════════════════════════════════════════════════════════
 * 3. ATADURA AL SITIO
 *
 * El AAD dice ser 'axidb:doc:v1:<coleccion>\0<id>'. La coleccion la pone quien
 * pregunta; el id sale de DENTRO del archivo, que es justo lo que el atacante
 * controla. Ahi esta la grieta.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('3. Un documento no abre en el sitio de otro');

[$dir, $db] = baseCifrada('sec_sitio', 'usuarios');
$db->insert('usuarios', ['rol' => 'admin', 'sueldo' => 90000], 'jefe');
$db->insert('usuarios', ['rol' => 'invitado', 'sueldo' => 0], 'intruso');
$db->encrypt('otras');
$db->insert('otras', ['x' => 1], 'jefe');
$db->storage()->close();

$delJefe = leerDoc($dir . '/usuarios/jefe.json');

// (a) Solo el bloque: esto YA lo para el AAD.
$victima = leerDoc($dir . '/usuarios/intruso.json');
$victima['_cif'] = $delJefe['_cif'];
escribirDoc($dir . '/usuarios/intruso.json', $victima);
throws('injertar solo el bloque cerrado de otro documento se rechaza',
    static fn () => (new Db($dir, ['durable' => false, 'key' => CLAVE]))->get('usuarios', 'intruso'));

$otra = leerDoc($dir . '/otras/jefe.json');
$otra['_cif'] = $delJefe['_cif'];
escribirDoc($dir . '/otras/jefe.json', $otra);
throws('ni con el mismo id en otra coleccion',
    static fn () => (new Db($dir, ['durable' => false, 'key' => CLAVE]))->get('otras', 'jefe'));

// (b) El ARCHIVO ENTERO. El atacante se lleva tambien el id de dentro, y el
//     motor usa ESE id para calcular el AAD en vez del que le han pedido.
//     Resultado: el bloque abre en un sitio donde no nacio.
\copy($dir . '/usuarios/jefe.json', $dir . '/usuarios/intruso.json');
$db2 = new Db($dir, ['durable' => false, 'key' => CLAVE]);
throws('copiar el ARCHIVO entero del jefe sobre el del intruso se rechaza',
    static fn () => $db2->get('usuarios', 'intruso'));

$leido = null;
try {
    $leido = $db2->get('usuarios', 'intruso');
} catch (\Throwable) {
}
eq('pedir el documento "intruso" nunca devuelve otro id', 'intruso', $leido['id'] ?? 'intruso');

$ids = \array_column($db2->all('usuarios'), 'id');
eq('y la coleccion no puede tener dos veces el mismo id', \count($ids), \count(\array_unique($ids)));
$db2->storage()->close();
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * 4. DEGRADACION A TEXTO PLANO
 *
 * El ataque mas barato de todos: en vez de romper el cifrado, quitarlo. Si el
 * motor acepta un documento sin bloque cerrado dentro de una coleccion que dice
 * estar cifrada, el atacante no necesita la clave para ESCRIBIR: escribe en
 * claro y el motor se lo sirve a la aplicacion como si fuera dato bueno.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('4. No se puede apagar el cifrado desde fuera');

[$dir, $db] = baseCifrada('sec_degradar');
$db->insert('fichas', ['rol' => 'invitado', 'saldo' => 1], 'u1');
$db->storage()->close();

// (a) Sin campo _cif, con los campos en claro puestos por el atacante.
$doc = leerDoc($dir . '/fichas/u1.json');
unset($doc['_cif']);
$doc['rol']   = 'admin';
$doc['saldo'] = 999999;
escribirDoc($dir . '/fichas/u1.json', $doc);
throws('un documento SIN bloque cerrado en una coleccion cifrada se rechaza',
    static fn () => (new Db($dir, ['durable' => false, 'key' => CLAVE]))->get('fichas', 'u1'));

// (b) Con el campo presente pero sin ser un bloque: misma puerta, otro picaporte.
foreach (['' => 'cadena vacia', 'null' => 'nulo'] as $marca => $nombre) {
    $doc['_cif'] = $marca === 'null' ? null : '';
    escribirDoc($dir . '/fichas/u1.json', $doc);
    throws("con _cif a {$nombre} tampoco pasa el contenido en claro",
        static fn () => (new Db($dir, ['durable' => false, 'key' => CLAVE]))->get('fichas', 'u1'));
}
rmrf($dir);

// (c) El interruptor de _axidb.json. Es un archivo de texto sin firmar.
[$dir, $db] = baseCifrada('sec_interruptor');
$db->insert('fichas', ['nota' => SECRETO], 'u1');
$db->storage()->close();

$ajustes = $dir . '/fichas/_axidb.json';
$conf    = leerDoc($ajustes);
$conf['encrypted'] = false;
escribirDoc($ajustes, $conf);

$db2 = new Db($dir, ['durable' => false, 'key' => CLAVE]);
ok('editar encrypted:false a mano no debe desactivar el cifrado', $db2->isEncrypted('fichas'));
$db2->insert('fichas', ['nota' => 'ZZINYECTADAZZ'], 'u2');
$db2->storage()->close();
ok('un documento escrito con el interruptor apagado no debe quedar en claro',
    !\str_contains(todosLosBytes($dir), 'ZZINYECTADAZZ'));

$conf['encrypted'] = true;
escribirDoc($ajustes, $conf);
throws('y al volver a encenderlo, ese documento en claro no debe servirse como bueno',
    static fn () => (new Db($dir, ['durable' => false, 'key' => CLAVE]))->get('fichas', 'u2'));
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * 5. REVERSION
 *
 * Un bloque antiguo es criptograficamente impecable: misma clave, mismo AAD,
 * tag valido. Nada dentro del bloque dice CUANDO se cerro. Reponer una version
 * vieja es, por tanto, indistinguible de no haber tocado nada. Importa cuando
 * el documento es un saldo, un permiso revocado o una contraseña rotada.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('5. Reponer una version antigua se detecta');

[$dir, $db] = baseCifrada('sec_reversion');
$db->insert('fichas', ['saldo' => 100, 'rol' => 'admin'], 'u1');
$archivo = $dir . '/fichas/u1.json';
$viejo   = (string) \file_get_contents($archivo);
$db->update('fichas', 'u1', ['saldo' => 0, 'rol' => 'invitado']);
$nuevo = leerDoc($archivo);
$db->storage()->close();

\file_put_contents($archivo, $viejo);
$db2 = new Db($dir, ['durable' => false, 'key' => CLAVE]);
eq('reponer el archivo anterior entero no devuelve el saldo viejo', 0,
    $db2->get('fichas', 'u1')['saldo'] ?? 0);
$db2->storage()->close();

// La variante fina: el bloque viejo, con la version NUEVA en claro encima. Ni
// siquiera queda el rastro de un _version que retrocede.
$nuevo['_cif'] = leerDoc($archivo)['_cif'];
escribirDoc($archivo, $nuevo);
$db3 = new Db($dir, ['durable' => false, 'key' => CLAVE]);
eq('injertar el bloque viejo bajo la version nueva tampoco cuela', 0,
    $db3->get('fichas', 'u1')['saldo'] ?? 0);
$db3->storage()->close();
rmrf($dir);

// En packed el bloque viejo ni hay que guardarlo: se queda en el propio log.
$dir = tmpdir('sec_reversion_packed');
$db  = new Db($dir, ['durable' => false, 'key' => CLAVE]);
$db->storage()->declareDriver('fichas', 'packed');
$db->encrypt('fichas');
$db->insert('fichas', ['saldo' => 100], 'u1');
$db->update('fichas', 'u1', ['saldo' => 0]);
$db->storage()->close();
eq('tras actualizar, el log no conserva el bloque anterior listo para reponer',
    1, \substr_count((string) \file_get_contents($dir . '/fichas/data.axi'), 'axi1:'));
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * 6. DERIVACION DE CLAVE
 * ═══════════════════════════════════════════════════════════════════════════ */
section('6. De la contraseña a la clave');

[$dirA, $dbA] = baseCifrada('sec_kdf_a');
$dbA->insert('fichas', ['n' => 1], 'u1');
$dbA->storage()->close();
[$dirB, $dbB] = baseCifrada('sec_kdf_b');
$dbB->insert('fichas', ['n' => 1], 'u1');
$dbB->storage()->close();

$llaveroA = leerDoc($dirA . '/_cifrado.json');
$llaveroB = leerDoc($dirB . '/_cifrado.json');

eq('la funcion de derivacion es PBKDF2-SHA256', 'pbkdf2-sha256', $llaveroA['kdf'] ?? null);
ok('con al menos las 210.000 vueltas que recomienda OWASP',
    (int) ($llaveroA['iteraciones'] ?? 0) >= 210000);
eq('la sal mide 16 bytes', 16, \strlen((string) \base64_decode((string) $llaveroA['sal'], true)));
ok('la sal es por base de datos: la misma contraseña no da la misma clave en dos instalaciones',
    $llaveroA['sal'] !== $llaveroB['sal']);
ok('y no es una sal fija ni previsible',
    (string) \base64_decode((string) $llaveroA['sal'], true) !== \str_repeat("\x00", 16));

// Iteraciones por debajo del minimo: rebota.
$conf = $llaveroA;
$conf['iteraciones'] = 1;
escribirDoc($dirA . '/_cifrado.json', $conf);
throws('bajar las iteraciones a 1 se rechaza',
    static fn () => (new Db($dirA, ['durable' => false, 'key' => CLAVE]))->get('fichas', 'u1'));

// Iteraciones por ENCIMA de lo razonable: no hay tope. El numero sale de un
// archivo que el atacante escribe y se GASTA antes de comprobar nada, asi que
// no vale con que luego el comprobante falle: el trabajo ya se hizo. Con 10^9
// el proceso se queda ahi. Es un apagon gratis para quien pueda tocar un byte.
//
// Se mide contra el coste normal en esta misma maquina para que la medida
// signifique lo mismo en un portatil que en la CI.
$medir = static function (string $dir): float {
    $t0 = \microtime(true);
    try {
        (new Db($dir, ['durable' => false, 'key' => CLAVE]))->get('fichas', 'u1');
    } catch (\Throwable) {
    }
    return \microtime(true) - $t0;
};
escribirDoc($dirA . '/_cifrado.json', $llaveroA);
$normal = $medir($dirA);

$conf['iteraciones'] = 3000000;                  // 14 veces el parametro legitimo
escribirDoc($dirA . '/_cifrado.json', $conf);
$absurdo = $medir($dirA);

ok('un numero de iteraciones absurdo se rechaza en vez de ejecutarse ('
    . \round($normal, 2) . ' s -> ' . \round($absurdo, 2) . ' s)',
    $absurdo < \max(0.5, $normal * 4));
rmrf($dirA);
rmrf($dirB);

/* ═══════════════════════════════════════════════════════════════════════════
 * 7. EL LLAVERO
 * ═══════════════════════════════════════════════════════════════════════════ */
section('7. Manipular el llavero');

$sembrar = static function (string $nombre): array {
    [$d, $x] = baseCifrada($nombre);
    $x->insert('fichas', ['n' => 1], 'u1');
    $x->storage()->close();
    return [$d, (string) \file_get_contents($d . '/_cifrado.json')];
};

[$dir, $original] = $sembrar('sec_llavero');
$danyos = [
    'cambiar la sal' => static function (array $c): array {
        $c['sal'] = \base64_encode(\str_repeat("\x01", 16));
        return $c;
    },
    'romper el comprobante' => static function (array $c): array {
        $c['comprobante'] = \substr((string) $c['comprobante'], 0, -8) . 'AAAAAAA=';
        return $c;
    },
    'borrar el comprobante' => static function (array $c): array {
        unset($c['comprobante']);
        return $c;
    },
    'dejarlo en basura' => static fn (array $c): array => ['esto' => 'no es un llavero'],
];
foreach ($danyos as $nombre => $fn) {
    \file_put_contents($dir . '/_cifrado.json', (string) \json_encode($fn((array) \json_decode($original, true))));
    throws("{$nombre} hace que la base se niegue a abrir",
        static fn () => (new Db($dir, ['durable' => false, 'key' => CLAVE]))->get('fichas', 'u1'));
}

// Borrarlo del todo. Aqui el motor no se niega: fabrica un llavero NUEVO con
// una sal nueva, y solo despues falla al abrir el primer documento. El mensaje
// culpa a la clave o al dato, que es justo donde el problema no esta. Peor:
// mientras el llavero nuevo este ahi, nada indica que el original hacia falta.
\file_put_contents($dir . '/_cifrado.json', $original);
\unlink($dir . '/_cifrado.json');
$mensaje = '';
try {
    (new Db($dir, ['durable' => false, 'key' => CLAVE]))->get('fichas', 'u1');
} catch (\Throwable $e) {
    $mensaje = $e->getMessage();
}
ok('con el llavero borrado y datos cifrados dentro, no se fabrica uno nuevo en silencio',
    !\is_file($dir . '/_cifrado.json'));
ok('y el error dice que falta el llavero, no que la clave sea mala',
    \str_contains(\strtolower($mensaje), 'llavero') || \stripos($mensaje, 'keyring') !== false);
rmrf($dir);

$fuente = (string) \file_get_contents(\dirname(__DIR__) . '/Crypto/Keyring.php');
ok('el comprobante se compara en tiempo constante (hash_equals)',
    \str_contains($fuente, 'hash_equals('));

/* ═══════════════════════════════════════════════════════════════════════════
 * 8. ORACULO POR MENSAJE DE ERROR
 *
 * Si "clave incorrecta" y "dato manipulado" se distinguen, el atacante puede
 * usar el motor como oraculo: probar contraseñas y saber cuando acierta sin
 * tener que descifrar nada. GCM no debe contar cual de las dos cosas fallo.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('8. El error no dice cual de las dos cosas fallo');

$claveA = new Box(\random_bytes(32));
$claveB = new Box(\random_bytes(32));
$bueno  = $claveA->seal('{"a":1}', 'ctx');
$roto   = 'axi1:' . \base64_encode(\substr((string) \base64_decode(\substr($bueno, 5), true), 0, -2) . 'zz');

$porClave = $porDato = '';
try {
    $claveB->open($bueno, 'ctx');
} catch (\Throwable $e) {
    $porClave = $e->getMessage();
}
try {
    $claveA->open($roto, 'ctx');
} catch (\Throwable $e) {
    $porDato = $e->getMessage();
}
eq('clave equivocada y dato manipulado dan el MISMO mensaje', $porClave, $porDato);
ok('y el mensaje no contiene ni la clave ni el texto',
    !\str_contains($porClave, 'a":1') && $porClave !== '');

/* ═══════════════════════════════════════════════════════════════════════════
 * 9. FUGAS FUERA DEL BLOQUE
 *
 * El bloque cerrado es solido. El problema es todo lo que el motor escribe
 * ALREDEDOR de el con el mismo dato dentro.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('9. Lo que se escapa por fuera del bloque');

// (a) El indice de una coleccion cifrada nombra sus archivos con sha1(valor).
//     Sin sal y sin clave: quien tenga el disco prueba un diccionario y sabe
//     que documentos valen 'moroso' sin descifrar ni un byte.
[$dir, $db] = baseCifrada('sec_indice');
$db->insert('fichas', ['estado' => 'moroso', 'correo' => 'jefe@empresa.es'], 'u1');
$db->insert('fichas', ['estado' => 'moroso', 'correo' => 'ana@empresa.es'], 'u2');
$db->index('fichas', 'estado');
$db->index('fichas', 'correo');
$db->storage()->close();

ok('adivinar el valor de un campo indexado no debe localizar su archivo de indice',
    !\is_file($dir . '/fichas/_idx/estado/h_' . \sha1('moroso') . '.json'));
ok('ni siquiera con un correo concreto',
    !\is_file($dir . '/fichas/_idx/correo/h_' . \sha1('jefe@empresa.es') . '.json'));
rmrf($dir);

// (b) Un indice creado ANTES de cifrar guarda el valor tal cual como nombre de
//     archivo, y encrypt() no lo reconstruye. El texto que el cifrado deberia
//     tapar se queda escrito en el arbol de directorios para siempre.
$dir = tmpdir('sec_indice_previo');
$db  = new Db($dir, ['durable' => false, 'key' => CLAVE]);
$db->insert('fichas', ['estado' => 'moroso'], 'u1');
$db->index('fichas', 'estado');
$db->unique('fichas', 'usuario');
$db->insert('fichas', ['estado' => 'moroso', 'usuario' => 'jefazo'], 'u2');
$db->encrypt('fichas');
$db->storage()->close();

$rutas = \implode('|', todasLasRutas($dir));
ok('cifrar borra el valor en claro que el indice dejo como nombre de archivo',
    !\str_contains($rutas, '/estado/moroso.json'));
ok('y tambien el que dejo la reserva de unicidad',
    !\str_contains($rutas, '/usuario/jefazo.json'));
rmrf($dir);

// (c) Los valores por defecto del esquema viven en _axidb.json, en claro, en la
//     misma carpeta de la coleccion cifrada.
[$dir, $db] = baseCifrada('sec_esquema');
$db->defineSchema('fichas', ['nivel' => ['tipo' => 'texto', 'defecto' => SECRETO]]);
$db->insert('fichas', [], 'u1');
$db->storage()->close();
ok('un valor por defecto del esquema no queda en claro en una coleccion cifrada',
    !\str_contains(todosLosBytes($dir), SECRETO));
rmrf($dir);

// (d) El registro de auditoria de los agentes copia el mensaje de error tal
//     cual, y el error de unicidad lleva el valor dentro.
[$dir, $db] = baseCifrada('sec_auditoria', 'clientes');
$db->unique('clientes', 'correo');
$db->insert('clientes', ['correo' => SECRETO . '@ejemplo.es'], 'c1');
$agente = $db->agent('robot', ['insert'], ['clientes']);
try {
    $agente->insert('clientes', ['correo' => SECRETO . '@ejemplo.es'], 'c2');
} catch (\Throwable) {
}
$db->storage()->close();
ok('el registro de auditoria no escribe en claro el valor de un campo cifrado',
    !\str_contains(todosLosBytes($dir), SECRETO));
rmrf($dir);

// (e) Lo que si aguanta.
[$dir, $db] = baseCifrada('sec_alrededor');
$db->insert('fichas', ['nota' => SECRETO], 'u1');

$doc = leerDoc($dir . '/fichas/u1.json');
$doc['rol'] = 'admin';
escribirDoc($dir . '/fichas/u1.json', $doc);
$db2 = new Db($dir, ['durable' => false, 'key' => CLAVE]);
ok('un campo en claro añadido junto a un bloque valido se ignora',
    !isset($db2->get('fichas', 'u1')['rol']));
$db2->storage()->close();

$carpeta = tmpdir('sec_copias');
$copia   = (new Db($dir, ['durable' => false, 'key' => CLAVE]))->backup($carpeta);
ok('la copia de seguridad conserva el cifrado y no contiene el texto',
    !\str_contains((string) \file_get_contents($copia['archivo']), SECRETO));

throws('exportar una coleccion cifrada sin la clave se niega en vez de volcar el bloque',
    static fn () => (new Db($dir, ['durable' => false]))->export('fichas', $carpeta . '/fuga.json'));
rmrf($carpeta);
rmrf($dir);

/* ═══════════════════════════════════════════════════════════════════════════
 * 10. LIMITES ASUMIDOS
 *
 * Esto no son fallos: son las consecuencias de cifrar por documento en vez de
 * cifrar el volumen. Se comprueban para que queden escritas y para que nadie
 * las descubra el dia equivocado.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('10. Lo que el cifrado por documento no puede tapar');

[$dir, $db] = baseCifrada('sec_limites');
$db->insert('fichas', ['nota' => SECRETO], 'nomina-de-julio');
$db->insert('fichas', ['nota' => SECRETO], 'u2');
$db->storage()->close();

$crudo = todosLosBytes($dir);
ok('el id se ve entero: no metas secretos en el id', \str_contains($crudo, 'nomina-de-julio'));
ok('las fechas se ven: se sabe cuando se escribio', \str_contains($crudo, '_createdAt'));

// Borrar un archivo es indistinguible de que nunca hubiera existido: no hay
// firma a nivel de coleccion que diga cuantos documentos deberia haber.
\unlink($dir . '/fichas/u2.json');
$db2 = new Db($dir, ['durable' => false, 'key' => CLAVE]);
eq('borrar un documento entero no se detecta, y esa es la frontera', 1, $db2->count('fichas'));
eq('el chequeo tampoco lo ve', [], $db2->checkup()['avisos']);
$db2->storage()->close();
rmrf($dir);

summary();
