<?php
/**
 * AxiDB - Core\InvalidName: el nombre que llega no vale.
 *
 * Se separa del resto de errores por una razon concreta: es el unico fallo del
 * nucleo que provoca siempre quien llama, nunca el motor. Eso permite que el
 * puente HTTP conteste 400 con el motivo —util para el que se ha equivocado— y
 * reserve el 500 mudo para lo que de verdad es un fallo interno.
 *
 * Sin esta distincion solo caben dos malas opciones: devolver todos los
 * mensajes internos hacia fuera, con las rutas del disco dentro, o devolver
 * "error" a secas y dejar sin pistas a quien escribio mal un identificador.
 */

declare(strict_types=1);

namespace Axi\Core;

final class InvalidName extends Exception
{
}
