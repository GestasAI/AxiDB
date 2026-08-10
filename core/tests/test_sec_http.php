<?php
/**
 * AxiDB - auditoria ofensiva del puente HTTP.
 *
 * El puente es la unica pieza de AxiDB que mira a internet. Todo lo demas se
 * ejecuta detras de un `require`, donde quien llama ya tiene el disco entero;
 * aqui no: aqui llama cualquiera. Un fallo en Bridge, Permissions o Request lo
 * ve el mundo, y lo ve el mismo dia.
 *
 * Este archivo NO repite lo que ya cubren test_http_seguridad, test_http_cors,
 * test_http_limites ni test_http_traversal. Ataca lo que esos no miran:
 *
 *   - que el ambito de un token se comprueba sobre UNA coleccion, mientras que
 *     una sentencia SQL puede tocar varias (JOIN y subconsultas);
 *   - que una coleccion publica es una ventana desde la que se interroga al
 *     resto de la base sin ninguna llave;
 *   - que hay entradas del cliente que llegan a un `(string)` sobre un array y
 *     escupen la ruta absoluta del servidor por el cuerpo de la respuesta;
 *   - que un numero JSON legal deja basura permanente en el disco.
 *
 * Las aserciones afirman el comportamiento SEGURO. Lo que salga en rojo es un
 * ataque que funciona hoy, no un test mal escrito.
 */

declare(strict_types=1);

require_once __DIR__ . '/_http.php';

use Axi\Core\Http\Cors;
use Axi\Core\Http\Request;

/** Token con ambito limitado a una sola coleccion. */
const T_PEDIDOS = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';
/** Token que lo ve todo pero no escribe nada. */
const T_LECTURA = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
/** Una IP cualquiera de internet. */
const INTERNET  = '198.51.100.23';

/** Puerto alto y poco comun: los tests de la casa no lo usan. */
const PUERTO = 8791;

/** Peticion cruda al puente en proceso, con control total del $_SERVER. */
function crudo(
    \Axi\Core\Http\Server $s,
    string $cuerpo,
    ?string $token = null,
    string $ip = '127.0.0.1',
    array $extra = []
): \Axi\Core\Http\Response {
    $server = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => $ip,
        'CONTENT_LENGTH' => (string) \strlen($cuerpo)] + $extra;
    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    return $s->respond($server, $cuerpo);
}

/** Atajo: una sentencia AxiSQL por el puente. */
function sql(\Axi\Core\Http\Server $s, string $sentencia, ?string $token = null, string $ip = '127.0.0.1'): \Axi\Core\Http\Response
{
    return pedir($s, ['accion' => 'sql', 'sentencia' => $sentencia], $token, null, 'POST', $ip);
}

/** True si el JSON de la respuesta contiene el texto marcado como secreto. */
function filtra(\Axi\Core\Http\Response $r, string $secreto): bool
{
    return \str_contains($r->json(), $secreto);
}

/* ═══════════════════════════════════════════════════════════════════════════
 * A] El ambito del token se comprueba sobre UNA coleccion; SQL toca varias.
 *
 * Bridge::handle saca la coleccion de $ast['collection'] —el FROM— y con esa
 * sola llama a Permissions::decide. Un SELECT con JOIN lee ADEMAS la coleccion
 * unida, y nadie pregunta si el token llega hasta ella.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('A] Ambito del token frente a SQL que cruza colecciones');

[$s, $db, $dir] = puente('sec_http_ambito', [
    'tokens' => [
        T_PEDIDOS => ['colecciones' => ['pedidos'], 'escribir' => true],
        T_LECTURA => ['colecciones' => ['pedidos'], 'escribir' => false],
    ],
]);
$db->insert('pedidos',  ['total' => 10, 'uid' => 'u1'], 'p1');
$db->insert('usuarios', ['correo' => 'ana@ejemplo.es', 'tarjeta' => 'TARJETA_SECRETA'], 'u1');

// La linea de base: por la puerta principal el ambito se respeta.
respuesta('el token de pedidos no abre usuarios por la via directa',
    pedir($s, ['accion' => 'get', 'coleccion' => 'usuarios', 'id' => 'u1'], T_PEDIDOS), 403, false);

// El mismo token, la misma coleccion prohibida, otro camino.
$r = sql($s, 'SELECT * FROM pedidos JOIN usuarios u ON pedidos.uid = u.id', T_PEDIDOS);
ok('un JOIN no puede sacar datos de una coleccion fuera del ambito',
    !filtra($r, 'TARJETA_SECRETA'));
ok('  y la peticion se rechaza en vez de responderse', $r->codigo === 403);

$r = sql($s, 'SELECT * FROM pedidos p LEFT JOIN usuarios u ON p.uid = u.id', T_PEDIDOS);
ok('tampoco con LEFT JOIN', !filtra($r, 'TARJETA_SECRETA'));

// El JOIN es aun mas grave con un token de SOLO LECTURA: no necesita escribir
// nada para llevarse una coleccion entera que no le corresponde.
$r = sql($s, 'SELECT * FROM pedidos JOIN usuarios u ON pedidos.uid = u.id', T_LECTURA);
ok('ni con un token de solo lectura', !filtra($r, 'TARJETA_SECRETA'));

/*
 * Subconsultas. No devuelven las filas de la otra coleccion, pero contestan
 * si o no sobre ellas, y eso basta: con LIKE se saca el valor letra a letra.
 * Es la exfiltracion a ciegas de toda la vida, aqui sin necesidad de inyectar
 * nada porque el SQL lo escribe el atacante entero.
 */
