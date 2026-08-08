<?php
/**
 * AxiDB - Sql\DdlParser: CREATE y DROP.
 *
 * Separado de Parser por tamaño y porque son gramaticas independientes: el DML
 * habla de documentos y el DDL de la forma de la coleccion. Comparte el mismo
 * TokenStream, asi que el cursor sigue siendo uno solo.
 *
 * Sentencias:
 *   CREATE COLLECTION <nombre>
 *   CREATE [UNIQUE] INDEX ON <coleccion> (<campo>)
 *   DROP COLLECTION <nombre>
 *   DROP INDEX ON <coleccion> (<campo>)
 *
 * TABLE se acepta como sinonimo de COLLECTION: quien viene de SQL lo escribe
 * sin pensar, y rechazarlo no aporta nada.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Exception;

final class DdlParser
{
    public function __construct(private TokenStream $ts)
    {
    }

    public function parse(): array
    {
        if ($this->ts->matchKw('CREATE')) {
            return $this->parseCreate();
        }
        $this->ts->consumeKw('DROP');
        return $this->parseDrop();
    }

    private function parseCreate(): array
    {
        $unico = $this->ts->matchKw('UNIQUE');

        if ($this->ts->matchKw('INDEX')) {
            [$coleccion, $campo] = $this->parseDestinoIndice();
            return [
                'type'       => 'create_index',
                'collection' => $coleccion,
                'field'      => $campo,
                'unique'     => $unico,
            ];
        }

        if ($unico) {
            throw new Exception('AxiSQL: UNIQUE solo tiene sentido en CREATE UNIQUE INDEX.');
        }

        if ($this->ts->matchAnyKw('COLLECTION', 'TABLE') !== null) {
            return ['type' => 'create_collection', 'collection' => $this->ts->consumeIdent()];
        }

        throw new Exception(
            "AxiSQL: tras CREATE esperaba COLLECTION o INDEX; encontro {$this->ts->peek()->describe()}."
        );
    }

    private function parseDrop(): array
    {
        if ($this->ts->matchKw('INDEX')) {
            [$coleccion, $campo] = $this->parseDestinoIndice();
            return ['type' => 'drop_index', 'collection' => $coleccion, 'field' => $campo];
        }

        if ($this->ts->matchAnyKw('COLLECTION', 'TABLE') !== null) {
            return ['type' => 'drop_collection', 'collection' => $this->ts->consumeIdent()];
        }

        throw new Exception(
            "AxiSQL: tras DROP esperaba COLLECTION o INDEX; encontro {$this->ts->peek()->describe()}."
        );
    }

    /** ON <coleccion> (<campo>) — comun a CREATE INDEX y DROP INDEX. */
    private function parseDestinoIndice(): array
    {
        $this->ts->consumeKw('ON');
        $coleccion = $this->ts->consumeIdent();
        $this->ts->consumePunct('(');
        $campo = $this->ts->consumeIdent();
        $this->ts->consumePunct(')');
        return [$coleccion, $campo];
    }
}
