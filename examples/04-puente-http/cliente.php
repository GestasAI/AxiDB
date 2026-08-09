<?php
/**
 * AxiDB - hablar con la base de datos desde otro proceso, por HTTP.
 *
 * Este archivo NO usa AxiDB: no hace `require` del nucleo ni sabe donde estan
 * los datos. Solo manda JSON por el cable. Podria estar escrito en cualquier
 * lenguaje, o correr en otra maquina; se deja en PHP para no pedir nada mas
 * instalado.
 *
 * Con el servidor levantado en otra terminal:
 *
 *   php -S localhost:8000 -t examples/04-puente-http examples/04-puente-http/servidor.php
 *   php examples/04-puente-http/cliente.php
 */

declare(strict_types=1);

$puente = \getenv('AXI_URL') ?: 'http://localhost:8000/api.php';

/**
 * Una peticion al puente. Todo el "cliente" es esta funcion.
 *
 * @param array<string,mixed> $peticion
 * @return array<string,mixed>
 */
function pedir(string $puente, array $peticion): array
{
    $ctx = \stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => (string) \json_encode($peticion),
        'ignore_errors' => true,
        'timeout'       => 5,
    ]]);
    $cuerpo = @\file_get_contents($puente, false, $ctx);
    if ($cuerpo === false) {
        \fwrite(\STDERR, "No hay nadie escuchando en {$puente}.\n"
            . "Levanta el servidor primero:\n"
            . "  php -S localhost:8000 -t examples/04-puente-http examples/04-puente-http/servidor.php\n");
        exit(1);
    }
    return (array) \json_decode((string) $cuerpo, true);
}

echo "=== La base de datos, desde el otro lado del cable ===\n";
echo "Puente: {$puente}\n\n";

/* ─── Limpiar lo de la ejecucion anterior ───────────────────────────────── */

foreach (pedir($puente, ['accion' => 'find', 'coleccion' => 'sensores'])['dato'] ?? [] as $doc) {
    pedir($puente, ['accion' => 'delete', 'coleccion' => 'sensores', 'id' => $doc['id']]);
}

/* ─── Altas ─────────────────────────────────────────────────────────────── */

$lecturas = [
    ['sensor' => 'nave-1', 'grados' => 21.4, 'humedad' => 48],
    ['sensor' => 'nave-2', 'grados' => 24.9, 'humedad' => 41],
    ['sensor' => 'camara', 'grados' => 3.2,  'humedad' => 76],
    ['sensor' => 'nave-1', 'grados' => 22.1, 'humedad' => 47],
];
foreach ($lecturas as $l) {
    $r = pedir($puente, ['accion' => 'insert', 'coleccion' => 'sensores', 'datos' => $l]);
    if (!($r['ok'] ?? false)) {
        \fwrite(\STDERR, 'El puente rechazo el alta: ' . ($r['error'] ?? '?') . "\n");
        exit(1);
    }
}
echo 'Lecturas enviadas: ' . \count($lecturas) . "\n";

/* ─── Consultas ─────────────────────────────────────────────────────────── */

$todas = pedir($puente, ['accion' => 'find', 'coleccion' => 'sensores'])['dato'];
echo 'Guardadas al otro lado: ' . \count($todas) . "\n\n";

echo "-- Todas las lecturas --\n";
foreach ($todas as $d) {
    \printf("   %-8s %5.1f C  %2d%% humedad\n", $d['sensor'], $d['grados'], $d['humedad']);
}

echo "\n-- Por encima de 22 grados, consultado por SQL a traves del puente --\n";
$calientes = pedir($puente, [
    'accion' => 'sql',
    'sentencia' => 'SELECT sensor, grados FROM sensores WHERE grados > 22 ORDER BY grados DESC',
])['dato'];
foreach ($calientes as $f) {
    \printf("   %-8s %5.1f C\n", $f['sensor'], $f['grados']);
}

echo "\n-- Media por sensor --\n";
$medias = pedir($puente, [
    'accion' => 'sql',
    'sentencia' => 'SELECT sensor, REDONDEA(AVG(grados), 1) AS media, COUNT(*) AS lecturas
                 FROM sensores GROUP BY sensor ORDER BY media DESC',
])['dato'];
foreach ($medias as $f) {
    \printf("   %-8s %5.1f C de media en %d lecturas\n", $f['sensor'], $f['media'], $f['lecturas']);
}

/* ─── Lo que el puente NO deja hacer ────────────────────────────────────── */

echo "\n-- Y lo que no se puede hacer desde fuera --\n";
$intento = pedir($puente, ['accion' => 'dropCollection', 'coleccion' => 'sensores']);
\printf("   borrar la coleccion entera: %s\n",
    ($intento['ok'] ?? false) ? 'PERMITIDO (no deberia)' : 'rechazado (' . ($intento['error'] ?? '?') . ')');

echo "\nNi una linea de este archivo sabe donde estan los datos.\n";
