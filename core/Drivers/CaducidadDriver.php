<?php
/**
 * AxiDB - CaducidadDriver: los documentos vencidos dejan de existir.
 *
 * Decorador, igual que el cifrado, y por el mismo motivo: los drivers de abajo
 * no tienen que enterarse.
 *
 * La decision que importa: **un documento vencido no se ve, aunque su archivo
 * siga en el disco**. Podria haberse hecho al reves —borrarlos en una limpieza
 * periodica y devolverlos mientras tanto— y seria mas barato, pero entonces
 * "caduca en una hora" significaria "caduca en una hora, o cuando pase el
 * barrendero, lo que ocurra despues". Eso no es una caducidad: es una promesa
 * con letra pequeña.
 *
 * Se cuenta desde `_updatedAt`, la ultima escritura. Para algo que se escribe
 * una vez —un token, una entrada de cache— es lo mismo que contar desde el
 * alta. Para una sesion, cada modificacion le da cuerda otra vez, que es lo que
 * se espera de una sesion.
 *
 * El coste: `count()` deja de ser O(1) en el driver empaquetado, porque hay que
 * mirar documento a documento quien sigue vivo. Solo se paga en las colecciones
 * que declaran caducidad.
 */

declare(strict_types=1);

namespace Axi\Core\Drivers;

final class CaducidadDriver implements Driver
{
    public function __construct(
        private Driver $dentro,
        private int $segundos
    ) {
    }

    public function nombre(): string
    {
        return $this->dentro->nombre() . '+caducidad';
    }

    public function get(string $collection, string $id): ?array
    {
        $doc = $this->dentro->get($collection, $id);
        return $doc !== null && $this->vencido($doc) ? null : $doc;
    }

    public function all(string $collection): array
    {
        $fuera = [];
        foreach ($this->dentro->all($collection) as $clave => $doc) {
            if (!$this->vencido($doc)) {
                $fuera[$clave] = $doc;
            }
        }
        return $fuera;
    }

    public function count(string $collection): int
    {
        return \count($this->all($collection));
    }

    /**
     * Barre tambien los vencidos, y esa es la unica forma de que el disco no
     * crezca para siempre con documentos que ya no se ven.
     */
    public function sweep(string $collection, int $minAgeSeconds = 300): int
    {
        $barridos = 0;
        foreach ($this->dentro->all($collection) as $doc) {
            if ($this->vencido($doc) && $this->dentro->delete($collection, (string) ($doc['id'] ?? ''))) {
                $barridos++;
            }
        }
        return $barridos + $this->dentro->sweep($collection, $minAgeSeconds);
    }

    /* Escribir no mira la fecha: escribir es justo lo que le da cuerda. */

    public function put(string $collection, string $id, array $data, bool $replace = false): array
    {
        return $this->dentro->put($collection, $id, $data, $replace);
    }

    public function copiar(string $collection, string $id, array $doc): void
    {
        $this->dentro->copiar($collection, $id, $doc);
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->dentro->delete($collection, $id);
    }

    /**
     * Un documento sin `_updatedAt` legible no se da por vencido.
     *
     * Es la eleccion prudente: ante la duda, se conserva. Al reves, una fecha
     * que no se entiende borraria datos, y eso no se puede deshacer.
     */
    private function vencido(array $doc): bool
    {
        $marca = \strtotime((string) ($doc['_updatedAt'] ?? ''));
        return $marca !== false && $marca + $this->segundos <= \time();
    }
}