$si = sql($s, "SELECT id FROM pedidos WHERE EXISTS (SELECT * FROM usuarios WHERE correo = 'ana@ejemplo.es')", T_PEDIDOS);
$no = sql($s, "SELECT id FROM pedidos WHERE EXISTS (SELECT * FROM usuarios WHERE correo = 'nadie@ejemplo.es')", T_PEDIDOS);

ok('una subconsulta EXISTS no interroga colecciones fuera del ambito',
    $si->codigo === 403 && $no->codigo === 403);
ok('  y no queda un oraculo de si/no sobre ellas',
    dato($si) === dato($no));

$r = sql($s, 'SELECT id FROM pedidos WHERE total IN (SELECT correo FROM usuarios)', T_PEDIDOS);
ok('ni una subconsulta IN', $r->codigo === 403);

// Lo que si tiene que seguir funcionando: cruzar dos colecciones que el token
// si alcanza. Una defensa que rompe el uso legitimo no se despliega.
$db->insert('lineas', ['pedido' => 'p1', 'articulo' => 'Vaso'], 'l1');
[$s2, $db2] = puente('sec_http_ambito_ok', [
    'tokens' => ['todo' => ['colecciones' => ['pedidos', 'lineas'], 'escribir' => true]],
]);
$db2->insert('pedidos', ['uid' => 'x'], 'p1');
$db2->insert('lineas',  ['pedido' => 'p1'], 'l1');
respuesta('un JOIN entre dos colecciones del ambito si se atiende',
    sql($s2, 'SELECT * FROM pedidos p JOIN lineas l ON p.id = l.pedido', 'todo'), 200, true);

/* ═══════════════════════════════════════════════════════════════════════════
 * B] Una coleccion publica es una ventana; no puede ser un mirador.
 *
 * 'publicas' esta pensado para un catalogo que lee cualquiera sin token. El
 * problema es que la accion 'sql' tambien vale sobre esa coleccion, y desde el
 * FROM de un SELECT publico se puede preguntar por cualquier otra coleccion de
 * la base. Sin token. Desde internet.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('B] Exfiltracion anonima a traves de una coleccion publica');

[$s, $db, $dir] = puente('sec_http_publica', [
    'tokens'   => [T_PEDIDOS => ['colecciones' => '*', 'escribir' => true]],
    'publicas' => ['catalogo'],
]);
$db->insert('catalogo', ['nombre' => 'Vaso', 'precio' => 3], 'v1');
$db->insert('privada',  ['correo' => 'ana@ejemplo.es', 'tarjeta' => 'TARJETA_SECRETA'], 's1');

// Lo que ya funciona bien y debe seguir funcionando.
respuesta('un anonimo lee la coleccion publica',
    pedir($s, ['accion' => 'get', 'coleccion' => 'catalogo', 'id' => 'v1'], null, null, 'POST', INTERNET), 200, true);
respuesta('y no la privada',
    pedir($s, ['accion' => 'get', 'coleccion' => 'privada', 'id' => 's1'], null, null, 'POST', INTERNET), 401, false);

// El oraculo: la misma pregunta con dos respuestas distintas segun un dato
// que el anonimo no deberia poder consultar.
$si = sql($s, "SELECT id FROM catalogo WHERE EXISTS (SELECT * FROM privada WHERE correo = 'ana@ejemplo.es')", null, INTERNET);
$no = sql($s, "SELECT id FROM catalogo WHERE EXISTS (SELECT * FROM privada WHERE correo = 'nadie@ejemplo.es')", null, INTERNET);
ok('un anonimo no obtiene un oraculo sobre una coleccion privada',
    dato($si) === dato($no) || $si->codigo >= 400);

/*
 * Y el ataque completo, porque un oraculo binario no es una curiosidad
 * academica: es la coleccion entera, caracter a caracter. Se extrae aqui de
 * verdad para que el numero que sale por pantalla no admita discusion.
 */
$alfabeto = \str_split('abcdefghijklmnopqrstuvwxyz0123456789@.-_');
$robado   = '';
for ($i = 0; $i < 14; $i++) {
    $avanzo = false;
    foreach ($alfabeto as $ch) {
        $r = sql($s, "SELECT id FROM catalogo WHERE EXISTS (SELECT * FROM privada WHERE correo LIKE '"
            . $robado . $ch . "%')", null, INTERNET);
        if (($r->cuerpo['dato'] ?? []) !== []) {
            $robado .= $ch;
            $avanzo  = true;
            break;
        }
    }
    if (!$avanzo) {
        break;
    }
}
if ($robado !== '') {
    echo "    exfiltrado sin token desde {$robado} <- coleccion 'privada'\n";
}
ok('no se extrae ni un caracter de una coleccion privada sin token', $robado === '');

// La escritura si esta bien cerrada: la coleccion publica es de solo lectura y
// eso incluye el SQL.
respuesta('el anonimo no escribe en la publica por SQL',
    sql($s, "INSERT INTO catalogo (nombre) VALUES ('Falso')", null, INTERNET), 401, false);
