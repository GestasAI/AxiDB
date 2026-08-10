<?php
/**
 * AxiDB - Fachada\WithDeclarations: lo que una coleccion declara de si misma.
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

namespace Axi\Core\Facade;

use Axi\Core\SchemaRules;
use Axi\Core\Profile;

trait WithDeclarations
{
    /**
     * El perfil con el que se abrio: que partes del motor se pueden usar.
     *
     * Se consulta desde las fachadas antes de dejar pasar una funcion que no
     * es del perfil basico.
     */
    public function profile(): Profile
    {
        return $this->perfil;
    }

    /**
     * Declara que forma tienen los documentos de una coleccion.
     *
     *   $db->defineSchema('clientes', [
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
    public function defineSchema(string $collection, array $reglas): void
    {
        $this->profile()->requireCapability('schema', 'defineSchema()');
        $this->storage->defineSchema($collection, $reglas);
    }

    /** @return array<string, array> las reglas declaradas. Vacio si no hay */
    public function schema(string $collection): array
    {
        return $this->storage->schemaOf($collection);
    }

    /**
     * Los documentos de esta coleccion dejan de existir pasados N segundos
     * desde su ultima escritura. Cero desactiva la caducidad.
     *
     * Vencido significa vencido: no se devuelve en `get`, ni en `all`, ni
     * cuenta en `count`, aunque su archivo siga en el disco hasta el proximo
     * barrido. Ver CaducidadDriver para por que se hizo asi y no al reves.
     */
    public function defineTtl(string $collection, int $segundos): void
    {
        $this->profile()->requireCapability('ttl', 'defineTtl()');
        $this->storage->defineTtl($collection, $segundos);
    }

    public function ttl(string $collection): int
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
    public function encrypt(string $collection): int
    {
        $this->profile()->requireCapability('encryption', 'encrypt()');
        $n = $this->storage->encrypt($collection);
        // Reconstruir los indices YA existentes: un indice creado antes de cifrar
        // guardo el valor en claro como nombre de archivo del cubo (_idx/estado/
        // moroso.json). Cifrar los documentos no lo reescribe; ese texto seguiria
        // en el arbol de directorios para siempre. build() borra los cubos viejos
        // y los rehace con el nombre CON CLAVE. Cubre indices y reservas de unique.
        foreach ($this->index->fields($collection) as $field) {
            $this->index->build($collection, $field);
        }
        return $n;
    }

    public function isEncrypted(string $collection): bool
    {
        return $this->storage->isEncrypted($collection);
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
    private function applySchema(
        string $collection,
        string $id,
        array $data,
        ?array $before,
        bool $replace
    ): array {
        $esquema = new SchemaRules($this->storage->schemaOf($collection));
        if ($esquema->isEmpty()) {
            return [$data, $replace];
        }
        $entero = $replace || $before === null
            ? $data
            : $data + \array_diff_key($before, \array_flip(['_version', '_createdAt', '_updatedAt']));

        return [$esquema->aplicar($collection, $id, $entero), true];
    }

    /**
     * Comprueba que un documento cumpliria el esquema, sin escribir nada.
     *
     * Existe para que una transaccion pueda validar TODAS sus operaciones antes
     * de cruzar la marca de confirmacion. Sin esto, un valor invalido se
     * detectaba al aplicar —despues de la frontera— y dejaba el diario marcado
     * como bueno: la recuperacion lo reintentaba en cada apertura y volvia a
     * lanzar, con la base tapiada para siempre. Un dato malo no puede inutilizar
     * la base; tiene que rebotar mientras aun se puede abortar limpiamente, igual
     * que ya hace la reserva de unicidad.
     *
     * El documento se valida entero (como si fuera un `put` con replace), que es
     * exactamente como lo aplica Tx\Applier: el plan de la transaccion ya trae el
     * documento fusionado.
     *
     * @param array $data el documento completo que se va a escribir
     */
    public function checkSchema(string $collection, string $id, array $data): void
    {
        $esquema = new SchemaRules($this->storage->schemaOf($collection));
        if ($esquema->isEmpty()) {
            return;
        }
        $esquema->aplicar($collection, $id, $data);   // lanza si no cumple
    }
}
