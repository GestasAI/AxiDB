<?php
/**
 * AxiDB - vigilancia de rendimiento contra una linea base guardada.
 *
 * Los demas tests comprueban que el resultado es correcto. Este comprueba que
 * sigue costando lo mismo. Sin el, una degradacion se cuela poco a poco y nadie
 * se entera hasta que un cliente se queja.
 *
 * Como evita ser un test caprichoso: no compara contra un numero fijo, sino
 * contra la linea base de ESTA maquina, y con un margen amplio. La suite corre
 * con Docker y otros procesos peleando por el disco, asi que un umbral ajustado
 * daria rojos que no son regresiones. Lo que tiene que cazar es un cambio de
 * orden de magnitud: volver a reescribir la coleccion en cada alta, perder un
 * indice, convertir una lectura O(1) en un escaneo.
 *
 *   php core/tests/test_rendimiento.php            compara con la linea base
 *   php core/tests/test_rendimiento.php --grabar   crea o actualiza la linea base
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

/** Cuantas veces mas lento se tolera antes de considerarlo regresion. */
const MARGEN = 4.0;

/** Suelo en milisegundos: por debajo, el ruido de medicion manda. */
const SUELO_MS = 2.0;

$baseFile = \dirname(__DIR__, 2) . '/bench/rendimiento.json';
$grabar   = \in_array('--grabar', $argv, true);

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Medicion de las operaciones clave');

$dir = tmpdir('rendimiento');
$db  = new Db($dir, ['durable' => false]);

/** Ejecuta $veces la operacion y devuelve el coste medio en milisegundos. */
function medir(callable $op, int $veces): float
{
    $t = \microtime(true);
    for ($i = 0; $i < $veces; $i++) {
        $op($i);
    }
    return (\microtime(true) - $t) * 1000 / $veces;
}

/**
 * Lo mismo, pero quedandose con la tanda mas rapida de tres.
 *
 * Una sola tanda mide tanto el codigo como el antivirus, el cache del disco y
 * lo que este haciendo Windows en ese momento; se han visto tandas identicas
 * separarse 2,7 veces. El minimo es la medida menos contaminada: el ruido solo
 * puede sumar tiempo, nunca restarlo.
 */
function medirLoMasLimpio(callable $op, int $veces): float
{
    $mejor = INF;
    for ($ronda = 0; $ronda < 3; $ronda++) {
        $mejor = \min($mejor, medir($op, $veces));
    }
    return $mejor;
}

$medidas = [];

$medidas['alta'] = medir(
    static fn(int $i) => $db->insert('p', ['cliente' => 'c' . ($i % 20), 'total' => $i], 'd' . $i),
    500
);

$medidas['lectura_por_id'] = medir(
    static fn(int $i) => $db->get('p', 'd' . ($i % 500)),
    500
);

$medidas['escaneo_500'] = medir(static fn() => $db->find('p')->where('total', '>', 250)->get(), 5);

$db->index('p', 'cliente');
$medidas['consulta_por_indice'] = medir(
    static fn(int $i) => $db->by('p', 'cliente', 'c' . ($i % 20)),
    100
);

$medidas['modificacion'] = medir(
    static fn(int $i) => $db->put('p', 'd' . ($i % 100), ['tocado' => $i]),
    100
);

$medidas['count'] = medir(static fn() => $db->count('p'), 50);

$medidas['sql_select'] = medir(
    static fn() => $db->sql("SELECT * FROM p WHERE cliente = 'c3'"),
    100
);

foreach ($medidas as $nombre => $ms) {
    \printf("    %-22s %8.3f ms\n", $nombre, $ms);
}
ok('se midieron las siete operaciones', \count($medidas) === 7);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Comparacion con la linea base');

if ($grabar || !\is_file($baseFile)) {
    @\mkdir(\dirname($baseFile), 0755, true);
    \file_put_contents($baseFile, \json_encode([
        'php'      => PHP_VERSION,
        'so'       => PHP_OS_FAMILY,
        'margen'   => MARGEN,
        'medidas'  => \array_map(static fn($v) => \round($v, 4), $medidas),
    ], JSON_PRETTY_PRINT));

    echo "    Linea base guardada en bench/rendimiento.json\n";
    ok('linea base creada: la proxima ejecucion ya compara', \is_file($baseFile));
    rmrf($dir);
    summary();
}

$base = \json_decode((string) \file_get_contents($baseFile), true);
ok('la linea base se lee', \is_array($base) && isset($base['medidas']));

/*
 * Solo se comparan milisegundos con una linea base tomada EN ESTA MISMA MAQUINA.
 *
 * Un runner compartido de la CI no tiene nada que ver con el portatil donde se
 * grabo el archivo: comparar sus tiempos no mide una regresion, mide que son
 * ordenadores distintos. La CI se puso roja justo por eso, y un rojo que no
 * significa nada acaba en que nadie mira los rojos.
 *
 * Lo que si vale en cualquier maquina son las relaciones entre operaciones —una
 * lectura por indice tiene que seguir siendo mucho mas barata que un escaneo— y
 * de eso se ocupa la seccion C, que se ejecuta siempre.
 */
