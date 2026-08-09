<?php
/**
 * AxiDB - cifrado en reposo.
 *
 * La comprobacion que de verdad importa no es que la API devuelva el texto: es
 * que el texto NO ESTE en el disco. Todo lo que hay aqui que dice "no se ve"
 * abre los archivos y busca dentro, sin pasar por el motor. Fiarse de la propia
 * API para verificar el cifrado seria preguntarle al interesado.
 *
 * Se prueba con los dos drivers: el cifrado va por encima de ellos y tiene que
 * dar igual quien guarde debajo.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Cifrado\Caja;
use Axi\Core\Db;

const CLAVE  = 'una contraseña larga y con espacios';
const SECRETO = 'ZZSECRETOZZ';

/** Todo lo que hay escrito bajo un directorio, concatenado. Sin pasar por AxiDB. */
function bytesEnDisco(string $dir): string
{
    $todo = '';
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $todo .= (string) @\file_get_contents($f->getPathname());
        }
    }
    return $todo;
}

/** Los nombres de archivo, que tambien pueden delatar un valor. */
function nombresEnDisco(string $dir): array
{
    $nombres = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        $nombres[] = $f->getFilename();
    }
    return $nombres;
}

/* ─────────────────────────────────────────────────────────────────────────── */

if (!Caja::haySoporte()) {
    section('Cifrado');
    ok('sin openssl no se puede probar el cifrado; el resto de AxiDB no lo necesita', true);
    summary();
}

