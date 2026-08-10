<?php
/**
 * AxiDB - runner de la suite del nucleo.
 *
 *   php axidb/core/tests/run.php
 *
 * Ejecuta cada test_*.php en su propio proceso, suma resultados y devuelve
 * codigo de salida 0 solo si todo esta en verde.
 */

declare(strict_types=1);

/*
 * Los tests de ataque (test_sec_*) NO entran en el gate de regresion mientras la
 * hoja de endurecimiento (claude/planes/axidb-seguridad.md) este abierta.
 *
 * No es esconder rojos: es separar dos cosas distintas. Este runner vigila que
 * un cambio nuevo no rompa lo que ya funcionaba, y para eso tiene que poder estar
 * verde. Los test_sec_* estan rojos A PROPOSITO —cada uno es un ataque que
 * todavia funciona— y su progreso se mide aparte, con run_sec.php. Meterlos aqui
 * dejaria el gate en rojo hasta cerrar las 28 vulnerabilidades, y un gate que
 * nunca esta verde deja de avisar de las regresiones de verdad.
 *
 * Cuando run_sec.php quede entero en verde, se borra esta exclusion y los ataques
 * pasan a ser parte del gate permanente, como red de no-regresion.
 */
$tests = \array_values(\array_filter(
    \glob(__DIR__ . '/test_*.php') ?: [],
    static fn(string $f) => !\str_starts_with(\basename($f), 'test_sec_')
));
\sort($tests);

echo "=== AxiDB core — suite del nucleo ===\n";
echo \count($tests) . " archivos, PHP " . PHP_VERSION . "\n";

$totalPass = 0;
$totalFail = 0;
$verdes    = 0;
$rojos     = [];
$inicio    = \microtime(true);

foreach ($tests as $file) {
    $nombre = \basename($file);
    $t0     = \microtime(true);
    $salida = (string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($file) . ' 2>&1');
    $ms     = (\microtime(true) - $t0) * 1000;

    $pass = 0;
    $fail = 0;
    if (\preg_match('/Resultado:\s*(\d+)\s+passed,\s*(\d+)\s+failed/i', $salida, $m)) {
        $pass = (int) $m[1];
        $fail = (int) $m[2];
    } else {
        $fail = 1;                                   // sin resumen: se cuenta como fallo
        $salida .= "\n  (el test no emitio resumen)\n";
    }

    $totalPass += $pass;
    $totalFail += $fail;

    if ($fail === 0) {
        $verdes++;
        \printf("  [ok]   %-28s %4d checks  %6.0f ms\n", $nombre, $pass, $ms);
    } else {
        $rojos[$nombre] = $salida;
        \printf("  [FAIL] %-28s %4d ok / %d ko  %6.0f ms\n", $nombre, $pass, $fail, $ms);
    }
}

$seg = \microtime(true) - $inicio;

foreach ($rojos as $nombre => $salida) {
    echo "\n---------- salida de {$nombre} ----------\n";
    foreach (\explode("\n", $salida) as $linea) {
        if (\str_contains($linea, '[FAIL]') || \str_contains($linea, 'Fatal')
            || \str_contains($linea, 'Warning') || \str_contains($linea, '  - ')) {
            echo $linea . "\n";
        }
    }
}

echo "\n=========================================\n";
\printf("  Archivos: %d verde / %d rojo\n", $verdes, \count($rojos));
\printf("  Checks:   %d passed, %d failed\n", $totalPass, $totalFail);
\printf("  Tiempo:   %.1f s\n", $seg);
echo "=========================================\n";

exit($totalFail === 0 ? 0 : 1);
