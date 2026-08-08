<?php
/**
 * AxiDB - Http\Respuesta: lo que sale, siempre con la misma forma.
 *
 *   {"ok": true,  "dato": ...}
 *   {"ok": false, "error": "por que"}
 *
 * Con codigos HTTP de verdad. Un 200 con `{"error": ...}` dentro obliga a cada
 * cliente a mirar el cuerpo para saber si fue bien, y rompe todo lo que hay
 * escrito encima de HTTP: reintentos, caches, monitorizacion, el propio fetch.
 *
 * Se construye y se emite en dos pasos para poder probar el contenido sin
 * levantar un servidor ni tocar cabeceras globales.
 */

declare(strict_types=1);

namespace Axi\Core\Http;

final class Respuesta
{
    /** @param array<string, string> $cabeceras */
    private function __construct(
        public readonly int $codigo,
        public readonly array $cuerpo,
        public readonly array $cabeceras = []
    ) {
    }

    public static function bien(mixed $dato, array $cabeceras = []): self
    {
        return new self(200, ['ok' => true, 'dato' => $dato], $cabeceras);
    }

    public static function mal(int $codigo, string $error, array $cabeceras = []): self
    {
        return new self($codigo, ['ok' => false, 'error' => $error], $cabeceras);
    }

    public function conCabeceras(array $extra): self
    {
        return new self($this->codigo, $this->cuerpo, $this->cabeceras + $extra);
    }

    public function json(): string
    {
        return (string) \json_encode(
            $this->cuerpo,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** Escribe cabeceras y cuerpo. Lo unico de esta clase que toca el mundo. */
    public function emitir(): void
    {
        if (!\headers_sent()) {
            \http_response_code($this->codigo);
            \header('Content-Type: application/json; charset=utf-8');
            // El puente responde datos, nunca paginas: que nadie lo interprete
            // como HTML ni lo guarde en una cache intermedia.
            \header('X-Content-Type-Options: nosniff');
            \header('Cache-Control: no-store');
            foreach ($this->cabeceras as $nombre => $valor) {
                \header($nombre . ': ' . $valor);
            }
        }
        echo $this->json();
    }
}
