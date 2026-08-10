<?php
/**
 * AxiDB - Sql\Structure: SHOW, DESCRIBE, ALTER y las vistas.
 *
 * Todo lo que habla de la FORMA de una coleccion y no de sus documentos. Vive
 * aparte del ejecutor porque son otro asunto y porque el ejecutor ya estaba en
 * su limite de tamaño.
 *
 * `DESCRIBE` merece una nota: AxiDB no tiene esquema obligatorio, asi que no
 * hay una lista de columnas que consultar. Lo que se hace es MIRAR los
 * documentos y contar que campos aparecen, con que tipo y en cuantos. Es una
 * foto de lo que hay, no una declaracion de lo que deberia haber, y por eso
 * ademas dice en cuantos documentos sale cada campo: en una coleccion sin
 * esquema, saber que `telefono` esta en 3 de 900 documentos vale mas que saber
 * que existe.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Db;
use Axi\Core\Exception;

final class Structure
{
    /**
     * Donde se guardan las vistas: una coleccion normal con un nombre reservado.
     *
     * No empieza por guion bajo aunque seria lo natural, porque un nombre de
     * coleccion no puede empezar asi: los nombres con guion bajo delante estan
     * reservados para los archivos internos de una coleccion (`_idx`, `_vec`,
     * `_axidb.json`) y mezclarlos daria sitios con dos significados.
     */
    public const VISTAS = 'axidb_vistas';

    public function __construct(private Db $db)
    {
    }

    public function execute(array $ast): mixed
    {
        return match ($ast['type']) {
            'show_collections'   => $this->collectionsOf(),
            'show_indexes'       => $this->indexesOf($ast['collection']),
            'describe'           => $this->describe($ast['collection']),
            'create_view'        => $this->createView($ast),
            'alter_rename'       => $this->renameCollection($ast),
            'alter_add_field'    => $this->addField($ast),
            'alter_drop_field'   => $this->dropField($ast),
            'alter_rename_field' => $this->renameField($ast),
            default => throw new Exception("AxiSQL: '{$ast['type']}' no es una orden de estructura."),
        };
    }

    /** @return list<array{coleccion:string, documentos:int}> */
    private function collectionsOf(): array
    {
        $fuera = [];
        foreach ($this->db->collections() as $c) {
            if ($c === self::VISTAS) {
                continue;                       // las vistas no son datos del usuario
            }
            $fuera[] = ['coleccion' => $c, 'documentos' => $this->db->count($c)];
        }
        return $fuera;
    }

    /** @return list<array{campo:string, unico:bool, valores:int}> */
    private function indexesOf(string $coleccion): array
    {
        $unicos = $this->db->uniques($coleccion);
        $fuera  = [];
        foreach ($this->db->indexes($coleccion) as $campo) {
            $fuera[] = [
                'campo'   => $campo,
                'unico'   => \in_array($campo, $unicos, true),
                'valores' => \count($this->db->indexer()->buckets($coleccion, $campo)),
            ];
        }
        return $fuera;
    }

    /** @return list<array{campo:string, tipo:string, documentos:int, de:int}> */
    private function describe(string $coleccion): array
    {
        $documentos = $this->db->all($coleccion);
        $total      = \count($documentos);
        $esquema    = $this->db->schema($coleccion);
        $campos     = [];

        foreach ($documentos as $doc) {
            foreach ($doc as $campo => $valor) {
                $campos[$campo] ??= ['tipos' => [], 'cuantos' => 0];
                $campos[$campo]['cuantos']++;
                if ($valor !== null) {
                    $campos[$campo]['tipos'][self::typeOf($valor)] = true;
                }
            }
        }
        // Un campo declarado en el esquema sale aunque no lo tenga ni un documento:
        // esta declarado, y no verlo aqui haria dudar de si se guardo la regla.
        foreach ($esquema as $campo => $regla) {
            $campos[$campo] ??= ['tipos' => [$regla['tipo'] ?? 'cualquiera' => true], 'cuantos' => 0];
        }
        \ksort($campos, SORT_STRING);

        $fuera = [];
        foreach ($campos as $campo => $visto) {
            $fuera[] = [
                'campo'      => (string) $campo,
                'tipo'       => $visto['tipos'] === [] ? 'vacio' : \implode('|', \array_keys($visto['tipos'])),
                'declarado'  => isset($esquema[$campo]) ? ($esquema[$campo]['tipo'] ?? 'sin tipo') : null,
                'documentos' => $visto['cuantos'],
                'de'         => $total,
            ];
        }
        return $fuera;
    }

    private static function typeOf(mixed $v): string
    {
        return match (true) {
            \is_bool($v)   => 'bool',
            \is_int($v)    => 'entero',
            \is_float($v)  => 'decimal',
            \is_string($v) => 'texto',
            \is_array($v)  => \array_is_list($v) ? 'lista' : 'mapa',
            default        => 'otro',
        };
    }

    private function createView(array $ast): array
    {
        $nombre = (string) $ast['view'];

        // Una vista no puede llamarse como una coleccion que ya existe: taparia
        // sus datos y dejaria a SELECT y COUNT diciendo cosas distintas. Una
        // coleccion real siempre gana, y crear una vista que nunca se veria solo
        // sirve para confundir o para esconder algo.
        if (\in_array($nombre, $this->db->collections(), true) && $nombre !== self::VISTAS) {
            throw new Exception("AxiSQL: ya existe una coleccion llamada '{$nombre}'; una vista no puede taparla.");
        }

        $this->db->put(self::VISTAS, $nombre, [
            'sql'    => (string) $ast['sql'],
            'nombre' => $nombre,
        ], true);

        return ['view' => $ast['view'], 'sql' => $ast['sql']];
    }

    /** El SQL de una vista, o null si ese nombre no es una vista. */
    public static function sqlDeVista(Db $db, string $nombre): ?string
    {
        $doc = $db->get(self::VISTAS, $nombre);
        return $doc === null ? null : (string) ($doc['sql'] ?? '');
    }

    private function renameCollection(array $ast): array
    {
        // Renombrar una coleccion cualquiera ENCIMA del almacen de vistas era el
        // otro camino para envenenarlo: se llenaba una coleccion normal con filas
        // `sql: DROP...` y luego se renombraba a axidb_vistas. El destino no puede
        // ser el almacen de vistas, ni el origen (mover las vistas a otro sitio y
        // dejar un hueco tambien rompe la invariante).
        if ((string) $ast['to'] === self::VISTAS || $ast['collection'] === self::VISTAS) {
            throw new Exception("AxiSQL: '" . self::VISTAS . "' no se renombra: es el almacen de vistas.");
        }
        return ['renamed' => $this->db->renameCollection($ast['collection'], (string) $ast['to'])];
    }

    private function addField(array $ast): array
    {
        return ['updated' => $this->db->fieldOp(
            $ast['collection'],
            'añadir',
            (string) $ast['field'],
            $ast['default'] ?? null
        )];
    }

    private function dropField(array $ast): array
    {
        return ['updated' => $this->db->fieldOp($ast['collection'], 'quitar', (string) $ast['field'])];
    }

    private function renameField(array $ast): array
    {
        return ['updated' => $this->db->fieldOp(
            $ast['collection'],
            'renombrar',
            (string) $ast['field'],
            (string) $ast['to']
        )];
    }
}
