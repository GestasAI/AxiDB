<?php
/**
 * AxiDB - comparativa contra SQLite, reproducible.
 *
 *   php axidb/bench/comparativa.php [--docs=10000] [--grabar]
 *
 * SQLite es la referencia honesta: embebido, sin servidor, el software mas
 * probado del mundo. Compararse con el dice donde esta AxiDB de verdad, y
 * publicar el numero aunque no favorezca es la unica forma de que el dato sirva
 * para decidir algo.
 *
 * La comparacion es lo mas justa que se puede hacer, y aun asi no es simetrica:
 * AxiDB valida nombres, calcula metadatos, mantiene un mapa de desplazamientos
 * y serializa a JSON legible. SQLite escribe en una transaccion sin ninguna de
 * esas cosas. Se anota, no se disimula.
 */

declare(strict_types=1);

require __DIR__ . '/../core/axidb.php';

use Axi\Core\Db;

$DOCS   = 10000;
$grabar = \in_array('--grabar', $argv, true);
foreach ($argv as $a) {
    if (\str_starts_with($a, '--docs=')) {
        $DOCS = \max(100, (int) \substr($a, 7));
    }
}

$base = \sys_get_temp_dir() . '/axidb_bench_' . \bin2hex(\random_bytes(3));
@\mkdir($base, 0777, true);

function borrarArbol(string $p): void
{
    if (!\is_dir($p)) {
        @\unlink($p);
        return;
    }
    foreach (\scandir($p) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            borrarArbol($p . '/' . $e);
        }
    }
    @\rmdir($p);
}

function cronometrar(callable $fn): float
{
    $t = \microtime(true);
    $fn();
    return (\microtime(true) - $t) * 1000;
}

/**
 * Para lo que se puede repetir sin efectos: se queda con la tanda mas rapida.
 *
 * Las lecturas en Windows son la medida mas ruidosa de todo el banco —tres
 * tandas identicas dieron 277, 142 y 189 ms—, porque cada `get` de fs abre un
 * archivo y ahi entran el antivirus y la cache del sistema. El minimo es la
 * medida menos contaminada: el ruido solo puede sumar tiempo, nunca restarlo.
 */
function cronometrarLoMasLimpio(callable $fn): float
{
    $mejor = INF;
    for ($i = 0; $i < 3; $i++) {
        $mejor = \min($mejor, cronometrar($fn));
    }
    return $mejor;
}

/** Los mismos datos para todos los contendientes. */
$documentos = [];
for ($i = 0; $i < $DOCS; $i++) {
    $documentos[] = [
        'id'      => 'd' . \str_pad((string) $i, 7, '0', STR_PAD_LEFT),
        'nombre'  => 'Documento numero ' . $i,
        'grupo'   => 'g' . ($i % 20),
        'total'   => $i + 0.5,
        'activo'  => $i % 2 === 0,
    ];
}

echo "=========================================================\n";
echo " AxiDB frente a SQLite — {$DOCS} documentos\n";
echo ' PHP ' . PHP_VERSION . ' · ' . PHP_OS_FAMILY . "\n";
echo "=========================================================\n\n";

$resultados = [];

/* ─────────────────────────── AxiDB, driver fs ─────────────────────────── */

$dbFs = new Db($base . '/fs', ['durable' => false]);
$resultados['fs']['alta_masiva'] = cronometrar(static function () use ($dbFs, $documentos) {
    foreach ($documentos as $d) {
        $dbFs->put('p', $d['id'], $d, true);
    }
});
$resultados['fs']['lectura_1000'] = cronometrarLoMasLimpio(static function () use ($dbFs, $DOCS) {
    for ($i = 0; $i < 1000; $i++) {
        $dbFs->get('p', 'd' . \str_pad((string) ($i * 7 % $DOCS), 7, '0', STR_PAD_LEFT));
    }
});
$resultados['fs']['escaneo'] = cronometrarLoMasLimpio(static fn() => $dbFs->all('p'));
$resultados['fs']['bytes']   = 0;
foreach (\glob($base . '/fs/p/*.json') ?: [] as $f) {
    $resultados['fs']['bytes'] += \filesize($f);
}

/* ───────────────────────── AxiDB, driver packed ───────────────────────── */

