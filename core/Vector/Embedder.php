<?php
/**
 * AxiDB - Vector\Embedder: de un texto a un vector.
 *
 * AxiDB no fabrica embeddings; los pide. Quien los fabrica es un modelo, y hay
 * cuatro sitios de donde traerlos —tu propia maquina con Ollama, o Google,
 * OpenAI o Voyage por internet— mas uno que no usa modelo ninguno y sirve para
 * los tests.
 *
 * El contrato es minusculo a proposito: texto entra, lista de numeros sale.
 * Cuantas dimensiones tiene esa lista lo decide el modelo, y por eso se pregunta
 * en vez de configurarse: si el manifiesto dice 768 y el modelo devuelve 384,
 * mas vale enterarse al activar los vectores que al buscar.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

interface Embedder
{
    /**
     * El vector de un texto. Sin normalizar: de eso se encarga el motor.
     *
     * @return list<float>
     */
    public function vector(string $texto): array;

    /** Cuantas dimensiones devuelve. */
    public function dims(): int;

    /**
     * Como se llama, para dejarlo escrito en el manifiesto. Formato
     * `proveedor:modelo`, por ejemplo `ollama:nomic-embed-text`.
     */
    public function driverName(): string;

    /** True si funciona sin salir a internet. */
    public function isLocal(): bool;
}
