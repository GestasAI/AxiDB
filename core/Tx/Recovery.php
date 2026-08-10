<?php
/**
 * AxiDB - Tx\Recovery: terminar lo que un corte dejo a medias.
 *
 * Se ejecuta al abrir la base, antes de que nadie lea nada. Mira los diarios
 * que quedaron y decide por la marca de confirmacion, sin preguntar a nadie:
 *
 *   con marca   la transaccion ocurrio -> se termina de aplicar
 *   sin marca   la transaccion no ocurrio -> se tira el diario
 *
 * No hay un tercer caso, y esa es toda la idea: la marca es un archivo, crear
 * un archivo es atomico, y por tanto el estado nunca es ambiguo.
 *
 * Se hace bajo el mismo cerrojo que las confirmaciones. Dos procesos que abren
 * la base a la vez tras un corte recuperarian el mismo diario los dos, y
 * aunque aplicar dos veces sea inofensivo, borrarlo dos veces mientras el otro
 * lo lee no lo es.
 */

declare(strict_types=1);

namespace Axi\Core\Tx;

use Axi\Core\Db;

final class Recovery
{
    /**
     * @return array{aplicadas:int, descartadas:int}
     */
    public static function alAbrir(Db $db): array
    {
        $base = $db->storage()->basePath();
        if (Journal::pendientes($base) === []) {
            return ['aplicadas' => 0, 'descartadas' => 0];   // el caso normal, sin cerrojo
        }

        return Lock::con($base, static function () use ($db, $base): array {
            $aplicadas = $descartadas = 0;

            foreach (Journal::pendientes($base) as $diario) {
                if (!$diario->isCommitted()) {
                    $descartadas++;
                    $diario->delete();
                    continue;
                }
                // Un diario confirmado deberia poder aplicarse siempre: lo que
                // podia fallar —esquema, unicidad— ya se comprobo antes de la
                // marca. Pero un diario de una version anterior a esa comprobacion,
                // o un archivo corrupto, podria reventar aqui. Si lo hace, se
                // aparta y la base sigue abriendo, en vez de quedar tapiada para
                // siempre reintentando lo imposible en cada arranque.
                try {
                    $aplicadas += Applier::aplicar($db, $diario->operaciones());
                    $diario->delete();
                } catch (\Throwable $e) {
                    $diario->quarantine();
                    $descartadas++;
                }
            }
            return ['aplicadas' => $aplicadas, 'descartadas' => $descartadas];
        });
    }
}
