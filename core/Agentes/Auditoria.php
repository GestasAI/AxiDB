<?php
/**
 * AxiDB - Agentes\Auditoria: quien hizo que, y cuando.
 *
 * Un renglon por operacion, en JSON, solo añadiendo al final:
 *
 *   {"ts":"2026-08-09T10:12:03+00:00","actor":"agent:recomendador",
 *    "op":"delete","coleccion":"clientes","id":"c7","ok":false,
 *    "error":"Este agente no puede 'delete'."}
 *
 * **Se anotan tambien los intentos que no se permitieron**, y esa es la parte
 * util. Que un agente haga lo que le toca no dice gran cosa; que lleve veinte
 * minutos intentando borrar una coleccion que no es suya, si.
 *
 * Solo se añade al final y nunca se reescribe. Un registro de auditoria que se
 * puede modificar no es un registro de auditoria.
 */

declare(strict_types=1);

namespace Axi\Core\Agentes;

final class Auditoria
{
    public function __construct(private string $archivo)
    {
    }

    public function anotar(
        string $actor,
        string $operacion,
        ?string $coleccion,
        ?string $id,
        bool $ok,
        ?string $error = null
    ): void {
        $linea = [
            'ts'        => \date('c'),
            'actor'     => $actor,
            'op'        => $operacion,
            'coleccion' => $coleccion,
            'id'        => $id,
            'ok'        => $ok,
        ];
        if ($error !== null) {
            $linea['error'] = $error;
        }
        $this->escribir((string) \json_encode($linea, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Lee el rastro, del mas reciente al mas antiguo.
     *
     * @param string|null $actor filtra por actor exacto
     * @return list<array>
     */
    public function leer(?string $actor = null, int $limite = 100): array
    {
        if (!\is_file($this->archivo)) {
            return [];
        }
        $lineas = \file($this->archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $salida = [];

        for ($i = \count($lineas) - 1; $i >= 0 && \count($salida) < $limite; $i--) {
            $fila = \json_decode($lineas[$i], true);
            if (!\is_array($fila)) {
                continue;                       // una linea a medias no invalida el resto
            }
            if ($actor !== null && ($fila['actor'] ?? null) !== $actor) {
                continue;
            }
            $salida[] = $fila;
        }
        return $salida;
    }

    /** Cuantas operaciones de un actor fallaron por falta de permiso. */
    public function rechazos(string $actor, int $limite = 1000): int
    {
        $n = 0;
        foreach ($this->leer($actor, $limite) as $fila) {
            if (($fila['ok'] ?? true) === false) {
                $n++;
            }
        }
        return $n;
    }

    public function archivo(): string
    {
        return $this->archivo;
    }

    /**
     * Un solo `fwrite` en modo añadir y bajo cerrojo.
     *
     * En POSIX una escritura corta en modo 'a' es atomica, pero eso no basta
     * cuando escriben varios procesos en Windows, asi que se toma el cerrojo del
     * propio archivo. Un renglon partido por la mitad haria ilegible el rastro
     * justo el dia que haga falta leerlo.
     */
    private function escribir(string $linea): void
    {
        $dir = \dirname($this->archivo);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            return;                             // sin sitio donde anotar, no se rompe la operacion
        }
        $fp = @\fopen($this->archivo, 'a');
        if (!$fp) {
            return;
        }
        try {
            if (\flock($fp, LOCK_EX)) {
                \fwrite($fp, $linea . "\n");
                \fflush($fp);
                \flock($fp, LOCK_UN);
            }
        } finally {
            \fclose($fp);
        }
    }
}
