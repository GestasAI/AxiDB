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
        if ($this->ts->matchKw('ALTER')) {
            return $this->parseAlter();
        }
        if ($this->ts->matchKw('SHOW')) {
            return $this->parseShow();
        }
        if ($this->ts->matchKw('DESCRIBE')) {
            return ['type' => 'describe', 'collection' => $this->ts->consumeIdent()];
        }
        $this->ts->consumeKw('DROP');
        return $this->parseDrop();
    }

    /**
     * SHOW COLLECTIONS | SHOW INDEXES ON <coleccion>
     */
    private function parseShow(): array
    {
        if ($this->ts->matchAnyKw('COLLECTIONS') !== null) {
            return ['type' => 'show_collections'];
        }
        if ($this->ts->matchKw('INDEXES')) {
            $this->ts->consumeKw('ON');
            return ['type' => 'show_indexes', 'collection' => $this->ts->consumeIdent()];
        }
        throw new Exception(
            "AxiSQL: tras SHOW esperaba COLLECTIONS o INDEXES; encontro {$this->ts->peek()->describe()}."
        );
    }

    /**
     * ALTER COLLECTION <c> RENAME TO <c2>
     * ALTER COLLECTION <c> ADD FIELD <campo>
     * ALTER COLLECTION <c> DROP FIELD <campo>
     * ALTER COLLECTION <c> RENAME FIELD <campo> TO <campo2>
     */
    private function parseAlter(): array
    {
        if ($this->ts->matchAnyKw('COLLECTION', 'TABLE') === null) {
            throw new Exception(
                "AxiSQL: tras ALTER esperaba COLLECTION; encontro {$this->ts->peek()->describe()}."
            );
        }
        $coleccion = $this->ts->consumeIdent();

        if ($this->ts->matchKw('RENAME')) {
            if ($this->ts->matchKw('FIELD')) {
                $campo = $this->ts->consumeIdent();
                $this->ts->consumeKw('TO');
                return ['type' => 'alter_rename_field', 'collection' => $coleccion,
                        'field' => $campo, 'to' => $this->ts->consumeIdent()];
            }
            $this->ts->consumeKw('TO');
            return ['type' => 'alter_rename', 'collection' => $coleccion,
                    'to' => $this->ts->consumeIdent()];
        }

        if ($this->ts->matchKw('ADD')) {
            $this->ts->matchKw('FIELD');
            $campo = $this->ts->consumeIdent();

            // El valor con el que se rellena en los documentos que ya hay.
            $defecto = $this->ts->peek()->isOp('=') ? $this->takeDefault() : null;
            return ['type' => 'alter_add_field', 'collection' => $coleccion,
                    'field' => $campo, 'default' => $defecto];
        }

        if ($this->ts->matchKw('DROP')) {
            $this->ts->matchKw('FIELD');
            return ['type' => 'alter_drop_field', 'collection' => $coleccion,
                    'field' => $this->ts->consumeIdent()];
        }

        throw new Exception(
            'AxiSQL: tras ALTER COLLECTION esperaba RENAME, ADD FIELD o DROP FIELD; '
            . "encontro {$this->ts->peek()->describe()}."
        );
    }

    private function takeDefault(): mixed
    {
        $this->ts->advance();
        return $this->ts->consumeLiteral();
    }

    private function parseCreate(): array
    {
        $unico = $this->ts->matchKw('UNIQUE');

        if ($this->ts->matchKw('INDEX')) {
            [$coleccion, $campo] = $this->parseIndexTarget();
            return [
                'type'       => 'create_index',
                'collection' => $coleccion,
                'field'      => $campo,
                'unique'     => $unico,
            ];
        }

        if ($unico) {
            throw new Exception('AxiSQL: UNIQUE only makes sense in CREATE UNIQUE INDEX.');
        }

        if ($this->ts->matchAnyKw('COLLECTION', 'TABLE') !== null) {
            return ['type' => 'create_collection', 'collection' => $this->ts->consumeIdent()];
        }

        throw new Exception(
            'AxiSQL: tras CREATE esperaba COLLECTION, INDEX o VIEW; '
            . "encontro {$this->ts->peek()->describe()}."
        );
    }

    private function parseDrop(): array
    {
        if ($this->ts->matchKw('INDEX')) {
            [$coleccion, $campo] = $this->parseIndexTarget();
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
    private function parseIndexTarget(): array
    {
        $this->ts->consumeKw('ON');
        $coleccion = $this->ts->consumeIdent();
        $this->ts->consumePunct('(');
        $campo = $this->ts->consumeIdent();
        $this->ts->consumePunct(')');
        return [$coleccion, $campo];
    }
}
