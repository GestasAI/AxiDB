<?php
/**
 * AxiDB - CifradoDriver: envuelve a otro driver y cifra lo que se guarda.
 *
 * Es un decorador a proposito. Ni FsDriver ni PackedDriver saben que existe el
 * cifrado, y siguen sin saberlo: se cifra por encima de ellos, asi que cifrar
 * funciona igual con los dos y con cualquiera que venga despues.
 *
 * Que queda en claro y por que:
 *
 *   id, _version, _createdAt, _updatedAt
 *
 * El motor los necesita para localizar, versionar y barrer sin abrir el
 * contenido, igual que un disco cifrado deja ver los nombres de los archivos.
 * Si tus fechas de alta o tus ids son en si mismos el secreto, el cifrado por
 * coleccion no te vale y hay que cifrar el volumen entero. Dicho aqui para que
 * nadie lo descubra tarde.
 */

declare(strict_types=1);

namespace Axi\Core\Drivers;

use Axi\Core\Crypto\Box;
use Axi\Core\Exception;

final class CifradoDriver implements Driver
{
    /** Lo unico que no se cifra. Todo lo demas del documento, si. */
    private const EN_CLARO = ['id', '_version', '_createdAt', '_updatedAt'];

    /** Donde viaja el bloque cerrado dentro del documento guardado. */
    private const CAMPO = '_cif';

    /** Campo de version en claro que pone el motor. */
    private const VERSION = '_version';

    /** Version atada DENTRO del bloque, para detectar el injerto de uno viejo. */
    private const VCAMPO = '_v';

    public function __construct(
        private Driver $dentro,
        private Box $caja
    ) {
    }

    public function driverName(): string
    {
        return $this->dentro->driverName() . '+cifrado';
    }

    /**
     * La fusion se hace AQUI, y no puede hacerse abajo.
     *
     * Sin cifrar, una actualizacion parcial la resuelve Meta fusionando el
     * documento viejo con el nuevo. Con el contenido cerrado en un solo bloque,
     * esa fusion mezclaria dos textos cifrados y el resultado no seria ninguno
     * de los dos: la mitad de los campos desapareceria en silencio.
     *
     * Asi que se abre lo que habia, se fusiona en claro, se vuelve a cerrar
     * entero, y abajo se guarda siempre como reemplazo.
     */
    public function put(string $collection, string $id, array $data, bool $replace = false): array
    {
        $carga = self::payload($data);

        $anterior = !$replace ? $this->get($collection, $id) : null;
        if ($anterior !== null) {
            $carga = \array_merge(self::payload($anterior), $carga);
        }

        // La version que le va a tocar a este documento se sella DENTRO del
        // bloque. El motor sube la version de uno en uno; el driver de abajo la
        // pondra en claro. Sin esto, injertar el bloque de la version 1 bajo un
        // _version=2 en claro colaria el saldo viejo con pinta de actual: el
        // bloque abre —misma clave, mismo id— y nada delata que es de antes.
        $prevV = $anterior !== null
            ? (int) ($anterior[self::VERSION] ?? 0)
            : (int) (($this->dentro->get($collection, $id)[self::VERSION] ?? 0));
        $carga[self::VCAMPO] = $prevV + 1;

        $guardado = $this->dentro->put(
            $collection,
            $id,
            [self::CAMPO => $this->seal($collection, $id, $carga)],
            true
        );
        unset($carga[self::VCAMPO]);
        return self::meta($guardado) + $carga;
    }

    public function copyDocument(string $collection, string $id, array $doc): void
    {
        $carga = self::payload($doc);
        $carga[self::VCAMPO] = (int) ($doc[self::VERSION] ?? 1);
        $this->dentro->copyDocument(
            $collection,
            $id,
            self::meta($doc) + [self::CAMPO => $this->seal($collection, $id, $carga)]
        );
    }

    public function get(string $collection, string $id): ?array
    {
        $doc = $this->dentro->get($collection, $id);
        // El id que ata el bloque es el que se PIDE, no el que viene dentro del
        // archivo. Si fueran el de dentro, copiar el archivo del jefe encima del
        // del intruso abriria: el archivo trae consigo el id 'jefe' y con el
        // cuadraria el AAD. Pidiendo 'intruso' y atando a 'intruso', el bloque
        // sellado para 'jefe' no abre y la copia se rechaza.
        return $doc === null ? null : $this->open($collection, $id, $doc);
    }

