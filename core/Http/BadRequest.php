<?php
/**
 * AxiDB - Http\BadRequest: la peticion viene mal, y se sabe por que.
 *
 * Existe para separar dos cosas que no deben responder igual: que el cliente
 * mande algo incorrecto —culpa suya, se le dice con un 4xx y su motivo— y que
 * el motor falle —culpa nuestra, 500 y ni una palabra de mas hacia fuera—.
 *
 * Sin esta distincion todo acaba siendo 500, que es la respuesta mas inutil
 * posible: el que llama no sabe si arreglar su peticion o avisar al de sistemas.
 */

declare(strict_types=1);

namespace Axi\Core\Http;

use Axi\Core\Exception;

final class BadRequest extends Exception
{
    public function __construct(string $mensaje, private int $codigoHttp = 400)
    {
        parent::__construct($mensaje);
    }

    public function httpCode(): int
    {
        return $this->codigoHttp;
    }
}
