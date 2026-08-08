<?php
/**
 * AxiDB - los dos drivers se comportan igual.
 *
 * Es el test que hace segura toda la ola. Cambiar como se guardan los datos es
 * lo mas delicado que se puede tocar en una base de datos: si packed devuelve
 * algo distinto que fs en un solo caso, hay dos motores y no uno.
 *
 * Cada comprobacion se ejecuta contra los dos y se exige el mismo resultado, no
 * "un resultado razonable en cada uno".
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

/** Abre una base con el driver indicado ya declarado en sus colecciones. */
function conDriver(string $driver, string $sufijo): Db
{
    $db = new Db(tmpdir('paridad_' . $driver . '_' . $sufijo), ['durable' => false]);
    foreach (['p', 'q', 'r'] as $c) {
        $db->storage()->declararDriver($c, $driver);
    }
    return $db;
}

/** Ejecuta lo mismo en los dos y compara. */
function enAmbos(string $sufijo, callable $caso): array
{
    $fs     = conDriver('fs', $sufijo);
    $packed = conDriver('packed', $sufijo);
    $r = [$caso($fs), $caso($packed)];
    rmrf($fs->path());
    rmrf($packed->path());
    return $r;
}

function iguales(string $etiqueta, string $sufijo, callable $caso): bool
{
    [$a, $b] = enAmbos($sufijo, $caso);
    $ok = \json_encode($a) === \json_encode($b);
    if (!$ok) {
        $etiqueta .= "\n           fs     = " . \json_encode($a)
                   . "\n           packed = " . \json_encode($b);
    }
    return ok($etiqueta, $ok);
}

/** Quita lo que cambia entre ejecuciones para poder comparar documentos. */
function sinFechas(mixed $x): mixed
{
    if (!\is_array($x)) {
        return $x;
    }
    unset($x['_createdAt'], $x['_updatedAt']);
    return \array_map('sinFechas', $x);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] El documento que se guarda es identico');

iguales('alta: mismo documento devuelto', 'a1', static function (Db $db) {
    return sinFechas($db->insert('p', ['nombre' => 'cafe', 'precio' => 2.5], 'd1'));
});

iguales('alta: mismo documento releido', 'a2', static function (Db $db) {
    $db->insert('p', ['nombre' => 'cafe', 'precio' => 2.5], 'd1');
    return sinFechas($db->get('p', 'd1'));
});

iguales('tipos: entero, decimal, nulo, booleano, lista y objeto', 'a3', static function (Db $db) {
    $db->insert('p', [
        'entero' => 42, 'decimal' => 18.0, 'negativo' => -3.75, 'cero' => 0,
        'texto' => 'Cañón con "comillas"', 'vacio' => '', 'si' => true, 'no' => false,
        'nulo' => null, 'lista' => [1, 2, 3], 'mapa' => ['a' => ['b' => 'c']], 'listaVacia' => [],
    ], 'tipos');
    $d = $db->get('p', 'tipos');
    return [sinFechas($d), \array_map('gettype', $d)];
});

iguales('un documento de 200 KB viaja igual', 'a4', static function (Db $db) {
    $db->insert('p', ['grande' => \str_repeat('x', 200000)], 'g');
    return \strlen($db->get('p', 'g')['grande']);
});

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Metadatos');

iguales('_version sube igual en cinco escrituras', 'b1', static function (Db $db) {
    for ($i = 0; $i < 5; $i++) {
        $db->put('p', 'v', ['n' => $i]);
    }
    return $db->get('p', 'v')['_version'];
});

iguales('_createdAt se conserva y _updatedAt cambia', 'b2', static function (Db $db) {
    $a = $db->insert('p', ['n' => 1], 'm');
    \usleep(1100000);
    $b = $db->put('p', 'm', ['n' => 2]);
    return [$a['_createdAt'] === $b['_createdAt'], $a['_updatedAt'] !== $b['_updatedAt']];
});

iguales('fusion y reemplazo se comportan igual', 'b3', static function (Db $db) {
    $db->insert('p', ['a' => 1, 'b' => 2], 'f');
    $fusion = sinFechas($db->put('p', 'f', ['b' => 20, 'c' => 3]));
    $reemp  = sinFechas($db->put('p', 'f', ['solo' => 'esto'], true));
    return [$fusion, $reemp];
});

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Lectura, listado y borrado');

iguales('all devuelve lo mismo, ordenado por id', 'c1', static function (Db $db) {
    for ($i = 0; $i < 10; $i++) {
        $db->insert('p', ['n' => $i], 'd' . $i);
    }
    $docs = $db->all('p');
    \usort($docs, static fn($x, $y) => \strcmp($x['id'], $y['id']));
    return \array_map('sinFechas', $docs);
});

