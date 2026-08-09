<?php
/**
 * AxiDB - Fachada\ConTransacciones: todo o nada, desde Db.
 *
 *   $db->transaccion(function ($tx) {
 *       $tx->update('cuentas', 'a', ['saldo' => 470]);
 *       $tx->update('cuentas', 'b', ['saldo' => 530]);
 *   });
 *
 * Si la segunda linea falla, la primera tampoco ocurre. Sin transaccion, el
 * dinero salia de una cuenta y no llegaba a la otra, y no habia forma de
 * saberlo despues.
 */

declare(strict_types=1);

namespace Axi\Core\Fachada;

use Axi\Core\Tx\Confirmacion;
use Axi\Core\Tx\Recuperacion;
use Axi\Core\Tx\Transaccion;

trait ConTransacciones
{
    /** La transaccion abierta con BEGIN, si la hay. Ver abrir(). */
    private ?Transaccion $abierta = null;

    /**
     * Empieza una transaccion por pasos, para `BEGIN` de AxiSQL.
     *
     * La version con funcion —`transaccion()`— es preferible siempre que se
     * pueda: confirma o descarta sola, y no hay forma de olvidarse. Esta existe
     * porque una sentencia SQL no puede recibir una funcion.
     */
    public function abrir(): void
    {
        if ($this->abierta !== null) {
            throw new \Axi\Core\Exception('Tx: ya hay una transaccion abierta. AxiDB no las anida.');
        }
        $this->abierta = new Transaccion($this);
    }

    /** Confirma la abierta con BEGIN. @return int operaciones aplicadas */
    public function cerrar(): int
    {
        $tx = $this->exigirAbierta('COMMIT');
        $this->abierta = null;                  // fuera antes de aplicar: al
                                                // aplicar se escribe de verdad,
                                                // y no debe volver al buffer
        return (new Confirmacion($this))->confirmar($tx);
    }

    /** Descarta la abierta con BEGIN. Nada de lo acumulado llega al disco. */
    public function descartar(): int
    {
        $tx = $this->exigirAbierta('ROLLBACK');
        $this->abierta = null;
        return \count($tx->operaciones());
    }

    /** La transaccion abierta, o null. El ejecutor de AxiSQL escribe en ella. */
    public function abierta(): ?Transaccion
    {
        return $this->abierta;
    }

    private function exigirAbierta(string $que): Transaccion
    {
        if ($this->abierta === null) {
            throw new \Axi\Core\Exception("Tx: {$que} sin una transaccion abierta. Falta BEGIN.");
        }
        return $this->abierta;
    }

    /**
     * Ejecuta la funcion y confirma al terminar. Si lanza, no se escribe nada.
     *
     * Lo que da: **atomicidad**. Ante un error, ante una excepcion tuya y ante
     * un corte de corriente, el resultado es todo o nada.
     *
     * Lo que NO da: **aislamiento**. Mientras se aplican los cambios —unos
     * milisegundos— otro proceso que lea puede ver la mitad. Lo que si se evita
     * es la actualizacion perdida: si alguien toca por debajo un documento que
     * la transaccion habia leido, se aborta con un error en vez de escribir
     * encima.
     *
     * @template T
     * @param callable(Transaccion): T $tarea
     * @return T lo que devuelva la funcion
     */
    public function transaccion(callable $tarea): mixed
    {
        $this->abrir();
        $tx = $this->abierta;

        try {
            $resultado = $tarea($tx);           // si lanza, no se confirma nada
        } catch (\Throwable $e) {
            $this->abierta = null;
            throw $e;
        }

        $this->cerrar();
        return $resultado;
    }

    /**
     * Termina o descarta las transacciones que un corte dejo a medias.
     *
     * Se llama sola al abrir la base. Esta aqui como metodo publico para poder
     * lanzarla a mano y para que un test pueda comprobar que hizo.
     *
     * @return array{aplicadas:int, descartadas:int}
     */
    public function recuperar(): array
    {
        return Recuperacion::alAbrir($this);
    }
}
