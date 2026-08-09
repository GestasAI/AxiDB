<?php
/**
 * AxiDB - lo que AxiSQL sabe hacer desde la ola A8.
 *
 * Agregados, GROUP BY, HAVING, DISTINCT, alias, expresiones, funciones,
 * BETWEEN, INSERT de varias filas, UPSERT, ALTER, SHOW, DESCRIBE y vistas.
 *
 * Donde se equivocan las implementaciones caseras, y por eso se comprueba:
 *
 *   - la precedencia: 2 + 3 * 4 son 14, no 20
 *   - COUNT(*) y COUNT(campo) NO son lo mismo con nulos por medio
 *   - AVG divide entre los que tenian valor, no entre todos
 *   - `SET n = n + 1` sube uno a CADA documento, no el del primero a todos
 *   - LIMIT va despues de ORDER BY, o devuelve diez cualesquiera
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir = tmpdir('sql_completo');
$db  = new Db($dir, ['durable' => false]);

$db->sql("INSERT INTO ventas (ciudad, quien, total, fecha) VALUES
    ('Murcia', 'Ana',  100.0, '2026-03-15T10:00:00+01:00'),
    ('Murcia', 'Juan', 250.0, '2026-03-20T11:00:00+01:00'),
    ('Lorca',  'Eva',   75.5, '2026-04-02T12:00:00+02:00'),
    ('Lorca',  'Luis', 300.0, '2026-04-11T13:00:00+02:00'),
    ('Cieza',  'Sara',  50.0, '2026-05-01T14:00:00+02:00')");

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] INSERT de varias filas y UPSERT');

eq('las cinco de una vez', 5, $db->count('ventas'));

eq('un INSERT con id usa ese id, no uno inventado', 'fijo',
    $db->sql("INSERT INTO cosas (id, n) VALUES ('fijo', 1)")['id']);

/*
 * Esto estaba mal y lo caza este test: el id se guardaba como un campo mas y el
 * documento se creaba con un id aleatorio. Lo peor era que la version con
 * ON DUPLICATE UPDATE si lo respetaba, asi que el mismo SQL hacia dos cosas
 * distintas segun como acabara la frase.
 */
$db->sql("INSERT INTO cosas (id, n) VALUES ('fijo', 99) ON DUPLICATE UPDATE");
eq('y ON DUPLICATE UPDATE actualiza el que ya estaba', 99, $db->get('cosas', 'fijo')['n']);
eq('sin crear uno nuevo', 1, $db->count('cosas'));

throws('una fila con mas valores que columnas se rechaza',
    static fn () => $db->sql("INSERT INTO cosas (n) VALUES (1, 2)"));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Expresiones, alias y funciones');

eq('una cuenta con alias', [['conIva' => 121.0]],
    $db->sql("SELECT total * 1.21 AS conIva FROM ventas WHERE quien = 'Ana'"));

eq('la precedencia es la de las matematicas', [['r' => 14]],
    $db->sql("SELECT 2 + 3 * 4 AS r FROM ventas LIMIT 1"));
eq('y los parentesis mandan', [['r' => 20]],
    $db->sql("SELECT (2 + 3) * 4 AS r FROM ventas LIMIT 1"));

eq('funciones de texto', [['n' => 'ANA']],
    $db->sql("SELECT MAYUS(quien) AS n FROM ventas WHERE quien = 'Ana'"));
eq('de texto, uniendo', [['x' => 'Ana (Murcia)']],
    $db->sql("SELECT UNIR(quien, ' (', ciudad, ')') AS x FROM ventas WHERE quien = 'Ana'"));
eq('de numero', [['x' => 33.33]],
    $db->sql("SELECT REDONDEA(100 / 3, 2) AS x FROM ventas LIMIT 1"));

eq('de fecha, en el SELECT', [['m' => 3]],
    $db->sql("SELECT MES(fecha) AS m FROM ventas WHERE quien = 'Ana'"));

/*
 * Lo que antes obligaba a sacar los documentos a PHP: filtrar por una parte de
 * la fecha. Es la razon principal por la que existen las funciones.
 */
