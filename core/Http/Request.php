<?php
/**
 * AxiDB - Http\Request: lo que llega, ya comprobado.
 *
 * Nada de lo que sale de aqui es de fiar por venir de fuera; lo que se garantiza
 * es que tiene la forma correcta. Quien valida el contenido —nombres de
 * coleccion, ids— es el nucleo, que ya sabe hacerlo y lanza si algo no cuadra.
 *
 * El limite de tamaño va antes de leer el cuerpo. Leer diez megas para despues
 * decir que son demasiados es pagar el ataque completo antes de rechazarlo.
 */

declare(strict_types=1);

namespace Axi\Core\Http;

final class Request
{
    /** 256 KB. Un documento razonable no se acerca; subir ficheros no es esto. */
    public const LIMITE_BYTES = 262144;

    private function __construct(
        public readonly string $metodo,
        public readonly array $cuerpo,
        public readonly ?string $token,
        public readonly ?string $origen,
        public readonly bool $esLocal
    ) {
    }

    /**
     * Construye desde el entorno del servidor.
     *
     * @param array       $server $_SERVER
     * @param string|null $crudo  cuerpo ya leido; si es null se lee de php://input
     * @throws BadRequest
     */
    public static function desde(array $server, ?string $crudo = null): self
    {
        $metodo = \strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        $declarado = (int) ($server['CONTENT_LENGTH'] ?? 0);
        if ($declarado > self::LIMITE_BYTES) {
            throw new BadRequest('El cuerpo supera el limite de ' . self::LIMITE_BYTES . ' bytes.', 413);
        }

        $cuerpo = [];
        if ($metodo === 'POST') {
            $crudo ??= (string) \file_get_contents('php://input', false, null, 0, self::LIMITE_BYTES + 1);
            if (\strlen($crudo) > self::LIMITE_BYTES) {
                throw new BadRequest('El cuerpo supera el limite de ' . self::LIMITE_BYTES . ' bytes.', 413);
            }
            $cuerpo = self::descodificar($crudo);
        }

        return new self(
            $metodo,
            $cuerpo,
            self::token($server),
            isset($server['HTTP_ORIGIN']) ? (string) $server['HTTP_ORIGIN'] : null,
            self::esLocal($server)
        );
    }

    /** Valor de una clave del cuerpo, como cadena. Null si no viene o no es escalar. */
    public function texto(string $clave): ?string
    {
        $v = $this->cuerpo[$clave] ?? null;
        return \is_string($v) || \is_int($v) ? (string) $v : null;
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    private static function descodificar(string $crudo): array
    {
        if (\trim($crudo) === '') {
            throw new BadRequest('Falta el cuerpo de la peticion.', 400);
        }
        $datos = \json_decode($crudo, true);
        if (!\is_array($datos)) {
            throw new BadRequest('El cuerpo no es JSON valido: ' . \json_last_error_msg(), 400);
        }
        if (\array_is_list($datos)) {
            throw new BadRequest('El cuerpo debe ser un objeto JSON, no una lista.', 400);
        }
        return $datos;
    }

    /**
     * Token del encabezado Authorization. Solo Bearer: admitir el token por
     * parametro de URL lo dejaria escrito en los registros del servidor, en el
     * historial del navegador y en la cabecera Referer de cada enlace.
     */
    private static function token(array $server): ?string
    {
        $cabecera = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($cabecera === '' || !\preg_match('/^Bearer\s+(\S+)$/i', $cabecera, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Peticion desde la propia maquina. Se mira la direccion real del que
     * conecta y nunca X-Forwarded-For, que la pone quien llama y por tanto
     * cualquiera puede afirmar ser localhost.
     *
     * Sin REMOTE_ADDR no hay peticion de red: es la linea de comandos, y ahi
     * no tiene sentido negar el acceso a la base de datos del propio disco.
     */
    private static function esLocal(array $server): bool
    {
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        return \in_array($ip, ['127.0.0.1', '::1', ''], true) || \str_starts_with($ip, '127.');
    }
}
