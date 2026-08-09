<?php
/**
 * AxiDB - Sql\Sesion: el estado que sobrevive entre dos sentencias.
 *
 * Casi todo AxiSQL es sin memoria: una sentencia entra, se ejecuta y se acaba.
 * `BEGIN` rompe eso —lo que venga detras se acumula hasta `COMMIT`— y ese es el
 * unico estado que hay. Vive aqui y no en el ejecutor para que el ejecutor
 * siga siendo lo que dice ser: un despachador sin memoria.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Db;
use Axi\Core\Evaluator;
use Axi\Core\Exception;
use Axi\Core\Tx\Transaccion;

final class Sesion
{
    public function __construct(private Db $db)
    {
    }

    public function ejecutar(string $tipo): array
    {
        return match ($tipo) {
            'begin'    => $this->begin(),
            'commit'   => ['aplicadas'   => $this->db->cerrar()],
            'rollback' => ['descartadas' => $this->db->descartar()],
            default    => throw new Exception("AxiSQL: '{$tipo}' no es una orden de transaccion."),
        };
    }

    private function begin(): array
    {
        $this->db->abrir();
        return ['transaccion' => 'abierta'];
    }

    /**
     * A donde van las escrituras: a la transaccion abierta si la hay, y si no,
     * a la base.
     *
     * Funciona porque `Transaccion` tiene los mismos metodos con las mismas
     * firmas que `Db`. No es casualidad: es la razon de que se llamen igual.
     */
    public function destino(): Db|Transaccion
    {
        return $this->db->abierta() ?? $this->db;
    }

}