respuesta('ni la borra',
    sql($s, 'DROP COLLECTION catalogo', null, INTERNET), 401, false);
eq('el catalogo no cambio', 1, $db->count('catalogo'));

// Y una subconsulta no puede colar una escritura donde se espera un SELECT.
$r = sql($s, 'SELECT id FROM catalogo WHERE EXISTS (DELETE FROM privada)', null, INTERNET);
respuesta('una subconsulta que no es SELECT se rechaza', $r, 400, false);
eq('la coleccion privada sigue entera', 1, $db->count('privada'));

// Apilar sentencias dentro del parentesis tampoco borra nada, pero hoy no se
// contesta con un 4xx sino con un 500, que es la respuesta que este proyecto
// se prohibio a si mismo para los errores del cliente.
$r = sql($s, 'SELECT id FROM catalogo WHERE EXISTS (SELECT * FROM privada; DELETE FROM privada)', null, INTERNET);
eq('apilar sentencias en la subconsulta no borra nada', 1, $db->count('privada'));
ok('  y se contesta 4xx, no 500', $r->codigo >= 400 && $r->codigo < 500);

/* ═══════════════════════════════════════════════════════════════════════════
 * C] Cadenas y objetos donde se espera texto.
 *
 * Bridge hace `(string) $condicion[0]` y `array_map('strval', $campos)` sin
 * comprobar el tipo. Un array ahi dentro dispara "Array to string conversion",
 * y ese aviso lo imprime PHP con la ruta absoluta del archivo y el numero de
 * linea. En un servidor con display_errors —el valor por defecto de muchas
 * instalaciones— ese texto sale POR DELANTE del JSON, en el cuerpo que ve el
 * atacante. Le regala la ruta del despliegue y la version del arbol de codigo.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('C] Tipos inesperados en el cuerpo');

[$s, $db, $dir] = puente('sec_http_tipos', ['tokens' => [T_PEDIDOS => ['colecciones' => '*', 'escribir' => true]]]);
$db->insert('p', ['n' => 1], 'p1');

$avisos = [];
\set_error_handler(static function (int $no, string $msg) use (&$avisos): bool {
    $avisos[] = $msg;
    return true;
}, E_ALL);

$r = pedir($s, ['accion' => 'find', 'coleccion' => 'p', 'donde' => [[['a' => 1], '=', 1]]], T_PEDIDOS);
ok('un objeto como nombre de campo no provoca ningun aviso de PHP', $avisos === []);
ok('  y se contesta 4xx explicando la forma correcta',
    $r->codigo >= 400 && $r->codigo < 500);

$avisos = [];
$r = pedir($s, ['accion' => 'find', 'coleccion' => 'p', 'donde' => [], 'campos' => [['x']]], T_PEDIDOS);
ok('un array dentro de campos tampoco', $avisos === []);
ok('  y tambien se contesta 4xx', $r->codigo >= 400 && $r->codigo < 500);

$avisos = [];
$r = pedir($s, ['accion' => 'find', 'coleccion' => 'p', 'donde' => [['n', ['='], 1]]], T_PEDIDOS);
ok('ni un array como operador', $avisos === []);

\restore_error_handler();

// Tipos escalares raros: aqui la defensa es correcta y se deja anotada.
respuesta('un booleano como id se rechaza',
    pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => true], T_PEDIDOS), 400, false);
respuesta('un objeto como accion se rechaza',
    pedir($s, ['accion' => ['get' => 1], 'coleccion' => 'p'], T_PEDIDOS), 400, false);
// Un numero como nombre de coleccion es legal —'123' pasa la validacion de
// Names— y lo unico que importa es que se trate como la coleccion '123' y no
// como un indice ni como otra cosa.
$r = pedir($s, ['accion' => 'get', 'coleccion' => 123, 'id' => 'p1'], T_PEDIDOS);
respuesta('un numero como coleccion es la coleccion con ese nombre', $r, 200, true);
eq('  y no devuelve el documento de otra', null, dato($r));

/* ═══════════════════════════════════════════════════════════════════════════
 * D] Numeros JSON legales que el motor no sabe volver a escribir.
 *
 * 1e999 es JSON valido y json_decode lo acepta como INF. Al guardarlo,
 * json_encode falla —INF no se puede representar— y la escritura revienta a
 * medias. El resultado son archivos temporales y de bloqueo que ya no borra
 * nadie: cada peticion deja uno nuevo, con nombre distinto, para siempre.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('D] Numeros que rompen la escritura y dejan restos');

[$s, $db, $dir] = puente('sec_http_inf', ['tokens' => [T_PEDIDOS => ['colecciones' => '*', 'escribir' => true]]]);
$db->insert('c', ['n' => 1], 'c1');

$r = crudo($s, '{"accion":"insert","coleccion":"c","id":"veneno","datos":{"n":1e999}}', T_PEDIDOS);
ok('un numero fuera de rango se contesta 4xx, no 500', $r->codigo >= 400 && $r->codigo < 500);
ok('  sin enseñar nada del disco',
    !\str_contains($r->json(), '.php') && !\str_contains($r->json(), $dir));

// Repetido: lo que importa no es el fallo, es lo que va quedando detras.
for ($i = 0; $i < 15; $i++) {
    crudo($s, '{"accion":"insert","coleccion":"c","id":"veneno","datos":{"n":1e999}}', T_PEDIDOS);
}
$restos = \array_values(\array_filter(
    \array_map('basename', \glob($dir . '/c/*') ?: []),
    static fn(string $n) => \str_contains($n, '.tmp.')
));
if ($restos !== []) {
    echo '    ' . \count($restos) . " temporales huerfanos tras 16 peticiones\n";
}
eq('una escritura fallida no deja archivos temporales huerfanos', 0, \count($restos));

respuesta('y el motor sigue atendiendo despues',
    pedir($s, ['accion' => 'count', 'coleccion' => 'c'], T_PEDIDOS), 200, true);
respuesta('el id que fallo se puede volver a usar',
    pedir($s, ['accion' => 'insert', 'coleccion' => 'c', 'id' => 'veneno', 'datos' => ['n' => 2]], T_PEDIDOS),
    200, true);

/* ═══════════════════════════════════════════════════════════════════════════
 * E] Comparacion de tokens.
 *
 * Lo que se busca aqui es el `==` que compara con conversion de tipos: en PHP
 * '0e123' == '0e456' es verdadero porque las dos cadenas parecen notacion
 * cientifica y se comparan como numeros. Con esa comparacion, cualquier cadena
 * '0e<digitos>' abre un token '0e<digitos>'.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('E] Comparacion de tokens');

$cientifico = '0e123456789012345678901234567890';
[$s, $db] = puente('sec_http_token_0e', ['tokens' => [$cientifico => ['colecciones' => '*', 'escribir' => true]]]);
$db->insert('c', ['n' => 1], 'c1');

respuesta('el token bueno entra',
    pedir($s, ['accion' => 'get', 'coleccion' => 'c', 'id' => 'c1'], $cientifico), 200, true);
respuesta('otra cadena en notacion cientifica no entra',
    pedir($s, ['accion' => 'get', 'coleccion' => 'c', 'id' => 'c1'], '0e999999999999999999999999999999'), 401, false);
respuesta('ni un cero pelado',
    pedir($s, ['accion' => 'get', 'coleccion' => 'c', 'id' => 'c1'], '0'), 401, false);

// Un token todo digitos se guarda como clave de array, y PHP convierte esas
// claves a entero. Si al comparar no se volviera a cadena, el token dejaria de
// funcionar o compararia numeros.
[$s, $db] = puente('sec_http_token_num', ['tokens' => ['1234567890' => ['colecciones' => '*', 'escribir' => true]]]);
$db->insert('c', ['n' => 1], 'c1');
respuesta('un token de solo digitos sigue siendo el mismo token',
    pedir($s, ['accion' => 'get', 'coleccion' => 'c', 'id' => 'c1'], '1234567890'), 200, true);
respuesta('y no lo abre un numero igual escrito distinto',
    pedir($s, ['accion' => 'get', 'coleccion' => 'c', 'id' => 'c1'], '1234567890.0'), 401, false);
respuesta('ni con ceros delante',
    pedir($s, ['accion' => 'get', 'coleccion' => 'c', 'id' => 'c1'], '01234567890'), 401, false);

// Prefijos correctos: con `===` o con `==` el tiempo delata cuantos caracteres
// se han acertado. Con hash_equals no. No se mide un umbral —seria un test que
// falla los viernes—: se comprueba que la comparacion es la correcta.
$fuente = (string) \file_get_contents(\dirname(__DIR__) . '/Http/Permissions.php');
ok('los tokens se comparan con hash_equals', \str_contains($fuente, 'hash_equals'));
ok('y no queda ninguna comparacion suelta con ==',
    !\preg_match('/\$token\s*==[^=]/', $fuente));

/* ═══════════════════════════════════════════════════════════════════════════
 * F] La lista de acciones.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('F] La lista de acciones no se cuela por los bordes');

[$s, $db] = puente('sec_http_acciones', ['tokens' => [T_PEDIDOS => ['colecciones' => '*', 'escribir' => true]]]);
$db->insert('p', ['n' => 1], 'p1');

$disfraces = [
    'GET'          => 'en mayusculas',
    'Get'          => 'capitalizada',
    ' get'         => 'con un espacio delante',
    "get\n"        => 'con un salto de linea detras',
    "get\0"        => 'con un byte nulo detras',
    'get.'         => 'con un punto',
    'ｇｅｔ'        => 'en caracteres anchos de unicode',
    'gеt'          => 'con una e cirilica',
    'sql;'         => 'con un punto y coma',
];
foreach ($disfraces as $accion => $porque) {
    respuesta("la accion {$porque} no pasa",
        pedir($s, ['accion' => $accion, 'coleccion' => 'p', 'id' => 'p1'], T_PEDIDOS), 400, false);
}
respuesta('una accion anidada en un array no pasa',
    pedir($s, ['accion' => ['get'], 'coleccion' => 'p', 'id' => 'p1'], T_PEDIDOS), 400, false);
respuesta('ni un entero que se parezca a una posicion',
    pedir($s, ['accion' => 0, 'coleccion' => 'p', 'id' => 'p1'], T_PEDIDOS), 400, false);

// La clave duplicada gana la ultima, como en cualquier analizador JSON. No es
// un fallo del puente, pero conviene tenerlo escrito: un filtro delante que
// mire la PRIMERA no esta viendo lo que se ejecuta.
$r = crudo($s, '{"accion":"get","accion":"insert","coleccion":"p","datos":{"colado":1}}', T_PEDIDOS);
ok('con la accion duplicada manda la ultima, y hay que saberlo',
    ($r->cuerpo['ok'] ?? false) === true);

/* ═══════════════════════════════════════════════════════════════════════════
 * G] EXPLAIN: se clasifica como lectura aunque la sentencia sea destructiva.
 *
 * Bridge::sqlWrites devuelve false en cuanto hay EXPLAIN. Eso deja pasar
 * `EXPLAIN DROP COLLECTION x` con un token de solo lectura. Lo que hay que
 * comprobar es que, pese a esa clasificacion, no se modifica nada.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('G] EXPLAIN de sentencias destructivas');

[$s, $db, $dir] = puente('sec_http_explain', [
    'tokens' => [T_LECTURA => ['colecciones' => '*', 'escribir' => false]],
]);
$db->insert('c', ['n' => 1], 'c1');

foreach ([
    'EXPLAIN DELETE FROM c',
    'EXPLAIN DROP COLLECTION c',
    'EXPLAIN CREATE COLLECTION nueva',
    'EXPLAIN UPDATE c SET n = 9',
    "EXPLAIN INSERT INTO c (n) VALUES (7)",
] as $sentencia) {
    sql($s, $sentencia, T_LECTURA);
}
eq('EXPLAIN no borra documentos',            1, $db->count('c'));
eq('EXPLAIN no modifica documentos',         1, $db->get('c', 'c1')['n']);
ok('EXPLAIN no crea colecciones',            !\is_dir($dir . '/nueva'));
ok('la coleccion sigue existiendo',          \is_dir($dir . '/c'));

// Y en modo solo lectura, lo mismo por el otro camino.
[$s, $db, $dir] = puente('sec_http_explain_ro', [
    'tokens'      => [T_PEDIDOS => ['colecciones' => '*', 'escribir' => true]],
    'soloLectura' => true,
]);
$db->insert('c', ['n' => 1], 'c1');
sql($s, 'EXPLAIN DELETE FROM c', T_PEDIDOS);
sql($s, 'EXPLAIN DROP COLLECTION c', T_PEDIDOS);
eq('en modo solo lectura, EXPLAIN tampoco toca nada', 1, $db->count('c'));

/* ═══════════════════════════════════════════════════════════════════════════
 * H] Vistas y colecciones internas.
 *
 * Una vista es un SELECT guardado que se ejecuta al leerla, asi que crear una
 * es escribir codigo que otro ejecutara con SUS permisos. Por el puente no se
 * puede crear —CREATE VIEW no lleva 'collection' y muere en el 400 de rigor—,
 * pero conviene dejarlo fijado con un test para que no se abra sin querer.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('H] Vistas: no se crean ni se leen fuera del ambito');

[$s, $db, $dir] = puente('sec_http_vistas', [
    'tokens'   => [T_PEDIDOS => ['colecciones' => ['catalogo'], 'escribir' => true]],
    'publicas' => ['catalogo'],
]);
$db->insert('catalogo', ['nombre' => 'Vaso'], 'v1');
$db->insert('privada',  ['tarjeta' => 'TARJETA_SECRETA'], 's1');
$db->sql('CREATE VIEW espejo AS SELECT * FROM privada');

$r = sql($s, 'CREATE VIEW puerta AS SELECT * FROM privada', T_PEDIDOS);
ok('no se crea una vista por el puente', $r->codigo >= 400);
$r = sql($s, 'SELECT * FROM espejo', T_PEDIDOS);
ok('un token fuera de ambito no lee una vista ajena', !filtra($r, 'TARJETA_SECRETA'));
$r = sql($s, 'SELECT * FROM espejo', null, INTERNET);
ok('ni un anonimo', !filtra($r, 'TARJETA_SECRETA'));

// La coleccion donde el motor guarda las vistas es una coleccion normal, y por
// tanto alcanzable por un token de ambito '*'. Quien la escribe decide que SQL
// ejecuta el que despues lea una coleccion con ese nombre. No es escalada
// —ese token ya lo escribe todo— pero si es un sitio donde esconderse.
$r = pedir($s, ['accion' => 'insert', 'coleccion' => 'axidb_vistas', 'id' => 'catalogo',
    'datos' => ['sql' => 'SELECT * FROM privada']], T_PEDIDOS);
ok('un token de ambito limitado no escribe en la coleccion de vistas',
    $r->codigo === 403);
$r = sql($s, 'SELECT * FROM catalogo', null, INTERNET);
ok('y la coleccion publica no quedo sombreada', !filtra($r, 'TARJETA_SECRETA'));

/* ═══════════════════════════════════════════════════════════════════════════
 * I] CORS: lo que se refleja en la cabecera.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('I] CORS e inyeccion en cabeceras');

[$s, $db] = puente('sec_http_cors', ['origenes' => ['https://miweb.com']]);
$db->insert('p', ['n' => 1], 'p1');

$intentos = [
    "https://miweb.com\r\nX-Inyectada: si" => 'un salto de linea dentro del origen',
    "https://miweb.com\nX-Inyectada: si"   => 'solo un salto',
    "https://miweb.com\r\n\r\n<script>"    => 'cortar la respuesta y meter cuerpo',
    'https://miweb.com%0d%0aX-Evil:1'      => 'el salto codificado en URL',
    'https://miweb.com#@evil.com'          => 'una almohadilla para despistar',
    'https://miweb.com@evil.com'           => 'la arroba de credenciales',
    'https://evil.com?x=https://miweb.com' => 'el dominio bueno en la consulta',
    'https://miweb.com.evil.com'           => 'el dominio bueno de prefijo',
    'https://xn--miweb-0va.com'            => 'un homografo punycode',
];
foreach ($intentos as $origen => $porque) {
    $r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'], null, $origen);
    $reflejado = $r->cabeceras['Access-Control-Allow-Origin'] ?? '';
    ok("no se autoriza {$porque}", $reflejado === '' || $reflejado === 'https://miweb.com');
    ok('  y ninguna cabecera lleva un salto de linea',
        !\preg_match('/[\r\n]/', \implode('', \array_merge(\array_keys($r->cabeceras), \array_values($r->cabeceras)))));
}

// El origen con credenciales normaliza al dominio limpio y por tanto se acepta.
// Ningun navegador manda eso en Origin, asi que no da acceso a nadie que no lo
// tuviera; queda anotado porque lo que se refleja debe ser SIEMPRE un origen
// declarado, nunca lo que mando el cliente.
$r = pedir($s, ['accion' => 'get', 'coleccion' => 'p', 'id' => 'p1'], null, 'https://usuario:clave@miweb.com');
$reflejado = $r->cabeceras['Access-Control-Allow-Origin'] ?? '';
ok('lo reflejado es un origen declarado, no la cadena del cliente',
    $reflejado === '' || $reflejado === 'https://miweb.com');

eq('normalizar deja el salto de linea fuera del resultado utilizable',
    false, \str_contains(Cors::normalizar("https://miweb.com\r\nX-Evil: 1"), "\r"));
eq('un esquema que no es http(s) no produce origen', '', Cors::normalizar('data:text/html,x'));
eq('ni un origen relativo',                          '', Cors::normalizar('//miweb.com'));

// Peticion simple de formulario: sin preflight, pero con Origin. Es el vector
// de CSRF clasico contra una API que confia en localhost.
$r = pedir($s, ['accion' => 'insert', 'coleccion' => 'p', 'datos' => ['csrf' => 1]], null, 'https://evil.com');
respuesta('una peticion desde otra pagina se corta en el servidor', $r, 403, false);
eq('y no escribio nada', 1, $db->count('p'));

/* ═══════════════════════════════════════════════════════════════════════════
 * J] Contra un servidor de verdad.
 *
 * Todo lo anterior llama a Server::respond() en proceso, que es donde se ven
 * los codigos y las cabeceras con precision. Lo que eso NO ve es el cuerpo real
 * que sale por el socket: los avisos de PHP se imprimen antes del JSON y no
 * pasan por Response. Por eso este bloque abre un puerto y habla por TCP.
 * ═══════════════════════════════════════════════════════════════════════════ */
