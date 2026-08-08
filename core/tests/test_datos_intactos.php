<?php
/**
 * AxiDB - los datos ya escritos se siguen leyendo igual.
 *
 * El resto de tests escriben y leen con la misma version del codigo, asi que un
 * cambio de formato pasaria inadvertido: se escribiria distinto y se leeria
 * distinto, y todo seguiria en verde mientras los datos de un cliente dejan de
 * abrirse.
 *
 * Este test parte de documentos escritos A MANO, con el formato tal y como esta
 * hoy en disco, sin pasar por el motor. Si alguien cambia como se guarda o como
 * se interpreta, aqui salta.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

$dir = tmpdir('datos_intactos');

/*
 * Documentos con el formato exacto que AxiDB tiene en produccion. Escritos como
 * texto a proposito: si se generaran con el motor, el test no probaria nada.
 */
$fixtures = [
    'carta_productos/prod_e2338a74c0397398.json' => <<<'JSON'
{
    "id": "prod_e2338a74c0397398",
    "carta_id": "c_9c9f200a117aacc4",
    "local_id": "l_4440c33155ca1c49",
    "nombre": "Jamón ibérico (ración)",
    "descripcion": "Cortado a cuchillo",
    "precio": 18.5,
    "moneda": "EUR",
    "alergenos": [],
    "disponible": true,
    "orden": 0,
    "_updatedAt": "2026-06-11T19:48:38+00:00",
    "_version": 3,
    "_createdAt": "2026-06-01T10:00:00+00:00"
}
JSON,
    'carta_productos/prod_sinfloat.json' => <<<'JSON'
{
    "id": "prod_sinfloat",
    "local_id": "l_4440c33155ca1c49",
    "nombre": "Agua",
    "precio": 2,
    "stock": null,
    "etiquetas": ["frio", "sin gluten"],
    "extra": {"origen": "manantial", "litros": 0.5},
    "_updatedAt": "2026-06-11T19:48:38+00:00",
    "_version": 1,
    "_createdAt": "2026-06-11T19:48:38+00:00"
}
JSON,
    'locales/l_4440c33155ca1c49.json' => <<<'JSON'
{
    "id": "l_4440c33155ca1c49",
    "slug": "alkimia",
    "nombre": "Alkimia",
    "activo": true,
    "_updatedAt": "2026-06-11T19:48:38+00:00",
    "_version": 7,
    "_createdAt": "2026-05-02T08:30:00+00:00"
}
JSON,
];

foreach ($fixtures as $ruta => $contenido) {
    @\mkdir($dir . '/' . \dirname($ruta), 0755, true);
    \file_put_contents($dir . '/' . $ruta, $contenido);
}

$db = new Db($dir, ['durable' => false]);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Se leen documentos que el motor no escribio');

eq('la coleccion se ve',            2, $db->count('carta_productos'));
eq('y la otra tambien',             1, $db->count('locales'));

$p = $db->get('carta_productos', 'prod_e2338a74c0397398');
ok('el documento se abre', \is_array($p));
eq('el texto con acentos intacto', 'Jamón ibérico (ración)', $p['nombre']);
eq('el decimal intacto',            18.5, $p['precio']);
ok('y sigue siendo decimal',        \is_float($p['precio']));
eq('el booleano intacto',           true, $p['disponible']);
eq('la lista vacia intacta',        [], $p['alergenos']);
eq('el metadato de version intacto', 3, $p['_version']);
eq('la fecha de alta intacta',      '2026-06-01T10:00:00+00:00', $p['_createdAt']);

