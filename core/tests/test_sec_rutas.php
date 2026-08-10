<?php
/**
 * AxiDB - auditoria ofensiva de la superficie de rutas y sistema de ficheros.
 *
 * En un motor que guarda cada documento en un archivo, TODO nombre es una ruta:
 * la coleccion es un directorio, el id es un archivo, el campo indexado es otro
 * directorio y el valor indexado es otro archivo. Cinco traducciones de texto a
 * ruta, y basta que una falle para que el motor escriba, lea o borre donde no
 * debe.
 *
 * Este archivo ataca esa traduccion de verdad: no comprueba que exista una
 * validacion, comprueba que la validacion aguanta el ataque. Cada asercion
 * afirma el comportamiento SEGURO. Una asercion en rojo aqui no es un test que
 * haya que ablandar: es un hallazgo.
 *
 * Complementa a test_http_traversal.php (que mira el puente HTTP) y a
 * test_portabilidad.php (que mira las mayusculas). Aqui se entra por la API de
 * PHP, que es la que usa quien integra AxiDB, y donde no hay ningun 400 que
 * pare nada antes.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Names;

$ES_WINDOWS = \stripos(PHP_OS_FAMILY, 'Windows') !== false;

/** Un nombre es seguro si Names::check lo rechaza. */
function rechaza(string $valor): bool
{
    try {
        Names::check($valor, 'prueba');
        return false;
    } catch (\Axi\Core\Exception) {
        return true;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════ */
section('A] La primera muralla: Names::check');

/*
 * Traversal en todas las formas que se escriben en la practica. Importa la
 * variedad y no solo '../': un filtro que solo mira la forma canonica deja
 * pasar la codificada, y el sistema de archivos las acaba resolviendo igual.
 */
$traversal = [
    '../otro'                  => 'subir un nivel',
    '..'                       => 'los dos puntos a secas',
    '..\\otro'                 => 'con barra invertida de Windows',
    'a/../../b'                => 'con el salto en medio',
    'a\\..\\..\\b'             => 'lo mismo con barras invertidas',
    '/etc/passwd'              => 'ruta absoluta de Unix',
    'C:/Windows/win.ini'       => 'ruta absoluta de Windows',
    'C:\\Windows\\win.ini'     => 'ruta absoluta con barras invertidas',
    '\\\\servidor\\recurso'    => 'ruta UNC a otra maquina',
    '//servidor/recurso'       => 'UNC con barras normales',
    'a/b'                      => 'una barra suelta ya es un nivel nuevo',
    'a\\b'                     => 'una barra invertida tambien, en Windows',
];
foreach ($traversal as $valor => $porque) {
    ok("rechaza {$porque}", rechaza($valor));
}

/*
 * Codificaciones. El motor no decodifica nada, asi que estas cadenas no son
 * peligrosas por si mismas; lo son cuando alguien las decodifica por el camino
 * (un proxy, un framework, una capa de rutas). Rechazar el '%' y el byte alto
 * cierra la puerta antes de que aparezca esa capa.
 */
$codificado = [
    '%2e%2e%2fp'          => 'los puntos en URL-encoding',
    '%252e%252e'          => 'doble codificacion',
    '..%2fp'              => 'mezcla de literal y codificado',
    '%c0%ae%c0%ae'        => 'UTF-8 overlong escrito en texto',
    "\xC0\xAE\xC0\xAE"    => 'UTF-8 overlong en bytes crudos',
    "\xE0\x80\xAE"        => 'overlong de tres bytes',
    "\xEF\xBC\x8E\xEF\xBC\x8E" => 'punto de ancho completo (homoglifo)',
    "\xD0\xB0dmin"        => "una 'a' cirilica que parece latina",
    "\xC3\xA9xito"        => 'acento legitimo, tampoco vale como nombre de ruta',
    "\xFF\xFEa\x00"       => 'preambulo UTF-16',
];
foreach ($codificado as $valor => $porque) {
    ok("rechaza {$porque}", rechaza($valor));
}

// Ni un solo byte por encima de 0x7F puede entrar. Es lo que hace que la
// expresion regular sin bandera /u sea una defensa y no un descuido.
$altosAceptados = 0;
for ($b = 0x80; $b <= 0xFF; $b++) {
    if (!rechaza('a' . \chr($b))) {
        $altosAceptados++;
    }
}
eq('ningun byte de 0x80 a 0xFF se cuela en un nombre', 0, $altosAceptados);

/*
 * Byte nulo y caracteres de control. El byte nulo EN MEDIO es el clasico: en C
 * corta la cadena, asi que 'inocente\0../../evil' puede llegar al sistema como
 * dos cosas distintas segun quien la mire. El salto de linea importa porque los
 * nombres acaban en mensajes de error y en logs.
 */
$control = [
    "p\x00.json"   => 'byte nulo al final',
    "in\x00../ev"  => 'byte nulo en medio',
    "a\nb"         => 'salto de linea',
    "a\r\nb"       => 'retorno de carro y salto',
    "a\tb"         => 'tabulador',
    "a\x1bb"       => 'escape ANSI',
    "a\x7f"        => 'DEL',
    "\x00"         => 'solo el byte nulo',
];
foreach ($control as $valor => $porque) {
    ok("rechaza {$porque}", rechaza($valor));
}

// Metacaracteres de shell y de glob. glob() se usa en Index y en Sweeper, asi
// que un '*' o un '?' dentro de un nombre podria ampliar un borrado.
foreach (['a*b', 'a?b', 'a[b]c', 'a{b}c', 'a;b', 'a|b', 'a&b', 'a$b', 'a`b', 'a>b', 'a"b', "a'b"] as $v) {
    ok('rechaza el metacaracter en ' . \json_encode($v), rechaza($v));
}

// Limites y prefijos reservados del propio motor.
ok('rechaza el vacio',                       rechaza(''));
ok('rechaza empezar por punto',              rechaza('.hidden'));
ok('rechaza empezar por guion bajo',         rechaza('_interno'));
ok('rechaza el .htaccess del blindaje',      rechaza('.htaccess'));
ok('rechaza la marca ~ de portabilidad',     rechaza('users~1234abcd'));
ok('rechaza espacios por delante',           rechaza(' a'));
ok('rechaza espacios por detras',            rechaza('a '));
ok('rechaza mas de 128 bytes',               rechaza(\str_repeat('a', 129)));
ok('acepta exactamente 128 bytes',           !rechaza(\str_repeat('a', 128)));

// Y lo legitimo sigue pasando: una muralla que tira lo bueno no sirve.
foreach (['pedidos', 'Pedidos', 'carta_productos', 'l_4440c33-1', 'a-b.c', 'x9'] as $bueno) {
    ok("sigue aceptando '{$bueno}'", !rechaza($bueno));
}

/* ═══════════════════════════════════════════════════════════════════════════ */
section('B] Nombres reservados de Windows');

/*
 * CON, PRN, AUX, NUL, COM1-9 y LPT1-9 no son nombres: son dispositivos. En
 * Windows, y en cualquier version de Windows, abrirlos como archivo abre el
 * dispositivo. Un motor que promete la misma carpeta en todas partes tiene que
 * rechazarlos, porque en Linux si son nombres normales y una carpeta creada
 * alli no se puede abrir aqui.
 */
$reservados = ['con', 'prn', 'aux', 'nul', 'com1', 'com9', 'lpt1', 'lpt9',
               'CON', 'NUL', 'con.json', 'nul.txt'];
foreach ($reservados as $r) {
    ok("rechaza el nombre reservado '{$r}'", rechaza($r));
}

// Lo mismo para el VALOR de un campo indexado: forValue lo convierte en el
// nombre del archivo del cubo, asi que un valor 'nul' produce '_idx/x/nul.json'.
foreach (['nul', 'con', 'aux', 'com1'] as $v) {
    ok("el valor indexado '{$v}' no se usa tal cual como nombre de archivo",
        \str_starts_with(Names::forValue($v), 'h_'));
}

$dirB = tmpdir('sec_rutas_b') . '/datos';
$dbB  = new Db($dirB, ['durable' => false]);

/*
 * Aceptar un nombre reservado y despues fallar al usarlo es lo peor de las dos
 * opciones: el que integra se entera en produccion y con un mensaje que habla de
 * un cerrojo. El motor lo rechaza ANTES de tocar el disco, con un error de
 * nombre, y no deja nada creado. El rechazo es portable: se aplica tambien en
 * Linux, donde 'nul' si seria un nombre valido, para que la carpeta signifique
 * lo mismo en todas partes.
 */
throws("una coleccion 'nul' se rechaza antes de tocar el disco",
    static fn() => $dbB->insert('nul', ['x' => 1], 'd1'));
ok('y no queda ninguna carpeta de dispositivo', !\is_dir($dirB . '/nul'));

throws('ensureCollection tampoco crea un nombre reservado',
    static fn() => $dbB->storage()->ensureCollection('nul'));

/* ═══════════════════════════════════════════════════════════════════════════ */
section('C] Puntos y espacios al final');

/*
 * Windows recorta los puntos y los espacios finales de cada tramo de una ruta,
 * asi que 'carpeta.' y 'carpeta' acaban siendo el mismo directorio. Dos nombres
 * que el motor considera distintos y el disco funde en uno es exactamente el
 * fallo que Names existe para impedir.
 */
ok('rechaza un punto final',        rechaza('carpeta.'));
ok('rechaza varios puntos finales', rechaza('carpeta..'));   // ya lo pilla la regla de '..'
ok('rechaza un punto en medio y al final', rechaza('a.b.'));
ok('rechaza el punto solo',         rechaza('.'));

$dirC = tmpdir('sec_rutas_c') . '/datos';
$dbC  = new Db($dirC, ['durable' => false]);

// Un nombre aceptado tiene que poder crearse. En Windows, 'carpeta.' no puede.
$creable = true;
try {
    Names::check('carpeta.', 'coleccion');
    $dbC->insert('carpeta.', ['x' => 1], 'd1');
} catch (\Axi\Core\InvalidName) {
    $creable = true;                       // rechazado antes de tocar el disco: correcto
} catch (\Throwable) {
    $creable = false;                      // aceptado y luego imposible de crear
}
ok('ningun nombre aceptado se queda sin poder crearse', $creable);

// Los ids si estan a salvo, y conviene dejarlo escrito: el sufijo '.json' que
// añade el driver hace que el punto final deje de ser final.
$dbC->insert('puntos', ['q' => 'sin'], 'x');
eq("el id 'x' conserva su documento", 'sin', $dbC->get('puntos', 'x')['q'] ?? null);

/* ═══════════════════════════════════════════════════════════════════════════ */
section('D] Colisiones por mayusculas y la marca de portabilidad');

/*
 * Cubierto a fondo en test_portabilidad.php. Aqui solo el angulo ofensivo:
 * ¿puede alguien FABRICAR la forma codificada de otro nombre y quedarse con su
 * archivo? No, porque el caracter de la marca esta prohibido en la entrada.
 */
ok('la marca ~ no se puede escribir a mano',  rechaza('users~' . \substr(\sha1('Users'), 0, 8)));
ok("'Users' y 'users' dan rutas distintas",   Names::toPath('Users') !== Names::toPath('users'));
eq('la transformacion es estable',            Names::toPath('Users'), Names::toPath('Users'));

$muchos = ['users', 'Users', 'USERS', 'UsErS', 'user', 'Users2', 'users2'];
eq('siete nombres distintos, siete rutas distintas',
    \count($muchos), \count(\array_unique(\array_map([Names::class, 'toPath'], $muchos))));

/* ═══════════════════════════════════════════════════════════════════════════ */
section('E] Nada se escribe fuera por la API de PHP');

/*
 * test_http_traversal.php prueba esto por el puente, que devuelve 400. Aqui se
 * entra por Db directamente, que es como lo usa el 90% de quien integra AxiDB y
 * donde no hay ninguna capa HTTP que filtre antes.
 */
$raiz  = tmpdir('sec_rutas_e');
$dirE  = $raiz . '/datos';
$dbE   = new Db($dirE, ['durable' => false]);
$dbE->insert('p', ['n' => 1], 'p1');

$testigo = $raiz . '/testigo.json';
\file_put_contents($testigo, '{"clave":"NO_DEBE_SALIR"}');

$ataques = ['../fuera', '..\\fuera', '/etc/passwd', 'C:/Windows/win.ini',
            'p/../../fuera', "p\x00.json", '....//p', '%2e%2e%2fp', '.hidden', '_interno'];

foreach ($ataques as $a) {
    throws('coleccion ' . \json_encode($a) . ' no se crea', static fn() => $dbE->insert($a, ['x' => 1], 'd'));
    throws('id ' . \json_encode($a) . ' no se escribe',      static fn() => $dbE->insert('p', ['x' => 1], $a));
    throws('id ' . \json_encode($a) . ' no se lee',          static fn() => $dbE->get('p', $a));
    throws('id ' . \json_encode($a) . ' no se borra',        static fn() => $dbE->delete('p', $a));
    throws('campo ' . \json_encode($a) . ' no crea indice',  static fn() => $dbE->index('p', $a));
}
ok('el archivo testigo de al lado sigue intacto',
    \file_get_contents($testigo) === '{"clave":"NO_DEBE_SALIR"}');
ok('no ha aparecido ninguna carpeta fuera del directorio de datos',
    !\is_dir($raiz . '/fuera') && !\is_dir(\dirname($raiz) . '/fuera'));

/*
 * Un valor de campo CON pinta de ruta es un dato legitimo y se guarda tal cual.
 * Lo que no puede pasar es que se convierta en ruta.
 */
$dbE->index('p', 'ruta');
$dbE->insert('p', ['ruta' => '../../etc/passwd'], 'r1');
eq('un valor con pinta de ruta se guarda como texto', '../../etc/passwd', $dbE->get('p', 'r1')['ruta']);
$cubos = \array_values(\array_filter(
    \array_map('basename', \glob($dirE . '/p/_idx/ruta/*') ?: []),
    static fn(string $n) => $n !== '_campo.json'
));
eq('y produce un solo archivo de cubo', 1, \count($cubos));
ok('con el valor reducido a hash', \str_starts_with($cubos[0] ?? '', 'h_'));

/* ═══════════════════════════════════════════════════════════════════════════ */
section('F] Los archivos internos del motor');

/*
 * Dentro de cada coleccion viven '_axidb.json' (ajustes), '_idx/' (indices),
 * 'data.axi' y '_write.lock' (formato empaquetado). En la raiz viven
 * '.htaccess', 'index.html', '_tx/' y '_agentes/'. Si un nombre de usuario
 * pudiera coincidir con alguno, una escritura de datos corromperia el estado
 * del motor.
 */
foreach (['_idx', '_axidb.json', '_write.lock', '_tx', '_agentes', '_campo.json', '.htaccess'] as $interno) {
    ok("no se puede nombrar nada como el interno '{$interno}'", rechaza($interno));
}

// 'data.axi' e 'index.html' SI pasan la validacion (no empiezan por _ ni por
// punto), asi que hay que comprobar el comportamiento, no el nombre.
$raizF = tmpdir('sec_rutas_f');
$dirF  = $raizF . '/datos';
$dbF   = new Db($dirF, ['durable' => false]);

ok('el blindaje deja su .htaccess',  \is_file($dirF . '/.htaccess'));
ok('y su index.html',                \is_file($dirF . '/index.html'));

// Una coleccion no puede quedarse con el nombre de un archivo del blindaje.
throws('no se puede crear una coleccion llamada index.html',
    static fn() => $dbF->insert('index.html', ['x' => 1], 'd1'));
ok('index.html sigue siendo el archivo del blindaje', \is_file($dirF . '/index.html'));

/*
 * Renombrar es la otra puerta, y es la que se olvida: comprueba que el destino
 * no sea un DIRECTORIO, pero no que sea un archivo del motor. Un ALTER de
 * coleccion no puede llevarse por delante el blindaje.
 */
$dbF->insert('origen', ['x' => 1], 'd1');
try {
    $dbF->renameCollection('origen', 'index.html');
} catch (\Throwable) {
    // rechazarlo es la respuesta correcta
}
ok('renombrar una coleccion no destruye el index.html del blindaje',
    \is_file($dirF . '/index.html'));

// Un documento no puede colisionar con los internos de la coleccion.
$dbF->insert('c', ['x' => 1], 'data');
ok("un documento llamado 'data' no pisa data.axi del formato empaquetado",
    \is_file($dirF . '/c/data.json') && !\is_file($dirF . '/c/data.axi'));

// El nombre real del campo se anota en _campo.json; ningun valor puede pisarlo.
$dbF->index('c', 'estado');
$dbF->insert('c', ['estado' => '_campo'], 'e1');
$anotacion = \json_decode((string) @\file_get_contents($dirF . '/c/_idx/estado/_campo.json'), true);
eq('la anotacion del campo sigue siendo la del campo', 'estado', $anotacion['campo'] ?? null);

// Y un campo indexado invalido no deja rastro ni a medias.
throws('un campo indexado con traversal no crea indice',
    static fn() => $dbF->index('c', '../../evil'));
ok('ni aparece un _idx fuera de la coleccion', !\is_dir($dirF . '/_idx'));
throws('unique() con un campo invalido tampoco pasa',
    static fn() => $dbF->unique('c', '../../evil'));
eq('y no queda declarado en los ajustes', [], $dbF->uniques('c'));
ok('la coleccion sigue admitiendo escrituras', $dbF->insert('c', ['x' => 2], 'd2')['x'] === 2);

/* ═══════════════════════════════════════════════════════════════════════════ */
section('G] Colision de cubos del indice');

/*
 * Names::forValue tiene DOS ramas: un valor "seguro" se usa tal cual como
 * nombre de archivo, y cualquier otro se reduce a 'h_' + sha1. El problema es
 * que 'h_' + 40 caracteres hexadecimales ES un valor seguro, asi que un
 * atacante puede escribir a mano el nombre de archivo del cubo de otro valor.
 *
 * Con un campo UNIQUE eso significa reservar el correo de otra persona sin
 * conocer nada mas que su correo: el cubo ya esta ocupado cuando llega.
 */
$victima  = 'victima@empresa.es';
$colision = 'h_' . \sha1($victima);

ok('dos valores distintos no comparten archivo de cubo',
    Names::forValue($victima) !== Names::forValue($colision));

$dirG = tmpdir('sec_rutas_g') . '/datos';
$dbG  = new Db($dirG, ['durable' => false]);
$dbG->unique('u', 'email');
$dbG->insert('u', ['email' => $colision, 'quien' => 'atacante'], 'atk');

$pudoRegistrarse = true;
$mensaje = '';
try {
    $dbG->insert('u', ['email' => $victima, 'quien' => 'victima'], 'vic');
} catch (\Throwable $e) {
    $pudoRegistrarse = false;
    $mensaje = $e->getMessage();
}
ok('ocupar el nombre del cubo no bloquea el registro del valor real', $pudoRegistrarse);
ok('y el error, si lo hay, no revela el valor ajeno', !\str_contains($mensaje, $victima));

/* ═══════════════════════════════════════════════════════════════════════════ */
section('H] Enlaces simbolicos y junctions dentro del directorio de datos');

/*
 * El motor nunca comprueba que una ruta resuelta siga dentro del directorio de
 * datos: construye la cadena y confia. Un enlace colocado dentro de la carpeta
 * de datos convierte cualquier recorrido del motor en un recorrido fuera.
 *
 * Y el motor recorre la carpeta en dos sitios que hacen daño: Sweeper::rmrf,
 * que borra, y Backup\Inventory, que copia. El escenario no es raro: la carpeta
 * de datos suele ser escribible por el propio proceso web, y basta un fallo de
 * subida de archivos, un descomprimido de un zip ajeno o un usuario del sistema
 * con menos privilegios para dejar el enlace puesto.
 */
$raizH   = tmpdir('sec_rutas_h');
$dirH    = $raizH . '/datos';
$dbH     = new Db($dirH, ['durable' => false]);
$dbH->insert('normal', ['x' => 1], 'n1');

$victimaDir = $raizH . '/fuera';
@\mkdir($victimaDir, 0777, true);
\file_put_contents($victimaDir . '/importante.txt', 'DATOS_QUE_NO_SE_DEBEN_PERDER');

$enlace = $dirH . '/enlace';
$hecho  = false;
if ($ES_WINDOWS) {
    @\exec('cmd /c mklink /J "' . \str_replace('/', '\\', $enlace) . '" "'
        . \str_replace('/', '\\', $victimaDir) . '" 2>&1', $salida, $rc);
    $hecho = $rc === 0;
} else {
    $hecho = @\symlink($victimaDir, $enlace);
}

if (!$hecho) {
    ok('no se pudo crear el enlace en este sistema: seccion omitida', true);
} else {
    // 1. La copia de seguridad no puede llevarse datos de fuera del directorio.
    $copias = $raizH . '/copias';
    @\mkdir($copias, 0777, true);
    $copia  = $dbH->backup($copias);
    ok('la copia de seguridad no se lleva archivos de fuera del directorio de datos',
        !\str_contains((string) @\file_get_contents($copia['archivo']), 'DATOS_QUE_NO_SE_DEBEN_PERDER'));

    // 2. Borrar la "coleccion" enlazada no puede borrar el objetivo.
    try {
        $dbH->dropCollection('enlace');
    } catch (\Throwable) {
        // rechazarla seria perfecto
    }
    ok('borrar una coleccion enlazada no borra los archivos de fuera',
        \is_file($victimaDir . '/importante.txt'));
    ok('ni vacia el directorio de fuera',
        \is_dir($victimaDir));
}

/* ═══════════════════════════════════════════════════════════════════════════ */
section('I] Rutas larguisimas y muchos niveles');

/*
 * Un nombre de 128 bytes por tramo, mas la base, mas '_idx', mas el campo, mas
 * el valor: se pasa de los 260 caracteres del MAX_PATH clasico de Windows sin
 * esfuerzo. Lo que NO puede pasar es que la escritura se de por buena y el dato
 * no este: perder en silencio es peor que fallar.
 */
$raizI = tmpdir('sec_rutas_i');
$hondo = $raizI . '/' . \implode('/', \array_fill(0, 12, \str_repeat('n', 30)));
@\mkdir($hondo, 0777, true);
ok('la base profunda se ha podido crear', \is_dir($hondo));

$colLarga  = \str_repeat('c', 128);
$idLargo   = \str_repeat('i', 128);
$campoLargo = \str_repeat('f', 128);

$dbI     = new Db($hondo . '/datos', ['durable' => false]);
$escrito = false;
$lanzo   = false;
try {
    $dbI->insert($colLarga, ['v' => 'presente'], $idLargo);
    $escrito = ($dbI->get($colLarga, $idLargo)['v'] ?? null) === 'presente';
} catch (\Throwable) {
    $lanzo = true;
}
ok('una escritura en ruta larguisima o funciona o falla en alto, nunca en silencio',
    $escrito || $lanzo);

$idxOk = false;
$idxLanzo = false;
try {
    $dbI->index($colLarga, $campoLargo);
    $dbI->insert($colLarga, [$campoLargo => \str_repeat('v', 60)], 'z1');
    $idxOk = \count($dbI->by($colLarga, $campoLargo, \str_repeat('v', 60))) === 1;
} catch (\Throwable) {
    $idxLanzo = true;
}
ok('lo mismo para el indice: encuentra el documento o lanza', $idxOk || $idxLanzo);

/* ═══════════════════════════════════════════════════════════════════════════ */
section('J] El blindaje no se borra desde la API');

$dirJ = tmpdir('sec_rutas_j') . '/datos';
$dbJ  = new Db($dirJ, ['durable' => false]);
$dbJ->insert('c', ['x' => 1], 'd1');

throws('dropCollection sobre .htaccess ni se intenta', static fn() => $dbJ->dropCollection('.htaccess'));
ok('.htaccess sigue',  \is_file($dirJ . '/.htaccess'));
eq('dropCollection sobre index.html no borra nada', false, $dbJ->dropCollection('index.html'));
ok('index.html sigue', \is_file($dirJ . '/index.html'));

$dbJ->dropCollection('c');
ok('borrar una coleccion de verdad no toca el blindaje',
    \is_file($dirJ . '/.htaccess') && \is_file($dirJ . '/index.html'));

/* ═══════════════════════════════════════════════════════════════════════════ */
section('K] Restaurar una copia que no es nuestra');

/*
 * El formato .axicopia lleva la ruta relativa de cada archivo DENTRO del propio
 * archivo de copia, y Restore la concatena con el directorio de datos sin
 * comprobar donde acaba. Es el clasico "zip slip", con dos agravantes:
 *
 *   - lo que se escribe es contenido elegido por quien hizo la copia;
 *   - dropExtras() borra despues todo lo que la cabecera de la copia no
 *     mencione, o sea el conjunto de datos entero y el blindaje con el.
 *
 * Y restaurar una copia ajena no es un escenario retorcido: es exactamente lo
 * que hace un soporte tecnico cuando un cliente manda su respaldo.
 */
// El directorio de datos cuelga de dos niveles propios: asi los ataques que
// suben uno o dos niveles siguen cayendo dentro del temporal del test y no se
// escribe nada en el sistema de quien lo ejecuta. Salir del directorio de datos
// es el fallo; no hace falta salir tambien del temporal para demostrarlo.
$raizK = tmpdir('sec_rutas_k');
$dirK  = $raizK . '/nivel1/nivel2/datos';
$dbK   = new Db($dirK, ['durable' => false]);
$dbK->insert('importante', ['dato' => 'no_se_puede_perder'], 'i1');

$copiasK = $raizK . '/copias';
@\mkdir($copiasK, 0777, true);

/** Fabrica un .axicopia con la ruta que se le diga. Formato documentado en Container. */
function copiaTrucada(string $destino, string $ruta, string $carga): string
{
    $cab = ['version' => 1, 'id' => 'trucada_' . \substr(\md5($ruta), 0, 8), 'momento' => \date('c'),
            'tipo' => 'completa', 'base' => null, 'huellas' => [$ruta => \sha1($carga)]];
    $txt = "AXIDB-COPIA-1\n"
         . \json_encode($cab, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
         . \json_encode(['ruta' => $ruta, 'bytes' => \strlen($carga), 'sha1' => \sha1($carga)],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
         . $carga . "\n";
    \file_put_contents($destino, $txt);
    return $destino;
}

$carga  = '<?php /* colado desde una copia de seguridad */';
$sufijo = \bin2hex(\random_bytes(3));

// Clave: la ruta que viaja dentro de la copia. Valor: donde acabaria el archivo
// si el motor la concatena sin comprobar nada. Los cuatro salen del directorio
// de datos, que es lo unico que hay que demostrar.
$vectores = [
    '../colado_a_' . $sufijo . '.php'         => $raizK . '/nivel1/nivel2/colado_a_' . $sufijo . '.php',
    '../../colado_b_' . $sufijo . '.php'      => $raizK . '/nivel1/colado_b_' . $sufijo . '.php',
    '..\\..\\colado_c_' . $sufijo . '.php'    => $raizK . '/nivel1/colado_c_' . $sufijo . '.php',
    'c/../../../colado_d_' . $sufijo . '.php' => $raizK . '/nivel1/colado_d_' . $sufijo . '.php',
];

$n = 0;
foreach ($vectores as $ruta => $objetivo) {
    $archivo = copiaTrucada($copiasK . '/trucada' . (++$n) . '.axicopia', $ruta, $carga);
    try {
        $dbK->restore($archivo);
    } catch (\Throwable) {
        // rechazar la copia es la respuesta correcta
    }
    ok('restaurar no escribe fuera del directorio de datos con ' . \json_encode($ruta),
        !\is_file($objetivo));
    @\unlink($objetivo);
}

// Una ruta absoluta se neutraliza sola al concatenarse detras del destino; se
// deja escrito para que nadie la "arregle" de una forma que la reactive.
$absoluta = \str_replace('\\', '/', \sys_get_temp_dir()) . '/axi_abs_' . $sufijo . '.txt';
try {
    $dbK->restore(copiaTrucada($copiasK . '/abs.axicopia', $absoluta, 'ABS'));
} catch (\Throwable) {
}
ok('una ruta absoluta dentro de la copia no crea el archivo absoluto', !\is_file($absoluta));
@\unlink($absoluta);

// Restaurar una copia ajena no puede arrasar los datos vivos ni el blindaje.
ok('el documento que ya habia sigue estando',
    ($dbK->get('importante', 'i1')['dato'] ?? null) === 'no_se_puede_perder');
ok('y el blindaje sigue en su sitio',
    \is_file($dirK . '/.htaccess') && \is_file($dirK . '/index.html'));

// Una copia legitima si tiene que restaurar el blindaje: se comprueba aparte
// para no confundir el fallo de arriba con una copia que no lo incluye.
$raizL = tmpdir('sec_rutas_l');
$dirL  = $raizL . '/datos';
$dbL   = new Db($dirL, ['durable' => false]);
$dbL->insert('c', ['x' => 1], 'd1');
$copL  = $raizL . '/copias';
@\mkdir($copL, 0777, true);
$hechaL = $dbL->backup($copL);
@\unlink($dirL . '/.htaccess');
$dbL->restore($hechaL['archivo']);
ok('una copia legitima si devuelve el .htaccess', \is_file($dirL . '/.htaccess'));

/* ═══════════════════════════════════════════════════════════════════════════ */

// Se recoge todo. El enlace de la seccion H ya no esta —lo quito el propio
// dropCollection— y su objetivo estaba dentro del temporal del test, asi que
// rmrf no puede seguirlo hacia ningun sitio de fuera.
foreach ([\dirname($dirB), \dirname($dirC), $raiz, $raizF, \dirname($dirG),
          $raizH, $raizI, \dirname($dirJ), $raizK, $raizL] as $temporal) {
    rmrf($temporal);
}

summary();
