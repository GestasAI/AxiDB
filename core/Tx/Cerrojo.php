<?php
/**
 * AxiDB - Tx\Cerrojo: un confirmador cada vez.
 *
 * Sin esto, dos transacciones podrian comprobar sus versiones a la vez, las dos
 * encontrarlas intactas, y entrelazar sus escrituras despues. La comprobacion
 * de versiones solo sirve si nadie puede colarse entre comprobar y escribir.
 *
 * Es un cerrojo de toda la base, no por coleccion: una transaccion abarca
 * varias colecciones, asi que un cerrojo por coleccion no la cubre y coger
 * varios en orden distinto es un abrazo mortal esperando a pasar.
 *
 * Solo frena a quien confirma. Leer no coge el cerrojo y por tanto no espera.
 */

declare(strict_types=1);

namespace Axi\Core\Tx;

use Axi\Core\Exception;

final class Cerrojo
{
    private const ARCHIVO = '_tx.lock';

    /**
     * Ejecuta la tarea con el cerrojo cogido, y lo suelta pase lo que pase.
     */
    public static function con(string $base, callable $tarea): mixed
    {
        $path = $base . '/' . self::ARCHIVO;
        $fp   = @\fopen($path, 'c');
        if (!$fp) {
            throw new Exception("Tx: no se pudo abrir el cerrojo en {$path}.");
        }
        try {
            if (!\flock($fp, LOCK_EX)) {
                throw new Exception("Tx: no se pudo coger el cerrojo en {$path}.");
            }
            return $tarea();
        } finally {
            \flock($fp, LOCK_UN);
            \fclose($fp);
        }
    }
}
