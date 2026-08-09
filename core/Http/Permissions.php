<?php
/**
 * AxiDB - Http\Permissions: quien puede hacer que, sobre que coleccion.
 *
 * Tres decisiones que conviene entender antes de tocar nada:
 *
 * 1. Sin tokens declarados solo se atiende desde localhost. Pedir configuracion
 *    obligatoria para probar en local espanta; dejar abierta una base de datos
 *    en produccion porque el desarrollador no leyo un parrafo es peor. Asi que
 *    funciona sin configurar en tu maquina y se niega en cuanto sale de ella.
 *    Quien quiera una API publica de verdad lo dice: 'abierto' => true.
 *
 * 2. Los tokens tienen ambito. Uno de 'pedidos' no abre 'usuarios'. Un token
 *    unico que lo abre todo es el que acaba pegado en el JavaScript de la web.
 *
 * 3. Las colecciones publicas son de solo lectura, siempre. Un catalogo que
 *    cualquiera puede leer no es un catalogo que cualquiera pueda escribir.
 */

declare(strict_types=1);

namespace Axi\Core\Http;

final class Permissions
{
    private const ESCRITURAS = ['insert', 'update', 'delete'];

    /** @var array<string, array{colecciones: string|list<string>, escribir: bool}> */
    private array $tokens = [];

    /** @var list<string> */
    private array $publicas;

    private bool $soloLectura;
    private bool $abierto;

    public function __construct(array $opciones)
    {
        foreach ((array) ($opciones['tokens'] ?? []) as $token => $ambito) {
            $this->tokens[(string) $token] = [
                'colecciones' => $ambito['colecciones'] ?? '*',
                'escribir'    => (bool) ($ambito['escribir'] ?? false),
            ];
        }
        // Forma corta: un token con acceso completo.
        if (!empty($opciones['token'])) {
            $this->tokens[(string) $opciones['token']] = ['colecciones' => '*', 'escribir' => true];
        }
        $this->publicas    = \array_map('strval', (array) ($opciones['publicas'] ?? []));
        $this->soloLectura = (bool) ($opciones['soloLectura'] ?? false);
        $this->abierto     = (bool) ($opciones['abierto'] ?? false);
    }

    /**
     * @param bool $escribe si la operacion modifica datos. Lo decide quien
     *                      llama, porque para `sql` hay que analizar la
     *                      sentencia y el nombre de la accion no basta.
     * @return array{0: bool, 1: int, 2: string} permitido, codigo HTTP, motivo
     */
    public function decide(bool $escribe, ?string $coleccion, ?string $token, bool $esLocal): array
    {
        if ($escribe && $this->soloLectura) {
            return [false, 403, 'El puente esta en modo solo lectura.'];
        }

        $portador = $this->tokenValido($token);

        if ($portador === null) {
            if ($token !== null && $token !== '') {
                return [false, 401, 'Token no valido.'];
            }
            return $this->sinToken($escribe, $coleccion, $esLocal);
        }

        if ($escribe && !$portador['escribir']) {
            return [false, 403, 'Este token es de solo lectura.'];
        }
        if (!self::alcanza($portador['colecciones'], $coleccion)) {
            return [false, 403, "Este token no alcanza a '{$coleccion}'."];
        }
        return [true, 200, ''];
    }

    public function hayTokens(): bool
    {
        return $this->tokens !== [];
    }

    public static function escribe(string $accion): bool
    {
        return \in_array($accion, self::ESCRITURAS, true);
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /** @return array{colecciones: string|list<string>, escribir: bool}|null */
    private function tokenValido(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        // hash_equals sobre todos los candidatos: comparar con === filtraria por
        // tiempo de respuesta cuantos caracteres iniciales ha acertado quien
        // prueba tokens a ciegas.
        $encontrado = null;
        foreach ($this->tokens as $valido => $ambito) {
            if (\hash_equals((string) $valido, $token)) {
                $encontrado = $ambito;
            }
        }
        return $encontrado;
    }

    /** @return array{0: bool, 1: int, 2: string} */
    private function sinToken(bool $escribe, ?string $coleccion, bool $esLocal): array
    {
        if (!$escribe && $coleccion !== null && \in_array($coleccion, $this->publicas, true)) {
            return [true, 200, ''];
        }
        if ($this->hayTokens()) {
            return [false, 401, 'Hace falta un token.'];
        }
        if ($this->abierto) {
            return [true, 200, ''];
        }
        if ($esLocal) {
            return [true, 200, ''];
        }
        return [false, 401,
            'Sin tokens declarados, el puente solo atiende desde localhost. '
            . "Declara 'tokens' o pon 'abierto' => true si de verdad quieres una API sin llave."];
    }

    private static function alcanza(string|array $ambito, ?string $coleccion): bool
    {
        if ($ambito === '*' || $coleccion === null) {
            return true;
        }
        return \in_array($coleccion, (array) $ambito, true);
    }
}
