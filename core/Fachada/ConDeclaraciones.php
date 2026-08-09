<?php
/**
 * AxiDB - Fachada\ConDeclaraciones: lo que una coleccion declara de si misma.
 *
 * Tres cosas que una coleccion puede decir de si misma, y que el motor hace
 * cumplir sin que haya que acordarse en cada alta:
 *
 *   esquema     que forma tienen sus documentos
 *   caducidad   cuanto duran
 *   cifrado     si su contenido se guarda cerrado
 *
 * Las tres son opcionales. Una coleccion que no declara nada se comporta como
 * antes de que esto existiera.
 */

declare(strict_types=1);

namespace Axi\Core\Fachada;

use Axi\Core\Esquema;
use Axi\Core\Perfil;

trait ConDeclaraciones
{
    /**
     * El perfil con el que se abrio: que partes del motor se pueden usar.
     *
     * Se consulta desde las fachadas antes de dejar pasar una funcion que no
     * es del perfil basico.
     */
    public function perfil(): Perfil
    {
        return $this->perfil;
    }

    /**
     * Declara que forma tienen los documentos de una coleccion.
     *
     *   $db->declararEsquema('clientes', [
     *       'correo' => ['tipo' => 'texto', 'obligatorio' => true],
     *       'activo' => ['tipo' => 'bool',  'defecto' => true],
     *   ]);
     *
     * Los campos que no se declaran se guardan igual: esto no cierra la
     * coleccion, solo pone reglas donde hacen falta.
     *
     * No se comprueba lo que ya hay dentro, a diferencia de `unico()`. Un
     * esquema que rechazara la coleccion entera por un documento antiguo seria
     * imposible de adoptar en algo que ya esta en marcha; los documentos viejos
     * se validan cuando se vuelvan a escribir.
     */
    public function declararEsquema(string $collection, array $reglas): void
    {
        $this->perfil()->exigir('esquema', 'declararEsquema()');
        $this->storage->declararEsquema($collection, $reglas);
    }

    /** @return array<string, array> las reglas declaradas. Vacio si no hay */
    public function esquema(string $collection): array
    {
        return $this->storage->esquemaDe($collection);
    }

    /**
     * Los documentos de esta coleccion dejan de existir pasados N segundos
     * desde su ultima escritura. Cero desactiva la caducidad.
     *
     * Vencido significa vencido: no se devuelve en `get`, ni en `all`, ni
     * cuenta en `count`, aunque su archivo siga en el disco hasta el proximo
     * barrido. Ver CaducidadDriver para por que se hizo asi y no al reves.
     */
    public function declararCaducidad(string $collection, int $segundos): void
    {
        $this->perfil()->exigir('caducidad', 'declararCaducidad()');
        $this->storage->declararCaducidad($collection, $segundos);
    }

    public function caducidad(string $collection): int
    {
        return $this->storage->caducidadDe($collection);
    }

    /**
     * Cifra una coleccion: lo que se guarde a partir de ahora va cerrado, y lo
     * que ya hubiera se reescribe cerrado tambien. Hace falta haber abierto la
     * base con la clave.
     *
     * @return int documentos reescritos
     */
    public function cifrar(string $collection): int
    {
        $this->perfil()->exigir('cifrado', 'cifrar()');
        return $this->storage->cifrar($collection);
    }

    public function estaCifrada(string $collection): bool
    {
        return $this->storage->estaCifrada($collection);
    }

    /**
     * Aplica el esquema a un documento que se va a escribir.
     *
     * Devuelve el documento con los valores por defecto puestos y un indicador
     * de si ya viene fusionado con el anterior. Lo segundo importa: la
     * validacion necesita ver el documento ENTERO tal y como va a quedar
     * —quitar un campo obligatorio en una actualizacion parcial no se veria
     * mirando solo lo que cambia— asi que al validar ya se ha fusionado y no
     * hay que volver a hacerlo mas abajo.
     *
     * @return array{0: array, 1: bool} [documento, ya_fusionado]
     */
    private function aplicarEsquema(
        string $collection,
        string $id,
        array $data,
        ?array $before,
        bool $replace
    ): array {
        $esquema = new Esquema($this->storage->esquemaDe($collection));
        if ($esquema->vacio()) {
            return [$data, $replace];
        }
        $entero = $replace || $before === null
            ? $data
            : $data + \array_diff_key($before, \array_flip(['_version', '_createdAt', '_updatedAt']));

        return [$esquema->aplicar($collection, $id, $entero), true];
    }
}
