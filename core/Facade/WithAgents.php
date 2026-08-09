<?php
/**
 * AxiDB - Fachada\WithAgents: los agentes y su rastro de Db.
 *
 * Db es la puerta de entrada a cuatro subsistemas -documentos, indices,
 * vectores y agentes- y con los cuatro dentro pasaba de 300 lineas. Cada uno
 * vive ahora en su propio archivo, y Db los junta.
 *
 * No cambia nada de puertas afuera: se sigue escribiendo `$db->agent(...)`.
 */

declare(strict_types=1);

namespace Axi\Core\Facade;

use Axi\Core\Agents;

trait WithAgents
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
    public function agent(string $nombre, array $puede, ?array $colecciones = null): Agents\Agent
    {
        $this->profile()->exigir('agents', 'los agentes');
        return new Agents\Agent(
            $nombre,
            $this,
            new Agents\Sandbox($puede, $colecciones),
            $this->audit(),
            $this->storage->basePath() . '/_agentes'
        );
    }

    /** El registro de quien hizo que. */
    public function audit(): Agents\Audit
    {
        return $this->auditoria ??= new Agents\Audit(
            $this->storage->basePath() . '/_agentes/auditoria.log'
        );
    }
}
