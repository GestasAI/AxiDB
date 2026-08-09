<?php
/**
 * AxiDB - cambiar de driver sin perder nada.
 *
 * La eleccion de driver no puede ser una puerta de un solo sentido: quien
 * empieza con fs porque quiere ver sus datos tiene que poder pasar a packed
 * cuando la coleccion crece, y volver si se arrepiente.
 *
 * Lo que hay que demostrar: que ida y vuelta devuelven exactamente los mismos
 * documentos, con sus metadatos, y que si algo se tuerce los datos originales
 * siguen ahi.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

/** Documentos ordenados y comparables. */
function normalizados(Db $db, string $col): array
{
    $docs = $db->all($col);
    \usort($docs, static fn($a, $b) => \strcmp((string) $a['id'], (string) $b['id']));
    return $docs;
}

function sembrar(Db $db, string $col, int $cuantos): void
{
    for ($i = 0; $i < $cuantos; $i++) {
        $db->insert($col, [
            'n'        => $i,
            'nombre'   => 'Documento numero ' . $i,
            'precio'   => $i + 0.5,
            'activo'   => $i % 2 === 0,
            'nulo'     => null,
            'etiquetas'=> ['a', 'b'],
            'grupo'    => 'g' . ($i % 4),
        ], 'd' . \str_pad((string) $i, 3, '0', STR_PAD_LEFT));
    }
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] De fs a packed');

$db = new Db(tmpdir('migracion'), ['durable' => false]);
sembrar($db, 'p', 50);

eq('empieza en fs',  'fs', $db->storage()->driverDe('p'));
$antes = normalizados($db, 'p');
eq('cincuenta documentos', 50, \count($antes));
ok('hay archivos sueltos', \count(\glob($db->path() . '/p/*.json') ?: []) >= 50);

$migrados = $db->storage()->migrarA('p', 'packed');

eq('informa de los cincuenta migrados', 50, $migrados);
eq('ahora es packed',             'packed', $db->storage()->driverDe('p'));
eq('los documentos son EXACTAMENTE los mismos', $antes, normalizados($db, 'p'));
eq('y el recuento cuadra',              50, $db->count('p'));

ok('ya no quedan archivos sueltos',
    \count(\array_filter(\glob($db->path() . '/p/*.json') ?: [],
        static fn($f) => !\str_starts_with(\basename($f), '_'))) === 0);
