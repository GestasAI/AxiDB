<?php
/**
 * AxiDB - auditoria de integridad de datos y confinamiento de agentes.
 *
 * Este archivo no busca tumbar el motor. Busca lo contrario: que el motor
 * conteste con normalidad, sin lanzar nada, y que lo que conteste o lo que
 * guarde no sea lo que deberia. Un error se ve; un dato equivocado devuelto en
 * silencio se cree.
 *
 * Cada asercion afirma el comportamiento SEGURO. Si un ataque funciona, la
 * asercion se queda en rojo a proposito: es el hallazgo, no un test mal escrito.
 *
 * Los tres frentes:
 *
 *   metadatos   el motor promete que id, _version y las fechas son suyos. Si el
 *               cuerpo del documento puede escribir alguno, la historia del
 *               documento la escribe quien lo manda.
 *   esquema     una regla que se puede rodear por otra puerta no es una regla.
 *   agentes     un agente decide solo lo que hace a partir de un texto que
 *               escribe un usuario. La lista de permisos es la unica defensa,
 *               asi que aqui se intenta rodearla por SQL, por JOIN, por
 *               subconsulta, por vista y por el propio boton de parada.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';
require_once __DIR__ . '/_http.php';

use Axi\Core\Agents\NotAllowed;
use Axi\Core\Db;
use Axi\Core\Vector\Embedders\Hash;

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Metadatos: quien escribe la historia del documento');

/*
 * Un documento que trae sus propios metadatos en el cuerpo. Importa porque el
 * cuerpo suele venir de fuera —un formulario, un JSON de una API, un agente— y
 * porque _version es lo que usa el control de actualizacion perdida de las
 * transacciones: una version falsificada la desarma.
 */
$dirA = tmpdir('sec_meta');
$dbA  = new Db($dirA, ['durable' => false]);

$suplantado = $dbA->insert('p', [
    'n'          => 'a',
    'id'         => 'OTRO',
    '_version'   => 999,
    '_createdAt' => '1970-01-01T00:00:00+00:00',
    '_updatedAt' => '1970-01-01T00:00:00+00:00',
], 'p1');

eq('el id del cuerpo no manda sobre el id pedido', 'p1', $suplantado['id']);
eq('_version arranca en uno, no en el que se dijo',  1, $suplantado['_version']);
ok('_updatedAt lo pone el motor, no el cuerpo',
    $suplantado['_updatedAt'] !== '1970-01-01T00:00:00+00:00');
ok('_createdAt lo pone el motor, no el cuerpo',
    $suplantado['_createdAt'] !== '1970-01-01T00:00:00+00:00');

// Y lo mismo mirando el disco: devolver bien y guardar mal seria peor todavia.
$enDisco = $dbA->get('p', 'p1');
ok('y en el disco tampoco esta la fecha inventada',
    ($enDisco['_createdAt'] ?? '') !== '1970-01-01T00:00:00+00:00');

/*
 * La fecha de alta no se toca nunca mas: es lo que dice Meta y es de lo que se
 * fia cualquier informe, cualquier orden cronologico y cualquier peritaje.
 */
$dbA->insert('q', ['n' => 'a'], 'q1');
$alta = $dbA->get('q', 'q1')['_createdAt'];
$dbA->update('q', 'q1', ['_createdAt' => '1999-01-01T00:00:00+00:00', '_version' => 500]);
$tras = $dbA->get('q', 'q1');

eq('una modificacion no puede reescribir la fecha de alta', $alta, $tras['_createdAt']);
eq('ni saltar la version a donde quiera',                       2, $tras['_version']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] La identidad del documento');

$dbA->update('q', 'q1', ['id' => 'q2']);

