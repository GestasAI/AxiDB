<?php
/**
 * AxiDB - Core\Uniqueness: hacer cumplir los campos unicos al escribir.
 *
 * El orden de los pasos es lo unico interesante de este archivo:
 *
 *   1. reservar el valor en el indice, bajo su cerrojo
 *   2. escribir el documento
 *   3. si la escritura falla, soltar la reserva
 *
 * Al reves —escribir y comprobar despues— habria que deshacer un documento ya
 * guardado, y deshacer tambien puede fallar. Reservando primero, lo peor que
 * pasa es que quede una reserva de mas, que no corrompe nada y que
 * `verifyIndexes()` señala.
 *
 * Los valores ausentes, vacios o que son listas no se reservan. Es lo mismo que
 * hace SQL con NULL: dos documentos sin correo no chocan entre si, porque no
 * tener valor no es compartir valor.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Uniqueness
{
    /** @var list<array{campo:string, valor:string}> reservas hechas en esta escritura */
    private array $reservadas = [];

    public function __construct(
        private Index $index,
        private string $collection,
        private string $id
    ) {
    }

    /**
     * Reserva los valores unicos que trae el documento nuevo.
     *
     * @param list<string> $campos    campos declarados unicos
     * @param array        $documento el documento tal y como va a quedar
     * @param array|null   $anterior  el que habia, para no reservar lo ya propio
     */
    public function reserve(array $campos, array $documento, ?array $anterior): void
    {
        foreach ($campos as $campo) {
            $valor = self::valueOf($documento, $campo);
            if ($valor === null || $valor === self::valueOf($anterior ?? [], $campo)) {
                continue;                      // sin valor, o ya era suyo
            }
            try {
                $this->index->claim($this->collection, $campo, $valor, $this->id);
            } catch (Exception $e) {
                $this->release();
                throw $e;
            }
            $this->reservadas[] = ['campo' => $campo, 'valor' => $valor];
        }
    }

    /** Suelta lo reservado. Se llama si la escritura del documento no sale. */
    public function release(): void
    {
        foreach ($this->reservadas as $r) {
            $this->index->remove($this->collection, $r['campo'], $r['valor'], $this->id);
        }
        $this->reservadas = [];
    }

    /** Un valor indexable, o null si ese campo no participa. */
    private static function valueOf(array $documento, string $campo): ?string
    {
        $v = $documento[$campo] ?? null;
        if ($v === null || $v === '') {
            return null;                            // ausente: el campo no participa
        }
        // Un array NO es "no participa": es un intento de rodear la restriccion.
        // Antes se devolvia null tambien para arrays, asi que mandar
        // ['email' => ['ana@x.es']] se saltaba la unicidad y entraban dos. Quien
        // elige el tipo del dato no puede elegir si la restriccion existe: un
        // campo unico que llega como lista se rechaza.
        if (\is_array($v)) {
            throw new Exception(
                "Uniqueness: el campo unico '{$campo}' no puede ser una lista."
            );
        }
        return (string) $v;
    }

    /**
     * Comprueba que una coleccion no tenga ya repetidos en ese campo.
     *
     * Se llama al declarar el campo unico, no en cada alta: declarar una
     * restriccion sobre datos que ya la incumplen dejaria la coleccion en un
     * estado que ninguna escritura futura puede arreglar.
     */
    public static function exigirSinRepetidos(Storage $storage, string $collection, string $campo): void
    {
        $vistos = [];
        foreach ($storage->all($collection) as $doc) {
            $valor = self::valueOf($doc, $campo);
            if ($valor === null) {
                continue;
            }
            if (isset($vistos[$valor])) {
                throw new Exception(
                    "Cannot declare '{$campo}' unico en '{$collection}': el valor "
                    . "'{$valor}' se repite en '{$vistos[$valor]}' y '{$doc['id']}'."
                );
            }
            $vistos[$valor] = (string) ($doc['id'] ?? '');
        }
    }
}
