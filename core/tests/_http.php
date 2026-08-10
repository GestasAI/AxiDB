<?php
/**
 * AxiDB - utilidades compartidas por los tests del puente HTTP.
 *
 * Casi todo se prueba en proceso, llamando a Server::respond() con un
 * $_SERVER fabricado. No es un atajo: es mas fiable. Un servidor de verdad
 * añade puertos ocupados, arranques lentos y procesos huerfanos —los tres nos
 * han costado ya un dia de trabajo—, y no demuestra nada que esto no demuestre.
 *
 * Lo que si necesita un servidor de verdad es el cliente JavaScript, porque ahi
 * lo que se prueba es precisamente que fetch y el puente se entienden.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Http\Response;
use Axi\Core\Http\Server;

/** Servidor sobre una base de datos recien creada. */
function puente(string $nombre, array $opciones = []): array
{
    $dir = tmpdir($nombre);
    $db  = new Db($dir, ['durable' => false]);
    return [new Server($db, $opciones), $db, $dir];
}

/**
 * Lanza una peticion al puente.
 *
 * @param array|string $cuerpo array que se codifica, o cadena cruda tal cual
 */
function pedir(
    Server $s,
    array|string $cuerpo,
    ?string $token = null,
    ?string $origen = null,
    string $metodo = 'POST',
    string $ip = '127.0.0.1'
): Response {
    $server = ['REQUEST_METHOD' => $metodo, 'REMOTE_ADDR' => $ip];
    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    if ($origen !== null) {
        $server['HTTP_ORIGIN'] = $origen;
    }
    $crudo = \is_string($cuerpo) ? $cuerpo : (string) \json_encode($cuerpo);
    $server['CONTENT_LENGTH'] = (string) \strlen($crudo);

    return $s->respond($server, $crudo);
}

/** Comprueba codigo HTTP y que la respuesta diga ok. */
function respuesta(string $etiqueta, Response $r, int $codigo, ?bool $ok = null): bool
{
    $bien = $r->codigo === $codigo && ($ok === null || ($r->cuerpo['ok'] ?? null) === $ok);
    return ok(
        $etiqueta . ($bien ? '' : "  (HTTP {$r->codigo}: " . \substr($r->json(), 0, 120) . ')'),
        $bien
    );
}

/** El dato devuelto, o null si la respuesta fue un error. */
function dato(Response $r): mixed
{
    return $r->cuerpo['dato'] ?? null;
}
