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
    /**
     * Tope de vueltas. El numero sale de un archivo que el atacante puede
     * escribir y se GASTA antes de comprobar nada: sin tope, un 10^9 convierte
     * abrir la base en un apagon gratis para quien pueda tocar un byte. 210.000
     * es lo recomendado; un millon deja margen para subirlo sin abrir esa puerta.
     */
    private const MAX_ITERACIONES = 1000000;
    private const TESTIGO      = 'axidb-comprobante-de-clave';
    private const CONTEXTO     = 'axidb:llavero:v1';
    private const CONTEXTO_SET = 'axidb:cifradas:v1';

    private ?Box $caja = null;

    /** @var list<string>|null conjunto de colecciones cifradas, en memoria */
    private ?array $setCache = null;

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

    /** Colecciones que el llavero dice cifradas, autenticado con la clave. */
    public function encryptedSet(): array
    {
        if ($this->setCache !== null) {
            return $this->setCache;
        }
        $conf = $this->config();
        if (!isset($conf['colecciones']) || !Box::esBloque($conf['colecciones'])) {
            return $this->setCache = [];
        }
        try {
            $lista = \json_decode($this->box()->open((string) $conf['colecciones'], self::CONTEXTO_SET), true);
        } catch (Exception) {
            return $this->setCache = [];
        }
        return $this->setCache = \is_array($lista) ? \array_values(\array_map('strval', $lista)) : [];
    }

    /**
     * Anota una coleccion como cifrada en el llavero, autenticado con la clave.
     *
     * Es lo que impide que editar `encrypted:false` a mano en el _axidb.json
     * —un archivo de texto sin firmar— apague el cifrado: la verdad vive aqui,
     * sellada, y el atacante no puede reescribir la lista sin la clave.
     */
    public function addEncrypted(string $collection): void
    {
        $lista = $this->encryptedSet();
        if (\in_array($collection, $lista, true)) {
            return;
        }
        $lista[] = $collection;
        // seal() primero: fuerza la creacion del llavero si aun no existe. Leer la
        // config ANTES daria el archivo vacio y write() machacaria el llavero
        // recien creado, dejando solo 'colecciones' y perdiendo sal y comprobante.
        $sellado = $this->box()->seal((string) \json_encode(\array_values($lista)), self::CONTEXTO_SET);
        $conf = $this->config();
        $conf['colecciones'] = $sellado;
        $this->write($conf);
        $this->setCache = \array_values($lista);
    }

    /** El _cifrado.json ya parseado, o vacio si no existe todavia. */
    private function config(): array
    {
        $path = $this->base . '/' . self::ARCHIVO;
        if (!\is_file($path)) {
            return [];
        }
        $conf = \json_decode((string) @\file_get_contents($path), true);
        return \is_array($conf) ? $conf : [];
    }

    private function write(array $conf): void
    {
        $path = $this->base . '/' . self::ARCHIVO;
        $tmp  = $path . '.tmp.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, \json_encode($conf, JSON_PRETTY_PRINT) . "\n") === false
            || !@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new Exception("Crypto: could not write the keyring to {$path}.");
        }
        @\chmod($path, 0600);
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

        // No hay llavero. Crear uno nuevo esta bien la PRIMERA vez, pero si ya
        // hay colecciones cifradas el llavero que las abre desaparecio: fabricar
        // otro con una sal nueva no las abre y el error posterior culparia a la
        // clave. Se para aqui, diciendo la verdad: falta el llavero.
        if ($this->hayColeccionesCifradas()) {
            throw new Exception(
                'Crypto: falta el llavero (_cifrado.json) y hay colecciones cifradas. '
                . 'No se fabrica uno nuevo: sin el llavero original los datos no se abren. '
                . 'The keyring is missing; restore it from a backup.'
            );
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
        // El tope se comprueba ANTES de gastar el trabajo: un numero absurdo se
        // rechaza, no se ejecuta. Comprobarlo despues no sirve —el apagon ya se
        // produjo—. El minimo evita el ataque contrario: debilitar la derivacion.
        if ($sal === '' || $iteraciones < 1000 || $iteraciones > self::MAX_ITERACIONES) {
            throw new Exception('Crypto: the keyring has invalid parameters (iteraciones fuera de rango).');
        }
        return \hash_pbkdf2('sha256', $this->contraseña, $sal, $iteraciones, 32, true);
    }

    /** True si alguna coleccion bajo la base declara estar cifrada. */
    private function hayColeccionesCifradas(): bool
    {
        foreach (\glob($this->base . '/*/_axidb.json') ?: [] as $ajuste) {
            $conf = \json_decode((string) @\file_get_contents($ajuste), true);
            if (\is_array($conf) && ($conf['encrypted'] ?? $conf['cifrado'] ?? false)) {
                return true;
            }
        }
        return false;
    }
}
