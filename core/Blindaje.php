<?php
/**
 * AxiDB - Core\Blindaje: que el directorio de datos no se pueda descargar.
 *
 * Una base de datos hecha de archivos tiene un riesgo que las de servidor no
 * tienen: si el directorio de datos queda dentro del docroot, cualquiera pide
 * `https://sitio.com/datos/usuarios/u1.json` y se lleva el documento entero sin
 * pasar por ninguna comprobacion. Ni token, ni permisos, ni nada.
 *
 * Es el error mas facil de cometer y el mas caro. Asi que no se avisa en la
 * documentacion y ya: al crear el directorio se deja dentro la negativa.
 *
 * LIMITE, y hay que decirlo claro: `.htaccess` solo lo entiende Apache, y solo
 * si el servidor tiene AllowOverride activado. **En nginx no hace nada.** La
 * unica proteccion que no depende del servidor es dejar los datos fuera del
 * docroot, y eso es lo que recomienda la guia.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Blindaje
{
    private const HTACCESS = <<<'TXT'
        # AxiDB: aqui viven los datos. No se sirven por HTTP jamas.
        # Apache 2.4
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        # Apache 2.2
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>
        TXT;

    /**
     * Deja la negativa en el directorio. Idempotente y silenciosa: si el disco
     * es de solo lectura o el sistema no admite estos archivos, no se puede
     * hacer nada al respecto y desde luego no se impide abrir la base de datos.
     */
    public static function aplicar(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $htaccess = $dir . '/.htaccess';
        if (!\file_exists($htaccess)) {
            @\file_put_contents($htaccess, self::HTACCESS . "\n");
        }

        // Segundo cierre, para servidores con el listado de directorios abierto:
        // un index vacio es preferible a enseñar la lista de colecciones.
        $indice = $dir . '/index.html';
        if (!\file_exists($indice)) {
            @\file_put_contents($indice, "\n");
        }
    }

    /**
     * True si el directorio esta a la vista desde la web. Lo usa la guia y el
     * puente HTTP para poder avisar en vez de callarse.
     *
     * Compara rutas reales, no cadenas: `strpos($dir, $docroot)` daria falsos
     * positivos con `/var/www/htmlaparte` y no vería un enlace simbolico.
     */
    public static function estaExpuesto(string $dir, ?string $docroot = null): bool
    {
        $docroot ??= (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($docroot === '') {
            return false;
        }
        $real = \realpath($dir);
        $raiz = \realpath($docroot);
        if ($real === false || $raiz === false) {
            return false;
        }
        $real = \str_replace('\\', '/', $real);
        $raiz = \rtrim(\str_replace('\\', '/', $raiz), '/');

        return $real === $raiz || \str_starts_with($real, $raiz . '/');
    }
}
