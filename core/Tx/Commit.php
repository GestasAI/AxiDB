<?php
/**
 * AxiDB - Tx\Commit: el orden de los pasos al confirmar.
 *
 * Todo lo interesante de una transaccion esta en la secuencia:
 *
 *   1. cerrojo de confirmacion    dos transacciones no se entrelazan
 *   2. comprobar versiones        nadie ha tocado por debajo lo que lei
 *   3. reservar valores unicos    si va a chocar, que choque ANTES de escribir
 *   4. escribir el diario         y bajarlo al disco con fsync
 *   5. MARCA DE CONFIRMACION      la frontera: a partir de aqui, ocurrio
 *   6. aplicar                    documento a documento
 *   7. borrar el diario
 *
 * Fallar en 2 o en 3 aborta sin haber escrito nada. Morir entre 5 y 7 no pierde
 * nada: al abrir, la recuperacion encuentra la marca y termina de aplicar.
 * Morir antes de 5 tampoco: no hay marca, se tira el diario y es como si la
 * transaccion no hubiera existido.
 *
 * El cerrojo serializa las confirmaciones, no las lecturas. Un lector que pase
 * durante el paso 6 puede ver la mitad de los cambios. Eso es aislamiento, y
 * esto no lo da: hace falta MVCC, que es otro motor. La ventana son los
 * milisegundos del paso 6, y se dice en vez de insinuar que no existe.
 */

declare(strict_types=1);

namespace Axi\Core\Tx;

use Axi\Core\Db;
use Axi\Core\Exception;
use Axi\Core\Unicidad;

final class Commit
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @return int operaciones aplicadas
     */
    public function confirmar(Transaccion $tx): int
    {
        if ($tx->vacia()) {
            return 0;
        }
        $base = $this->db->storage()->basePath();

        return Lock::con($base, function () use ($tx, $base): int {
            $this->exigirVersionesIntactas($tx->vistos());

            $reservas = $this->reservar($tx->operaciones());
            $diario   = new Journal($base, 'tx' . \bin2hex(\random_bytes(8)));

            try {
                $diario->anotar($tx->operaciones());
            } catch (\Throwable $e) {
                self::soltar($reservas);
                $diario->borrar();
                throw $e;
            }

            $diario->confirmar();                       // ── la frontera ──

            $hechas = Applier::aplicar($this->db, $diario->operaciones());
            $diario->borrar();
            return $hechas;
        });
    }

    /**
     * Nadie ha cambiado por debajo lo que la transaccion leyo.
     *
     * Sin esto: dos transacciones leen stock 10, cada una resta 3, las dos
     * escriben 7. Faltan tres unidades y no hay ningun error en ningun sitio.
     * Es el fallo mas facil de no ver de todos los que tiene la concurrencia.
     *
     * @param array<string, int|null> $vistos
     */
    private function exigirVersionesIntactas(array $vistos): void
    {
        foreach ($vistos as $clave => $version) {
            [$coleccion, $id] = \explode("\0", $clave, 2);

            $ahora = $this->db->get($coleccion, $id);
            $actual = $ahora === null ? null : (int) ($ahora['_version'] ?? 0);

            if ($actual !== $version) {
                throw new Exception(
                    "Tx: '{$coleccion}/{$id}' cambio mientras la transaccion estaba en curso "
                    . '(version ' . self::pinta($version) . ' -> ' . self::pinta($actual) . '). '
                    . 'No se ha escrito nada; vuelve a intentarlo leyendo de nuevo.'
                );
            }
        }
    }

    /**
     * Reserva los valores unicos de todo lo que se va a escribir.
     *
     * Aqui y no al aplicar: despues de la marca de confirmacion ya no se puede
     * fallar, porque la transaccion cuenta como ocurrida. Un choque de unicidad
     * tiene que aparecer mientras todavia se puede abortar limpiamente.
     *
     * @param list<array{coleccion:string, id:string, accion:string, datos:array}> $operaciones
     * @return list<Unicidad>
     */
    private function reservar(array $operaciones): array
    {
        $hechas = [];
        foreach ($operaciones as $op) {
            if ($op['accion'] !== 'poner') {
                continue;
            }
            $unicos = $this->db->storage()->unicosDe($op['coleccion']);
            if ($unicos === []) {
                continue;
            }
            $reserva = new Unicidad($this->db->indexer(), $op['coleccion'], $op['id']);
            try {
                $reserva->reservar($unicos, $op['datos'], $this->db->get($op['coleccion'], $op['id']));
            } catch (\Throwable $e) {
                self::soltar($hechas);
                throw $e;
            }
            $hechas[] = $reserva;
        }
        return $hechas;
    }

    /** @param list<Unicidad> $reservas */
    private static function soltar(array $reservas): void
    {
        foreach ($reservas as $r) {
            $r->soltar();
        }
    }

    private static function pinta(?int $v): string
    {
        return $v === null ? 'no existia' : (string) $v;
    }
}
