<?php
/**
 * AxiDB - endpoint HTTP listo para usar.
 *
 * Apunta aqui el servidor web y tienes la base de datos en el navegador sin
 * escribir una linea de PHP. Toda la configuracion sale de axidb.json, que va
 * junto a la carpeta del nucleo:
 *
 *   {
 *     "data": "../datos",
 *     "http": {
 *       "origenes": ["https://miweb.com"],
 *       "tokens": { "1f3c...": { "colecciones": ["pedidos"], "escribir": true } },
 *       "publicas": ["productos"]
 *     }
 *   }
 *
 * SIN esa seccion "http", este archivo se niega a atender. Y es a proposito.
 *
 * El nucleo se copia dentro de proyectos, y esos proyectos se despliegan
 * enteros. Sin esta negativa, cualquier sitio que llevara AxiDB dentro
 * publicaria un endpoint de base de datos que nadie decidio publicar, en una
 * ruta adivinable, listo para crear directorios. Ha pasado en este mismo
 * repositorio: la carpeta entera acababa dentro de la version desplegada.
 *
 * Que exista el archivo no es publicar una API. Publicarla se dice.
 *
 * Si prefieres el control en tu codigo, no uses este archivo: escribe el tuyo
 * con axidb_http() y pasale las opciones. Son tres lineas y no depende de
 * ningun json.
 */

declare(strict_types=1);

require_once __DIR__ . '/axidb.php';

$configurado = axidb_config()['http'] ?? null;

if (!\is_array($configurado)) {
    \http_response_code(503);
    \header('Content-Type: application/json; charset=utf-8');
    \header('Cache-Control: no-store');
    echo \json_encode([
        'ok'    => false,
        'error' => 'AxiDB: this endpoint is not configured. Declare the section "http" en '
                 . 'axidb.json, o escribe tu propio endpoint con axidb_http().',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

axidb_http();