$dbPk = new Db($base . '/packed', ['durable' => false]);
$dbPk->storage()->declareDriver('p', 'packed');
$resultados['packed']['alta_masiva'] = cronometrar(static function () use ($dbPk, $documentos) {
    foreach ($documentos as $d) {
        $dbPk->put('p', $d['id'], $d, true);
    }
});
$resultados['packed']['lectura_1000'] = cronometrarLoMasLimpio(static function () use ($dbPk, $DOCS) {
    for ($i = 0; $i < 1000; $i++) {
        $dbPk->get('p', 'd' . \str_pad((string) ($i * 7 % $DOCS), 7, '0', STR_PAD_LEFT));
    }
});
$resultados['packed']['escaneo'] = cronometrarLoMasLimpio(static fn() => $dbPk->all('p'));
$resultados['packed']['bytes']   = (int) @\filesize($base . '/packed/p/data.axi');

/* ───────────────────────────────── SQLite ───────────────────────────────── */

$hay = \extension_loaded('pdo_sqlite');
if ($hay) {
    $sq = new PDO('sqlite:' . $base . '/ref.db');
    $sq->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sq->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
    $sq->exec('CREATE TABLE p (id TEXT PRIMARY KEY, grupo TEXT, total REAL, doc TEXT)');

    $resultados['sqlite']['alta_masiva'] = cronometrar(static function () use ($sq, $documentos) {
        $sq->beginTransaction();
        $st = $sq->prepare('INSERT INTO p VALUES (?,?,?,?)');
        foreach ($documentos as $d) {
            $st->execute([$d['id'], $d['grupo'], $d['total'], \json_encode($d)]);
        }
        $sq->commit();
    });
    $resultados['sqlite']['lectura_1000'] = cronometrarLoMasLimpio(static function () use ($sq, $DOCS) {
        $st = $sq->prepare('SELECT doc FROM p WHERE id = ?');
        for ($i = 0; $i < 1000; $i++) {
            $st->execute(['d' . \str_pad((string) ($i * 7 % $DOCS), 7, '0', STR_PAD_LEFT)]);
            $st->fetch();
        }
    });
    $resultados['sqlite']['escaneo'] = cronometrarLoMasLimpio(static fn() => $sq->query('SELECT doc FROM p')->fetchAll());
    $resultados['sqlite']['bytes']   = (int) @\filesize($base . '/ref.db')
                                     + (int) @\filesize($base . '/ref.db-wal');
} else {
    echo "AVISO: pdo_sqlite no esta disponible; se compara solo entre drivers.\n\n";
}

/* ───────────────────────────────── Informe ───────────────────────────────── */

$fila = static function (string $etiqueta, callable $valor) use ($resultados): void {
    \printf('  %-22s', $etiqueta);
    foreach (['fs', 'packed', 'sqlite'] as $quien) {
        \printf('%14s', isset($resultados[$quien]) ? $valor($resultados[$quien]) : '-');
    }
    echo "\n";
};

\printf("  %-22s%14s%14s%14s\n", '', 'AxiDB fs', 'AxiDB packed', 'SQLite');
echo '  ' . \str_repeat('-', 64) . "\n";
$fila('alta masiva (ms)',   static fn($r) => \number_format($r['alta_masiva'], 0));
$fila('  por documento',    static fn($r) => \number_format($r['alta_masiva'] / $GLOBALS['DOCS'], 3));
$fila('1000 lecturas (ms)', static fn($r) => \number_format($r['lectura_1000'], 0));
$fila('escaneo total (ms)', static fn($r) => \number_format($r['escaneo'], 0));
$fila('tamaño en disco (KB)', static fn($r) => \number_format($r['bytes'] / 1024, 0));

echo "\n";
$ganancia = $resultados['fs']['alta_masiva'] / \max(0.001, $resultados['packed']['alta_masiva']);
\printf("  packed escribe %.1f veces mas rapido que fs\n", $ganancia);

if ($hay) {
    $vsSqlite = $resultados['packed']['alta_masiva'] / \max(0.001, $resultados['sqlite']['alta_masiva']);
    \printf("  y sigue siendo %.0f veces mas lento que SQLite escribiendo\n", $vsSqlite);
    $lectura = $resultados['packed']['lectura_1000'] / \max(0.001, $resultados['sqlite']['lectura_1000']);
    \printf("  leyendo por id, packed esta a %.1fx de SQLite\n", $lectura);
}