section('J] Contra un servidor PHP real');

$raiz  = tmpdir('sec_http_real');
$datos = $raiz . '/datos';
@\mkdir($datos, 0777, true);

// Ruta al nucleo con barras normales: va dentro de una cadena PHP entrecomillada
// y en Windows las contrabarras se comerian el escape.
$nucleo = \str_replace('\\', '/', \dirname(__DIR__) . '/axidb.php');

\file_put_contents($raiz . '/api.php', <<<PHP
<?php
declare(strict_types=1);
require '{$nucleo}';
axidb_http(__DIR__ . '/datos', [
    'origenes' => ['https://miweb.com'],
    'tokens'   => ['TOKEN_REAL_0123456789abcdef' => ['colecciones' => '*', 'escribir' => true]],
    'publicas' => ['catalogo'],
]);
PHP);

$sembrar = new \Axi\Core\Db($datos, ['durable' => false]);
$sembrar->insert('p', ['n' => 1], 'p1');
$sembrar->insert('catalogo', ['nombre' => 'Vaso'], 'v1');
$sembrar->insert('privada', ['tarjeta' => 'TARJETA_SECRETA'], 's1');

$tuberias = [];
$servidor = \proc_open(
    \escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . PUERTO . ' api.php',
    [1 => ['file', $raiz . '/servidor.log', 'w'], 2 => ['file', $raiz . '/servidor.log', 'a']],
    $tuberias,
    $raiz,
    null,
    ['bypass_shell' => true]
);
$pidServidor = \is_resource($servidor) ? (\proc_get_status($servidor)['pid'] ?? 0) : 0;

