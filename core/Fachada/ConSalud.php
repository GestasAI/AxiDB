<?php
/**
 * AxiDB - Fachada\ConSalud: saber que esta pasando, desde Db.
 *
 *   $db->describe('clientes');    que campos hay y en cuantos documentos
 *   $db->stats('clientes'); tamaño, driver, indices, espacio muerto
 *   $db->checkup();               un vistazo a todo, con avisos
 */

declare(strict_types=1);

namespace Axi\Core\Fachada;

use Axi\Core\Salud;
use Axi\Core\Sql\Estructura;

trait ConSalud
{
    /**
     * Que campos tienen los documentos de una coleccion, con que tipo y en
     * cuantos de cuantos.
     *
     * Lo mismo que `DESCRIBE` en AxiSQL. Como aqui no hay esquema obligatorio,
     * esto no es una declaracion sino una foto de lo que hay: por eso dice en
     * cuantos documentos aparece cada campo. Que `telefono` este en 3 de 900
     * vale mas que saber que existe.
     *
     * @return list<array{campo:string, tipo:string, declarado:?string, documentos:int, de:int}>
     */
    public function describe(string $collection): array
    {
        return (new Estructura($this))->ejecutar(['type' => 'describe', 'collection' => $collection]);
    }

    /**
     * Tamaño, formato, indices y espacio muerto de una coleccion.
     *
     * @return array{coleccion:string, documentos:int, driver:string, durabilidad:string,
     *               cifrada:bool, caducidad:int, unicos:list<string>, indices:list<string>,
     *               vectores:bool, bytes:int, proporcionMuerta:float}
     */
    public function stats(string $collection): array
    {
        return (new Salud($this))->stats($collection);
    }

    /**
     * Un vistazo a la base entera, para un cron o un panel.
     *
     * Cada aviso dice QUE pasa y QUE HACER. Un diagnostico que solo dice que
     * hay un problema no sirve de nada a las tres de la mañana.
     *
     * @return array{colecciones:int, documentos:int, bytes:int, avisos:list<array>}
     */
    public function checkup(): array
    {
        return (new Salud($this))->checkup();
    }
}
