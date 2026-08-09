<?php
/**
 * AxiDB - la busqueda por significado, tal y como se usa.
 *
 * Los otros tests miran el motor por dentro. Este mira lo unico que ve quien lo
 * usa: que haga un `insert` normal y luego pueda buscar por lo que las cosas
 * significan, tambien desde AxiSQL, y combinandolo con los filtros de siempre.
 *
 * Con el generador Hash, que no sale a internet: aqui no se mide si el modelo
 * entiende de sinonimos —eso es cosa del modelo— sino que la maquinaria que hay
 * debajo esta bien montada.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$db = new Db(tmpdir('vector_consulta'), ['durable' => false]);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Activar y que el resto siga igual');

$m = $db->vectores('articulos', ['auto' => ['titulo', 'resumen', 'etiquetas']]);

eq('dice el campo',      'embedding', $m['campo']);
eq('y las dimensiones',          256, $m['dims']);
eq('y con que se generan',  'hash:256', $m['fuente']);
eq('y de donde saca el texto', ['titulo', 'resumen', 'etiquetas'], $m['auto']);

throws('buscar en una coleccion sin vectores se explica',
    static fn() => $db->similar('otra', 'lo que sea', 5));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Un insert normal, y ya se puede buscar');

$articulos = [
    'a1' => ['titulo' => 'Como podar un olivo',        'resumen' => 'poda de arboles frutales en invierno',
             'etiquetas' => ['huerto', 'arboles'], 'publicado' => true,  'seccion' => 'jardin'],
    'a2' => ['titulo' => 'Injertar arboles frutales',  'resumen' => 'tecnicas de injerto en frutales jovenes',
             'etiquetas' => ['huerto', 'arboles'], 'publicado' => true,  'seccion' => 'jardin'],
    'a3' => ['titulo' => 'Cambiar el aceite del coche', 'resumen' => 'mantenimiento basico del motor',
             'etiquetas' => ['taller'],            'publicado' => true,  'seccion' => 'motor'],
    'a4' => ['titulo' => 'Revisar los frenos',          'resumen' => 'pastillas y discos del coche',
             'etiquetas' => ['taller'],            'publicado' => false, 'seccion' => 'motor'],
];
foreach ($articulos as $id => $doc) {
    $db->insert('articulos', $doc, $id);
}

eq('los cuatro documentos estan',  4, $db->count('articulos'));
eq('y los cuatro tienen vector',   4, $db->vectorial('articulos')->manifiesto()->vivos());

$r = $db->similar('articulos', 'poda de arboles del huerto', 2);
eq('devuelve dos',            2, \count($r));
eq('y el primero es el que habla de podar', 'a1', $r[0]['id']);
ok('con su puntuacion',       ($r[0]['score'] ?? 0) > 0);
ok('y el documento entero',   ($r[0]['doc']['titulo'] ?? '') === 'Como podar un olivo');
ok('el segundo es el otro de arboles', $r[1]['id'] === 'a2');

$r = $db->similar('articulos', 'mantenimiento del coche', 2);
ok('otra consulta trae los del taller', \in_array($r[0]['id'], ['a3', 'a4'], true));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Filtrar antes de buscar');

$r = $db->similar('articulos', 'coche taller frenos', 5, $db->find('articulos')->where('publicado', '=', true));
eq('solo salen los publicados', 0,
    \count(\array_filter($r, static fn($f) => $f['doc']['publicado'] === false)));
ok('y el filtro no impide encontrar lo del taller',
    \in_array('a3', \array_column($r, 'id'), true));

$r = $db->similar('articulos', 'arboles', 5, $db->find('articulos')->where('seccion', '=', 'motor'));
eq('un filtro que no cuadra con la consulta devuelve lo que hay en el filtro', 2, \count($r));
eq('y ninguno es de jardin', 0,
    \count(\array_filter($r, static fn($f) => $f['doc']['seccion'] === 'jardin')));

$r = $db->similar('articulos', 'arboles', 5, $db->find('articulos')->where('seccion', '=', 'inventada'));
eq('un filtro que no deja pasar a nadie devuelve nada', [], $r);

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Desde AxiSQL');

$filas = $db->sql("SELECT titulo FROM articulos ORDER BY EMBEDDING <-> 'podar arboles' LIMIT 2");
eq('devuelve dos filas', 2, \count($filas));
eq('el primero es el que toca', 'Como podar un olivo', $filas[0]['titulo']);
ok('trae la puntuacion',        isset($filas[0]['_score']));
ok('y solo el campo pedido',    !isset($filas[0]['resumen']));

$filas = $db->sql(
    "SELECT titulo, seccion FROM articulos WHERE publicado = true "
    . "ORDER BY EMBEDDING <-> 'coche' LIMIT 3"
);
eq('el WHERE se aplica antes', 0,
    \count(\array_filter($filas, static fn($f) => $f['seccion'] === null)));
ok('y no aparece el no publicado',
    !\in_array('Revisar los frenos', \array_column($filas, 'titulo'), true));

$plan = $db->sql("EXPLAIN SELECT * FROM articulos ORDER BY EMBEDDING <-> 'algo' LIMIT 5");
eq('EXPLAIN dice que es vectorial', 'busqueda vectorial', $plan['operacion']);
eq('y cuantos pide',                                   5, $plan['k']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Los cambios se reflejan');

$db->update('articulos', 'a3', ['titulo' => 'Podar un limonero', 'resumen' => 'poda de arboles citricos']);
$r = $db->similar('articulos', 'podar arboles', 3);
ok('un documento cambiado se reindexa solo', \in_array('a3', \array_column($r, 'id'), true));
eq('sin duplicar vectores', 4, $db->vectorial('articulos')->manifiesto()->vivos());
eq('y contando la baja del anterior', 1, $db->vectorial('articulos')->manifiesto()->bajas);

$db->delete('articulos', 'a1');
$r = $db->similar('articulos', 'podar un olivo', 5);
ok('un documento borrado desaparece de la busqueda',
    !\in_array('a1', \array_column($r, 'id'), true));
eq('y del indice', 3, $db->vectorial('articulos')->manifiesto()->vivos());

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Un vector puesto a mano');

$db->vectores('puntos', ['campo' => 'v']);
$db->insert('puntos', ['v' => \array_merge([1.0], \array_fill(0, 255, 0.0)), 'nombre' => 'este'], 'p1');
$db->insert('puntos', ['v' => \array_merge([0.0, 1.0], \array_fill(0, 254, 0.0)), 'nombre' => 'otro'], 'p2');

$r = $db->similar('puntos', \array_merge([1.0], \array_fill(0, 255, 0.0)), 1);
eq('se busca con un vector, no con texto', 'p1', $r[0]['id']);
eq('y el parecido consigo mismo es 1',      1.0, \round($r[0]['score'], 3));

throws('un vector con las dimensiones cambiadas se rechaza',
    static fn() => $db->insert('puntos', ['v' => [1.0, 2.0]], 'p3'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Los vectores quedan tan protegidos como los documentos');

/*
 * El plan pedia rechazar "coleccion cifrada + vectorial". **El nucleo no tiene
 * cifrado**: eso era del motor anterior, que se archivo en A6, asi que esa
 * incompatibilidad no puede darse y no se escribe un guardian para una funcion
 * que no existe.
 *
 * Lo que si aplica, y es lo que se comprueba: los vectores viven dentro del
 * directorio de datos, asi que les alcanza el mismo blindaje que a los
 * documentos. Seria absurdo negar el acceso a los .json y dejar al lado un
 * archivo con la representacion de esos mismos textos.
 */
