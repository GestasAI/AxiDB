<?php
/**
 * AxiDB - Embedders\Remoto: pedirle el vector a un modelo por HTTP.
 *
 * Los cuatro proveedores hacen lo mismo con distinto envoltorio: se envia un
 * texto, vuelve una lista de numeros. Lo unico que cambia es la direccion, la
 * cabecera de autenticacion, el nombre de los campos y donde viene el vector
 * dentro de la respuesta. Todo eso son datos, no codigo, asi que hay una clase y
 * una tabla en vez de cuatro clases casi iguales.
 *
 * **Sin curl.** El nucleo solo puede usar extensiones que vengan siempre con
 * PHP, y curl no es una de ellas. Se usa el envoltorio de flujos, que es parte
 * del lenguaje; si el servidor tiene `allow_url_fopen` desactivado se dice con
 * todas las letras en vez de fallar de forma rara.
 *
 * Sobre Claude: **Anthropic no publica una API de embeddings.** Su
 * documentacion remite a Voyage para esto, y por eso el proveedor que cubre ese
 * ecosistema aqui es `voyage` y no `claude`. Un cliente contra un endpoint que
 * no existe solo serviria para fallar en produccion.
 */

declare(strict_types=1);

namespace Axi\Core\Vector\Embedders;

use Axi\Core\Exception;
use Axi\Core\Vector\Embedder;

final class Remoto implements Embedder
{
    /** Lo que cambia de un proveedor a otro. */
    private const PROVEEDORES = [
        'ollama' => [
            'url'       => 'http://localhost:11434/api/embeddings',
            'modelo'    => 'nomic-embed-text',
            'dims'      => 768,
            'cabecera'  => null,
            'cuerpo'    => ['model' => '%modelo%', 'prompt' => '%texto%'],
            'respuesta' => 'embedding',
            'local'     => true,
        ],
        'openai' => [
            'url'       => 'https://api.openai.com/v1/embeddings',
            'modelo'    => 'text-embedding-3-small',
            'dims'      => 1536,
            'cabecera'  => 'Authorization: Bearer %clave%',
            'cuerpo'    => ['model' => '%modelo%', 'input' => '%texto%'],
            'respuesta' => 'data.0.embedding',
            'local'     => false,
        ],
        'gemini' => [
            'url'       => 'https://generativelanguage.googleapis.com/v1beta/models/%modelo%:embedContent?key=%clave%',
            'modelo'    => 'text-embedding-004',
            'dims'      => 768,
            'cabecera'  => null,
            'cuerpo'    => ['content' => ['parts' => [['text' => '%texto%']]]],
            'respuesta' => 'embedding.values',
            'local'     => false,
        ],
        'voyage' => [
            'url'       => 'https://api.voyageai.com/v1/embeddings',
            'modelo'    => 'voyage-3',
            'dims'      => 1024,
            'cabecera'  => 'Authorization: Bearer %clave%',
            'cuerpo'    => ['model' => '%modelo%', 'input' => ['%texto%']],
            'respuesta' => 'data.0.embedding',
            'local'     => false,
        ],
    ];

    private array $config;

    /**
     * @param string $proveedor ollama, openai, gemini o voyage
     * @param array  $opciones  modelo, clave, url, dims, timeout
     */
    public function __construct(
        private string $proveedor,
        private array $opciones = []
    ) {
        if (!isset(self::PROVEEDORES[$proveedor])) {
            throw new Exception(
                "Embedder: no conozco el proveedor '{$proveedor}'. Los que hay: "
                . \implode(', ', \array_keys(self::PROVEEDORES)) . '.'
            );
        }
        $this->config = self::PROVEEDORES[$proveedor];

        foreach (['modelo', 'dims', 'url'] as $campo) {
            if (isset($opciones[$campo])) {
                $this->config[$campo] = $opciones[$campo];
            }
        }
        if (!$this->config['local'] && empty($opciones['clave'])) {
            throw new Exception("Embedder: '{$proveedor}' necesita una clave de API.");
        }
    }

    public function vector(string $texto): array
    {
        if (!\ini_get('allow_url_fopen')) {
            throw new Exception(
                'Embedder: este PHP tiene allow_url_fopen desactivado, asi que no puede '
                . 'salir a internet. Usa el embedder Hash o genera los vectores por tu cuenta '
                . 'y pasalos ya hechos.'
            );
        }

        $cuerpo = \json_encode($this->plantilla($this->config['cuerpo'], $texto));
        $opciones = ['http' => [
            'method'        => 'POST',
            'header'        => $this->cabeceras(),
            'content'       => $cuerpo,
            'timeout'       => (float) ($this->opciones['timeout'] ?? 20),
            'ignore_errors' => true,
        ]];

        $url      = $this->sustituir($this->config['url'], '');
        $respuesta = @\file_get_contents($url, false, \stream_context_create($opciones));
        if ($respuesta === false) {
            throw new Exception("Embedder: no se pudo hablar con {$this->proveedor} en {$url}.");
        }

        $datos = \json_decode($respuesta, true);
        if (!\is_array($datos)) {
            throw new Exception("Embedder: {$this->proveedor} contesto algo que no es JSON.");
        }
        $vector = $this->extraer($datos, $this->config['respuesta']);
        if (!\is_array($vector) || $vector === []) {
            $error = $datos['error']['message'] ?? \substr($respuesta, 0, 200);
            throw new Exception("Embedder: {$this->proveedor} no devolvio un vector. Dijo: {$error}");
        }
        return \array_map('floatval', \array_values($vector));
    }

    public function dims(): int
    {
        return (int) $this->config['dims'];
    }

    public function nombre(): string
    {
        return $this->proveedor . ':' . $this->config['modelo'];
    }

    public function esLocal(): bool
    {
        return (bool) $this->config['local'];
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    private function cabeceras(): string
    {
        $lineas = ['Content-Type: application/json'];
        if ($this->config['cabecera'] !== null) {
            $lineas[] = $this->sustituir($this->config['cabecera'], '');
        }
        return \implode("\r\n", $lineas) . "\r\n";
    }

    /** Rellena %modelo%, %clave% y %texto% dentro de la plantilla del cuerpo. */
    private function plantilla(array $plantilla, string $texto): array
    {
        $salida = [];
        foreach ($plantilla as $clave => $valor) {
            $salida[$clave] = \is_array($valor)
                ? $this->plantilla($valor, $texto)
                : $this->sustituir((string) $valor, $texto);
        }
        return $salida;
    }

    private function sustituir(string $texto, string $contenido): string
    {
        return \strtr($texto, [
            '%modelo%' => (string) $this->config['modelo'],
            '%clave%'  => (string) ($this->opciones['clave'] ?? ''),
            '%texto%'  => $contenido,
        ]);
    }

    /** Saca un valor anidado con una ruta tipo 'data.0.embedding'. */
    private function extraer(array $datos, string $ruta): mixed
    {
        $actual = $datos;
        foreach (\explode('.', $ruta) as $paso) {
            if (!\is_array($actual) || !\array_key_exists($paso, $actual)) {
                return null;
            }
            $actual = $actual[$paso];
        }
        return $actual;
    }
}
