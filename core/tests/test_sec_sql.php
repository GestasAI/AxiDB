<?php
/**
 * AxiDB - AxiSQL: auditoria ofensiva del analizador y del ejecutor.
 *
 * `test_sql_inyeccion.php` cubre la frontera clasica: que un literal entre
 * comillas nunca se convierta en sintaxis. Eso esta bien resuelto y aqui no se
 * repite. Este archivo ataca la frontera de al lado, que es la que sigue
 * abierta: **texto que se guarda como dato y despues se ejecuta como sentencia**,
 * y **condiciones que se analizan por un camino y se ejecutan por otro**.
 *
 * La regla de todo el archivo: cada asercion afirma el comportamiento SEGURO.
 * Una asercion en rojo es un ataque que funciona, no un test mal escrito.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Sql\Parser;

$BASE = tmpdir('sec_sql');

/** Una base limpia por seccion: un ataque que rompe el estado no contamina al siguiente. */
function baseNueva(string $nombre): Db
{
    $dir = $GLOBALS['BASE'] . '/' . $nombre;
    @\mkdir($dir, 0777, true);
    return new Db($dir, ['durable' => false]);
}

/** Ejecuta lo que sea y dice si murio, sin dejar que se lleve el test por delante. */
function intentar(callable $fn): ?\Throwable
{
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        return $e;
    }
}

/**
 * Corre un fragmento en un proceso PHP aparte.
 *
 * Los ataques de agotamiento no lanzan excepcion: matan el proceso entero con un
 * error fatal. Desde dentro no se pueden observar, asi que se observan desde
 * fuera: codigo de salida y salida estandar.
 *
 * @return array{0:int, 1:string} [codigo de salida, salida]
 */
function enSubproceso(string $cuerpo): array
{
    $dir = $GLOBALS['BASE'] . '/hijo_' . \substr(\sha1($cuerpo), 0, 8);
    @\mkdir($dir, 0777, true);
    $script = $dir . '/caso.php';

    \file_put_contents($script, "<?php\n"
        . 'require_once ' . \var_export(\dirname(__DIR__) . '/axidb.php', true) . ";\n"
        . '$db = new Axi\Core\Db(' . \var_export($dir, true) . ", ['durable' => false]);\n"
        . "try {\n{$cuerpo}\n  echo \"TERMINO\\n\";\n"
        . "} catch (\\Axi\\Core\\Exception \$e) { echo \"AXI\\n\"; }\n"
        . "catch (\\Throwable \$e) { echo 'OTRO ' . \$e::class . \"\\n\"; }\n");

    $salida = [];
    $codigo = 0;
    \exec(
        \escapeshellarg(PHP_BINARY) . ' -d memory_limit=64M ' . \escapeshellarg($script) . ' 2>&1',
        $salida,
        $codigo
    );
    return [$codigo, \implode("\n", $salida)];
}

/* ═══════════════════════════════════════════════════════════════════════════ */
section('A] La tienda de vistas: datos que se ejecutan como sentencias');

/*
 * Una vista se guarda como TEXTO en la coleccion `axidb_vistas` y se ejecuta con
 * db->sql() cada vez que alguien la lee. Al usarla no se vuelve a comprobar que
 * ese texto sea un SELECT. Y `axidb_vistas` es una coleccion corriente: cualquier
 * INSERT la alcanza.
 *
 * Resultado: escribir una fila se convierte en ejecutar la sentencia que quieras,
 * disparada por la siguiente LECTURA de cualquiera.
 */

$db = baseNueva('vistas');
$db->insert('clientes', ['nombre' => 'ana'], 'c1');
$db->insert('clientes', ['nombre' => 'luis'], 'c2');

$db->sql("INSERT INTO axidb_vistas (id, sql) VALUES ('informe', 'DROP COLLECTION clientes')");
$fallo = intentar(static fn() => $db->sql('SELECT * FROM informe'));

ok('un SELECT sobre una vista envenenada no ejecuta el DROP',
    \in_array('clientes', $db->collections(), true));
eq('y los documentos siguen ahi', 2, $db->count('clientes'));
ok('si algo falla, falla con la excepcion del motor y no con un TypeError de PHP',
    $fallo === null || $fallo instanceof \Axi\Core\Exception);

