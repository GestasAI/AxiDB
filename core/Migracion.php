<?php
/**
 * AxiDB - Core\Migracion: mover una coleccion de un driver a otro.
 *
 * Se separa de Storage porque no es una operacion de almacenamiento sino de
 * mantenimiento, y porque el orden de sus pasos es lo unico que la hace segura:
 *
 *   1. leer todo con el driver de origen
 *   2. escribirlo con el de destino
 *   3. solo entonces declarar el cambio y retirar lo viejo
 *
 * Si algo falla en 1 o 2, los datos originales siguen intactos y la coleccion
 * sigue leyendose con su driver de siempre. No hay ningun momento en que los
 * documentos esten solo a medio copiar y ya se lean del sitio nuevo.
 */

declare(strict_types=1);

namespace Axi\Core;

use Axi\Core\Drivers\Driver;

final class Migracion
{
    public function __construct(
        private Collections $colecciones,
        private Ajustes $ajustes
    ) {
    }

    /**
     * @param Driver $origen  driver con el que estan escritos los documentos
     * @param Driver $destino driver al que se pasan
     * @return int documentos migrados
     */
    public function mover(string $collection, Driver $origen, Driver $destino): int
    {
        if ($origen->nombre() === $destino->nombre()) {
            return 0;
        }

        $documentos = $origen->all($collection);

        foreach ($documentos as $doc) {
            // copiar() y no put(): put le subiria la version y le pondria la
            // fecha de ahora. Cambiar de formato no debe notarse en el dato.
            $destino->copiar($collection, (string) $doc['id'], $doc);
        }

        $this->ajustes->fijar($collection, ['driver' => $destino->nombre()]);
        $this->retirarRestosDe($origen->nombre(), $collection);

        return \count($documentos);
    }

    /**
     * Borra los archivos del driver que ya no se usa. Los indices no se tocan:
     * son independientes del formato y siguen siendo validos.
     */
    private function retirarRestosDe(string $origen, string $collection): void
    {
        $dir = $this->colecciones->path($collection);

        if ($origen === 'fs') {
            foreach (\glob($dir . '/*.json') ?: [] as $f) {
                if (!\str_starts_with(\basename($f), '_')) {
                    @\unlink($f);
                    @\unlink($f . '.lock');
                }
            }
            return;
        }
        // El cerrojo entra en la lista: es del formato empaquetado, no de la
        // coleccion. Dejarlo atras no rompe nada, pero deja un archivo que ya no
        // significa nada en un directorio que presume de explicarse solo.
        foreach (['data.axi', 'offsets.log', 'offsets.idx', '_write.lock'] as $f) {
            @\unlink($dir . '/' . $f);
        }
    }
}
