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
 * Los tests de ataque (test_sec_*) entran en el gate como red de no-regresion.
 * Estuvieron fuera mientras la hoja de endurecimiento seguia abierta —cada uno
 * era un ataque que todavia funcionaba y habrian dejado el gate en rojo—; ahora
 * que run_sec.php esta entero en verde, cada ataque cerrado se queda aqui para que
 * ningun cambio futuro lo reabra sin que salte el gate. run_sec.php sigue como
 * vista dedicada de la superficie de ataque.
 */
$tests = \array_values(\glob(__DIR__ . '/test_*.php') ?: []);
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