/* Lo mismo, pero escribiendo: la vista crea un documento que nadie pidio. */
$db2 = baseNueva('vistas_escritura');
$db2->insert('admin', ['rol' => 'user'], 'a1');
$db2->sql(
    "INSERT INTO axidb_vistas (id, sql) VALUES "
    . "('panel', 'INSERT INTO admin (id, rol) VALUES (''pwn'', ''root'')')"
);
intentar(static fn() => $db2->sql('SELECT * FROM panel'));
ok('una vista no puede insertar documentos privilegiados', $db2->get('admin', 'pwn') === null);

/* La otra puerta: UPDATE sobre una vista que ya existia y era legitima. */
$db3 = baseNueva('vistas_update');
$db3->insert('ventas', ['importe' => 10], 'v1');
$db3->sql('CREATE VIEW resumen AS SELECT * FROM ventas');
$db3->sql("UPDATE axidb_vistas SET sql = 'DROP COLLECTION ventas'");
intentar(static fn() => $db3->sql('SELECT * FROM resumen'));
ok('redefinir una vista con UPDATE no convierte una lectura en un DROP',
    \in_array('ventas', $db3->collections(), true));

/*
 * Escalada completa desde un permiso minimo.
 *
 * Importa por el puente HTTP: un token con ambito de escritura en UNA coleccion
 * no deberia poder tocar ninguna otra. Con estos dos pasos, si puede.
 */
$db4 = baseNueva('escalada');
$db4->insert('clientes', ['nombre' => 'ana'], 'c1');
$db4->insert('notas', ['texto' => 'hola'], 'n1');
$db4->sql("INSERT INTO notas (id, sql) VALUES ('clientes', 'DROP COLLECTION clientes')");
$renombro = intentar(static fn() => $db4->sql('ALTER COLLECTION notas RENAME TO axidb_vistas'));
ok('no se puede renombrar una coleccion encima del almacen de vistas', $renombro !== null);
intentar(static fn() => $db4->sql('SELECT * FROM clientes'));
ok('y una escritura limitada a una coleccion no alcanza a otra',
    \in_array('clientes', $db4->collections(), true));

/*
 * El desajuste que hace explotable todo lo anterior: el AST dice una cosa y la
 * ejecucion hace otra. El puente HTTP decide los permisos mirando el AST
 * (`select` = lectura), asi que un token de solo lectura pasa el control y
 * despues escribe.
 */
$db5 = baseNueva('ast');
$db5->insert('caja', ['saldo' => 100], 'k1');
$db5->sql("INSERT INTO axidb_vistas (id, sql) VALUES ('lectura', 'DELETE FROM caja')");
$ast = (new Parser())->parse('SELECT * FROM lectura');
eq('el AST de la sentencia se clasifica como lectura', 'select', $ast['type']);
intentar(static fn() => $db5->sql('SELECT * FROM lectura'));
eq('luego una sentencia clasificada como lectura no puede borrar', 1, $db5->count('caja'));

/*
 * EXPLAIN promete explicar sin ejecutar, y el puente lo clasifica siempre como
 * lectura. Con una vista de por medio, ejecuta primero y explica despues.
 */
$db6 = baseNueva('explain_vista');
$db6->insert('secreto', ['a' => 1], 's1');
$db6->sql("INSERT INTO axidb_vistas (id, sql) VALUES ('inocente', 'DROP COLLECTION secreto')");
intentar(static fn() => $db6->sql('EXPLAIN SELECT * FROM inocente'));
ok('EXPLAIN no ejecuta la consulta de una vista', \in_array('secreto', $db6->collections(), true));

/*
 * Una vista se busca ANTES que la coleccion, asi que puede tapar datos reales.
 * Quien mire por SELECT no vera nada; quien mire por COUNT(*) los seguira viendo,
 * porque el camino de COUNT ni se entera de las vistas.
 */
$db7 = baseNueva('sombra');
$db7->insert('ventas', ['importe' => 100], 'v1');
$db7->insert('ventas', ['importe' => 999], 'v2');
$db7->sql('CREATE VIEW ventas AS SELECT * FROM ninguna');
$porSelect = $db7->sql('SELECT * FROM ventas');
eq('una vista no puede tapar una coleccion que existe', 2, \count((array) $porSelect));
eq('y SELECT y COUNT(*) no pueden discrepar sobre lo mismo',
    $db7->sql('SELECT COUNT(*) FROM ventas'), \count((array) $porSelect));

/*
 * Vista que se llama a si misma. No hay tope de profundidad ni deteccion de
 * ciclo: cada nivel vuelve a tokenizar la sentencia entera hasta agotar la
 * memoria del proceso. Y como la vista queda GUARDADA, el veneno sobrevive:
 * cada proceso que vuelva a leerla vuelve a morir.
 */
