<?php
/**
 * AxiDB - Core\Planificador: decide si una consulta puede apoyarse en un indice.
 *
 * Es la unica pregunta que responde: dada la condicion de una consulta, ¿hay una
 * igualdad sobre un campo indexado que se cumpla SIEMPRE? Si la hay, la consulta
 * arranca de un puñado de documentos en vez de recorrer la coleccion.
 *
 * La palabra clave es "siempre". En un arbol de condiciones solo vale bajando
 * por ramas AND: dentro de un OR la condicion puede no cumplirse, y dentro de un
 * NOT se cumple justo al reves. En esos casos el indice no puede descartar nada
 * y toca escanear. Confundirse aqui no da lentitud: da resultados que faltan.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Planificador
{
    public function __construct(
        private Index $index,
        private string $collection
    ) {
    }

    /**
     * @param list<array{field:string, op:string, value:mixed}> $where condiciones sueltas
     * @param array|null $expr arbol de condiciones, si lo hay
     * @return array{0:string,1:string}|null [campo, valor] o null si toca escanear
     */
    public function igualdadIndexable(array $where, ?array $expr): ?array
    {
        foreach ($where as $c) {
            $encontrada = $this->indexable($c['field'], $c['op'], $c['value']);
            if ($encontrada !== null) {
                return $encontrada;
            }
        }
        return $expr === null ? null : $this->buscarEnAnd($expr);
    }

    private function buscarEnAnd(array $nodo): ?array
    {
        if (($nodo['type'] ?? '') === 'and') {
            return $this->buscarEnAnd($nodo['left']) ?? $this->buscarEnAnd($nodo['right']);
        }
        // Un cmp sin `field` compara una expresion —`MONTH(fecha) = 3`— y no un
        // campo. Ningun indice guarda el mes de una fecha, asi que ahi no hay
        // nada que aprovechar: se escanea, que es la respuesta correcta.
        if (($nodo['type'] ?? '') === 'cmp' && isset($nodo['field'])) {
            return $this->indexable((string) $nodo['field'], (string) $nodo['op'], $nodo['value'] ?? null);
        }
        return null;
    }

    private function indexable(string $field, string $op, mixed $value): ?array
    {
        if (\strtoupper($op) !== '=' || $value === null || \is_array($value) || \is_bool($value)) {
            return null;
        }
        if (!$this->index->isIndexed($this->collection, $field)) {
            return null;
        }
        return [$field, (string) $value];
    }
}
