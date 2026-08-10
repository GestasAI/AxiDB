<?php
/**
 * AxiDB - cuando el disco dice que no.
 *
 * Un disco lleno, un directorio sin permiso de escritura o un archivo bloqueado
 * por una copia de seguridad son cosas que pasan en produccion. Lo que no puede
 * pasar es que el motor lo disimule: si una escritura no se puede completar, hay
 * que enterarse **y el documento anterior tiene que seguir intacto**.
 *
 * Lo peor que puede hacer una base de datos no es fallar. Es fallar y decir que
 * fue bien.
 *
 * Sobre el disco lleno: no se simula de verdad, porque llenar una particion
 * dentro de un test es peor remedio que enfermedad. Lo que si se prueba es la
 * forma en que un disco lleno se manifiesta —la escritura o el renombrado no se
 * pueden hacer— y esa es exactamente la ruta de codigo que importa.
 *
 * Aviso de portabilidad: en Windows, marcar un directorio como de solo lectura
 * no impide crear archivos dentro. El test lo comprueba en ejecucion en lugar de
 * suponerlo, y salta esa parte donde el sistema no la respeta.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Exception;

/**
 * True si un archivo de solo lectura impide ABRIRLO para escribir.
 *
 * Aqui entra tambien el caso de root, que se salta los permisos sin inmutarse.
 */
function archivosProtegen(string $base): bool
{
    $sonda = $base . '/sonda_archivo';
    @\file_put_contents($sonda, 'x');
    @\chmod($sonda, 0444);
    $pudo = @\file_put_contents($sonda, 'y') !== false;
    @\chmod($sonda, 0666);
    @\unlink($sonda);
    return !$pudo;
}

/**
 * True si un archivo de solo lectura impide REEMPLAZARLO con un renombrado.
 *
 * No es la misma pregunta que la anterior, y la diferencia es de fondo:
 *
 *   Windows  renombrar sobre un archivo de solo lectura falla.
 *   Linux    funciona. `rename()` cambia una entrada del directorio, y para eso
 *            manda el permiso del DIRECTORIO, no el del archivo que se sustituye.
 *
 * Como AxiDB escribe con temporal y renombrado, en Linux marcar un documento
 * como de solo lectura **no impide que el motor lo reemplace**. No es un fallo:
 * es como funciona POSIX, y es el mismo mecanismo que hace atomica la escritura.
 * Lo destapo la CI, con este test dando por hecho el comportamiento de Windows.
 */
function renombradoProtegido(string $base): bool
{
    $destino = $base . '/sonda_destino';
    $origen  = $base . '/sonda_origen';
    @\file_put_contents($destino, 'viejo');
    @\file_put_contents($origen, 'nuevo');
    @\chmod($destino, 0444);
    $pudo = @\rename($origen, $destino);
    @\chmod($destino, 0666);
    @\unlink($destino);
    @\unlink($origen);
    return !$pudo;
}

/** True si este sistema respeta de verdad un directorio de solo lectura. */
function directoriosProtegen(string $base): bool
{
    $dir = $base . '/sonda_dir';
    @\mkdir($dir, 0777, true);
    @\chmod($dir, 0555);
    $pudo = @\file_put_contents($dir . '/x', 'x') !== false;
    @\chmod($dir, 0777);
    @\unlink($dir . '/x');
    @\rmdir($dir);
    return !$pudo;
}

$dir = tmpdir('permisos');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Un directorio de datos imposible se dice, no se disimula');

$archivo = $dir . '/soy_un_archivo';
\file_put_contents($archivo, 'x');

throws('abrir la base de datos sobre un archivo lanza',
    static fn() => new Db($archivo . '/datos', ['durable' => false]));

$mensaje = '';
try {
    new Db($archivo . '/datos', ['durable' => false]);
} catch (Exception $e) {
    $mensaje = $e->getMessage();
}
ok('y el mensaje dice que no pudo crear el directorio',
    \str_contains($mensaje, 'data directory'));
