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

    public function driverName(): string
    {
        return 'packed';
    }

    public function put(string $collection, string $id, array $data, bool $replace = false): array
    {
        Names::check($id, 'id');
        $this->colecciones->ensure($collection);

        $lock = $this->archivos->lock($collection);
        try {
            ['log' => $log, 'off' => $off] = $this->archivos->of($collection);

            $existente = null;
            $donde     = $off->of($id);
            if ($donde !== null) {
                $existente = $log->readAt($donde[0], $donde[1]);
            }
            $data = Meta::aplicar($data, $id, $existente, $replace);

            [$desplazamiento, $longitud] = $log->append($data);
            $off->record($id, $desplazamiento, $longitud);
        } finally {
            $this->archivos->unlock($lock);
        }
        return $data;
    }

    /** Copia literal: el mismo añadido que put, pero sin pasar por Meta. */
    public function copyDocument(string $collection, string $id, array $doc): void
    {
        Names::check($id, 'id');
        $lock = $this->archivos->lock($collection);
        try {
            ['log' => $log, 'off' => $off] = $this->archivos->of($collection);
            [$desplazamiento, $longitud] = $log->append($doc);
            $off->record($id, $desplazamiento, $longitud);
        } finally {
            $this->archivos->unlock($lock);
        }
    }

    public function get(string $collection, string $id): ?array
    {
        Names::check($id, 'id');
        ['log' => $log, 'off' => $off] = $this->archivos->of($collection);

        $donde = $off->of($id);
        return $donde === null ? null : $log->readAt($donde[0], $donde[1]);
    }

    public function delete(string $collection, string $id): bool
    {
        Names::check($id, 'id');
        $lock = $this->archivos->lock($collection);
        try {
            ['off' => $off] = $this->archivos->of($collection);
            if ($off->of($id) === null) {
                return false;
            }
            $off->markDeleted($id);
        } finally {
            $this->archivos->unlock($lock);
        }
        return true;
    }

    public function all(string $collection): array
    {
        ['log' => $log, 'off' => $off] = $this->archivos->of($collection);

        $out = [];
        foreach ($off->map() as [$desplazamiento, $longitud]) {
            $doc = $log->readAt($desplazamiento, $longitud);
            if ($doc !== null) {
                $out[] = $doc;
            }
        }
        return $out;
    }

    public function count(string $collection): int
    {
        ['off' => $off] = $this->archivos->of($collection);
        return $off->howMany();
    }

    /**
     * Compacta si hace falta y barre temporales. $minAgeSeconds no aplica:
     * compactar es seguro siempre porque trabaja sobre un temporal.
     */
    public function sweep(string $collection, int $minAgeSeconds = 300): int
    {
        return $this->withCompactor($collection, function (Compactador $c) use ($collection) {
            $recuperados = $c->isNeeded() ? $c->compact() : 0;
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
    public function compact(string $collection): int
    {
        return $this->withCompactor($collection, fn (Compactador $c) => $c->compact());
    }

    /** Cuanto del archivo es espacio muerto, entre 0 y 1. Para diagnostico. */
    public function deadRatio(string $collection): float
    {
        ['log' => $log, 'off' => $off] = $this->archivos->of($collection);
        return (new Compactador($log, $off))->deadRatio();
    }

    /** Suelta los descriptores. Obligatorio antes de borrar la coleccion. */
    public function forget(?string $collection = null): void
    {
        $this->archivos->forget($collection);
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    /** Un Compactador bajo lock exclusivo. Devuelve 0 si la coleccion no existe. */
    private function withCompactor(string $collection, callable $fn): int
    {
        if (!$this->colecciones->exists($collection)) {
            return 0;
        }
        $lock = $this->archivos->lock($collection);
        try {
            ['log' => $log, 'off' => $off] = $this->archivos->of($collection);
            return $fn(new Compactador($log, $off));
        } finally {
            $this->archivos->unlock($lock);
        }
    }
}