ok('cambiar el id en el cuerpo no crea un duplicado', !$dbA->exists('q', 'q2'));
eq('ni deja huerfano al original',           'q1', $dbA->get('q', 'q1')['id']);
eq('la coleccion sigue con un documento',       1, $dbA->count('q'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Confusion de tipos: el esquema, el indice y la unicidad');

$dirC = tmpdir('sec_tipos');
$dbC  = new Db($dirC, ['durable' => false]);

$dbC->insert('u', ['email' => 'ana@x.es'], 'u1');
$dbC->unique('u', 'email');

throws('el mismo correo repetido, en texto, se rechaza',
    static fn() => $dbC->insert('u', ['email' => 'ana@x.es'], 'u2'));

// El mismo valor con otro tipo: el indice trabaja con cadenas, asi que 1 y "1"
// son el mismo hueco. Que lo sean es lo correcto; lo peligroso seria que uno de
// los dos se colara.
$dbC->insert('n', ['cod' => 1], 'n1');
$dbC->unique('n', 'cod');
throws('el mismo codigo como texto no se cuela por el tipo',
    static fn() => $dbC->insert('n', ['cod' => '1'], 'n2'));
throws('ni como booleano',
    static fn() => $dbC->insert('n', ['cod' => true], 'n3'));

/*
 * Un valor que llega como lista no se reserva, asi que la unicidad deja de
 * aplicarse. Quien manda el dato elige el tipo, y por tanto elige si la
 * restriccion existe. Ademas el documento resultante no se encuentra buscando
 * por ese correo, con lo que la fuga es doble: entra y ademas no se ve.
 */
$dbC->insert('u', ['email' => ['ana@x.es']], 'u3');
throws('un correo repetido disfrazado de lista tambien se rechaza',
    static fn() => $dbC->insert('u', ['email' => ['ana@x.es']], 'u4'));
eq('y no hay dos documentos con el mismo correo', 1,
    \count(\array_filter($dbC->all('u'), static fn($d) => ($d['email'] ?? null) === 'ana@x.es'
        || (\is_array($d['email'] ?? null) && \in_array('ana@x.es', $d['email'], true)))));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Comparaciones: PHP es generoso con la igualdad, una base no debe serlo');

$dirD = tmpdir('sec_eval');
$dbD  = new Db($dirD, ['durable' => false]);

$dbD->insert('e', ['v' => 'texto'], 'e1');
$dbD->insert('e', ['v' => null],    'e2');
$dbD->insert('e', ['otro' => 1],    'e3');   // sin el campo
$dbD->insert('e', ['v' => 0],       'e4');
$dbD->insert('e', ['v' => [1, 2]],  'e5');
$dbD->insert('e', ['v' => ''],      'e6');
$dbD->insert('e', ['v' => true],    'e7');

$ids = static fn(array $r): array => \array_values(\array_column($r, 'id'));

eq('WHERE v = 0 no arrastra el texto',        ['e4'], $ids($dbD->find('e')->where('v', '=', 0)->get()));
eq('WHERE v = "" no arrastra el nulo',        ['e6'], $ids($dbD->find('e')->where('v', '=', '')->get()));
eq('WHERE v = true no arrastra el 1 ni el 0', ['e7'], $ids($dbD->find('e')->where('v', '=', true)->get()));

/*
 * Comparar una lista con un escalar con un operador de orden. En PHP eso
 * convierte el array a la cadena 'Array' y avisa por el canal de errores. Una
 * base de datos no puede emitir avisos de PHP con el dato del usuario: en
 * produccion eso acaba en la respuesta HTTP o llenando el log.
 */
$avisos = [];
\set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
    $avisos[] = $mensaje;
    return true;
});
$dbD->find('e')->where('v', '>', '')->get();
\restore_error_handler();

eq('comparar una lista con un escalar no emite avisos de PHP', [], $avisos);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] El esquema, por todas las puertas');

$dirE = tmpdir('sec_esquema');
$dbE  = new Db($dirE, ['durable' => false]);
$dbE->defineSchema('c', ['req' => ['tipo' => 'texto', 'obligatorio' => true]]);