[$codigo, $salida] = enSubproceso(
    "  \$db->sql('CREATE VIEW bucle AS SELECT * FROM bucle');\n"
    . "  \$db->sql('SELECT * FROM bucle');"
);
ok('una vista recursiva no tumba el proceso (salida limpia)', $codigo === 0);
ok('una vista recursiva se corta con un error del motor, no con un fatal de PHP',
    \str_contains($salida, 'AXI') && !\str_contains($salida, 'Fatal error'));

/* Dos vistas que se apuntan la una a la otra: el mismo agujero sin autorreferencia. */
[$codigo2, ] = enSubproceso(
    "  \$db->sql('CREATE VIEW va AS SELECT * FROM vb');\n"
    . "  \$db->sql('CREATE VIEW vb AS SELECT * FROM va');\n"
    . "  \$db->sql('SELECT * FROM va');"
);
ok('dos vistas que se referencian entre si tampoco tumban el proceso', $codigo2 === 0);

/*
 * Una vista puede abrir una transaccion. Cuando el SELECT revienta despues, la
 * transaccion se queda abierta y todo lo que escriba el resto del programa cae
 * dentro de algo que nadie va a confirmar.
 */
$db8 = baseNueva('vista_tx');
$db8->insert('p', ['a' => 1], 'd1');
$db8->sql("INSERT INTO axidb_vistas (id, sql) VALUES ('tx', 'BEGIN')");
intentar(static fn() => $db8->sql('SELECT * FROM tx'));
ok('una lectura no deja una transaccion abierta a espaldas del programa',
    $db8->currentTransaction() === null);

/* ═══════════════════════════════════════════════════════════════════════════ */
section('B] Subconsultas: se resuelven al leer y se ignoran al escribir');

/*
 * `Read` pasa el WHERE por `Subqueries::resolve()`. `Write::affected()` no.
 *
 * El nodo llega al evaluador con `value` = ['subquery' => 'SELECT ...'], que es
 * un array. Para IN eso significa "no esta en la lista" siempre, y para NOT IN
 * significa "no esta" siempre: la condicion se evapora y el DELETE se lleva la
 * coleccion entera sin decir nada.
 */
$db9 = baseNueva('subq');
$db9->insert('c', ['ciudad' => 'Murcia'], 'c1');
$db9->insert('c', ['ciudad' => 'Lorca'], 'c2');
foreach ([['c1', 1], ['c2', 2], ['c1', 3]] as [$cli, $n]) {
    $db9->insert('ped', ['cli' => $cli, 'n' => $n], "p{$n}");
}

eq('en un SELECT la subconsulta de IN si se resuelve',
    2, \count($db9->sql("SELECT * FROM ped WHERE cli IN (SELECT id FROM c WHERE ciudad = 'Murcia')")));
eq('y la de NOT IN tambien',
    1, \count($db9->sql("SELECT * FROM ped WHERE cli NOT IN (SELECT id FROM c WHERE ciudad = 'Murcia')")));

$db9->sql("UPDATE ped SET marcado = 1 WHERE cli NOT IN (SELECT id FROM c WHERE ciudad = 'Murcia')");
$marcados = \count(\array_filter($db9->sql('SELECT id, marcado FROM ped'),
    static fn(array $f) => ($f['marcado'] ?? null) === 1));
eq('UPDATE ... NOT IN (subconsulta) toca solo lo que dice la condicion', 1, $marcados);

$db9->sql("UPDATE ped SET otro = 1 WHERE cli IN (SELECT id FROM c WHERE ciudad = 'Murcia')");
$otros = \count(\array_filter($db9->sql('SELECT id, otro FROM ped'),
    static fn(array $f) => ($f['otro'] ?? null) === 1));
eq('UPDATE ... IN (subconsulta) tampoco se queda corto', 2, $otros);

$db10 = baseNueva('subq_delete');
foreach ([1, 2, 3] as $n) {
    $db10->insert('r', ['n' => $n], "r{$n}");
}
$db10->insert('arch', ['n' => 1], 'r1');
$db10->sql('DELETE FROM r WHERE id NOT IN (SELECT id FROM arch)');
eq('DELETE ... NOT IN (subconsulta) no arrasa la coleccion entera', 1, $db10->count('r'));

