<?php
/**
 * AxiDB - Sql\Escritura: ejecuta INSERT, UPDATE y DELETE.
 *
 * Aparte del ejecutor porque escribir tiene sus propias reglas —a donde va cada
 * documento cuando hay una transaccion abierta, que id lleva, que expresiones
 * hay que calcular por documento— y porque juntos pasaban del limite de tamaño.
 *
 * Todo pasa por Db, nunca por Storage: asi los indices, la unicidad, el esquema
 * y los vectores se mantienen igual que si se hubiera escrito a mano. AxiSQL no
 * es una puerta trasera al almacen.
 */

declare(strict_types=1);

namespace Axi\Core\Sql;

use Axi\Core\Db;

final class Escritura
{
    public function __construct(private Db $db, private Sesion $sesion)
    {
    }

    public function ejecutar(array $ast, bool $explicar): mixed
    {
        return match ($ast['type']) {
            'insert' => $this->insert($ast, $explicar),
            'update' => $this->update($ast, $explicar),
            default  => $this->delete($ast, $explicar),
        };
    }

    /**
     * El plan de un EXPLAIN, con la MISMA forma que el del ejecutor.
     *
     * Dos formas distintas para lo mismo darian una respuesta segun quien
     * contestara, y EXPLAIN existe justamente para poder fiarse de lo que
     * cuenta. Por eso esto es una copia exacta y no una version parecida.
     */
    private function plan(string $operacion, string $coleccion, array $extra): array
    {
        return \array_merge([
            'explain'    => true,
            'operacion'  => $operacion,
            'coleccion'  => $coleccion,
        ], $extra);
    }

    /* ─────────────────────────────── Escritura ─────────────────────────────── */

    private function insert(array $ast, bool $explicar): mixed
    {
        $filas = $ast['rows'] ?? [$ast['data']];

        if ($explicar) {
            return $this->plan('insert', $ast['collection'], ['documentos' => \count($filas)]);
        }
        $destino = $this->sesion->destino();

        // Una fila sola devuelve el documento, como hasta ahora. Varias devuelven
        // el recuento: encadenar cinco documentos enteros en la respuesta de un
        // INSERT masivo no le sirve a nadie.
        if (\count($filas) === 1 && empty($ast['upsert'])) {
            return $this->unaFila($destino, $ast['collection'], $filas[0]);
        }

        $puestos = 0;
        foreach ($filas as $fila) {
            if (!empty($ast['upsert']) && isset($fila['id'])) {
                $destino->put($ast['collection'], (string) $fila['id'], $fila);
            } else {
                $this->unaFila($destino, $ast['collection'], $fila);
            }
            $puestos++;
        }
        return ['inserted' => $puestos];
    }

    /**
     * Un INSERT que trae la columna `id` usa ese id.
     *
     * Antes se le generaba uno aleatorio y el que se habia escrito se guardaba
     * como un campo mas, asi que `INSERT INTO p (id, ...) VALUES ('fijo', ...)`
     * creaba un documento con otro id y nadie avisaba. Ademas la version con
     * ON DUPLICATE UPDATE si lo respetaba: el mismo SQL hacia dos cosas
     * distintas segun como acabara la frase.
     */
    private function unaFila(Db|\Axi\Core\Tx\Transaccion $destino, string $coleccion, array $fila): array
    {
        $id = isset($fila['id']) && $fila['id'] !== '' ? (string) $fila['id'] : null;
        unset($fila['id']);

        return $destino->insert($coleccion, $fila, $id);
    }

    private function update(array $ast, bool $explicar): mixed
    {
        $afectados = $this->afectados($ast);
        if ($explicar) {
            return $this->plan('update', $ast['collection'], [
                'documentos' => \count($afectados),
                'campos'     => \array_keys($ast['set']),
            ]);
        }
        $n = 0;
        $destino = $this->sesion->destino();
        foreach ($afectados as $doc) {
            /*
             * Las expresiones se calculan sobre el documento QUE HAY, uno a uno.
             * Por eso `SET visitas = visitas + 1` suma uno al valor de cada
             * documento y no al del primero: si se resolviera una sola vez
             * antes del bucle, todos acabarian con el mismo numero.
             */
            $cambios = $ast['set'];
            foreach ($ast['set_expr'] ?? [] as $campo => $expr) {
                $cambios[$campo] = Valor::de($expr, $doc);
            }
            $destino->put($ast['collection'], (string) $doc['id'], $cambios);
            $n++;
        }
        return ['updated' => $n];
    }

    private function delete(array $ast, bool $explicar): mixed
    {
        $afectados = $this->afectados($ast);
        if ($explicar) {
            return $this->plan('delete', $ast['collection'], ['documentos' => \count($afectados)]);
        }
        $n = 0;
        $destino = $this->sesion->destino();
        foreach ($afectados as $doc) {
            if ($destino->delete($ast['collection'], (string) $doc['id'])) {
                $n++;
            }
        }
        return ['deleted' => $n];
    }

    /**
     * Documentos que toca un UPDATE o un DELETE. Se resuelven ANTES de escribir:
     * modificar mientras se recorre el listado deja resultados impredecibles.
     */
    private function afectados(array $ast): array
    {
        $q = $this->db->find($ast['collection'])->whereExpr($ast['where_expr']);

        // `UPDATE ... LIMIT 10` y `DELETE ... LIMIT 10`: tocar unos pocos y no
        // la coleccion entera. Sirve para ir por tandas sin bloquearlo todo.
        if (($ast['limit'] ?? null) !== null) {
            $q->limit($ast['limit']);
        }
        return $q->get();
    }
}