throws('insert respeta el obligatorio',
    static fn() => $dbE->insert('c', ['x' => 1], 'i1'));
throws('put tampoco es una puerta de atras',
    static fn() => $dbE->put('c', 'i2', ['x' => 1]));
throws('ni AxiSQL',
    static fn() => $dbE->sql("INSERT INTO c (id, x) VALUES ('i3', 1)"));
throws('ni una transaccion',
    static fn() => $dbE->transaction(static fn($tx) => $tx->insert('c', ['x' => 1], 'i4')));
eq('la coleccion sigue vacia', 0, $dbE->count('c'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Atomicidad: la pildora envenenada');

/*
 * El ataque mas rentable de todo este archivo, y no necesita permisos
 * especiales: basta poder abrir una transaccion con dos pasos.
 *
 * El esquema se valida DENTRO de Db::put, y Db::put se llama al aplicar, que es
 * DESPUES de la marca de confirmacion. Asi que un documento invalido en el
 * segundo paso no aborta la transaccion: la parte de arriba ya se escribio, y
 * el diario se queda con su marca puesta porque Applier lanza antes de que
 * Commit lo borre.
 *
 * Consecuencia: la transaccion se aplica a medias y, peor, la base no vuelve a
 * abrirse nunca, porque cada `new Db(...)` llama a recover(), reintenta el mismo
 * diario y vuelve a lanzar el mismo error.
 */
$dirF = tmpdir('sec_atomicidad');
$dbF  = new Db($dirF, ['durable' => false]);
$dbF->defineSchema('cta', ['saldo' => ['tipo' => 'entero', 'obligatorio' => true]]);
$dbF->insert('cta', ['saldo' => 100], 'a');
$dbF->insert('cta', ['saldo' => 100], 'b');

throws('una transaccion con un documento invalido se rechaza',
    static fn() => $dbF->transaction(static function ($tx): void {
        $tx->update('cta', 'a', ['saldo' => 50]);
        $tx->update('cta', 'b', ['saldo' => 'CIEN']);
    }));

eq('y no deja aplicada la primera mitad', 100, $dbF->get('cta', 'a')['saldo']);
eq('la segunda tampoco, claro',           100, $dbF->get('cta', 'b')['saldo']);

$reabre = true;
$porQue = '';
try {
    new Db($dirF, ['durable' => false]);
} catch (\Throwable $e) {
    $reabre = false;
    $porQue = $e->getMessage();
}
ok('y la base se sigue pudiendo abrir despues' . ($reabre ? '' : ": {$porQue}"), $reabre);

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Caducidad: vencido tiene que significar vencido');

$dirG = tmpdir('sec_ttl');
$dbG  = new Db($dirG, ['durable' => false, 'embedder' => new Hash(64)]);
$dbG->defineTtl('ses', 1);
$dbG->index('ses', 'user');
$dbG->enableVectors('ses', ['auto' => ['secreto']]);
$dbG->insert('ses', ['user' => 'ana', 'email' => 'ana@x.es', 'secreto' => 'la clave de la caja'], 's1');
\sleep(2);

// Lo que ya funciona: por ninguna de las puertas de lectura se ve.
ok('no se ve por get',        $dbG->get('ses', 's1') === null);
eq('ni por all',           0, \count($dbG->all('ses')));
eq('ni por count',         0, $dbG->count('ses'));
eq('ni por el indice',     0, \count($dbG->find('ses')->where('user', '=', 'ana')->get()));
eq('ni por SQL',          [], $dbG->sql('SELECT * FROM ses'));
eq('ni por busqueda vectorial', [], $dbG->similar('ses', 'la clave de la caja', 5));

/*
 * Pero sigue en el disco, y el driver de abajo no sabe nada de caducidad. Al
 * escribir con el mismo id, Meta fusiona con lo que lee del archivo crudo: el
 * documento vencido revive con sus campos antiguos dentro, incluido el secreto
 * que la caducidad daba por ido. Quien escribe cree estar creando; en realidad
 * esta desenterrando.
 */
$revivido = $dbG->put('ses', 's1', ['nota' => 'documento nuevo']);
ok('escribir con el id de uno vencido no resucita su contenido',
    !isset($revivido['secreto']));
eq('y arranca como alta, no como continuacion', 1, $revivido['_version']);

// La unicidad, al reves: el valor de un documento que "no existe" sigue tomado.
$dirG2 = tmpdir('sec_ttl_unico');
$dbG2  = new Db($dirG2, ['durable' => false]);
$dbG2->defineTtl('ses', 1);
$dbG2->insert('ses', ['email' => 'ana@x.es'], 's1');
$dbG2->unique('ses', 'email');
\sleep(2);

$reservado = false;
try {
    $dbG2->insert('ses', ['email' => 'ana@x.es'], 's2');
} catch (\Axi\Core\Exception $e) {
    $reservado = true;
}
ok('el correo de un documento vencido queda libre para otro', !$reservado);

// Y borrar un vencido tiene que limpiar tambien su rastro en el indice.
$dirG3 = tmpdir('sec_ttl_indice');
$dbG3  = new Db($dirG3, ['durable' => false]);
$dbG3->defineTtl('t', 1);
$dbG3->index('t', 'k');
$dbG3->insert('t', ['k' => 'v'], 't1');
\sleep(2);
$dbG3->delete('t', 't1');

eq('borrar un vencido no deja entradas sobrantes en el indice',
    0, (int) ($dbG3->verifyIndexes('t')['k']['sobran'] ?? -1));

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] Perfiles: lo que se niega por la puerta no puede entrar por la ventana');

$dirH = tmpdir('sec_perfiles');
$dbH  = new Db($dirH, ['durable' => false, 'profile' => 'core']);
$dbH->insert('a', ['x' => 1, 'r' => 'k'], 'a1');
$dbH->insert('b', ['y' => 2, 'r' => 'k'], 'b1');

throws('con perfil core, join() se para',
    static fn() => $dbH->find('a')->join('b', 'r', 'r')->get());
throws('y el mismo JOIN escrito en SQL tiene que pararse igual',
    static fn() => $dbH->sql('SELECT * FROM a JOIN b ON a.r = b.r'));
throws('las transacciones no llegan por SQL',
    static fn() => $dbH->sql('BEGIN'));
throws('ni los vectores',
    static fn() => $dbH->similar('a', 'hola'));
throws('ni los agentes',
    static fn() => $dbH->agent('z', ['get'], ['a']));

/* ─────────────────────────────────────────────────────────────────────────── */
section('I] Sandbox de agentes: intentar salirse');

$dirI = tmpdir('sec_agentes');
$dbI  = new Db($dirI, ['durable' => false]);
$dbI->insert('publica', ['t' => 'nota', 'ref' => 'k'], 'p1');
$dbI->insert('secreta', ['iban' => 'ES9121000418450200051332', 'ref' => 'k'], 's1');

$bot = $dbI->agent('bot', ['get', 'find'], ['publica']);

// Lo que ya rebota, para tener la linea de partida.
throws('no llega a la coleccion ajena',        static fn() => $bot->get('secreta', 's1'));
throws('ni por un SELECT directo',             static fn() => $bot->sql('SELECT * FROM secreta'));
throws('ni cambiando las mayusculas',          static fn() => $bot->get('Publica', 'p1'));
throws('ni con un nombre que no es un nombre', static fn() => $bot->all('_agentes'));

/*
 * El cruce. `find()` devuelve un Query, y Query::join va a buscar la otra
 * coleccion por su cuenta: el sandbox comprobo 'find' sobre 'publica' y ya no
 * vuelve a mirar. El agente se lleva el IBAN entero.
 */
$fuga = [];
try {
    $fuga = $bot->find('publica')->join('secreta', 'ref', 'ref')->get();
} catch (NotAllowed) {
    $fuga = [];
}
ok('un JOIN desde el constructor de consultas no alcanza lo prohibido',
    $fuga === [] || !\str_contains((string) \json_encode($fuga), 'ES9121'));

// El mismo cruce escrito en SQL: el sandbox solo mira el FROM.
$fugaSql = [];
try {
    $fugaSql = (array) $bot->sql('SELECT * FROM publica JOIN secreta ON publica.ref = secreta.ref');
} catch (NotAllowed) {
    $fugaSql = [];
}
ok('ni un JOIN escrito en SQL',
    $fugaSql === [] || !\str_contains((string) \json_encode($fugaSql), 'ES9121'));

/*
 * La subconsulta no devuelve el dato, pero responde si o no sobre el contenido
 * de una coleccion que el agente no puede tocar. Repetida, un IBAN se saca
 * caracter a caracter. Un oraculo tambien es una fuga.
 */
throws('una subconsulta sobre lo prohibido se rechaza',
    static fn() => $bot->sql("SELECT * FROM publica WHERE ref IN (SELECT ref FROM secreta)"));

$analista = $dbI->agent('analista', ['find', 'sql'], ['publica']);

throws('listar las colecciones se rechaza si hay lista de colecciones',
    static fn() => $analista->sql('SHOW COLLECTIONS'));

/*
 * ALTER es una escritura con nombre de otra cosa. El sandbox lo traduce a la
 * operacion generica 'sql', asi que un agente al que solo se le dio consultar
 * modifica todos los documentos de la coleccion y borra un campo entero.
 */
throws('ALTER ... ADD FIELD es una escritura y necesita permiso de escritura',
    static fn() => $analista->sql("ALTER COLLECTION publica ADD FIELD hackeado = 'si'"));
throws('ALTER ... DROP FIELD, lo mismo',
    static fn() => $analista->sql('ALTER COLLECTION publica DROP FIELD ref'));
eq('y el documento sigue como estaba', 'k', $dbI->get('publica', 'p1')['ref'] ?? null);

/*
 * CREATE VIEW no lleva coleccion en su arbol, asi que la lista de colecciones
 * del agente no se comprueba. Y como una vista se resuelve ANTES que la
 * coleccion del mismo nombre, el agente puede ponerle a 'publica' el contenido
 * de 'secreta' — para el, y para todo el que use SQL contra esa base.
 */
try {
    $analista->sql('CREATE VIEW publica AS SELECT * FROM secreta');
} catch (NotAllowed) {
    // lo esperado
}
$trasVista = (string) \json_encode($dbI->sql('SELECT * FROM publica'));
ok('un agente no puede definir vistas sobre lo que no alcanza',
    !\str_contains($trasVista, 'ES9121'));

/* Una transaccion del anfitrion no es del agente. */
$dbI->begin();
$dbI->currentTransaction()->update('publica', 'p1', ['t' => 'trabajo del anfitrion']);
try {
    $analista->sql('ROLLBACK');
} catch (NotAllowed) {
    // lo esperado
}
$confirmo = true;
try {
    $dbI->commit();
} catch (\Axi\Core\Exception) {
    $confirmo = false;
}
ok('el agente no puede descartar la transaccion de quien lo hospeda', $confirmo);

/* El boton de parada. */
$parado = $dbI->agent('paradas', ['get'], ['publica']);
$parado->stop('se estaba pasando de listo');
$parado->resume();
ok('un agente detenido no se reanuda solo', $parado->isStopped());

/*
 * Dos nombres distintos, un mismo archivo de parada: el nombre se normaliza
 * sustituyendo lo que no sea [A-Za-z0-9_-] por un guion bajo. Detener a uno
 * detiene al otro, y reanudar al inocente suelta al que se paro.
 */
$puntos = $dbI->agent('bot.uno', ['get'], ['publica']);
$guion  = $dbI->agent('bot_uno', ['get'], ['publica']);
$puntos->stop('sospechoso');
ok('parar a un agente no para a otro con nombre parecido', !$guion->isStopped());
$guion->resume();
ok('y reanudar al otro no lo suelta',                       $puntos->isStopped());

/* El rastro: no se puede leer desde dentro, ni falsear con el nombre. */
$colado = $dbI->agent("bot\",\"ok\":true,\"x\":\"", ['get'], ['publica']);
$colado->get('publica', 'p1');
$lineas = \file($dirI . '/_agentes/auditoria.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$rotas  = \array_filter($lineas, static fn($l) => \json_decode($l, true) === null);
eq('un nombre de agente con comillas no parte el rastro', [], \array_values($rotas));

/*
 * Y si el cruce funciono, el rastro tendria que decirlo. No lo dice: la
 * operacion queda anotada como un `find` correcto sobre 'publica', que es
 * justo la coleccion que el agente NO leyo. Quien revise el registro vera un
 * agente portandose bien.
 */
$rastro = $dbI->audit()->readAt('agent:bot', 200);
$cruces = \array_filter(
    $rastro,
    static fn($f) => ($f['coleccion'] ?? '') === 'secreta' && ($f['ok'] ?? false) === true
);
ok('y si el agente alcanzo la coleccion ajena, el rastro lo dice',
    $fuga === [] || $cruces !== []);

/* ─────────────────────────────────────────────────────────────────────────── */
section('J] Vistas: una consulta con nombre no puede suplantar a una coleccion');

$dirJ = tmpdir('sec_vistas');
$dbJ  = new Db($dirJ, ['durable' => false]);
$dbJ->insert('publica', ['t' => 'catalogo'], 'p1');
$dbJ->insert('nominas', ['sueldo' => 90000], 'n1');

$choque = false;
try {
    $dbJ->sql('CREATE VIEW publica AS SELECT * FROM nominas');
} catch (\Axi\Core\Exception) {
    $choque = true;
}
ok('crear una vista con el nombre de una coleccion existente se rechaza', $choque);

$porSql   = (string) \json_encode($dbJ->sql('SELECT * FROM publica'));
$porQuery = (string) \json_encode($dbJ->find('publica')->get());
eq('y SQL y el constructor de consultas siguen diciendo lo mismo', $porQuery, $porSql);

/* ─────────────────────────────────────────────────────────────────────────── */
section('K] Puente HTTP: el ambito de un token es el ambito de verdad');

const TOKEN_CATALOGO = 'bb00cc11dd22ee33ff44aa55bb66cc77';

[$srv, $dbK] = puente('sec_puente', [
    'tokens' => [TOKEN_CATALOGO => ['colecciones' => ['catalogo'], 'escribir' => false]],
]);
$dbK->insert('catalogo', ['nombre' => 'cafe', 'ref' => 'k'], 'c1');
$dbK->insert('nominas',  ['sueldo' => 90000, 'ref' => 'k'], 'n1');

respuesta('el token llega a lo suyo',
    pedir($srv, ['accion' => 'get', 'coleccion' => 'catalogo', 'id' => 'c1'], TOKEN_CATALOGO), 200, true);
respuesta('y no llega a lo demas',
    pedir($srv, ['accion' => 'get', 'coleccion' => 'nominas', 'id' => 'n1'], TOKEN_CATALOGO), 403, false);

$r = pedir($srv, [
    'accion'    => 'sql',
    'sentencia' => 'SELECT * FROM catalogo JOIN nominas ON catalogo.ref = nominas.ref',
], TOKEN_CATALOGO);
ok('ni con un JOIN, que solo declara la coleccion de la izquierda',
    $r->codigo === 403 || !\str_contains($r->json(), '90000'));

summary();