$vivo  = false;
$hasta = \microtime(true) + 10.0;
while (\microtime(true) < $hasta) {
    $c = @\stream_socket_client('tcp://127.0.0.1:' . PUERTO, $e, $m, 0.2);
    if ($c) {
        \fclose($c);
        $vivo = true;
        break;
    }
    \usleep(100000);
}
ok('el servidor de pruebas responde en el puerto ' . PUERTO, $vivo);

/**
 * Peticion por socket, con control total de la linea de peticion, cabeceras y
 * cuerpo. file_get_contents no vale: no deja mandar metodos inventados ni
 * omitir Content-Length.
 *
 * @return array{codigo:int, cabeceras:string, cuerpo:string}
 */
function socket(string $peticion): array
{
    $c = @\stream_socket_client('tcp://127.0.0.1:' . PUERTO, $e, $m, 5.0);
    if (!$c) {
        return ['codigo' => 0, 'cabeceras' => '', 'cuerpo' => ''];
    }
    \stream_set_timeout($c, 10);
    \fwrite($c, $peticion);
    $todo = '';
    while (!\feof($c)) {
        $trozo = \fread($c, 8192);
        if ($trozo === false || $trozo === '') {
            break;
        }
        $todo .= $trozo;
    }
    \fclose($c);

    $corte     = \strpos($todo, "\r\n\r\n");
    $cabeceras = $corte === false ? $todo : \substr($todo, 0, $corte);
    $cuerpo    = $corte === false ? '' : \substr($todo, $corte + 4);
    $codigo    = \preg_match('#^HTTP/\S+\s+(\d+)#', $todo, $mm) ? (int) $mm[1] : 0;

    return ['codigo' => $codigo, 'cabeceras' => $cabeceras, 'cuerpo' => $cuerpo];
}

