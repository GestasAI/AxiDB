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
        $this->exigirLlavero($collection);

        if ($this->ajustes->isEncrypted($collection)) {
            return 0;
        }
        // El mismo motivo que en VectorStore::activar, por el otro lado: cifrar
        // los documentos y dejar el indice vectorial como esta protegeria el
        // texto y publicaria los vectores de los que ese texto se reconstruye.
        if (\is_dir($this->dir($collection) . '/_vec')) {
            throw new Exception(
                "No se puede cifrar '{$collection}': tiene un indice vectorial. "
                . 'De un embedding se puede reconstruir el texto, asi que el indice '
                . 'dejaria en claro lo que el cifrado protege. Borra los vectores primero.'
            );
        }
        $enClaro = $this->driver($collection)->all($collection);
        $this->ajustes->fijar($collection, ['encrypted' => true]);
        $this->cifrados = [];

        $n = 0;
        foreach ($enClaro as $doc) {
            $this->driver($collection)->copiar($collection, (string) $doc['id'], $doc);
            $n++;
        }

        // Y ahora lo que se descubrio probandolo, no pensandolo: en el driver
        // empaquetado reescribir NO borra. El log solo añade, asi que la version
        // en claro del documento se queda fisicamente en el archivo detras de la
        // cifrada. La coleccion diria estar cifrada con el texto original
        // todavia legible unos bytes mas arriba.
        //
        // Compactar reescribe el log dejando solo lo vivo. Sin esta linea, el
        // cifrado sobre packed es teatro.
        $this->compactar($collection);

        return $n;
    }

    public function isEncrypted(string $collection): bool
    {
        return $this->ajustes->isEncrypted($collection);
    }

    public function hayLlavero(): bool
    {
        return $this->llavero !== null;
    }

    /** Monta el llavero al abrir la base. Sin clave, no se monta nada. */
    private function prepararCifrado(string $base, ?string $clave): void
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
    private function envolver(Driver $base, string $collection): Driver
    {
        $driver = $this->envolverSiCifrada($base, $collection);

        $segundos = $this->ajustes->ttl($collection);
        if ($segundos <= 0) {
            return $driver;
        }
        $clave = $collection . '|' . $driver->nombre() . '|' . $segundos;
        return $this->caducados[$clave] ??= new CaducidadDriver($driver, $segundos);
    }

    private function envolverSiCifrada(Driver $base, string $collection): Driver
    {
        if (!$this->ajustes->isEncrypted($collection)) {
            return $base;
        }
        $this->exigirLlavero($collection);

        $clave = $collection . '|' . $base->nombre();
        return $this->cifrados[$clave] ??= new CifradoDriver($base, $this->llavero->caja());
    }

    private function exigirLlavero(string $collection): void
    {
        if ($this->llavero === null) {
            throw new Exception(
                "La coleccion '{$collection}' esta cifrada y no se ha dado la clave. "
                . "Abre la base con: new Db(\$dir, ['key' => '...'])."
            );
        }
    }
}