/* Lo que si falla cerrado: EXISTS en una escritura lanza y no borra nada. */
$db11 = baseNueva('subq_exists');
$db11->insert('s', ['n' => 1], 's1');
throws('EXISTS en un DELETE se rechaza en vez de resolverse a la ligera',
    static fn() => $db11->sql('DELETE FROM s WHERE EXISTS (SELECT * FROM s)'));
eq('y no se borro nada por el camino', 1, $db11->count('s'));

/* ═══════════════════════════════════════════════════════════════════════════ */
section('C] Lo que el analizador si para');

$db12 = baseNueva('parser');
$db12->insert('p', ['n' => 'x', 'precio' => 2], 'd1');

/* Apilar sentencias, por fuera y por dentro de una subconsulta. */
throws('dos sentencias en una llamada',
    static fn() => $db12->sql('SELECT * FROM p; DROP COLLECTION p'));
throws('punto y coma dentro de una subconsulta',
    static fn() => $db12->sql('SELECT * FROM p WHERE n IN (SELECT n FROM p; DROP COLLECTION p)'));
throws('DDL donde se espera una subconsulta',
    static fn() => $db12->sql('SELECT * FROM p WHERE EXISTS (DROP COLLECTION p)'));
throws('DDL dentro de una lista IN',
    static fn() => $db12->sql('SELECT * FROM p WHERE n IN (DROP COLLECTION p)'));
throws('UNION no existe y no se cuela como alias',
    static fn() => $db12->sql('SELECT * FROM p UNION SELECT * FROM p'));
ok('la coleccion sigue entera tras los intentos de apilar', $db12->count('p') === 1);

/* Comentarios. */
throws('el comentario de almohadilla no se acepta en silencio',
    static fn() => $db12->sql('SELECT * FROM p # y aqui lo que sea'));
throws('el comentario de bloque tampoco',
    static fn() => $db12->sql('SELECT * FROM p /* WHERE 1=0 */'));
$db12->sql("INSERT INTO notas (t) VALUES ('-- DROP COLLECTION p')");
eq('un guion doble dentro de un literal es texto y vuelve intacto',
    '-- DROP COLLECTION p', $db12->sql('SELECT * FROM notas')[0]['t'] ?? null);
eq('y la coleccion sigue ahi despues', 1, $db12->count('p'));

/* Comillas. */
$db12->insert('q', ['n' => "a'b"], 'q1');
eq('la comilla doblada produce un literal con una comilla',
    1, \count($db12->sql("SELECT * FROM q WHERE n = 'a''b'")));
throws('una comilla sin cerrar no se perdona',
    static fn() => $db12->sql("SELECT * FROM p WHERE n = 'abierta"));
$conBarra = $db12->sql("SELECT * FROM p WHERE n = 'x\\'");
eq('la barra invertida no escapa la comilla (no se puede truncar la sentencia)', [], $conBarra);
throws('un nombre de coleccion entre comillas dobles no es un nombre',
    static fn() => $db12->sql('SELECT * FROM "p"'));

/* Identificadores que quieren ser rutas. */
foreach ([
    'SELECT * FROM ../fuga'                => 'ruta relativa',
    'SELECT * FROM _idx'                   => 'nombre interno con guion bajo',
    'SELECT * FROM ' . \str_repeat('a', 200) => 'nombre larguisimo',
    'DROP COLLECTION ..'                   => 'punto punto',
] as $sql => $motivo) {
    throws("rechaza como nombre: {$motivo}", static fn() => $db12->sql($sql));
}

/* El id de un documento sale de un LITERAL, o sea de la parte que controla el atacante. */
throws('un id con traversal en el VALUES se rechaza',
    static fn() => $db12->sql("INSERT INTO p (id, n) VALUES ('../../evil', 1)"));
throws('tambien con ON DUPLICATE UPDATE',
    static fn() => $db12->sql("INSERT INTO p (id, n) VALUES ('../../evil', 1) ON DUPLICATE UPDATE"));

/* La identidad de un documento no se puede reescribir desde un SET. */
$db13 = baseNueva('identidad');
$db13->insert('p', ['n' => 1], 'd1');
$db13->insert('p', ['n' => 2], 'd2');
$db13->sql("UPDATE p SET id = 'd2' WHERE n = 1");
eq('UPDATE SET id no funde dos documentos en uno', 2, $db13->count('p'));
ok('el documento conserva su id', ($db13->get('p', 'd1')['n'] ?? null) === 1);

/* Funciones: nombre y aridad se comprueban al analizar. */
throws('una funcion que no existe se rechaza',
    static fn() => $db12->sql("SELECT SYSTEM('ls') AS x FROM p"));
