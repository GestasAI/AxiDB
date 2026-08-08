<?php
/**
 * Enrutador para el servidor de desarrollo de PHP.
 *
 *   php -S localhost:8000 servidor.php
 *
 * Hace dos cosas, y las dos hay que entenderlas porque en produccion se
 * resuelven igual, solo que en la configuracion del servidor web:
 *
 * 1. NIEGA /datos/. Sin esto, `http://localhost:8000/datos/clientes/xxx.json`
 *    devuelve el documento entero: la base de datos completa descargable,
 *    saltandose el puente, los tokens y todo lo demas. El `.htaccess` que AxiDB
 *    deja dentro solo lo entiende Apache; el servidor de desarrollo y nginx lo
 *    ignoran. Comprobado, no supuesto.
 *
 *    En nginx la misma regla es:  location ~ /datos/ { deny all; }
 *    Y lo mejor de todo, en cualquier servidor: sacar datos/ del directorio
 *    publicado.
 *
 * 2. Sirve /axi.js desde el nucleo, que esta fuera de esta carpeta. En un sitio
 *    de verdad el nucleo se copia dentro del directorio publicado y esto no
 *    hace falta; aqui evita tener una copia del cliente por cada ejemplo.
 */

declare(strict_types=1);

$ruta = (string) \parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (\str_starts_with($ruta, '/datos')) {
    \http_response_code(404);
    \header('Content-Type: text/plain; charset=utf-8');
    echo "Los datos no se sirven por HTTP. Para eso esta api.php.\n";
    return true;
}

if ($ruta === '/axi.js') {
    \header('Content-Type: text/javascript; charset=utf-8');
    \readfile(__DIR__ . '/../../core/axi.js');
    return true;
}

return false;   // lo demas, que lo sirva el servidor como un archivo normal
