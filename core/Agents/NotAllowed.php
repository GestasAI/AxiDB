<?php
/**
 * AxiDB - Agentes\NotAllowed: el agente pidio algo que no le corresponde.
 *
 * Tiene su propia clase para que quien orquesta agentes pueda distinguir "este
 * agente se ha salido de su sitio" de "el motor ha fallado". Lo primero es
 * informacion sobre el agente —quiza haya que reajustar su cometido, quiza
 * alguien esta intentando algo— y lo segundo es un problema del sistema. Meter
 * las dos cosas en la misma excepcion obliga a leer mensajes para decidir, y
 * eso siempre acaba mal.
 */

declare(strict_types=1);

namespace Axi\Core\Agents;

use Axi\Core\Exception;

final class NotAllowed extends Exception
{
}
