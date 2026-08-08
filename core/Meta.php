<?php
/**
 * AxiDB - Core\Meta: los metadatos del documento y como se serializa.
 *
 * Vive aparte porque lo comparten los dos drivers. Si cada uno llevara su copia
 * de "sube _version, conserva _createdAt, codifica asi", acabarian divergiendo
 * y un documento significaria una cosa en fs y otra en packed. Aqui la regla es
 * una sola y test_drivers_paridad.php lo comprueba.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Meta
{
    /**
     * Aplica los metadatos a un documento que se va a guardar.
     *
     *   id          el que se pidio, siempre
     *   _version    sube uno en cada escritura
     *   _createdAt  la del alta, no se toca nunca mas
     *   _updatedAt  la de ahora
     *
     * @param array|null $existente el documento que ya habia, o null si es alta
     */
    public static function aplicar(array $data, string $id, ?array $existente, bool $replace): array
    {
        if (\is_array($existente) && !$replace) {
            $data = \array_merge($existente, $data);
        }
        $data['id']         = $id;
        $data['_updatedAt'] = \date('c');
        $data['_version']   = (int) ($existente['_version'] ?? 0) + 1;
        if (empty($data['_createdAt'])) {
            $data['_createdAt'] = $existente['_createdAt'] ?? $data['_updatedAt'];
        }
        return $data;
    }

    /**
     * JSON con sangria, unicode sin escapar y decimales con su parte
     * fraccionaria. Las tres cosas importan:
     *
     *  - la sangria y el unicode hacen que el dato se lea a ojo y que un diff de
     *    git sea util;
     *  - PRESERVE_ZERO_FRACTION es obligatorio: sin el, un float como 18.0 se
     *    guarda como 18 y vuelve del disco convertido en entero.
     */
    public static function codificar(array $data): string
    {
        $json = \json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new Exception('No se pudo serializar el documento: ' . \json_last_error_msg());
        }
        return $json;
    }

    /**
     * Igual que codificar() pero en una sola linea, para el log del driver
     * empaquetado, donde cada documento ocupa exactamente un renglon.
     */
    public static function codificarPlano(array $data): string
    {
        $json = \json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new Exception('No se pudo serializar el documento: ' . \json_last_error_msg());
        }
        return $json;
    }

    /** Devuelve el documento o null si el texto no es un objeto JSON valido. */
    public static function decodificar(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $doc = \json_decode($raw, true);
        return \is_array($doc) ? $doc : null;
    }
}