$a = $db->get('carta_productos', 'prod_sinfloat');
eq('un entero se lee como entero',  2, $a['precio']);
ok('y no como decimal',             \is_int($a['precio']));
ok('el nulo se conserva',           \array_key_exists('stock', $a) && $a['stock'] === null);
eq('la lista con valores',          ['frio', 'sin gluten'], $a['etiquetas']);
eq('el objeto anidado',             ['origen' => 'manantial', 'litros' => 0.5], $a['extra']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Las consultas los encuentran');

eq('all los devuelve',       2, \count($db->all('carta_productos')));
eq('find con filtro',        1, \count($db->find('carta_productos')->where('precio', '>', 10)->get()));
eq('SQL tambien',            1, $db->sql("SELECT COUNT(*) FROM carta_productos WHERE nombre LIKE 'Jam%'"));
eq('ids devuelve los reales',
    ['prod_e2338a74c0397398', 'prod_sinfloat'],
    $db->ids('carta_productos'));

$db->index('carta_productos', 'local_id');
eq('indexar datos preexistentes los encuentra', 2,
    \count($db->by('carta_productos', 'local_id', 'l_4440c33155ca1c49')));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Modificarlos no pierde nada de lo que ya tenian');

$antes = $db->get('carta_productos', 'prod_e2338a74c0397398');
$db->put('carta_productos', 'prod_e2338a74c0397398', ['precio' => 19.5]);
$despues = $db->get('carta_productos', 'prod_e2338a74c0397398');

eq('cambia lo que se pidio',        19.5, $despues['precio']);
eq('_version sube desde la que habia', $antes['_version'] + 1, $despues['_version']);
eq('_createdAt NO se toca',         $antes['_createdAt'], $despues['_createdAt']);

foreach (['carta_id', 'nombre', 'descripcion', 'moneda', 'alergenos', 'disponible', 'orden'] as $campo) {
    eq("el campo '{$campo}' sobrevive a la modificacion", $antes[$campo], $despues[$campo]);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] El formato en disco no ha cambiado');

/*
 * Ademas de leerse, tienen que seguir ESCRIBIENDOSE igual: JSON con sangria,
 * unicode sin escapar y decimales con su parte fraccionaria. Si esto cambia,
 * los datos dejan de ser legibles a ojo y los diffs de git se vuelven inutiles.
 */
$crudo = (string) \file_get_contents($dir . '/carta_productos/prod_e2338a74c0397398.json');

ok('sigue siendo JSON con sangria de cuatro espacios', \str_contains($crudo, "\n    \""));
ok('el unicode no se escapa',   \str_contains($crudo, 'Jamón'));
ok('y no aparece como \\u00f3', !\str_contains($crudo, '\\u00f3'));
ok('los decimales conservan su parte fraccionaria', \str_contains($crudo, '19.5'));

$db->put('carta_productos', 'entero_exacto', ['valor' => 7.0]);
$crudo2 = (string) \file_get_contents($dir . '/carta_productos/entero_exacto.json');
ok('un decimal con parte cero se guarda como 7.0, no como 7', \str_contains($crudo2, '7.0'));
ok('y vuelve del disco como decimal', \is_float($db->get('carta_productos', 'entero_exacto')['valor']));

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Un documento con formato viejo o raro no tumba la coleccion');

// Sin metadatos: el motor debe poder leerlo igualmente.
\file_put_contents($dir . '/carta_productos/antiguo.json', '{"id":"antiguo","nombre":"Sin metadatos"}');
$viejo = $db->get('carta_productos', 'antiguo');
eq('un documento sin metadatos se lee', 'Sin metadatos', $viejo['nombre']);
ok('y no inventa version',              !isset($viejo['_version']));

$db->put('carta_productos', 'antiguo', ['visto' => true]);
eq('al modificarlo se le pone version 1', 1, $db->get('carta_productos', 'antiguo')['_version']);

// Un archivo ilegible no puede tirar abajo la lectura del resto.
\file_put_contents($dir . '/carta_productos/roto.json', '{esto no es json');
$todos = $db->all('carta_productos');
ok('los documentos sanos se siguen leyendo', \count($todos) >= 4);
ok('y el roto simplemente no aparece',
    !\in_array('roto', \array_column($todos, 'id'), true));

rmrf($dir);
summary();
