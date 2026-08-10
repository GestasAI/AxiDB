<?php
/**
 * AxiDB - Facade\WithExpiry: el rastro que deja un documento vencido.
 *
 * Un documento caducado deja de verse, pero su archivo, su entrada de indice y su
 * reserva de valor unico siguen en el disco hasta que algo los barre. Este trait
 * es ese barrido, hecho justo cuando estorba: al escribir sobre un valor unico
 * que un vencido todavia retiene.
 */

declare(strict_types=1);

namespace Axi\Core\Facade;

trait WithExpiry
{
    /**
     * Libera los valores unicos que retiene un documento ya vencido.
     *
     * Para cada campo unico del documento que entra, mira quien tiene ese valor.
     * Si el dueño existe en el indice pero su documento ya no es visible —esta
     * vencido—, se borra del todo: eso limpia su reserva y su entrada de indice, y
     * el valor queda disponible. Solo se toca lo que de verdad caduco; un dueño
     * vivo hace que la reserva rebote, como debe.
     *
     * @param list<string> $unicos
     */
    private function purgeExpiredOwners(string $collection, array $unicos, array $data): void
    {
        foreach ($unicos as $campo) {
            $valor = $data[$campo] ?? null;
            if ($valor === null || $valor === '' || \is_array($valor)) {
                continue;
            }
            foreach ($this->index->ids($collection, $campo, (string) $valor) ?? [] as $duenno) {
                if ($duenno !== '' && $this->storage->get($collection, (string) $duenno) === null
                    && $this->storage->rawGet($collection, (string) $duenno) !== null) {
                    $this->delete($collection, (string) $duenno);   // vencido: se barre
                }
            }
        }
    }
}