throws('argumentos de mas',   static fn() => $db12->sql('SELECT NOW(1) AS x FROM p'));
throws('argumentos de menos', static fn() => $db12->sql('SELECT SUBSTR(n) AS x FROM p'));
throws('CONCAT por encima del maximo',
    static fn() => $db12->sql('SELECT CONCAT(' . \implode(',', \array_fill(0, 100, "'a'")) . ') AS x FROM p'));

/* Funciones con basura dentro: devuelven null, no revientan ni inventan. */
$raras = $db12->sql(
    'SELECT SUBSTR(n, -9223372036854775807) AS a, ROUND(precio, 999999999) AS b, '
    . "DATEDIFF('no es fecha', 'tampoco') AS c FROM p LIMIT 1"
);
ok('SUBSTR con desplazamiento absurdo no rompe', \array_key_exists('a', $raras[0] ?? []));
ok('DATEDIFF con fechas invalidas devuelve null y no una fecha inventada',
    \array_key_exists('c', $raras[0] ?? []) && $raras[0]['c'] === null);

/* LIKE: el patron es un patron, no una expresion regular. */
$db12->insert('re', ['n' => 'hola'], 're1');
eq('los metacaracteres de regex en LIKE van escapados', [], $db12->sql("SELECT * FROM re WHERE n LIKE '.*'"));
$t0 = \microtime(true);
$db12->insert('re', ['n' => \str_repeat('a', 60)], 're2');
$db12->sql("SELECT * FROM re WHERE n LIKE '" . \str_repeat('%a', 14) . "%b'");
ok('un LIKE con retroceso catastrofico no cuelga la consulta', (\microtime(true) - $t0) < 2.0);

/* ═══════════════════════════════════════════════════════════════════════════ */
section('D] Comparacion: cuando dos cadenas distintas se declaran iguales');

/*
 * `Evaluator::areEqual` convierte a float en cuanto los dos lados son numericos,
 * y `is_numeric` acepta la notacion exponencial. Dos hashes distintos que
 * empiezan por '0e' y siguen con digitos valen los dos cero, asi que son iguales.
 *
 * Es el mismo fallo que hundio a media internet con `==` en PHP 5, y aqui llega
 * por la puerta de una consulta: quien compare un token, una firma o un hash
 * guardado como texto puede acertarlo sin conocerlo.
 *
 * La regla segura que afirman estas aserciones: la coercion numerica tiene
 * sentido cuando UNO de los lados es un numero de verdad —el 5 y el "5" de un
 * formulario son el mismo precio, y eso esta bien— pero dos CADENAS se comparan
 * como cadenas. En cuanto dos textos distintos pasan por float, el motor deja de
 * poder decir si dos identificadores son el mismo.
 */
$db14 = baseNueva('juggling');
$db14->insert('tok', ['t' => '0e123456789012345678901234567890'], 'k1');
$db14->insert('tok', ['t' => 'abc'], 'k2');
$db14->insert('tok', ['t' => '100'], 'k3');
$db14->insert('tok', ['t' => 'no'], 'k4');

eq('dos cadenas distintas del tipo 0e... no son el mismo valor',
    [], $db14->sql("SELECT * FROM tok WHERE t = '0e999999999999999999999999999999'"));
eq('un texto guardado no se compara igual al numero cero',
    [], $db14->sql('SELECT * FROM tok WHERE t = 0'));
eq("'1e2' y '100' son cadenas distintas",
    [], $db14->sql("SELECT * FROM tok WHERE t = '1e2'"));
eq('un espacio delante cambia la cadena',
    [], $db14->sql("SELECT * FROM tok WHERE t = ' 100'"));

/* Lo que si esta bien resuelto y conviene fijar para que no se pierda. */
eq('una cadena no numerica no casa con un numero (PHP 8)',
    [], $db14->sql("SELECT * FROM tok WHERE t = 'abc' AND t = 0"));
eq('los booleanos no se mezclan con textos', [], $db14->sql('SELECT * FROM tok WHERE t = FALSE'));
eq('NULL solo es igual a NULL', [], $db14->sql('SELECT * FROM tok WHERE t = NULL'));
eq('IS NULL distingue campo ausente de campo con valor',
    0, \count($db14->sql('SELECT * FROM tok WHERE t IS NULL')));

/* ═══════════════════════════════════════════════════════════════════════════ */
section('E] LIMIT y OFFSET');

