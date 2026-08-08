<?php
/**
 * Cristaleria Los Arcos - el unico PHP de toda la aplicacion.
 *
 * Tres lineas utiles. No hay controladores, ni rutas, ni modelos, ni un ORM:
 * el navegador habla con la base de datos y la base de datos se defiende sola.
 *
 * En local no hace falta token —el puente solo atiende desde esta maquina—.
 * Para publicarlo hay que declarar tokens; lo explica el README de al lado.
 */

declare(strict_types=1);

require __DIR__ . '/../../core/axidb.php';

axidb_http(__DIR__ . '/datos', [
    'origenes' => ['http://localhost:8000', 'http://127.0.0.1:8000'],
]);
