<?php
/**
 * Worker de ataque: escritor normal, sin transacciones.
 *
 * Sirve para dos ataques distintos:
 *
 *   nuevos  da de alta documentos nuevos sin parar. Con esto se ataca todo lo
 *           que hace una foto de la coleccion mientras alguien escribe:
 *           migrar de driver y hacer una copia de seguridad.
 *   mismo   machaca el MISMO documento que otro proceso esta modificando dentro
 *           de una transaccion. La comprobacion de versiones del motor solo
 *           protege entre transacciones; una escritura normal no coge el
 *           cerrojo de confirmacion, asi que es la via para colarse.
 *   dup     intenta una y otra vez dar de alta el MISMO valor unico con ids
 *           distintos. Solo la primera puede entrar. Si entra alguna mas es que
 *           la restriccion se cayo en algun instante.
 *
 *   argv: <dir> <coleccion> <modo> <prefijo> <segundos> [<id>]
 */

declare(strict_types=1);

require_once \dirname(__DIR__) . '/axidb.php';

[, $dir, $coleccion, $modo, $prefijo, $segundos] = $argv;
$id = $argv[6] ?? 'x';

$db = new Axi\Core\Db($dir, ['durable' => false]);

$hasta     = \microtime(true) + (float) $segundos;
$escritos  = 0;
$intentos  = 0;
$aceptados = 0;

while (\microtime(true) < $hasta) {
    if ($modo === 'nuevos') {
        $db->put($coleccion, $prefijo . '-' . $escritos, ['n' => $escritos, 'quien' => $prefijo]);
        $escritos++;
    } elseif ($modo === 'dup') {
        $intentos++;
        try {
            $db->insert($coleccion, ['correo' => 'peleado@ejemplo.com'], $prefijo . $intentos);
            $aceptados++;
        } catch (\Throwable) {
            // Lo normal: el valor ya es de otro documento.
        }
    } else {
        $db->put($coleccion, $id, ['w' => $escritos]);
        $escritos++;
    }
    \usleep(300);
}

\file_put_contents(
    $dir . '/_sec_plano_' . $prefijo . '.json',
    \json_encode(['escritos' => $escritos, 'intentos' => $intentos, 'aceptados' => $aceptados])
);
exit(0);