/** POST con cuerpo JSON y, si se pide, token. */
function post(string $json, ?string $token = null, array $cabeceras = []): array
{
    $lineas = [
        'POST /api.php HTTP/1.1',
        'Host: 127.0.0.1:' . PUERTO,
        'Content-Type: application/json',
        'Content-Length: ' . \strlen($json),
        'Connection: close',
    ];
    if ($token !== null) {
        $lineas[] = 'Authorization: Bearer ' . $token;
    }
    foreach ($cabeceras as $c) {
        $lineas[] = $c;
    }
    return socket(\implode("\r\n", $lineas) . "\r\n\r\n" . $json);
}

const TOKEN_REAL = 'TOKEN_REAL_0123456789abcdef';

if (!$vivo) {
    ok('sin servidor no hay nada que probar aqui', false);
} else {
    $r = post('{"accion":"get","coleccion":"p","id":"p1"}', TOKEN_REAL);
    eq('una peticion legitima responde 200', 200, $r['codigo']);
    ok('  y el cuerpo es JSON puro', \json_decode($r['cuerpo'], true) !== null);
    ok('  con la cabecera que impide adivinar el tipo',
        \stripos($r['cabeceras'], 'X-Content-Type-Options: nosniff') !== false);
    ok('  y sin cache intermedia',
        \stripos($r['cabeceras'], 'Cache-Control: no-store') !== false);

    /*
     * El ataque de este bloque. Si PHP imprime el aviso, sale delante del JSON
     * y con la ruta absoluta del servidor dentro.
     */
    $r = post('{"accion":"find","coleccion":"p","donde":[[{"a":1},"=",1]]}', TOKEN_REAL);
    $sospechoso = \str_contains($r['cuerpo'], 'Warning')
        || \str_contains($r['cuerpo'], 'Array to string')
        || \str_contains($r['cuerpo'], '.php')
        || \str_contains($r['cuerpo'], 'Bridge');
    if ($sospechoso) {
        echo '    cuerpo devuelto: ' . \substr(\str_replace(["\r", "\n"], ' ', $r['cuerpo']), 0, 200) . "\n";
    }
    ok('un objeto en donde[] no imprime avisos de PHP en el cuerpo', !$sospechoso);
    ok('  y la respuesta sigue siendo JSON valido',
        \is_array(\json_decode($r['cuerpo'], true)));

    $r = post('{"accion":"find","coleccion":"p","donde":[],"campos":[["x"]]}', TOKEN_REAL);
    ok('tampoco un array en campos[]',
        !\str_contains($r['cuerpo'], 'Warning') && !\str_contains($r['cuerpo'], '.php'));

    $r = post('{"accion":"insert","coleccion":"p","datos":{"n":1e999}}', TOKEN_REAL);
    ok('un numero fuera de rango no filtra la ruta del servidor',
        !\str_contains($r['cuerpo'], '.php') && !\str_contains($r['cuerpo'], 'Fatal'));
    ok('  y no se contesta con un 500', $r['codigo'] !== 500);

    /* Metodos. */
    foreach (['GET', 'PUT', 'DELETE', 'PATCH', 'TRACE', 'INVENTADO'] as $metodo) {
        $r = socket("{$metodo} /api.php HTTP/1.1\r\nHost: 127.0.0.1:" . PUERTO
            . "\r\nAuthorization: Bearer " . TOKEN_REAL . "\r\nConnection: close\r\n\r\n");
        ok("{$metodo} no atiende la base de datos",
            $r['codigo'] === 405 || $r['codigo'] === 501 || $r['codigo'] === 400);
        ok('  y no devuelve ningun documento', !\str_contains($r['cuerpo'], '"dato":{'));
    }

    $r = socket("TRACE /api.php HTTP/1.1\r\nHost: 127.0.0.1:" . PUERTO
        . "\r\nX-Reflejo: SECRETO_TRACE\r\nConnection: close\r\n\r\n");
    ok('TRACE no devuelve las cabeceras que mando el cliente',
        !\str_contains($r['cuerpo'], 'SECRETO_TRACE'));

    $r = socket("HEAD /api.php HTTP/1.1\r\nHost: 127.0.0.1:" . PUERTO . "\r\nConnection: close\r\n\r\n");
    ok('HEAD no devuelve cuerpo', \trim($r['cuerpo']) === '');

    /* Token, por el cable. */
    $r = post('{"accion":"get","coleccion":"p","id":"p1"}');
    eq('sin token no se atiende, aunque se llame desde localhost', 401, $r['codigo']);
    $r = post('{"accion":"get","coleccion":"p","id":"p1"}', '');
    ok('con un token vacio tampoco', $r['codigo'] === 401);
    $r = post('{"accion":"get","coleccion":"p","id":"p1"}', 'TOKEN_REAL_0123456789abcde');
    eq('ni con el token al que le falta un caracter', 401, $r['codigo']);
    $r = post('{"accion":"get","coleccion":"p","id":"p1"}', 'TOKEN_REAL_0123456789ABCDEF');
    eq('ni cambiando las mayusculas', 401, $r['codigo']);

    // Authorization repetida: si el servidor se queda con una y el puente con
    // otra, se cuela un token por delante de un filtro.
    $r = post('{"accion":"get","coleccion":"p","id":"p1"}', 'invalido',
        ['Authorization: Bearer ' . TOKEN_REAL]);
    ok('dos cabeceras Authorization no dejan pasar la buena por detras',
        $r['codigo'] === 401 || $r['codigo'] === 400);

    /* Limite de tamaño, medido de verdad sobre el socket. */
    $enorme = (string) \json_encode(['accion' => 'insert', 'coleccion' => 'p',
        'datos' => ['t' => \str_repeat('a', Request::LIMITE_BYTES * 2)]]);
    $r = post($enorme, TOKEN_REAL);
    eq('un cuerpo del doble del limite se rechaza con 413', 413, $r['codigo']);

    $r = post('{"accion":"count","coleccion":"p"}', TOKEN_REAL, ['Content-Length-Falso: 999999999']);
    eq('y una cabecera inventada de longitud no descoloca nada', 200, $r['codigo']);

    // Sin Content-Length declarado y con cuerpo enorme: el limite tiene que
    // seguir aplicandose al leer, no al creer lo que dice el cliente.
    $cuerpoEnorme = \str_repeat('a', Request::LIMITE_BYTES * 2);
    $r = socket("POST /api.php HTTP/1.1\r\nHost: 127.0.0.1:" . PUERTO
        . "\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n"
        . \dechex(\strlen($cuerpoEnorme)) . "\r\n" . $cuerpoEnorme . "\r\n0\r\n\r\n");
    ok('un cuerpo troceado y enorme no se traga entero',
        $r['codigo'] >= 400 && $r['codigo'] < 500);

    /* CORS por el cable. */
    $r = post('{"accion":"get","coleccion":"p","id":"p1"}', TOKEN_REAL, ['Origin: https://evil.com']);
    eq('un origen no declarado se rechaza', 403, $r['codigo']);
    ok('  y no se le responde ninguna cabecera de permiso',
        \stripos($r['cabeceras'], 'Access-Control-Allow-Origin') === false);

    $r = post('{"accion":"get","coleccion":"p","id":"p1"}', TOKEN_REAL, ['Origin: https://miweb.com']);
    ok('el origen declarado recibe su cabecera',
        \stripos($r['cabeceras'], 'Access-Control-Allow-Origin: https://miweb.com') !== false);
    ok('  y nunca el comodin', !\str_contains($r['cabeceras'], 'Allow-Origin: *'));
    ok('  ni credenciales abiertas',
        \stripos($r['cabeceras'], 'Access-Control-Allow-Credentials') === false);

    /* Exfiltracion anonima contra el servidor real. */
    $r = post('{"accion":"sql","sentencia":"SELECT id FROM catalogo WHERE EXISTS '
        . '(SELECT * FROM privada WHERE tarjeta LIKE \'TARJETA%\')"}');
    $oraculoSi = \str_contains($r['cuerpo'], '"v1"');
    $r = post('{"accion":"sql","sentencia":"SELECT id FROM catalogo WHERE EXISTS '
        . '(SELECT * FROM privada WHERE tarjeta LIKE \'ZZZZZ%\')"}');
    $oraculoNo = \str_contains($r['cuerpo'], '"v1"');
    ok('por el cable tampoco hay oraculo anonimo sobre una coleccion privada',
        $oraculoSi === $oraculoNo);

    /* Los datos no se sirven como archivos. */
    foreach (['/datos/privada/s1.json', '/datos/', '/api.php.bak', '/servidor.log'] as $ruta) {
        $r = socket("GET {$ruta} HTTP/1.1\r\nHost: 127.0.0.1:" . PUERTO . "\r\nConnection: close\r\n\r\n");
        ok("{$ruta} no entrega contenido", !\str_contains($r['cuerpo'], 'TARJETA_SECRETA'));
    }

    /* Despues de todo, el puente sigue en pie. */
    $r = post('{"accion":"count","coleccion":"p"}', TOKEN_REAL);
    eq('el puente sigue respondiendo al final', 200, $r['codigo']);
    eq('  y la coleccion tiene los documentos de siempre', 1, \json_decode($r['cuerpo'], true)['dato'] ?? -1);
}

if (\is_resource($servidor)) {
    @\proc_terminate($servidor, 9);
    foreach ($tuberias as $t) {
        if (\is_resource($t)) {
            @\fclose($t);
        }
    }
    @\proc_close($servidor);
    if ($pidServidor > 0) {
        forceKill($pidServidor);
    }
}
rmrf($raiz);

summary();
