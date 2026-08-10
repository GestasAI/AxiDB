<?php
/**
 * AxiDB - runner de la suite de seguridad.
 *
 *   php axidb/core/tests/run_sec.php
 *
 * Ejecuta solo los test_sec_*: los ataques que escribieron los auditores. Cada
 * comprobacion afirma el comportamiento SEGURO, asi que un rojo es un ataque que
 * todavia funciona. Mide el progreso del endurecimiento
 * (claude/planes/axidb-seguridad.md): cuantos ataques se rebotan ya y cuantos
 * quedan.
 *
 * Se mantiene aparte del gate de regresion (run.php) a proposito, mientras la
 * hoja este abierta. Cuando este runner quede entero en verde, sus tests se
 * funden con run.php como red permanente de no-regresion de seguridad.
 */

declare(strict_types=1);

$tests = \glob(__DIR__ . '/test_sec_*.php') ?: [];
\sort($tests);

echo "=== AxiDB core — suite de seguridad (ataques) ===\n";
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
        $fail = 1;
        $salida .= "\n  (el test no emitio resumen)\n";
    }

    $totalPass += $pass;
    $totalFail += $fail;

    if ($fail === 0) {
        $verdes++;
        \printf("  [ok]   %-26s %4d ataques rebotados  %6.0f ms\n", $nombre, $pass, $ms);
    } else {
        $rojos[$nombre] = $fail;
        \printf("  [ROJO] %-26s %4d ok / %d sin cerrar  %6.0f ms\n", $nombre, $pass, $fail, $ms);
    }
}

$seg = \microtime(true) - $inicio;

echo "\n=========================================\n";
\printf("  Archivos:  %d verde / %d con ataques sin cerrar\n", $verdes, \count($rojos));
\printf("  Ataques:   %d rebotados, %d sin cerrar\n", $totalPass, $totalFail);
\printf("  Tiempo:    %.1f s\n", $seg);
echo "=========================================\n";

exit($totalFail === 0 ? 0 : 1);