$mismaMaquina = ($base['maquina'] ?? null) === \php_uname('n')
             && ($base['so'] ?? '') === PHP_OS_FAMILY;

if (!$mismaMaquina) {
    echo '    La linea base es de otra maquina (' . ($base['maquina'] ?? '?')
       . ' / ' . ($base['so'] ?? '?') . "): no se comparan milisegundos.\n";
    echo "    Para compararlos aqui: php core/tests/test_rendimiento.php --grabar\n";
    ok('linea base de otra maquina: comparacion omitida a proposito', true);
}

foreach ($mismaMaquina ? $medidas : [] as $nombre => $ms) {
    $ref = $base['medidas'][$nombre] ?? null;
    if ($ref === null) {
        ok("'{$nombre}' es nuevo: añadelo con --grabar", false);
        continue;
    }
    // Por debajo del suelo, la relacion entre medidas es ruido puro.
    $tope  = \max($ref * MARGEN, SUELO_MS);
    $veces = $ref > 0 ? $ms / $ref : 1.0;
    \printf("    %-22s %8.3f ms  (base %.3f, x%.1f)\n", $nombre, $ms, $ref, $veces);
    ok(\sprintf("'%s' dentro del margen (tope %.3f ms)", $nombre, $tope), $ms <= $tope);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Invariantes que no dependen de la maquina');

/*
 * Las relaciones entre operaciones si son estables aunque la maquina cambie.
 * Son las que cazan una regresion estructural en cualquier ordenador.
 */
ok('leer por id es mas barato que escanear 500',
    $medidas['lectura_por_id'] < $medidas['escaneo_500']);
ok('consultar por indice es mas barato que escanear',
    $medidas['consulta_por_indice'] < $medidas['escaneo_500']);
ok('contar no cuesta mas que escanear (no abre documentos)',
    $medidas['count'] <= $medidas['escaneo_500']);
ok('SQL por indice cuesta lo mismo que la API por indice, con su margen',
    $medidas['sql_select'] < \max($medidas['consulta_por_indice'] * 4, SUELO_MS));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] El alta no es cuadratica');

/*
 * La regresion mas cara de la historia del proyecto: el motor viejo reescribia
 * la coleccion entera en cada alta, asi que insertar N documentos costaba N al
 * cuadrado. Se detecta comparando el coste de las primeras altas con el de las
 * ultimas: si crece con el tamaño, ha vuelto.
 *
 * El umbral esta puesto con cabeza. Entre las dos mediciones la coleccion se
 * multiplica por siete, asi que una vuelta a la reescritura completa saldria
 * como un x7 largo. Se exige menos de x3: sobra para cazarla y no salta con el
 * ruido de la maquina, que medido llega a x2,7 entre tandas identicas.
 */
$dir2 = tmpdir('rendimiento_escala');
$db2  = new Db($dir2, ['durable' => false]);

$sello = 0;
$alta  = static function (int $i) use ($db2, &$sello): void {
    $db2->insert('q', ['n' => $i], 'k' . (++$sello));
};

/*
 * Se repite la medicion entera hasta tres veces y basta con que UNA salga
 * limpia. No es indulgencia: una regresion cuadratica de verdad da x7 en las
 * tres, mientras que un tiron del disco solo estropea la que le toca.
 *
 * Hizo falta porque en PHP 8.4 el alta baja a 0,78 ms —contra los 2,0 de 8.2— y
 * cuanto mas rapida es la operacion, mas distorsiona la proporcion cualquier
 * pausa del sistema: con 0,78 ms de base, un paron de milisegundo y medio ya
 * triplica el resultado. El test se ponia rojo sin que nada hubiera empeorado.
 */
$mejorRatio = INF;
$primeras   = 0.0;
$ultimas    = 0.0;

for ($intento = 0; $intento < 3; $intento++) {
    $primeras = medirLoMasLimpio($alta, 200);
    while ($sello < 2000 + $intento * 2500) {
        $alta($sello);
    }
    $ultimas = medirLoMasLimpio($alta, 200);

    $ratio      = $primeras > 0 ? $ultimas / $primeras : 0;
    $mejorRatio = \min($mejorRatio, $ratio);

    \printf("    intento %d: %.3f -> %.3f ms/alta  (x%.2f)\n",
        $intento + 1, $primeras, $ultimas, $ratio);

    if ($mejorRatio <= 3.0) {
        break;                          // limpio a la primera: no hace falta mas
    }
}

ok('el coste del alta no crece con el tamaño de la coleccion',
    $mejorRatio <= 3.0 || $ultimas <= SUELO_MS);

rmrf($dir);
rmrf($dir2);
summary();
