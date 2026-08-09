<?php
/**
 * AxiDB - Fachada\ConAgentes: los agentes y su rastro de Db.
 *
 * Db es la puerta de entrada a cuatro subsistemas -documentos, indices,
 * vectores y agentes- y con los cuatro dentro pasaba de 300 lineas. Cada uno
 * vive ahora en su propio archivo, y Db los junta.
 *
 * No cambia nada de puertas afuera: se sigue escribiendo `$db->agent(...)`.
 */

declare(strict_types=1);

namespace Axi\Core\Fachada;

use Axi\Core\Agentes;

trait ConAgentes
{
    /* ─────────────────────────────── Agentes ─────────────────────────────── */

    /**
     * Vista restringida para un programa autonomo: solo las operaciones y
     * colecciones que se le permitan, y todo lo que intente queda anotado.
     * Detalle en Agentes\Sandbox y en la guia 09.
     *
     * @param list<string>      $puede       operaciones permitidas
     * @param list<string>|null $colecciones null para no limitar
     */
    public function agent(string $nombre, array $puede, ?array $colecciones = null): Agentes\Agente
    {
        $this->profile()->exigir('agents', 'los agentes');
        return new Agentes\Agente(
            $nombre,
            $this,
            new Agentes\Sandbox($puede, $colecciones),
            $this->audit(),
            $this->storage->basePath() . '/_agentes'
        );
    }

    /** El registro de quien hizo que. */
    public function audit(): Agentes\Auditoria
    {
        return $this->auditoria ??= new Agentes\Auditoria(
            $this->storage->basePath() . '/_agentes/auditoria.log'
        );
    }
}
