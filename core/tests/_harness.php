<?php
/**
 * AxiDB - arnes minimo de test del nucleo.
 *
 * Sin PHPUnit, sin Composer. Cada test es un .php que se ejecuta directo y
 * termina con summary(), que fija el codigo de salida.
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

$GLOBALS['_axi'] = ['pass' => 0, 'fail' => 0, 'fails' => []];

function section(string $title): void
{
    echo "\n[" . $title . "]\n";
}

function ok(string $label, bool $cond): bool
{
    if ($cond) {
        $GLOBALS['_axi']['pass']++;
        echo "  [ok]   {$label}\n";
    } else {
        $GLOBALS['_axi']['fail']++;
        $GLOBALS['_axi']['fails'][] = $label;
        echo "  [FAIL] {$label}\n";
    }
    return $cond;
}

function eq(string $label, mixed $expected, mixed $actual): bool
{
    $cond = $expected === $actual;
    if (!$cond) {
        $e = \is_scalar($expected) || $expected === null ? \var_export($expected, true) : \json_encode($expected);
        $a = \is_scalar($actual)   || $actual === null   ? \var_export($actual, true)   : \json_encode($actual);
        $label .= "  (esperado {$e}, obtenido {$a})";
    }
    return ok($label, $cond);
}

/** Verifica que el callable lanza Axi\Core\Exception. */
function throws(string $label, callable $fn): bool
{
    try {
        $fn();
    } catch (\Axi\Core\Exception) {
        return ok($label, true);
    } catch (\Throwable $e) {
        return ok($label . ' (excepcion inesperada: ' . $e::class . ')', false);
    }
    return ok($label . ' (no lanzo)', false);
}

function summary(): never
{
    $p = $GLOBALS['_axi']['pass'];
    $f = $GLOBALS['_axi']['fail'];
    echo "\n=== Resultado: {$p} passed, {$f} failed ===\n";
    foreach ($GLOBALS['_axi']['fails'] as $label) {
        echo "  - {$label}\n";
    }
    exit($f === 0 ? 0 : 1);
}

/** Directorio temporal limpio para un test. Se borra al empezar. */
function tmpdir(string $name): string
{
    $base = \sys_get_temp_dir() . '/axidb_test_' . $name;
    rmrf($base);
    @\mkdir($base, 0777, true);
    return $base;
}

function rmrf(string $path): void
{
    if (!\is_dir($path)) {
        if (\is_file($path)) {
            @\unlink($path);
        }
        return;
    }
    foreach (\scandir($path) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            rmrf($path . '/' . $e);
        }
    }
    @\rmdir($path);
}

/**
 * Lanza un worker PHP en segundo plano.
 *
 * bypass_shell es obligatorio en Windows: sin el, proc_open envuelve el comando
 * en cmd.exe y proc_terminate mata el cmd, dejando vivo el PHP hijo. Con el, el
 * PID que devuelve proc_get_status es el del propio PHP y se puede matar.
 */
function spawn(string $script, array $args = []): array
{
    $cmd = \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= ' ' . \escapeshellarg((string) $a);
    }
    $pipes = [];
    $proc  = \proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!\is_resource($proc)) {
        throw new \RuntimeException("No se pudo lanzar: {$cmd}");
    }
    $status = \proc_get_status($proc);
    return ['proc' => $proc, 'pipes' => $pipes, 'pid' => $status['pid'] ?? 0];
}

function waitFor(array $h): void
{
    foreach ($h['pipes'] as $p) {
        @\stream_get_contents($p);
        @\fclose($p);
    }
    \proc_close($h['proc']);
}

/**
 * Mata el worker sin contemplaciones. Cierra las tuberias ANTES de proc_close
 * para no bloquearse leyendo de un proceso muerto, y verifica que el PID ya no
 * existe: si sobrevive, remata con la herramienta del sistema.
 */
function killNow(array $h): void
{
    $pid = $h['pid'] ?? 0;
    @\proc_terminate($h['proc'], 9);
    foreach ($h['pipes'] as $p) {
        @\fclose($p);
    }
    for ($i = 0; $i < 20; $i++) {
        $st = @\proc_get_status($h['proc']);
        if (!$st || !$st['running']) {
            break;
        }
        \usleep(25000);
    }
    $st = @\proc_get_status($h['proc']);
    if ($st && $st['running'] && $pid > 0) {
        forceKill($pid);
    }
    @\proc_close($h['proc']);
}

function forceKill(int $pid): void
{
    if (\stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        @\exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
    } else {
        @\exec('kill -9 ' . $pid . ' 2>/dev/null');
    }
}