/*
 * `consumeInt()` acepta el numero negativo que produce el lexer, y el recorte
 * final es un `array_slice` crudo. Un LIMIT negativo no falla: cambia de
 * significado y se lleva filas por el otro extremo. Si el numero viene de fuera
 * —una paginacion, un parametro— el resultado deja de ser el que se pidio sin
 * que nadie lo note.
 */
$db15 = baseNueva('limites');
foreach ([1, 2, 3, 4, 5] as $n) {
    $db15->insert('p', ['n' => $n], "d{$n}");
}
throws('un LIMIT negativo se rechaza', static fn() => $db15->sql('SELECT * FROM p LIMIT -1'));
throws('un OFFSET negativo se rechaza', static fn() => $db15->sql('SELECT * FROM p LIMIT 2 OFFSET -4'));
eq('un LIMIT enorme no revienta y devuelve lo que hay',
    5, \count($db15->sql('SELECT * FROM p LIMIT 99999999999999999999')));
throws('LIMIT con decimal se rechaza', static fn() => $db15->sql('SELECT * FROM p LIMIT 1.5'));

$db15->sql('DELETE FROM p LIMIT -1');
eq('un DELETE con LIMIT negativo no borra nada', 5, $db15->count('p'));

/* ═══════════════════════════════════════════════════════════════════════════ */
section('F] EXPLAIN: que cuenta y que no ejecuta');

$db16 = baseNueva('explain');
$db16->insert('p', ['n' => 1], 'd1');

$plan = $db16->sql('EXPLAIN SELECT * FROM p');
ok('EXPLAIN no devuelve rutas del disco',
    !\str_contains(\json_encode($plan) ?: '', $db16->path()));
ok('EXPLAIN no devuelve separadores de ruta sospechosos',
    !\preg_match('#[A-Za-z]:[\\\\/]#', \json_encode($plan) ?: ''));

$db16->sql('EXPLAIN INSERT INTO p (n) VALUES (2)');
$db16->sql('EXPLAIN DELETE FROM p');
$db16->sql('EXPLAIN UPDATE p SET n = 99');
$db16->sql('EXPLAIN DROP COLLECTION p');
$db16->sql('EXPLAIN CREATE COLLECTION nueva');
$db16->sql('EXPLAIN CREATE VIEW v AS SELECT * FROM p');
$db16->sql('EXPLAIN BEGIN');
eq('ningun EXPLAIN de escritura escribio nada', 1, $db16->count('p'));
eq('ni cambio el documento', 1, $db16->get('p', 'd1')['n']);
ok('EXPLAIN CREATE COLLECTION no crea la coleccion',
    !\in_array('nueva', $db16->collections(), true));
eq('EXPLAIN CREATE VIEW no guarda la vista', 0, \count($db16->sql('SELECT * FROM axidb_vistas')));
ok('EXPLAIN BEGIN no abre transaccion', $db16->currentTransaction() === null);

/* ═══════════════════════════════════════════════════════════════════════════ */
section('G] Entradas desmedidas: el proceso tiene que sobrevivir');

$db17 = baseNueva('desmedidas');
$db17->insert('p', ['n' => 1], 'd1');

foreach ([
    'SELECT * FROM p WHERE ' . \str_repeat('NOT ', 20000) . 'n = 1' => 'cadena de NOT',
    'SELECT * FROM p WHERE ' . \str_repeat('n = 1 OR ', 20000) . 'n = 1' => 'cadena de OR',
    'SELECT ' . \str_repeat('(', 5000) . '1' . \str_repeat(')', 5000) . ' AS x FROM p' => 'parentesis anidados',
] as $sql => $motivo) {
    $e = intentar(static fn() => $db17->sql($sql));
    ok("{$motivo}: se resuelve o se rechaza, pero no mata el proceso",
        $e === null || $e instanceof \Axi\Core\Exception);
}

/*
 * 200.000 parentesis son 400 KB de texto. Si eso agota 64 MB de memoria, la
 * amplificacion es de dos ordenes de magnitud: cualquiera con acceso al puente
 * HTTP tumba un worker por peticion.
 */
[$codigo3, $salida3] = enSubproceso(
    "  \$db->sql('SELECT ' . str_repeat('(', 200000) . '1' . str_repeat(')', 200000) . ' AS x FROM p');"
);
ok('una sentencia de 400 KB no agota la memoria del proceso',
    $codigo3 === 0 && !\str_contains($salida3, 'Fatal error'));

eq('tras toda la seccion los datos siguen intactos', 1, $db17->count('p'));

rmrf($BASE);
summary();