eq('y de fecha en el WHERE, que es para lo que hacian falta', ['Eva', 'Luis'],
    \array_column($db->sql("SELECT quien FROM ventas WHERE MES(fecha) = 4 ORDER BY quien"), 'quien'));

eq('un alias sin AS tambien vale', [['c' => 'Murcia']],
    $db->sql("SELECT ciudad c FROM ventas WHERE quien = 'Ana'"));

eq('sin alias, la columna se llama como la expresion', ['total * 2'],
    \array_keys($db->sql("SELECT total * 2 FROM ventas WHERE quien = 'Ana'")[0]));

eq('* y una expresion juntos', 200.0,
    $db->sql("SELECT *, total * 2 AS doble FROM ventas WHERE quien = 'Ana'")[0]['doble']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Nulos: se propagan, no revientan ni inventan ceros');

$db->sql("INSERT INTO nulos (a, n) VALUES ('con', 10), ('sin', 0)");
$db->sql("UPDATE nulos SET n = 0 WHERE a = 'sin'");
$db->put('nulos', 'vacio', ['a' => 'vacio'], true);

eq('sumar a un campo que no esta da null, no un cero', null,
    $db->sql("SELECT n + 1 AS r FROM nulos WHERE a = 'vacio'")[0]['r']);
eq('dividir por cero da null', null,
    $db->sql("SELECT 10 / 0 AS r FROM nulos LIMIT 1")[0]['r']);
eq('SI_NULO pone el sustituto', 0,
    $db->sql("SELECT SI_NULO(n, 0) AS r FROM nulos WHERE a = 'vacio'")[0]['r']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Agregados y GROUP BY');

$filas = $db->sql("SELECT ciudad, COUNT(*) AS cuantas, SUM(total) AS suma
                   FROM ventas GROUP BY ciudad ORDER BY suma DESC");
eq('un grupo por ciudad, ordenados por su suma', ['Lorca', 'Murcia', 'Cieza'],
    \array_column($filas, 'ciudad'));
eq('con su recuento', [2, 2, 1], \array_column($filas, 'cuantas'));
eq('y su suma',       [375.5, 350.0, 50.0], \array_column($filas, 'suma'));

eq('sin GROUP BY, un agregado devuelve UNA fila', 1,
    \count($db->sql("SELECT COUNT(*) AS c, SUM(total) AS s FROM ventas")));
eq('con el total de todo', 775.5,
    $db->sql("SELECT SUM(total) AS s FROM ventas")[0]['s']);
eq('minimo y maximo', [50.0, 300.0],
    [$db->sql("SELECT MIN(total) AS m FROM ventas")[0]['m'],
     $db->sql("SELECT MAX(total) AS m FROM ventas")[0]['m']]);

/*
 * COUNT(*) cuenta filas; COUNT(campo) cuenta las que tienen valor. Confundirlos
 * es el error mas repetido con los agregados.
 */
eq('COUNT(*) cuenta las tres filas',   3, $db->sql("SELECT COUNT(*) AS c FROM nulos")[0]['c']);
eq('COUNT(campo) solo las que tienen valor', 2,
    $db->sql("SELECT COUNT(n) AS c FROM nulos")[0]['c']);
eq('y AVG divide entre esas dos, no entre tres', 5.0,
    $db->sql("SELECT AVG(n) AS m FROM nulos")[0]['m']);

eq('un agregado sobre una coleccion vacia sigue respondiendo', 0,
    $db->sql("SELECT COUNT(*) AS c FROM ventas WHERE total > 99999")[0]['c']);
eq('y la suma de nada es null, no cero', null,
    $db->sql("SELECT SUM(total) AS s FROM ventas WHERE total > 99999")[0]['s']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] HAVING y DISTINCT');

eq('HAVING filtra grupos, no documentos', ['Lorca', 'Murcia'],
    \array_column($db->sql("SELECT ciudad FROM ventas GROUP BY ciudad
                            HAVING COUNT(*) > 1 ORDER BY ciudad"), 'ciudad'));
eq('y puede mirar una suma', ['Lorca'],
    \array_column($db->sql("SELECT ciudad FROM ventas GROUP BY ciudad
                            HAVING SUM(total) > 360 ORDER BY ciudad"), 'ciudad'));

/*
 * El alias del SELECT vale dentro del HAVING, igual que en MySQL y SQLite.
 *
 * Devolvia cero filas, en silencio y sin error, porque el alias no es campo de
 * ningun documento. Lo peor no era la limitacion: era que el mismo alias SI
 * funcionaba en ORDER BY, asi que la respuesta cambiaba segun donde escribieras
 * el nombre. Un resultado vacio que parece "no hay datos" y es "no te he
 * entendido".
 */
eq('el alias del SELECT sirve dentro del HAVING', ['Lorca'],
    \array_column($db->sql("SELECT ciudad, SUM(total) AS suma FROM ventas GROUP BY ciudad
                            HAVING suma > 360 ORDER BY ciudad"), 'ciudad'));
eq('y da lo mismo que escribir la funcion entera',
    $db->sql("SELECT ciudad, SUM(total) AS suma FROM ventas GROUP BY ciudad HAVING SUM(total) > 360"),
    $db->sql("SELECT ciudad, SUM(total) AS suma FROM ventas GROUP BY ciudad HAVING suma > 360"));
eq('un campo normal en el HAVING sigue funcionando', ['Murcia'],
    \array_column($db->sql("SELECT ciudad, COUNT(*) AS n FROM ventas GROUP BY ciudad
                            HAVING ciudad = 'Murcia'"), 'ciudad'));

eq('DISTINCT quita las filas repetidas', ['Cieza', 'Lorca', 'Murcia'],
    \array_column($db->sql("SELECT DISTINCT ciudad FROM ventas ORDER BY ciudad"), 'ciudad'));
eq('sin DISTINCT salen las cinco', 5,
    \count($db->sql("SELECT ciudad FROM ventas")));

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] BETWEEN, NOT LIKE y comparar dos campos');

eq('BETWEEN incluye los extremos', ['Ana', 'Eva', 'Juan'],
    \array_column($db->sql("SELECT quien FROM ventas WHERE total BETWEEN 75.5 AND 250
                            ORDER BY quien"), 'quien'));
eq('NOT BETWEEN es lo de fuera', ['Luis', 'Sara'],
    \array_column($db->sql("SELECT quien FROM ventas WHERE total NOT BETWEEN 75.5 AND 250
                            ORDER BY quien"), 'quien'));
eq('NOT LIKE', ['Eva', 'Juan', 'Luis', 'Sara'],
    \array_column($db->sql("SELECT quien FROM ventas WHERE quien NOT LIKE 'A%'
                            ORDER BY quien"), 'quien'));

$db->sql("INSERT INTO margen (art, precio, coste) VALUES ('a', 10, 4), ('b', 5, 8)");
eq('se pueden comparar dos campos entre si', ['a'],
    \array_column($db->sql("SELECT art FROM margen WHERE precio > coste"), 'art'));
eq('y con una cuenta por medio', ['a'],
    \array_column($db->sql("SELECT art FROM margen WHERE precio > coste * 2"), 'art'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] UPDATE con expresiones, y LIMIT al escribir');

$db->sql("INSERT INTO visitas (pag, n) VALUES ('a', 1), ('b', 5), ('c', 10)");
$db->sql("UPDATE visitas SET n = n + 1");

eq('cada documento sube desde SU valor, no desde el del primero', [2, 6, 11],
    \array_column($db->sql("SELECT n FROM visitas ORDER BY pag"), 'n'));

$db->sql("UPDATE visitas SET n = n * 10 WHERE pag = 'a'");
eq('y una cuenta mas compleja', 20, $db->get('visitas', $db->ids('visitas')[0])['n'] ?? null);

eq('UPDATE con LIMIT toca solo unos pocos', ['updated' => 2],
    $db->sql("UPDATE visitas SET n = 0 LIMIT 2"));
eq('DELETE con LIMIT tambien', ['deleted' => 1],
    $db->sql("DELETE FROM visitas LIMIT 1"));
eq('y quedan los demas', 2, $db->count('visitas'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] SHOW, DESCRIBE y ALTER');

$db->sql('CREATE INDEX ON ventas (ciudad)');

$colecciones = \array_column($db->sql('SHOW COLLECTIONS'), 'coleccion');
ok('SHOW COLLECTIONS las lista', \in_array('ventas', $colecciones, true));

$indices = $db->sql('SHOW INDEXES ON ventas');
eq('SHOW INDEXES dice el campo', 'ciudad', $indices[0]['campo'] ?? null);
eq('y cuantos valores distintos tiene', 3, $indices[0]['valores'] ?? null);

$descripcion = [];
foreach ($db->sql('DESCRIBE ventas') as $campo) {
    $descripcion[$campo['campo']] = $campo;
}
eq('DESCRIBE dice el tipo que ve', 'texto', $descripcion['ciudad']['tipo'] ?? null);
eq('y en cuantos documentos aparece', 5, $descripcion['ciudad']['documentos'] ?? null);
eq('de cuantos', 5, $descripcion['ciudad']['de'] ?? null);

// Un campo que solo tienen algunos: en una coleccion sin esquema, esto es el
// dato que de verdad importa.
$db->put('ventas', 'raro', ['ciudad' => 'Yecla', 'quien' => 'X', 'notas' => 'algo'], true);
$descripcion = [];
foreach ($db->sql('DESCRIBE ventas') as $campo) {
    $descripcion[$campo['campo']] = $campo;
}
eq('un campo que solo tiene uno se ve como tal', 1, $descripcion['notas']['documentos'] ?? null);
eq('sobre el total', 6, $descripcion['notas']['de'] ?? null);
$db->delete('ventas', 'raro');

eq('ALTER añade un campo a todos', ['updated' => 5],
    $db->sql('ALTER COLLECTION ventas ADD FIELD activa = true'));
eq('y queda puesto', 5,
    \count($db->sql("SELECT quien FROM ventas WHERE activa = true")));

eq('ALTER renombra un campo', ['updated' => 5],
    $db->sql('ALTER COLLECTION ventas RENAME FIELD quien TO cliente'));
eq('el valor viaja al nombre nuevo', 'Ana',
    $db->sql("SELECT cliente FROM ventas WHERE cliente = 'Ana'")[0]['cliente'] ?? null);
eq('y el viejo ya no esta', null,
    $db->sql("SELECT quien FROM ventas WHERE cliente = 'Ana'")[0]['quien'] ?? null);

eq('ALTER quita un campo', ['updated' => 5],
    $db->sql('ALTER COLLECTION ventas DROP FIELD activa'));

throws('no deja tocar los campos del motor',
    static fn () => $db->sql('ALTER COLLECTION ventas DROP FIELD id'));

$db->sql('ALTER COLLECTION ventas RENAME TO facturas');
eq('renombrar la coleccion se lleva los documentos', 5, $db->count('facturas'));
eq('y los indices', 2, \count($db->by('facturas', 'ciudad', 'Murcia')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('I] Vistas');

$db->sql("CREATE VIEW grandes AS SELECT cliente, total FROM facturas WHERE total > 200");

eq('una vista se consulta como una coleccion', ['Juan', 'Luis'],
    \array_column($db->sql('SELECT cliente FROM grandes ORDER BY cliente'), 'cliente'));
eq('y se le puede seguir filtrando encima', ['Luis'],
    \array_column($db->sql("SELECT cliente FROM grandes WHERE total > 260"), 'cliente'));

/*
 * Una vista no guarda datos: al usarla se ejecuta su consulta, asi que refleja
 * lo que hay AHORA. Si guardara una copia, este documento nuevo no apareceria.
 */
$db->sql("INSERT INTO facturas (ciudad, cliente, total) VALUES ('Jumilla', 'Nuevo', 999)");
eq('la vista refleja lo que hay ahora, no una copia de entonces', 3,
    \count($db->sql('SELECT * FROM grandes')));

ok('la coleccion donde viven las vistas no sale en SHOW COLLECTIONS',
    !\in_array('axidb_vistas', \array_column($db->sql('SHOW COLLECTIONS'), 'coleccion'), true));

$db->storage()->cerrar();
rmrf($dir);
summary();