$raiz = $db->path();
ok('el directorio de datos esta blindado', \is_file($raiz . '/.htaccess'));

$vec = $raiz . '/articulos/_vec';
ok('los vectores viven dentro de la coleccion', \is_dir($vec));
ok('o sea, dentro de lo blindado',
    \str_starts_with(\str_replace('\\', '/', $vec), \str_replace('\\', '/', $raiz) . '/'));

/*
 * Y un aviso que la guia repite: un embedding no es texto, pero tampoco es
 * ruido. De un vector se puede reconstruir aproximadamente de que hablaba el
 * documento. Quien guarde datos sensibles ha de tratar `_vec/` con el mismo
 * cuidado que los documentos, no como un archivo tecnico sin importancia.
 */
ok('y el archivo de vectores no contiene el texto en claro',
    !\str_contains((string) \file_get_contents($vec . '/vectores.f32'), 'olivo'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('Activar vectores indexa lo que ya habia');

/*
 * Antes solo entraban los documentos escritos DESPUES de activar: `similar()`
 * no encontraba los anteriores y no habia ningun error que lo dijera. El mismo
 * fallo silencioso que tuvo `cifrar()` con el formato empaquetado.
 */
$dirYa = tmpdir('vector_ya_habia');
$ya = new Db($dirYa, ['durable' => false]);
$ya->insert('notas', ['texto' => 'pan de masa madre'], 'n1');
$ya->insert('notas', ['texto' => 'cerveza artesana'], 'n2');
$ya->insert('notas', ['texto' => 'huerto en marzo'], 'n3');

$ya->vectores('notas', ['auto' => ['texto']]);

eq('los tres que ya estaban quedan indexados', 3, $ya->vectorial('notas')->manifiesto()->vivos());
eq('y la busqueda los encuentra', 3, \count($ya->similar('notas', 'pan', 3)));

$ya->insert('notas', ['texto' => 'levadura natural'], 'n4');
eq('los nuevos siguen entrando', 4, $ya->vectorial('notas')->manifiesto()->vivos());

// Reactivar reindexa: es tambien la forma de reparar un indice incompleto.
$ya->vectores('notas', ['auto' => ['texto']]);
eq('volver a activar es idempotente', 4, $ya->vectorial('notas')->manifiesto()->vivos());

$ya->storage()->cerrar();
rmrf($dirYa);

/* ─────────────────────────────────────────────────────────────────────────── */
section('Umbral de parecido, busqueda hibrida y exacta automatica');

$dirU = tmpdir('vector_umbral');
$u = new Db($dirU, ['durable' => false]);
foreach ([
    'Pan de masa madre casero', 'Cerveza artesana en el garaje',
    'Huerto urbano en marzo',   'Levadura natural paso a paso',
    'REF-4471 recambio de bomba',
] as $i => $t) {
    $u->insert('art', ['titulo' => $t, 'zona' => $i < 3 ? 'norte' : 'sur'], 'a' . $i);
}
$u->vectores('art', ['auto' => ['titulo']]);

$todos = $u->sql("SELECT titulo FROM art ORDER BY EMBEDDING <-> 'masa madre' LIMIT 5");
eq('sin umbral devuelve los cinco, se parezcan o no', 5, \count($todos));
ok('el mas parecido va primero', \str_contains($todos[0]['titulo'], 'masa madre'));

$conUmbral = $u->sql("SELECT titulo FROM art WHERE parecido > 0.5
                      ORDER BY EMBEDDING <-> 'masa madre' LIMIT 5");
ok('con umbral salen solo los que se parecen de verdad: ' . \count($conUmbral),
    \count($conUmbral) < 5 && \count($conUmbral) >= 1);
ok('y el que sale lo supera', ($conUmbral[0]['_score'] ?? 0) > 0.5);

eq('un umbral imposible no devuelve nada', [],
    $u->sql("SELECT titulo FROM art WHERE parecido > 0.99
             ORDER BY EMBEDDING <-> 'algo que no esta' LIMIT 5"));

// El umbral se combina con un filtro normal, y cada uno actua donde debe.
$mixto = $u->sql("SELECT titulo FROM art WHERE zona = 'norte' AND parecido > 0.01
                  ORDER BY EMBEDDING <-> 'masa madre' LIMIT 5");
ok('el filtro de campo va antes y el umbral despues', \count($mixto) >= 1);
foreach ($mixto as $fila) {
    ok('todos los que salen son de la zona filtrada',
        \str_contains($fila['titulo'], 'Pan') || \str_contains($fila['titulo'], 'Cerveza')
        || \str_contains($fila['titulo'], 'Huerto'));
}

eq('EXPLAIN cuenta el umbral', 'parecido > 0.5',
    $u->sql("EXPLAIN SELECT * FROM art WHERE parecido > 0.5 ORDER BY EMBEDDING <-> 'x'")['umbral']);

throws('dentro de un OR se niega en vez de dar algo parecido',
    static fn () => $u->sql("SELECT * FROM art WHERE parecido > 0.5 OR zona = 'sur'
                             ORDER BY EMBEDDING <-> 'y'"));

/*
 * La hibrida existe para esto: un codigo de referencia que la busqueda por
 * significado no entiende, pero que aparece literal en el titulo. Al salir en
 * las dos listas, sube al primer puesto.
 */
$hibrida = $u->hibrida('art', 'REF-4471', 3);
eq('la hibrida pone primero al que sale en las dos listas', 'a4', $hibrida[0]['id']);
eq('y lo dice', ['significado', 'palabra'], $hibrida[0]['en']);
ok('los demas salen solo por significado', $hibrida[1]['en'] === ['significado']);
ok('y trae el documento entero', isset($hibrida[0]['doc']['titulo']));

// Con cinco documentos y 200 candidatos, la criba no descarta a nadie: se salta.
eq('una coleccion pequeña devuelve todo lo pedido', 5, \count($u->similar('art', 'pan', 5)));

$u->storage()->cerrar();
rmrf($dirU);

summary();
