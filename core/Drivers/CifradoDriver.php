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

    public function __construct(
        private Driver $dentro,
        private Box $caja
    ) {
    }

    public function nombre(): string
    {
        return $this->dentro->nombre() . '+cifrado';
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
        $carga = self::carga($data);

        if (!$replace) {
            $anterior = $this->get($collection, $id);
            if ($anterior !== null) {
                $carga = \array_merge(self::carga($anterior), $carga);
            }
        }

        $guardado = $this->dentro->put(
            $collection,
            $id,
            [self::CAMPO => $this->cerrar($collection, $id, $carga)],
            true
        );
        return self::meta($guardado) + $carga;
    }

    public function copiar(string $collection, string $id, array $doc): void
    {
        $this->dentro->copiar(
            $collection,
            $id,
            self::meta($doc) + [self::CAMPO => $this->cerrar($collection, $id, self::carga($doc))]
        );
    }

    public function get(string $collection, string $id): ?array
    {
        $doc = $this->dentro->get($collection, $id);
        return $doc === null ? null : $this->abrir($collection, $doc);
    }

    public function all(string $collection): array
    {
        $fuera = [];
        foreach ($this->dentro->all($collection) as $clave => $doc) {
            $fuera[$clave] = $this->abrir($collection, $doc);
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

    private function cerrar(string $collection, string $id, array $carga): string
    {
        $json = \json_encode($carga, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new Exception('Crypto: no se pudo serializar el documento: ' . \json_last_error_msg());
        }
        return $this->caja->cerrar($json, self::contexto($collection, $id));
    }

    /**
     * Un documento sin bloque cerrado se devuelve tal cual. Pasa cuando se
     * activa el cifrado sobre una coleccion que ya tenia datos: los viejos
     * siguen leyendose en claro hasta que se reescriben, en vez de reventar.
     */
    private function abrir(string $collection, array $doc): array
    {
        if (!isset($doc[self::CAMPO]) || !Box::esBloque($doc[self::CAMPO])) {
            return $doc;
        }
        $id    = (string) ($doc['id'] ?? '');
        $json  = $this->caja->abrir((string) $doc[self::CAMPO], self::contexto($collection, $id));
        $carga = \json_decode($json, true);
        if (!\is_array($carga)) {
            throw new Exception("Crypto: el contenido de '{$collection}/{$id}' no es un documento valido.");
        }
        return self::meta($doc) + $carga;
    }

    /** Ata el bloque a su sitio: no abre en otra coleccion ni con otro id. */
    private static function contexto(string $collection, string $id): string
    {
        return 'axidb:doc:v1:' . $collection . "\0" . $id;
    }

    /** @return array<string,mixed> lo que se guarda en claro */
    private static function meta(array $doc): array
    {
        return \array_intersect_key($doc, \array_flip(self::EN_CLARO));
    }

    /** @return array<string,mixed> lo que se cifra */
    private static function carga(array $doc): array
    {
        return \array_diff_key($doc, \array_flip([...self::EN_CLARO, self::CAMPO]));
    }
}
