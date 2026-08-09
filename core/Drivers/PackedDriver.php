<?php
/**
 * AxiDB - Drivers\PackedDriver: una coleccion, un archivo.
 *
 *   <coleccion>/data.axi      los documentos, solo se añade al final
 *   <coleccion>/offsets.log   donde esta cada uno, tambien solo añadiendo
 *   <coleccion>/offsets.idx   instantanea del mapa, para arrancar rapido
 *
 * Un alta son dos añadidos al final, no un archivo nuevo con su temporal y su
 * rename: por eso mete diez mil documentos en lo que FsDriver tarda unos
 * cientos. El precio, y el motivo de que fs siga siendo el defecto, es que el
 * dato deja de verse en el explorador de archivos.
 *
 * Orden de escritura, que es lo que lo hace seguro: primero el dato, despues el
 * desplazamiento. Un corte en medio deja un dato al que no apunta nadie
 * —inofensivo— y jamas un apunte hacia bytes que no llegaron a escribirse.
 */

declare(strict_types=1);

namespace Axi\Core\Drivers;

use Axi\Core\Settings;
use Axi\Core\Collections;
use Axi\Core\Drivers\Packed\Compactador;
use Axi\Core\Drivers\Packed\Descriptores;
use Axi\Core\Meta;
use Axi\Core\Names;

final class PackedDriver implements Driver
{
    private Descriptores $archivos;

    public function __construct(
        private Collections $colecciones,
        Settings $ajustes
    ) {
        $this->archivos = new Descriptores($colecciones, $ajustes);
    }

    public function nombre(): string
    {
        return 'packed';
    }

    public function put(string $collection, string $id, array $data, bool $replace = false): array
    {
        Names::check($id, 'id');
        $this->colecciones->ensure($collection);

        $lock = $this->archivos->bloquear($collection);
        try {
            ['log' => $log, 'off' => $off] = $this->archivos->de($collection);

            $existente = null;
            $donde     = $off->de($id);
            if ($donde !== null) {
                $existente = $log->leer($donde[0], $donde[1]);
            }
            $data = Meta::aplicar($data, $id, $existente, $replace);

            [$desplazamiento, $longitud] = $log->añadir($data);
            $off->anotar($id, $desplazamiento, $longitud);
        } finally {
            $this->archivos->desbloquear($lock);
        }
        return $data;
    }

    /** Copia literal: el mismo añadido que put, pero sin pasar por Meta. */
    public function copiar(string $collection, string $id, array $doc): void
    {
        Names::check($id, 'id');
        $lock = $this->archivos->bloquear($collection);
        try {
            ['log' => $log, 'off' => $off] = $this->archivos->de($collection);
            [$desplazamiento, $longitud] = $log->añadir($doc);
            $off->anotar($id, $desplazamiento, $longitud);
        } finally {
            $this->archivos->desbloquear($lock);
        }
    }

    public function get(string $collection, string $id): ?array
    {
        Names::check($id, 'id');
        ['log' => $log, 'off' => $off] = $this->archivos->de($collection);

        $donde = $off->de($id);
        return $donde === null ? null : $log->leer($donde[0], $donde[1]);
    }

    public function delete(string $collection, string $id): bool
    {
        Names::check($id, 'id');
        $lock = $this->archivos->bloquear($collection);
        try {
            ['off' => $off] = $this->archivos->de($collection);
            if ($off->de($id) === null) {
                return false;
            }
            $off->marcarBorrado($id);
        } finally {
            $this->archivos->desbloquear($lock);
        }
        return true;
    }

    public function all(string $collection): array
    {
        ['log' => $log, 'off' => $off] = $this->archivos->de($collection);

        $out = [];
        foreach ($off->mapa() as [$desplazamiento, $longitud]) {
            $doc = $log->leer($desplazamiento, $longitud);
            if ($doc !== null) {
                $out[] = $doc;
            }
        }
        return $out;
    }

    public function count(string $collection): int
    {
        ['off' => $off] = $this->archivos->de($collection);
        return $off->cuantos();
    }

    /**
     * Compacta si hace falta y barre temporales. $minAgeSeconds no aplica:
     * compactar es seguro siempre porque trabaja sobre un temporal.
     */
    public function sweep(string $collection, int $minAgeSeconds = 300): int
    {
        return $this->conCompactador($collection, function (Compactador $c) use ($collection) {
            $recuperados = $c->haceFalta() ? $c->compactar() : 0;
            foreach (\glob($this->colecciones->path($collection) . '/*.tmp.*') ?: [] as $tmp) {
                @\unlink($tmp);
            }
            return $recuperados;
        });
    }

    /**
     * Compacta ahora, pase lo que pase el umbral. sweep() es oportunista; esto
     * es para mantenimiento deliberado, como tras una purga grande.
     */
    public function compactar(string $collection): int
    {
        return $this->conCompactador($collection, fn (Compactador $c) => $c->compactar());
    }

    /** Cuanto del archivo es espacio muerto, entre 0 y 1. Para diagnostico. */
    public function proporcionMuerta(string $collection): float
    {
        ['log' => $log, 'off' => $off] = $this->archivos->de($collection);
        return (new Compactador($log, $off))->proporcionMuerta();
    }

    /** Suelta los descriptores. Obligatorio antes de borrar la coleccion. */
    public function olvidar(?string $collection = null): void
    {
        $this->archivos->olvidar($collection);
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /** Un Compactador bajo lock exclusivo. Devuelve 0 si la coleccion no existe. */
    private function conCompactador(string $collection, callable $fn): int
    {
        if (!$this->colecciones->exists($collection)) {
            return 0;
        }
        $lock = $this->archivos->bloquear($collection);
        try {
            ['log' => $log, 'off' => $off] = $this->archivos->de($collection);
            return $fn(new Compactador($log, $off));
        } finally {
            $this->archivos->desbloquear($lock);
        }
    }
}