    public function all(string $collection): array
    {
        $fuera = [];
        foreach ($this->dentro->all($collection) as $clave => $doc) {
            // El driver base ya descarta los documentos cuyo archivo no cuadra
            // con su id, asi que aqui el id de dentro es de fiar como sitio. Un
            // bloque manipulado o ausente hace saltar ESE documento, no cae el
            // listado entero: un archivo tocado no ciega a los demas.
            try {
                $abierto = $this->open($collection, (string) ($doc['id'] ?? ''), $doc);
                if ($abierto !== null) {
                    $fuera[$clave] = $abierto;
                }
            } catch (Exception) {
                continue;
            }
        }
        return $fuera;
    }

    /* Contar y barrer no miran dentro del documento: pasan tal cual. */

    public function count(string $collection): int
    {
        return $this->dentro->count($collection);
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->dentro->delete($collection, $id);
    }

    public function sweep(string $collection, int $minAgeSeconds = 300): int
    {
        return $this->dentro->sweep($collection, $minAgeSeconds);
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    private function seal(string $collection, string $id, array $carga): string
    {
        $json = \json_encode($carga, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new Exception('Crypto: could not serialise the document: ' . \json_last_error_msg());
        }
        return $this->caja->seal($json, self::contextOf($collection, $id));
    }

    /**
     * Abre el bloque atado al id que se pide. Un documento SIN bloque cerrado en
     * una coleccion cifrada se RECHAZA, no se devuelve en claro: si se devolviera,
     * el ataque mas barato contra el cifrado seria quitarlo —escribir el documento
     * en claro sin la clave y dejar que el motor lo sirva como bueno—. Cuando este
     * driver esta puesto, la coleccion esta cifrada; no hay documento legitimo sin
     * bloque, porque encrypt() reescribe todo lo que hubiera antes de terminar.
     */
    private function open(string $collection, string $id, array $doc): ?array
    {
        if (!isset($doc[self::CAMPO]) || !Box::esBloque($doc[self::CAMPO])) {
            throw new Exception(
                "Crypto: '{$collection}/{$id}' no trae bloque cerrado en una coleccion cifrada. "
                . 'Se rechaza en vez de servir contenido en claro (posible degradacion).'
            );
        }
        $json  = $this->caja->open((string) $doc[self::CAMPO], self::contextOf($collection, $id));
        $carga = \json_decode($json, true);
        if (!\is_array($carga)) {
            throw new Exception("Crypto: the contents of '{$collection}/{$id}' no es un documento valido.");
        }
        // La version sellada tiene que cuadrar con la que el motor puso en claro.
        // Si no, el bloque es de otra version —injertado bajo un _version mas
        // alto— y el documento no se sirve. Se devuelve null (no se lanza): el
        // listado sigue, y una lectura directa lo ve como ausente, no como bueno.
        $vSellada = $carga[self::VCAMPO] ?? null;
        unset($carga[self::VCAMPO]);
        if ($vSellada !== null && (int) $vSellada !== (int) ($doc[self::VERSION] ?? 0)) {
            return null;
        }
        // El id que vale es el pedido, no el de dentro del archivo: asi el
        // documento nunca se sirve bajo un id distinto del que ocupa en disco.
        return ['id' => $id] + self::meta($doc) + $carga;
    }

    /** Ata el bloque a su sitio: no abre en otra coleccion ni con otro id. */
    private static function contextOf(string $collection, string $id): string
    {
        return 'axidb:doc:v1:' . $collection . "\0" . $id;
    }

    /** @return array<string,mixed> lo que se guarda en claro */
    private static function meta(array $doc): array
    {
        return \array_intersect_key($doc, \array_flip(self::EN_CLARO));
    }

    /** @return array<string,mixed> lo que se cifra */
    private static function payload(array $doc): array
    {
        return \array_diff_key($doc, \array_flip([...self::EN_CLARO, self::CAMPO]));
    }
}
