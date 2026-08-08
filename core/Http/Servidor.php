<?php
/**
 * AxiDB - Http\Servidor: el ciclo completo de una peticion.
 *
 * Junta las piezas y, sobre todo, decide que se cuenta cuando algo falla:
 *
 *   nombre invalido, JSON roto, accion desconocida  -> 4xx con el motivo
 *   cualquier otra cosa                             -> 500 y ni una palabra
 *
 * Esa segunda linea es deliberada. Un mensaje de error del motor lleva dentro
 * rutas del disco y nombres de archivo; devolverlo al navegador le dibuja el
 * mapa del servidor a quien esta probando. El detalle va al registro de errores,
 * donde lo lee el que administra y nadie mas.
 */

declare(strict_types=1);

namespace Axi\Core\Http;

use Axi\Core\Db;
use Axi\Core\NombreInvalido;

final class Servidor
{
    private Cors $cors;
    private Permisos $permisos;
    private Puente $puente;

    /**
     * @param array $opciones origenes, tokens, token, publicas, soloLectura, abierto
     */
    public function __construct(Db $db, array $opciones = [])
    {
        $this->cors     = new Cors((array) ($opciones['origenes'] ?? []));
        $this->permisos = new Permisos($opciones);
        $this->puente   = new Puente($db, $this->permisos);
    }

    /** Resuelve la peticion sin tocar cabeceras: asi se puede probar entera. */
    public function responder(array $server, ?string $crudo = null): Respuesta
    {
        $origen    = isset($server['HTTP_ORIGIN']) ? (string) $server['HTTP_ORIGIN'] : null;
        $cabeceras = $this->cors->cabeceras($origen);

        try {
            $p = Peticion::desde($server, $crudo);

            // El navegador pregunta antes de enviar. Si el origen no vale, la
            // respuesta va sin cabeceras CORS y el propio navegador lo corta.
            if ($p->metodo === 'OPTIONS') {
                return Respuesta::bien(null, $cabeceras);
            }
            if ($p->metodo !== 'POST') {
                return Respuesta::mal(405, 'El puente solo admite POST.', $cabeceras + ['Allow' => 'POST, OPTIONS']);
            }
            if ($origen !== null && !$this->cors->permite($origen)) {
                return Respuesta::mal(403, 'Origen no permitido.', $cabeceras);
            }

            return $this->puente->atender($p)->conCabeceras($cabeceras);
        } catch (PeticionInvalida $e) {
            return Respuesta::mal($e->codigoHttp(), $e->getMessage(), $cabeceras);
        } catch (NombreInvalido $e) {
            return Respuesta::mal(400, $e->getMessage(), $cabeceras);
        } catch (\Throwable $e) {
            \error_log('AxiDB HTTP: ' . $e->getMessage());
            return Respuesta::mal(500, 'Error interno.', $cabeceras);
        }
    }

    /** Resuelve y escribe. Es lo que llama el endpoint. */
    public function atender(array $server, ?string $crudo = null): void
    {
        $this->responder($server, $crudo)->emitir();
    }
}
