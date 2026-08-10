<?php
/**
 * AxiDB - Crypto\Keyring: de una contraseña a la clave de 32 bytes.
 *
 * La contraseña que escribe una persona no sirve como clave: es corta, tiene
 * poca entropia y se adivina probando. PBKDF2 la estira con 210.000 vueltas de
 * SHA-256 —lo que recomienda OWASP en 2023— de forma que probar contraseñas
 * cuesta tiempo de verdad. Es la unica defensa si alguien se lleva la carpeta.
 *
 * La sal se guarda junto a los datos y no es un secreto: su trabajo es que dos
 * bases con la misma contraseña no compartan clave, para que una tabla de
 * claves precalculadas no valga para las dos.
 *
 * El comprobante es un bloque pequeño cifrado con la clave. Sin el, abrir con
 * la contraseña equivocada no fallaria hasta el primer documento, y el mensaje
 * hablaria de un documento corrupto en vez de decir la verdad: la clave no es
 * esa. Un error que apunta al sitio equivocado cuesta una tarde.
 */

declare(strict_types=1);

namespace Axi\Core\Crypto;

use Axi\Core\Exception;

final class Keyring
{
    private const ARCHIVO      = '_cifrado.json';
    private const ITERACIONES  = 210000;
    private const TESTIGO      = 'axidb-comprobante-de-clave';
    private const CONTEXTO     = 'axidb:llavero:v1';

    private ?Box $caja = null;

    public function __construct(
        private string $base,
        private string $contraseña
    ) {
        if ($this->contraseña === '') {
            throw new Exception('Crypto: the password cannot be empty.');
        }
        Box::exigirSoporte();
    }

    /** La caja lista para cerrar y abrir documentos. */
    public function box(): Box
    {
        return $this->caja ??= $this->openOrCreate();
    }

    /** Si esta base ya tiene un llavero creado. */
    public static function hasIndex(string $base): bool
    {
        return \is_file($base . '/' . self::ARCHIVO);
    }

    private function openOrCreate(): Box
    {
        $path = $this->base . '/' . self::ARCHIVO;

        if (\is_file($path)) {
            $conf = \json_decode((string) @\file_get_contents($path), true);
            if (!\is_array($conf) || !isset($conf['sal'], $conf['comprobante'], $conf['iteraciones'])) {
                throw new Exception("Crypto: {$path} is damaged or is not an AxiDB keyring.");
            }
            $sal  = (string) \base64_decode((string) $conf['sal'], true);
            $caja = new Box($this->derive($sal, (int) $conf['iteraciones']));

            // Aqui se cae pronto y con el motivo correcto si la clave no es esa.
            try {
                $visto = $caja->open((string) $conf['comprobante'], self::CONTEXTO);
            } catch (Exception) {
                throw new Exception(
                    'Crypto: the password does not open this database. '
                    . 'The data is intact; the key is a different one.'
                );
            }
            if (!\hash_equals(self::TESTIGO, $visto)) {
                throw new Exception('Crypto: the keyring verifier does not match.');
            }
            return $caja;
        }

        $sal  = \random_bytes(16);
        $caja = new Box($this->derive($sal, self::ITERACIONES));

        $conf = [
            'version'     => 1,
            'kdf'         => 'pbkdf2-sha256',
            'iteraciones' => self::ITERACIONES,
            'sal'         => \base64_encode($sal),
            'comprobante' => $caja->seal(self::TESTIGO, self::CONTEXTO),
        ];
        $tmp = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, \json_encode($conf, JSON_PRETTY_PRINT) . "\n") === false
            || !@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new Exception("Crypto: could not write the keyring to {$path}.");
        }
        @\chmod($path, 0600);
        return $caja;
    }

    private function derive(string $sal, int $iteraciones): string
    {
        if ($sal === '' || $iteraciones < 1000) {
            throw new Exception('Crypto: the keyring has invalid parameters.');
        }
        return \hash_pbkdf2('sha256', $this->contraseña, $sal, $iteraciones, 32, true);
    }
}
