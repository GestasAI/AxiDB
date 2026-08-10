<?php
/**
 * AxiDB - Almacen\WithEncryption: la parte cifrada de Storage.
 *
 * Mismo motivo que los traits de Db: Storage ya rozaba las 250 lineas con los
 * documentos, las colecciones y los drivers dentro. El cifrado es un cuarto
 * asunto y se saca aqui en vez de engordar el archivo.
 *
 * No cambia nada de puertas afuera: se sigue escribiendo `$storage->encrypt(...)`.
 */

declare(strict_types=1);

namespace Axi\Core\Storage;

use Axi\Core\Crypto\Box;
use Axi\Core\Crypto\Keyring;
use Axi\Core\Drivers\CaducidadDriver;
use Axi\Core\Drivers\CifradoDriver;
use Axi\Core\Drivers\Driver;
use Axi\Core\Exception;

trait WithEncryption
{
    private ?Keyring $llavero = null;

    /** @var array<string, Driver> decoradores ya montados, uno por coleccion */
    private array $cifrados = [];

    /** @var array<string, Driver> lo mismo para la caducidad */
    private array $caducados = [];

    /**
     * Activa el cifrado en una coleccion.
     *
     * Los documentos que ya hubiera se reescriben cerrados. Si se dejaran como
     * estaban, la coleccion diria estar cifrada y el contenido antiguo seguiria
     * legible en el disco: la peor de las situaciones, porque nadie lo sabria.
     *
     * @return int documentos reescritos
     */
    public function encrypt(string $collection): int
    {
        self::name($collection, 'coleccion');
        $this->requireKeyring($collection);

        if ($this->encriptada($collection)) {
            return 0;
        }
        // El mismo motivo que en VectorStore::activar, por el otro lado: cifrar
        // los documentos y dejar el indice vectorial como esta protegeria el
        // texto y publicaria los vectores de los que ese texto se reconstruye.
        if (\is_dir($this->dir($collection) . '/_vec')) {
            throw new Exception(
                "Cannot encrypt '{$collection}': tiene un indice vectorial. "
                . 'The text can be reconstructed from an embedding, so the index '
                . 'dejaria en claro lo que el cifrado protege. Borra los vectores primero.'
            );
        }
        $enClaro = $this->driver($collection)->all($collection);
        // El llavero se anota ANTES de marcar la bandera en el _axidb.json. Al
        // reves, la primera vez que se cifra en una base el llavero aun no existe
        // y crearlo veria la coleccion ya marcada como cifrada, confundiendolo con
        // un llavero perdido. La verdad autenticada va primero; la bandera despues.
        $this->llavero->addEncrypted($collection);
        $this->ajustes->set($collection, ['encrypted' => true]);
        $this->cifrados = [];

        // Un esquema definido ANTES de cifrar tiene sus valores por defecto en
        // claro en el _axidb.json. Ahora que la coleccion esta cifrada, se cierran.
        $esquema = $this->ajustes->schema($collection);
        if ($esquema !== []) {
            $this->ajustes->set($collection, ['schema' => $this->hideSchemaDefaults($collection, $esquema)]);
        }

        $n = 0;
        foreach ($enClaro as $doc) {
            $this->driver($collection)->copyDocument($collection, (string) $doc['id'], $doc);
            $n++;
        }

        // En packed, reescribir NO borra: el log solo añade, asi que la version
        // en claro se quedaria fisicamente detras de la cifrada, todavia legible.
        // Compactar deja solo lo vivo. Sin esto, el cifrado sobre packed es teatro.
        $this->compact($collection);

        return $n;
    }

    public function isEncrypted(string $collection): bool
    {
        return $this->encriptada($collection);
    }

    /**
     * Nombre del archivo de cubo para un valor indexado. En una coleccion
     * cifrada es una huella CON CLAVE (HMAC): sin la clave nadie puede calcular
     * el nombre, asi que el arbol de directorios deja de revelar que documentos
     * valen 'moroso'. Sin cifrar devuelve null y el indice usa el nombre legible.
     */
    public function indexBucket(string $collection, string $value): ?string
    {
        if ($this->llavero === null || !$this->encriptada($collection)) {
            return null;
        }
        return 'h_' . $this->llavero->box()->tag($value);
    }

