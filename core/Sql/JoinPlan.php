<?php
/**
 * AxiDB - Sql\JoinPlan: juntar documentos de dos colecciones.
 *
 * Hash join: se recorre la coleccion de la derecha UNA vez y se mete en un mapa
 * por el campo de union; despues, cada documento de la izquierda busca el suyo
 * en ese mapa. Coste O(izquierda + derecha), no O(izquierda x derecha), que es
 * la diferencia entre que un cruce de mil por mil sean dos mil pasos o un millon.
 *
 * **La derecha entra entera en memoria.** Es el precio del hash join y hay que
 * decirlo: cruzar contra una coleccion de un millon de documentos carga un
 * millon de documentos. Para eso estan las colecciones pequeñas a la derecha.
 *
 * Como quedan los campos, que es lo que hay que tener claro al escribir la
 * consulta:
 *
 *   izquierda   tal cual, y ademas con su prefijo:  total  y  pedidos.total
 *   derecha     SOLO con su prefijo:                clientes.nombre
 *
 * Asi no hay ambiguedad posible. Si las dos tienen `nombre`, `nombre` es el de
 * la izquierda y el otro es `clientes.nombre`, siempre, sin reglas que recordar.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

final class JoinPlan
{
    public const INTERNO = 'inner';
    public const IZQUIERDO = 'left';

    /**
     * @param callable(string): list<array> $documentosDe de donde salen los
     *        documentos de la coleccion unida. Es una funcion y no un objeto
     *        para que sirvan tanto Db como Storage: al cruce le da igual quien
     *        se los de, y asi lo pueden usar el ejecutor de AxiSQL y Query sin
     *        que ninguno tenga que conocer al otro.
     * @param list<array> $izquierda documentos de la coleccion del FROM
     * @param list<array{coleccion:string, alias:string, tipo:string, izq:string, der:string}> $uniones
     * @return list<array> filas ya cruzadas
     */
    public static function aplicar(callable $documentosDe, array $izquierda, string $aliasIzq, array $uniones): array
    {
        $filas = \array_map(
            static fn(array $doc): array => self::withPrefix($doc, $aliasIzq) + $doc,
            $izquierda
        );

        foreach ($uniones as $union) {
            $filas = self::oneUnion($documentosDe, $filas, $union);
        }
        return $filas;
    }

    /**
     * @param list<array> $filas
     * @param array{coleccion:string, alias:string, tipo:string, izq:string, der:string} $union
     * @return list<array>
     */
    private static function oneUnion(callable $documentosDe, array $filas, array $union): array
    {
        $mapa = self::mapBy($documentosDe($union['coleccion']), $union['der']);
        $vacio = self::emptyOf($mapa, $union['alias']);

        $fuera = [];
        foreach ($filas as $fila) {
            $clave = self::keyOf($fila[$union['izq']] ?? null);
            $casan = $clave === null ? [] : ($mapa[$clave] ?? []);

            if ($casan === []) {
                // INNER descarta lo que no casa; LEFT lo conserva con la derecha
                // a nulos, que es justo la diferencia entre los dos.
                if ($union['tipo'] === self::IZQUIERDO) {
                    $fuera[] = $fila + $vacio;
                }
                continue;
            }
            foreach ($casan as $doc) {
                $fuera[] = $fila + self::withPrefix($doc, $union['alias']);
            }
        }
        return $fuera;
    }

    /**
     * @param list<array> $documentos
     * @return array<string, list<array>>
     */
    private static function mapBy(array $documentos, string $campo): array
    {
        // El campo de la derecha puede venir con prefijo —`c.id`— porque es como
        // se escribe en el ON. Aqui se busca en el documento, que no lo lleva.
        $campo = self::sinPrefijo($campo);
        $mapa  = [];

        foreach ($documentos as $doc) {
            $clave = self::keyOf($doc[$campo] ?? null);
            if ($clave !== null) {
                $mapa[$clave][] = $doc;
            }
        }
        return $mapa;
    }

    /**
     * Los campos de la derecha, a null, para las filas de un LEFT que no casan.
     *
     * Se sacan de un documento cualquiera del mapa: si la coleccion de la
     * derecha esta vacia no hay campos que poner a null, y tampoco hace falta.
     *
     * @param array<string, list<array>> $mapa
     */
    private static function emptyOf(array $mapa, string $alias): array
    {
        foreach ($mapa as $grupo) {
            $vacio = [];
            foreach (\array_keys($grupo[0]) as $campo) {
                $vacio[$alias . '.' . $campo] = null;
            }
            return $vacio;
        }
        return [];
    }

    /** @return array<string, mixed> los campos del documento con su prefijo delante */
    private static function withPrefix(array $doc, string $alias): array
    {
        $fuera = [];
        foreach ($doc as $campo => $valor) {
            $fuera[$alias . '.' . $campo] = $valor;
        }
        return $fuera;
    }

    public static function sinPrefijo(string $campo): string
    {
        $punto = \strrpos($campo, '.');
        return $punto === false ? $campo : \substr($campo, $punto + 1);
    }

    /**
     * La clave con la que se compara al unir.
     *
     * Se pasa a texto a proposito: el id 7 y el "7" que viene de un formulario
     * tienen que casar, igual que casan en un WHERE. Un null nunca casa con
     * nada, ni siquiera con otro null, que es lo que hace SQL y lo unico
     * sensato: "no se sabe" no es igual a "no se sabe".
     */
    private static function keyOf(mixed $valor): ?string
    {
        if ($valor === null || \is_array($valor)) {
            return null;
        }
        if (\is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }
        return (string) $valor;
    }
}