iguales('ids coinciden',   'c2', static function (Db $db) {
    for ($i = 0; $i < 6; $i++) {
        $db->insert('p', ['n' => $i], 'd' . $i);
    }
    return $db->ids('p');
});

iguales('count coincide',  'c3', static function (Db $db) {
    for ($i = 0; $i < 7; $i++) {
        $db->insert('p', ['n' => $i], 'd' . $i);
    }
    $db->delete('p', 'd3');
    return $db->count('p');
});

iguales('borrar devuelve lo mismo y deja lo mismo', 'c4', static function (Db $db) {
    $db->insert('p', ['n' => 1], 'x');
    return [$db->delete('p', 'x'), $db->delete('p', 'x'), $db->get('p', 'x'), $db->count('p')];
});

iguales('coleccion inexistente', 'c5', static function (Db $db) {
    return [$db->get('nada', 'x'), $db->all('nada'), $db->count('nada'), $db->delete('nada', 'x')];
});

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Indices y consultas');

iguales('el indice encuentra lo mismo', 'd1', static function (Db $db) {
    $db->index('p', 'grupo');
    for ($i = 0; $i < 12; $i++) {
        $db->insert('p', ['grupo' => 'g' . ($i % 3), 'n' => $i], 'd' . $i);
    }
    return [\count($db->by('p', 'grupo', 'g0')), \count($db->by('p', 'grupo', 'g1'))];
});

iguales('verifyIndexes no encuentra huecos', 'd2', static function (Db $db) {
    $db->index('p', 'grupo');
    for ($i = 0; $i < 9; $i++) {
        $db->insert('p', ['grupo' => 'g' . ($i % 3)], 'd' . $i);
    }
    return $db->verifyIndexes('p');
});

iguales('find con filtros y orden', 'd3', static function (Db $db) {
    foreach ([['cafe', 2], ['te', 3], ['tarta', 5], ['guiso', 12]] as $i => [$n, $pr]) {
        $db->insert('p', ['n' => $n, 'precio' => $pr], 'd' . $i);
    }
    return \array_column($db->find('p')->where('precio', '>', 2)->orderBy('precio', 'desc')->get(), 'n');
});

iguales('AxiSQL da lo mismo', 'd4', static function (Db $db) {
    $db->sql("INSERT INTO q (cliente, total) VALUES ('Ana', 421.20)");
    $db->sql("INSERT INTO q (cliente, total) VALUES ('Luis', 76.00)");
    return [
        $db->sql('SELECT COUNT(*) FROM q'),
        \array_column($db->sql('SELECT cliente FROM q ORDER BY total DESC'), 'cliente'),
        $db->sql("UPDATE q SET total = 500 WHERE cliente = 'Ana'"),
        $db->sql('SELECT COUNT(*) FROM q WHERE total > 100'),
    ];
});

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Errores y casos limite');

iguales('un id invalido se rechaza igual', 'e1', static function (Db $db) {
    $mensajes = [];
    foreach (['../fuera', '', 'a/b', 'con espacio'] as $malo) {
        try {
            $db->get('p', $malo);
            $mensajes[] = 'NO LANZO';
        } catch (\Axi\Core\Exception) {
            $mensajes[] = 'rechazado';
        }
    }
    return $mensajes;
});

iguales('update de un id inexistente lanza en los dos', 'e2', static function (Db $db) {
    try {
        $db->update('p', 'fantasma', ['a' => 1]);
        return 'NO LANZO';
    } catch (\Axi\Core\Exception) {
        return 'lanzo';
    }
});

iguales('portabilidad: ids que solo difieren en mayusculas', 'e3', static function (Db $db) {
    $db->insert('p', ['q' => 'minuscula'], 'l_la');
    $db->insert('p', ['q' => 'Mayuscula'], 'l_lA');
    return [$db->count('p'), $db->get('p', 'l_la')['q'], $db->get('p', 'l_lA')['q']];
});

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Lo que si es distinto, y a proposito');

$fs     = conDriver('fs', 'z');
$packed = conDriver('packed', 'z');
$fs->insert('p', ['n' => 1], 'd1');
$packed->insert('p', ['n' => 1], 'd1');

ok('fs deja un archivo por documento, legible',
    \is_file($fs->path() . '/p/d1.json'));
ok('packed no: los documentos van dentro de data.axi',
    !\is_file($packed->path() . '/p/d1.json') && \is_file($packed->path() . '/p/data.axi'));
eq('cada coleccion declara su driver', 'fs',     $fs->storage()->driverDe('p'));
eq('y el otro el suyo',                'packed', $packed->storage()->driverDe('p'));
eq('sin declaracion, el defecto es fs', 'fs',    $fs->storage()->driverDe('sin_declarar'));

rmrf($fs->path());
rmrf($packed->path());
summary();