    /**
     * Sella los valores por defecto del esquema antes de guardarlos: viven en
     * _axidb.json, al lado de la coleccion cifrada, y un defecto puede ser un
     * secreto (un token, un pin) que quedaria en claro. reveal() lo abre al leer.
     */
    private function hideSchemaDefaults(string $collection, array $reglas): array
    {
        if ($this->llavero === null || !$this->encriptada($collection)) {
            return $reglas;
        }
        $caja = $this->llavero->box();
        foreach ($reglas as $campo => $regla) {
            if (\is_array($regla) && \array_key_exists('defecto', $regla)
                && $regla['defecto'] !== null && !Box::esBloque($regla['defecto'])) {
                $reglas[$campo]['defecto'] = $caja->seal(
                    (string) \json_encode($regla['defecto']),
                    self::schemaCtx($collection, (string) $campo)
                );
            }
        }
        return $reglas;
    }

    /** Abre los valores por defecto sellados para poder aplicarlos. */
    private function revealSchemaDefaults(string $collection, array $reglas): array
    {
        if ($this->llavero === null || !$this->encriptada($collection)) {
            return $reglas;
        }
        $caja = $this->llavero->box();
        foreach ($reglas as $campo => $regla) {
            if (\is_array($regla) && isset($regla['defecto']) && Box::esBloque($regla['defecto'])) {
                $reglas[$campo]['defecto'] = \json_decode(
                    $caja->open((string) $regla['defecto'], self::schemaCtx($collection, (string) $campo)),
                    true
                );
            }
        }
        return $reglas;
    }

    private static function schemaCtx(string $collection, string $field): string
    {
        return 'axidb:schema:v1:' . $collection . "\0" . $field;
    }

    /**
     * Cifrada de verdad: lo dice el ajuste O el llavero autenticado. El ajuste
     * vive en un archivo de texto que el atacante puede editar; el llavero, no,
     * porque su lista va sellada con la clave. Apagar el cifrado desde fuera
     * tendria que enganyar a los dos, y al segundo no se puede sin la clave.
     */
    private function encriptada(string $collection): bool
    {
        if ($this->ajustes->isEncrypted($collection)) {
            return true;
        }
        return $this->llavero !== null
            && \in_array($collection, $this->llavero->encryptedSet(), true);
    }

    public function hasKeyring(): bool
    {
        return $this->llavero !== null;
    }

    /** Monta el llavero al abrir la base. Sin clave, no se monta nada. */
    private function prepareEncryption(string $base, ?string $clave): void
    {
        if ($clave !== null && $clave !== '') {
            $this->llavero = new Keyring($base, $clave);
        }
    }

    /**
     * Pone los decoradores que pida la coleccion, en este orden:
     *
     *   driver base  ->  cifrado  ->  caducidad
     *
     * La caducidad va fuera porque solo mira `_updatedAt`, que queda en claro
     * incluso cifrando: asi no necesita la clave para saber si algo vencio.
     */
    private function wrap(Driver $base, string $collection): Driver
    {
        $driver = $this->wrapIfEncrypted($base, $collection);

        $segundos = $this->ajustes->ttl($collection);
        if ($segundos <= 0) {
            return $driver;
        }
        $clave = $collection . '|' . $driver->driverName() . '|' . $segundos;
        return $this->caducados[$clave] ??= new CaducidadDriver($driver, $segundos);
    }

    /**
     * El driver descifrado pero SIN la capa de caducidad. Para el mantenimiento
     * de indices y unicidad, que trabajan sobre lo que hay en disco de verdad,
     * incluidos los documentos ya vencidos que aun no se han barrido.
     */
    private function driverSinCaducidad(string $collection): Driver
    {
        return $this->wrapIfEncrypted(
            $this->driverByName($this->driverDe($collection)),
            $collection
        );
    }

    /**
     * El documento tal cual esta en el disco, saltando el filtro de caducidad
     * (pero descifrando si hace falta). Lo usa el mantenimiento de indices y
     * unicidad: un vencido conserva su rastro en disco y hay que verlo para
     * barrerlo, aunque get() lo esconda. Ver Db y WithExpiry.
     */
    public function rawGet(string $collection, string $id): ?array
    {
        return $this->driverSinCaducidad($collection)->get($collection, $id);
    }

    private function wrapIfEncrypted(Driver $base, string $collection): Driver
    {
        if (!$this->encriptada($collection)) {
            return $base;
        }
        $this->requireKeyring($collection);

        $clave = $collection . '|' . $base->driverName();
        return $this->cifrados[$clave] ??= new CifradoDriver($base, $this->llavero->box());
    }

    private function requireKeyring(string $collection): void
    {
        if ($this->llavero === null) {
            throw new Exception(
                "La coleccion '{$collection}' is encrypted and no key was given. "
                . "Open the database with: new Db(\$dir, ['key' => '...'])."
            );
        }
    }
}