ok('y si el archivo empaquetado', \is_file($db->path() . '/p/data.axi'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Los metadatos sobreviven al cambio');

$uno = $db->get('p', 'd007');
eq('_version no se toca',           1, $uno['_version']);
eq('el id se conserva',        'd007', $uno['id']);
eq('el decimal sigue siendo decimal', 7.5, $uno['precio']);
ok('y del tipo correcto',      \is_float($uno['precio']));
eq('el booleano',               false, $uno['activo']);
ok('el nulo',                   \array_key_exists('nulo', $uno) && $uno['nulo'] === null);
eq('la lista',            ['a', 'b'], $uno['etiquetas']);
ok('_createdAt intacto',        !empty($uno['_createdAt']));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B2] Version alta y fecha vieja: migrar no las reescribe');

// Esto lo destapo una ejecucion en la que la migracion cayo justo en el cambio
// de segundo. Antes se copiaba con put(), que resella la fecha y reinicia la
// version: solo pasaba porque el test entero cabia dentro del mismo segundo y
// todos los documentos eran de version 1. Ahora se comprueba a proposito.
$dbMeta = new Db(tmpdir('migracion_meta'), ['durable' => false]);
$dbMeta->insert('m', ['v' => 1], 'x');
for ($i = 2; $i <= 5; $i++) {
    $dbMeta->update('m', 'x', ['v' => $i]);
}
$original = $dbMeta->get('m', 'x');
eq('el documento parte de la version 5', 5, $original['_version']);

// Cruzar la frontera del segundo: si la migracion resellara la fecha, se veria.
$marca = \date('c');
while (\date('c') === $marca) {
    \usleep(20000);
}

$dbMeta->storage()->migrarA('m', 'packed');
$tras = $dbMeta->get('m', 'x');

eq('la version no se reinicia',        $original['_version'],   $tras['_version']);
eq('_updatedAt no se vuelve a sellar', $original['_updatedAt'], $tras['_updatedAt']);
eq('_createdAt sigue igual',           $original['_createdAt'], $tras['_createdAt']);
eq('el documento entero es identico',  $original, $tras);

$marca = \date('c');
while (\date('c') === $marca) {
    \usleep(20000);
}
$dbMeta->storage()->migrarA('m', 'fs');
eq('y a la vuelta tampoco', $original, $dbMeta->get('m', 'x'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] De packed a fs, la vuelta');

$antesVuelta = normalizados($db, 'p');
$vueltos = $db->storage()->migrarA('p', 'fs');

eq('vuelven los cincuenta', 50, $vueltos);
eq('otra vez en fs',      'fs', $db->storage()->driverDe('p'));
eq('los documentos siguen siendo los mismos', $antesVuelta, normalizados($db, 'p'));
ok('vuelve a haber archivos sueltos', \is_file($db->path() . '/p/d007.json'));
ok('y ya no esta el empaquetado',    !\is_file($db->path() . '/p/data.axi'));

// Lo encontro un evaluador externo: el cerrojo del formato empaquetado se
// quedaba en el directorio despues de migrar a fs, sin significar ya nada.
foreach (['data.axi', 'offsets.log', 'offsets.idx', '_write.lock'] as $resto) {
    ok("tampoco queda {$resto}", !\is_file($db->path() . '/p/' . $resto));
}

// El viaje completo de ida y vuelta no ha cambiado un solo byte de contenido.
eq('ida y vuelta: identico al original', $antes, normalizados($db, 'p'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Los indices siguen funcionando tras migrar');

$db2 = new Db(tmpdir('migracion_idx'), ['durable' => false]);
sembrar($db2, 'p', 40);
$db2->index('p', 'grupo');

eq('el indice funciona en fs', 10, \count($db2->by('p', 'grupo', 'g1')));

$db2->storage()->migrarA('p', 'packed');
eq('y sigue funcionando en packed', 10, \count($db2->by('p', 'grupo', 'g1')));
eq('sin huecos',                     0, $db2->verifyIndexes('p')['grupo']['faltan']);

$db2->insert('p', ['grupo' => 'g1', 'n' => 999], 'nuevo');
eq('las altas posteriores tambien entran', 11, \count($db2->by('p', 'grupo', 'g1')));

$db2->storage()->migrarA('p', 'fs');
eq('y al volver a fs siguen', 11, \count($db2->by('p', 'grupo', 'g1')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Casos de borde');

eq('migrar al mismo driver no hace nada', 0, $db2->storage()->migrarA('p', 'fs'));
eq('y no toca los documentos',           41, $db2->count('p'));

throws('un driver desconocido se rechaza',
    static fn() => $db2->storage()->migrarA('p', 'inventado'));
eq('sin tocar nada', 41, $db2->count('p'));

$db3 = new Db(tmpdir('migracion_vacia'), ['durable' => false]);
$db3->storage()->ensureCollection('vacia');
eq('migrar una coleccion vacia funciona', 0, $db3->storage()->migrarA('vacia', 'packed'));
eq('y queda declarada',            'packed', $db3->storage()->driverDe('vacia'));
$db3->insert('vacia', ['n' => 1], 'x');
eq('y se puede escribir en ella', 1, $db3->count('vacia'));

/*
 * declararDriver() no mueve datos. Sobre una coleccion con documentos dentro,
 * dejaria los viejos en disco pero invisibles: una perdida silenciosa. Que no
 * ocurra no puede depender de que el que llama haya leido la documentacion.
 */
$db5 = new Db(tmpdir('migracion_declarar'), ['durable' => false]);
sembrar($db5, 'p', 5);

throws('declarar otro driver con documentos dentro se rechaza',
    static fn() => $db5->storage()->declararDriver('p', 'packed'));
eq('la coleccion se queda como estaba', 'fs', $db5->storage()->driverDe('p'));
eq('y sus documentos siguen visibles',     5, $db5->count('p'));

$db5->storage()->declararDriver('p', 'fs');
eq('declarar el que ya tiene no molesta', 'fs', $db5->storage()->driverDe('p'));

$db5->storage()->declararDriver('otra', 'packed');
eq('sobre una coleccion sin nada, si vale', 'packed', $db5->storage()->driverDe('otra'));

eq('y migrarA sigue siendo el camino bueno', 5, $db5->storage()->migrarA('p', 'packed'));
eq('con sus documentos intactos',            5, $db5->count('p'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Durabilidad por coleccion');

$db4 = new Db(tmpdir('migracion_durab'), ['durable' => false]);
eq('el defecto viene del constructor', 'fast', $db4->storage()->durabilidadDe('c'));

$db4->storage()->declararDurabilidad('segura', 'safe');
$db4->storage()->declararDurabilidad('rapida', 'fast');
eq('una coleccion puede pedir safe', 'safe', $db4->storage()->durabilidadDe('segura'));
eq('y otra fast al mismo tiempo',    'fast', $db4->storage()->durabilidadDe('rapida'));

$db4->insert('segura', ['n' => 1], 'x');
$db4->insert('rapida', ['n' => 1], 'x');
eq('las dos guardan igual de bien', 1, $db4->get('segura', 'x')['n']);
eq('y la otra tambien',             1, $db4->get('rapida', 'x')['n']);

throws('una durabilidad inventada se rechaza',
    static fn() => $db4->storage()->declararDurabilidad('c', 'medio_pensionista'));

// Los ajustes viajan con la carpeta: al reabrir se recuerdan.
$ruta = $db4->path();
$db4->storage()->cerrar();
unset($db4);
$db5 = new Db($ruta, ['durable' => false]);
eq('la durabilidad se recuerda tras reabrir', 'safe', $db5->storage()->durabilidadDe('segura'));

$ajustes = \json_decode((string) \file_get_contents($ruta . '/segura/_axidb.json'), true);
eq('y esta escrita en la propia coleccion',
    ['driver' => 'fs', 'durability' => 'safe', 'encrypted' => false,
     'uniques' => [], 'schema' => [], 'ttl' => 0], $ajustes);

// El orden de las claves es estable pase lo que pase: este archivo se lee a ojo
// y se versiona, y un diff tiene que enseñar el cambio, no la baraja.
$db5->storage()->defineTtl('segura', 60);
$db5->storage()->declararDriver('segura', 'fs');
eq('y el orden de las claves no baila al tocar un ajuste',
    ['driver', 'durability', 'encrypted', 'uniques', 'schema', 'ttl'],
    \array_keys((array) \json_decode(
        (string) \file_get_contents($ruta . '/segura/_axidb.json'), true
    )));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Una base escrita con los nombres antiguos sigue abriendo igual');

/*
 * Las claves de `_axidb.json` se llamaban `unicos`, `esquema`, `caducidad`,
 * `cifrado` y `durabilidad`. Ahora se escriben en ingles.
 *
 * Esto comprueba lo unico que de verdad importa de ese cambio: que una base
 * creada antes no pierda sus reglas al abrirse. Si el motor ignorara la clave
 * vieja, la coleccion se abriria SIN su `UNIQUE` —sin un error, sin un aviso— y
 * empezaria a admitir duplicados. Un renombrado que se lleva por delante una
 * restriccion en silencio es exactamente lo que no puede pasar.
 */
$viejo = tmpdir('ajustes_antiguos');
\mkdir($viejo . '/clientes', 0777, true);
\file_put_contents($viejo . '/clientes/_axidb.json', (string) \json_encode([
    'driver'      => 'fs',
    'durabilidad' => 'fast',
    'cifrado'     => false,
    'unicos'      => ['correo'],
    'esquema'     => ['nombre' => ['obligatorio' => true]],
    'caducidad'   => 120,
], JSON_PRETTY_PRINT));

$antiguo = new Db($viejo, ['durable' => false]);
eq('lee la unicidad declarada a la antigua', ['correo'], $antiguo->uniques('clientes'));
eq('y el esquema',      ['nombre' => ['obligatorio' => true]], $antiguo->schema('clientes'));
eq('y la caducidad',    120,    $antiguo->ttl('clientes'));
eq('y la durabilidad', 'fast',  $antiguo->storage()->durabilidadDe('clientes'));

$antiguo->insert('clientes', ['nombre' => 'Ana', 'correo' => 'ana@ejemplo.es'], 'c1');
throws('y la restriccion se sigue cumpliendo, que es lo que importa',
    static fn() => $antiguo->insert('clientes', ['nombre' => 'Otra', 'correo' => 'ana@ejemplo.es'], 'c2'));

// La primera escritura deja el archivo ya con los nombres nuevos.
$antiguo->defineTtl('clientes', 300);
$tras = (array) \json_decode((string) \file_get_contents($viejo . '/clientes/_axidb.json'), true);
eq('al escribir, el archivo queda en los nombres nuevos',
    ['driver', 'durability', 'encrypted', 'uniques', 'schema', 'ttl'], \array_keys($tras));
eq('sin perder lo que habia', ['correo'], $tras['uniques']);
eq('y con el cambio aplicado', 300, $tras['ttl']);

rmrf($viejo);
rmrf($db->path());
rmrf($db2->path());
rmrf($db3->path());
rmrf($db5->path());
summary();
