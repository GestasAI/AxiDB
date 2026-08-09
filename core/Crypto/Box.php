<?php
/**
 * AxiDB - Crypto\Box: cerrar y abrir un texto con AES-256-GCM.
 *
 * Una sola responsabilidad: dado un texto y una clave de 32 bytes, devolver un
 * bloque cerrado, y al reves. No sabe que es un documento ni donde se guarda.
 *
 * GCM y no CBC porque GCM **autentica**: si alguien cambia un byte del archivo,
 * abrir falla en lugar de devolver basura que parece un dato. Una base de datos
 * cifrada que no detecta la manipulacion solo protege de la mitad del problema.
 *
 * El dato asociado (AAD) ata el bloque a su coleccion y a su id. Sin eso, quien
 * pueda escribir en el directorio podria copiar el archivo del usuario admin
 * encima del suyo: el descifrado funcionaria —misma clave— y se quedaria con
 * los datos del otro. Con el AAD, ese bloque solo abre en el sitio donde nacio.
 */

declare(strict_types=1);

namespace Axi\Core\Crypto;

use Axi\Core\Exception;

final class Box
{
    public const CIFRADO = 'aes-256-gcm';

    /** Marca de version al principio del bloque, para poder cambiar de formato. */
    private const MARCA = 'axi1:';

    private const IV_BYTES  = 12;   // el tamaño que recomienda GCM
    private const TAG_BYTES = 16;

    /**
     * @param string $clave 32 bytes crudos
     */
    public function __construct(private string $clave)
    {
        if (\strlen($this->clave) !== 32) {
            throw new Exception('Crypto: la clave debe ser de 32 bytes.');
        }
        self::exigirSoporte();
    }

    /**
     * Comprueba que el sistema puede cifrar, y si no, dice exactamente que
     * falta. Es el unico punto de AxiDB que necesita una extension aparte de
     * json, asi que el aviso tiene que ser inequivoco.
     */
    public static function exigirSoporte(): void
    {
        if (!\extension_loaded('openssl')) {
            throw new Exception(
                'Crypto: hace falta la extension openssl de PHP, que no esta cargada. '
                . 'Es la unica parte de AxiDB que no funciona solo con json. '
                . 'En Debian/Ubuntu: apt install php-openssl; en Windows, quita el ; '
                . 'de extension=openssl en php.ini.'
            );
        }
        if (!\in_array(self::CIFRADO, \openssl_get_cipher_methods(), true)) {
            throw new Exception(
                'Crypto: esta openssl pero no ofrece ' . self::CIFRADO . '. '
                . 'Hace falta una version de OpenSSL con GCM (1.0.1 o posterior).'
            );
        }
    }

    public static function haySoporte(): bool
    {
        return \extension_loaded('openssl')
            && \in_array(self::CIFRADO, \openssl_get_cipher_methods(), true);
    }

    /**
     * Cierra el texto. Cada llamada usa un IV nuevo, asi que cifrar dos veces
     * lo mismo da dos bloques distintos: sin eso se veria desde fuera que dos
     * documentos tienen el mismo contenido.
     */
    public function cerrar(string $texto, string $contexto): string
    {
        $iv  = \random_bytes(self::IV_BYTES);
        $tag = '';

        $cifrado = \openssl_encrypt(
            $texto,
            self::CIFRADO,
            $this->clave,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $contexto,
            self::TAG_BYTES
        );
        if ($cifrado === false) {
            throw new Exception('Crypto: no se pudo cerrar el bloque.');
        }
        return self::MARCA . \base64_encode($iv . $tag . $cifrado);
    }

    /**
     * Abre el bloque. Lanza si la clave no es la correcta, si el contexto no
     * cuadra o si alguien toco los bytes: los tres casos son indistinguibles
     * desde fuera, y asi debe ser.
     */
    public function abrir(string $bloque, string $contexto): string
    {
        if (!\str_starts_with($bloque, self::MARCA)) {
            throw new Exception('Crypto: el bloque no tiene el formato esperado.');
        }
        $crudo = \base64_decode(\substr($bloque, \strlen(self::MARCA)), true);
        if ($crudo === false || \strlen($crudo) < self::IV_BYTES + self::TAG_BYTES) {
            throw new Exception('Crypto: bloque incompleto o mal codificado.');
        }

        $iv       = \substr($crudo, 0, self::IV_BYTES);
        $tag      = \substr($crudo, self::IV_BYTES, self::TAG_BYTES);
        $cifrado  = \substr($crudo, self::IV_BYTES + self::TAG_BYTES);

        $texto = \openssl_decrypt(
            $cifrado,
            self::CIFRADO,
            $this->clave,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $contexto
        );
        if ($texto === false) {
            throw new Exception(
                'Crypto: no se pudo abrir el bloque. La clave no es la correcta, '
                . 'o el dato se modifico fuera de AxiDB.'
            );
        }
        return $texto;
    }

    /** Distingue un bloque cerrado de un texto cualquiera. */
    public static function esBloque(mixed $valor): bool
    {
        return \is_string($valor) && \str_starts_with($valor, self::MARCA);
    }
}