ok('nombrando la ruta, para poder arreglarlo', \str_contains($mensaje, 'datos'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Un documento que no se puede reescribir deja el anterior intacto');

/*
 * Que puede probarse aqui depende del sistema, y se averigua en ejecucion en vez
 * de suponerlo. Son dos preguntas distintas y hay que separarlas:
 *
 *   $protegen    ¿un archivo 0444 impide ABRIRLO para escribir?   (indices, log)
 *   $noRenombra  ¿impide REEMPLAZARLO con un renombrado?          (documentos fs)
 *
 * En Linux la segunda es que no, y no es un defecto: rename() mira el permiso
 * del directorio. Es el mismo mecanismo que hace atomica la escritura.
 */
$protegen   = archivosProtegen($dir);
$noRenombra = renombradoProtegido($dir);

if (!$noRenombra) {
    echo "    (en este sistema se puede renombrar sobre un archivo de solo lectura,\n";
    echo "     que es lo normal en POSIX. La seccion B no aplica aqui.)\n";
    ok('comprobado en ejecucion, no supuesto', true);
}

if ($noRenombra) {

$db = new Db($dir . '/db', ['durable' => false]);
$db->insert('p', ['n' => 1, 'texto' => 'ORIGINAL'], 'x1');

$ruta = $dir . '/db/p/x1.json';
ok('el documento esta en su archivo', \is_file($ruta));

\chmod($ruta, 0444);

throws('reescribirlo lanza en vez de callarse',
    static fn() => $db->put('p', 'x1', ['n' => 2, 'texto' => 'NUEVO'], true));

$crudo = (string) \file_get_contents($ruta);
ok('el archivo sigue siendo JSON valido', \is_array(\json_decode($crudo, true)));
ok('con el contenido de antes',           \str_contains($crudo, 'ORIGINAL'));
ok('y sin rastro del que no se pudo escribir', !\str_contains($crudo, 'NUEVO'));

eq('leido por el motor, tambien es el de antes', 'ORIGINAL', $db->get('p', 'x1')['texto']);
eq('y conserva su version',                            1, $db->get('p', 'x1')['_version']);

$temporales = \glob($dir . '/db/p/*.tmp.*') ?: [];
eq('el intento fallido no dejo temporales tirados', [], \array_map('basename', $temporales));

\chmod($ruta, 0666);

eq('recuperado el permiso, se escribe con normalidad', 'NUEVO',
    $db->put('p', 'x1', ['n' => 2, 'texto' => 'NUEVO'], true)['texto']);
eq('y ahora si sube la version', 2, $db->get('p', 'x1')['_version']);

/* ─────────────────────────────────────────────────────────────────────────── */
}   // fin del bloque que depende del renombrado protegido

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Un indice que no se puede escribir se canta');

if (!$protegen) {
    echo "    (este usuario se salta los permisos de archivo: es root, o el sistema\n";
    echo "     no los aplica. Las secciones C y D no se pueden probar aqui.)\n";
    ok('comprobado en ejecucion, no supuesto', true);
}

if ($protegen) {

/*
 * Base de datos propia, no la de la seccion B: aquella se salta en Linux y una
 * seccion que solo funciona si corrio la anterior es una trampa esperando.
 */
$db = new Db($dir . '/dbidx', ['durable' => false]);
$db->insert('p', ['n' => 2, 'texto' => 'primero'], 'x1');
$db->index('p', 'n');

// Se bloquea el archivo del valor que la siguiente alta VA a tocar. Bloquear
// otro cualquiera haria un test que pasa sin comprobar nada: el motor ni lo
// abriria.
$archivoIndice = $dir . '/dbidx/p/_idx/n/2.json';
ok('el indice tiene el archivo del valor 2', \is_file($archivoIndice));
\chmod($archivoIndice, 0444);

$falloIndice = '';
try {
    $db->insert('p', ['n' => 2, 'texto' => 'otro'], 'x2');
} catch (Exception $e) {
    $falloIndice = $e->getMessage();
}

ok('un indice que no se puede escribir LANZA', $falloIndice !== '');
ok('el mensaje dice que fue el indice',        \str_contains($falloIndice, 'Index'));
ok('y que archivo exactamente',                \str_contains($falloIndice, '2.json'));
ok('y que hacer a continuacion',               \str_contains($falloIndice, 'reindex()'));

/*
 * Donde el sistema lo permite, el mensaje ademas dice quien es el dueño del
 * directorio y como quien corre el proceso. Ese dato concreto costo horas de
 * produccion: un `_idx` creado por root que Apache no podia escribir. En
 * Windows no hay posix_* y el mensaje cae al generico, que sigue siendo util.
 */
if (\function_exists('posix_geteuid')) {
    ok('y en sistemas POSIX, quien es el dueño y quien corre el proceso',
        \str_contains($falloIndice, 'is owned by') && \str_contains($falloIndice, 'runs as'));
} else {
    ok('(sin posix_*, el mensaje generico; comprobado en ejecucion)',
        \str_contains($falloIndice, 'Check the directory permissions'));
}

/*
 * Aqui hay un detalle que conviene tener escrito: el documento se escribe antes
 * que su entrada de indice, asi que tras este fallo el documento existe y el
 * indice se ha quedado corto. No es una corrupcion —el dato original esta
 * entero— sino estado derivado incompleto, que es justo para lo que existen
 * verifyIndexes() y reindex().
 */
ok('el documento si entro', $db->get('p', 'x2') !== null);
eq('y el indice se quedo corto, que es lo esperable',
    1, $db->verifyIndexes('p')['n']['faltan'] ?? -1);

\chmod($archivoIndice, 0666);

$db->reindex('p');
eq('reindexar lo repara entero', 0, $db->verifyIndexes('p')['n']['faltan'] ?? -1);

// Los dos que se dieron de alta en esta seccion, x1 y x2.
eq('y la consulta por indice los encuentra a los dos', 2, \count($db->by('p', 'n', '2')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Lo mismo con el formato empaquetado');

$db2 = new Db($dir . '/db2', ['durable' => false]);
$db2->storage()->declararDriver('q', 'packed');
$db2->insert('q', ['texto' => 'ORIGINAL'], 'y1');
$db2->storage()->cerrar();

$datos = $dir . '/db2/q/data.axi';
ok('el archivo empaquetado existe', \is_file($datos));
$antes = (string) \file_get_contents($datos);

\chmod($datos, 0444);

$db3 = new Db($dir . '/db2', ['durable' => false]);
throws('escribir sobre el log bloqueado lanza',
    static fn() => $db3->insert('q', ['texto' => 'NUEVO'], 'y2'));
$db3->storage()->cerrar();

eq('y el archivo no cambio ni un byte', $antes, (string) \file_get_contents($datos));

\chmod($datos, 0666);

$db4 = new Db($dir . '/db2', ['durable' => false]);
eq('con el permiso devuelto, el documento de antes sigue ahi',
    'ORIGINAL', $db4->get('q', 'y1')['texto'] ?? null);
eq('y se puede volver a escribir', 'NUEVO', $db4->insert('q', ['texto' => 'NUEVO'], 'y2')['texto']);
$db4->storage()->cerrar();

}   // fin del bloque que exige permisos de archivo efectivos

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Un directorio sin permiso de escritura');

if (!directoriosProtegen($dir)) {
    echo "    (este sistema no respeta el solo-lectura en directorios: en Windows\n";
    echo "     se pueden crear archivos dentro igualmente. Parte omitida a proposito.)\n";
    ok('comprobado en ejecucion, no supuesto', true);
} else {
    $db5 = new Db($dir . '/db5', ['durable' => false]);
    $db5->insert('r', ['texto' => 'ORIGINAL'], 'z1');

    \chmod($dir . '/db5/r', 0555);

    throws('escribir en una coleccion sin permiso lanza',
        static fn() => $db5->insert('r', ['texto' => 'NUEVO'], 'z2'));

    eq('el documento anterior se sigue leyendo', 'ORIGINAL', $db5->get('r', 'z1')['texto']);
    eq('y el que no entro no existe',                  null, $db5->get('r', 'z2'));

    \chmod($dir . '/db5/r', 0777);
    eq('devuelto el permiso, entra sin problema', 'NUEVO', $db5->insert('r', ['texto' => 'NUEVO'], 'z2')['texto']);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Nada de lo anterior dejo basura');

foreach (\glob($dir . '/db*/*/*.tmp.*') ?: [] as $t) {
    ok('temporal huerfano: ' . \basename($t), false);
}
ok('no quedaron temporales de ningun intento fallido',
    (\glob($dir . '/db*/*/*.tmp.*') ?: []) === []);

summary();