echo "\n  Lo que la tabla no dice y hay que tener en cuenta:\n";
echo "   - AxiDB valida nombres, calcula metadatos y serializa JSON legible en\n";
echo "     cada escritura. SQLite inserta en una transaccion sin nada de eso.\n";
echo "   - fs deja cada documento en un archivo que se abre con un editor.\n";
echo "     Ese es el motivo de que sea lento, y para muchas colecciones vale la pena.\n";
echo "   - SQLite es relacional; AxiDB guarda documentos sin esquema.\n";

/* ───────────────────────────── Linea base ───────────────────────────── */

$baseFile = __DIR__ . '/comparativa.json';
$actual   = [
    'docs'    => $DOCS,
    'php'     => PHP_VERSION,
    'so'      => PHP_OS_FAMILY,
    'medidas' => \array_map(
        static fn($r) => ['alta_masiva' => \round($r['alta_masiva'], 1), 'lectura_1000' => \round($r['lectura_1000'], 1)],
        $resultados
    ),
];

$salida = 0;
if ($grabar || !\is_file($baseFile)) {
    \file_put_contents($baseFile, \json_encode($actual, JSON_PRETTY_PRINT));
    echo "\n  Linea base guardada en bench/comparativa.json\n";
} else {
    $ref = \json_decode((string) \file_get_contents($baseFile), true);
    echo "\n  Frente a la linea base ({$ref['docs']} docs, " . ($ref['so'] ?? '?') . "):\n";

    /*
     * SQLite es el control del experimento: nuestro codigo no puede hacerlo mas
     * lento. Si el tiempo de SQLite sube, lo que ha cambiado es la maquina —el
     * disco, el antivirus, el portatil caliente—, no AxiDB.
     *
     * Por eso no se comparan milisegundos contra milisegundos, que ya dio un
     * falso positivo: una tanda salio x1.4 entera, SQLite incluido. Se compara
     * cuantas veces mas lento que SQLite es cada driver, hoy y en la linea base.
     * Esa proporcion sobrevive a que la maquina vaya lenta.
     */
    $patron    = static fn(array $medidas, string $op): float => (float) ($medidas['sqlite'][$op] ?? 0);
    $patronHoy = static fn(string $op): float => $patron($actual['medidas'], $op);
    $patronBase = static fn(string $op): float => $patron($ref['medidas'] ?? [], $op);

    foreach (['alta_masiva', 'lectura_1000'] as $op) {
        $derivaMaquina = $patronBase($op) > 0 ? $patronHoy($op) / $patronBase($op) : 1.0;
        if ($derivaMaquina > 1.25 || $derivaMaquina < 0.8) {
            \printf("    [la maquina va hoy a x%.2f: SQLite tambien se ha movido]\n", $derivaMaquina);
        }
    }

    foreach ($actual['medidas'] as $quien => $m) {
        foreach ($m as $op => $ms) {
            $antes = $ref['medidas'][$quien][$op] ?? null;
            if ($antes === null || $antes <= 0 || $ref['docs'] !== $DOCS) {
                continue;
            }
            $veces = $ms / $antes;

            // Lo que se juzga: la distancia a SQLite, no el reloj de pared.
            $hoy  = $patronHoy($op) > 0 ? $ms / $patronHoy($op) : 0.0;
            $ayer = $patronBase($op) > 0 ? $antes / $patronBase($op) : 0.0;

            $aviso = '';
            if ($quien !== 'sqlite' && $ayer > 0 && $hoy / $ayer > 1.5) {
                $aviso  = '  <-- REGRESION';
                $salida = 1;
            }
            \printf("    %-8s %-14s %8.1f ms (base %.1f, x%.2f reloj | x%.1f vs SQLite, base x%.1f)%s\n",
                $quien, $op, $ms, $antes, $veces, $hoy, $ayer, $aviso);
        }
    }
    if ($salida === 0) {
        echo "    sin regresiones\n";
    }
}

borrarArbol($base);
exit($salida);