foreach (['fs', 'packed'] as $driver) {
    section("Cifrado sobre el driver {$driver}");

    $dir = tmpdir('cifrado_' . $driver);
    $db  = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
    if ($driver !== 'fs') {
        $db->storage()->declararDriver('fichas', $driver);
    }

    // Un documento ANTES de cifrar: al activar el cifrado tiene que reescribirse.
    $db->insert('fichas', ['nombre' => 'juan.perez', 'nota' => SECRETO . '-viejo'], 'f1');
    ok('antes de cifrar, el secreto se ve en el disco', \str_contains(bytesEnDisco($dir), SECRETO));

    eq('cifrar reescribe el documento que ya habia', 1, $db->cifrar('fichas'));
    ok('la coleccion queda marcada como cifrada', $db->estaCifrada('fichas'));

    $db->insert('fichas', ['nombre' => 'ana.lopez', 'nota' => SECRETO . '-nuevo', 'saldo' => 1200.0], 'f2');
    $db->index('fichas', 'nombre');

    /* ─── Lo que importa: mirar el disco ─────────────────────────────────── */
    $crudo = bytesEnDisco($dir);
    ok('el secreto ya no aparece en ningun archivo', !\str_contains($crudo, SECRETO));

    // Esta es la que fallo la primera vez, y solo en packed: el log solo añade,
    // asi que la version en claro seguia en el archivo detras de la cifrada.
    ok('ni el rastro del que ya estaba antes de cifrar (packed: el log se compacta)',
        !\str_contains($crudo, SECRETO . '-viejo'));
    ok('ni el valor indexado aparece como texto', !\str_contains($crudo, 'ana.lopez'));

    $nombres = nombresEnDisco($dir);
    ok('el indice no publica el valor como nombre de archivo',
        !\in_array('ana.lopez.json', $nombres, true) && !\in_array('juan.perez.json', $nombres, true));

    ok('los metadatos si quedan en claro, y se dice: id visible', \str_contains($crudo, '"id"'));

    /* ─── Y aun asi la base funciona igual ───────────────────────────────── */
    eq('se lee lo que se guardo', SECRETO . '-nuevo', $db->get('fichas', 'f2')['nota']);
    eq('el float sigue siendo float al volver', 1200.0, $db->get('fichas', 'f2')['saldo']);
    eq('el documento anterior al cifrado se lee igual', SECRETO . '-viejo', $db->get('fichas', 'f1')['nota']);
    eq('el indice encuentra por valor exacto', 'f2', $db->by('fichas', 'nombre', 'ana.lopez')[0]['id'] ?? null);
    eq('contar no necesita descifrar', 2, $db->count('fichas'));
    eq('las consultas filtran sobre el contenido', 'f2',
        $db->find('fichas')->where('saldo', '>', 1000)->first()['id'] ?? null);

    /* ─── La actualizacion parcial, que es donde se pierden datos ────────── */
    $db->update('fichas', 'f2', ['saldo' => 50.0]);
    $tras = $db->get('fichas', 'f2');
    eq('actualizar un campo no borra los demas', SECRETO . '-nuevo', $tras['nota'] ?? 'PERDIDA');
    eq('y el campo cambiado cambia', 50.0, $tras['saldo']);
    eq('la version sube', 2, $tras['_version']);

    $db->delete('fichas', 'f1');
    eq('borrar funciona igual', 1, $db->count('fichas'));

    $db->storage()->cerrar();

    /* ─── Reabrir: con la clave, sin ella y con otra ─────────────────────── */
    $otra = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
    eq('cerrar y reabrir con la clave devuelve el dato', SECRETO . '-nuevo', $otra->get('fichas', 'f2')['nota']);
    $otra->storage()->cerrar();

    throws('sin clave, leer una coleccion cifrada se niega en vez de devolver basura',
        static fn () => (new Db($dir, ['durable' => false]))->get('fichas', 'f2'));

    throws('con la clave equivocada, tambien',
        static fn () => (new Db($dir, ['durable' => false, 'clave' => 'otra distinta']))->get('fichas', 'f2'));

    rmrf($dir);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('Un byte cambiado se detecta');

/*
 * GCM autentica ademas de cifrar. Sin eso, quien pudiera escribir en la carpeta
 * podria alterar un documento y el motor devolveria el resultado como bueno.
 */
$dir = tmpdir('cifrado_manipulado');
$db  = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
$db->cifrar('fichas');
$db->insert('fichas', ['nota' => 'intacta'], 'f1');
$db->storage()->cerrar();

$archivo = $dir . '/fichas/f1.json';
ok('el documento esta donde se espera', \is_file($archivo));

$doc = \json_decode((string) \file_get_contents($archivo), true);
ok('y lo que hay guardado es un bloque cerrado, no el texto', Caja::esBloque($doc['_cif'] ?? null));

// Un solo caracter del bloque, cambiado por otro valido en base64.
$bloque = (string) $doc['_cif'];
$pos    = \strlen($bloque) - 6;
$doc['_cif'] = \substr($bloque, 0, $pos) . ($bloque[$pos] === 'A' ? 'B' : 'A') . \substr($bloque, $pos + 1);
\file_put_contents($archivo, \json_encode($doc));

$db2 = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
throws('un byte cambiado hace que abrir falle, no que devuelva basura',
    static fn () => $db2->get('fichas', 'f1'));
$db2->storage()->cerrar();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('Un documento no se puede suplantar con otro');

/*
 * Sin atar el bloque a su sitio, quien pudiera escribir en la carpeta copiaria
 * el archivo del administrador encima del suyo: misma clave, descifra bien, y
 * se queda con los datos del otro. El dato asociado de GCM lo impide.
 */
$dir = tmpdir('cifrado_suplantacion');
$db  = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
$db->cifrar('fichas');
$db->insert('fichas', ['rol' => 'admin'], 'jefe');
$db->insert('fichas', ['rol' => 'invitado'], 'intruso');
$db->storage()->cerrar();

$delJefe = \json_decode((string) \file_get_contents($dir . '/fichas/jefe.json'), true);
$deIntruso = \json_decode((string) \file_get_contents($dir . '/fichas/intruso.json'), true);
$deIntruso['_cif'] = $delJefe['_cif'];               // el bloque del jefe, en el archivo del intruso
\file_put_contents($dir . '/fichas/intruso.json', \json_encode($deIntruso));

$db3 = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
throws('el bloque de un documento no abre en otro, aunque la clave sea la misma',
    static fn () => $db3->get('fichas', 'intruso'));
eq('y el original sigue leyendose', 'admin', $db3->get('fichas', 'jefe')['rol']);

// Lo mismo entre colecciones.
$db3->cifrar('otras');
$db3->insert('otras', ['x' => 1], 'jefe');
$db3->storage()->cerrar();
$copia = \json_decode((string) \file_get_contents($dir . '/otras/jefe.json'), true);
$copia['_cif'] = $delJefe['_cif'];
\file_put_contents($dir . '/otras/jefe.json', \json_encode($copia));

$db4 = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
throws('ni con el mismo id en otra coleccion', static fn () => $db4->get('otras', 'jefe'));
$db4->storage()->cerrar();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('Cifrado y vectores no se mezclan');

$dir = tmpdir('cifrado_vectores');
$db  = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
$db->cifrar('notas');
throws('no se activan vectores sobre una coleccion cifrada',
    static fn () => $db->vectores('notas'));

$db->vectores('libres');
$db->insert('libres', ['texto' => 'una nota cualquiera'], 'n1');
throws('ni se cifra una coleccion que ya tiene vectores',
    static fn () => $db->cifrar('libres'));
$db->storage()->cerrar();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('Migrar de driver no necesita la clave');

/*
 * El bloque cerrado viaja entero de un driver a otro. Que la migracion no tenga
 * que abrirlo no es casualidad: significa que mover datos y leerlos son
 * permisos distintos.
 */
$dir = tmpdir('cifrado_migracion');
$db  = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
$db->cifrar('fichas');
for ($i = 1; $i <= 5; $i++) {
    $db->insert('fichas', ['nota' => SECRETO . $i], 'f' . $i);
}
eq('se migran los cinco', 5, $db->storage()->migrarA('fichas', 'packed'));
eq('y siguen leyendose tras la migracion', SECRETO . '3', $db->get('fichas', 'f3')['nota']);
ok('sin que el secreto asome en el nuevo formato', !\str_contains(bytesEnDisco($dir), SECRETO));
$db->storage()->cerrar();
rmrf($dir);

/* ─────────────────────────────────────────────────────────────────────────── */
section('Lo que el cifrado NO tapa, dicho aqui');

$dir = tmpdir('cifrado_limites');
$db  = new Db($dir, ['durable' => false, 'clave' => CLAVE]);
$db->cifrar('fichas');
$db->insert('fichas', ['nota' => SECRETO], 'un-id-que-dice-algo');
$db->storage()->cerrar();

$crudo = bytesEnDisco($dir);
ok('el id se ve: no metas secretos en el id', \str_contains($crudo, 'un-id-que-dice-algo'));
ok('las fechas se ven: se sabe cuando se escribio', \str_contains($crudo, '"_createdAt"'));
ok('el numero de documentos se ve: no oculta cuantos hay', $db->count('fichas') === 1);
rmrf($dir);

summary();
