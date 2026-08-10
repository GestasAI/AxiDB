<?php
/**
 * AxiDB - Vector\Compaction: recoger el hueco que dejan las bajas.
 *
 * Borrar un vector no borra nada: se pone su id a ceros y su sitio queda muerto.
 * Cuando los muertos pasan de una quinta parte, se reescriben los tres archivos
 * dejando solo los vivos. Es el mismo criterio y el mismo motivo que en el
 * driver empaquetado, y por eso vive aparte igual que alli: compactar es
 * mantenimiento, no almacenamiento.
 *
 * Trabaja sobre temporales y renombra al final. Si se interrumpe a mitad, los
 * archivos viejos siguen enteros y no se pierde un vector.
 *
 * Ojo con los ordinales: despues de esto son OTROS. Quien tuviera una posicion
 * apuntada ha de olvidarla, y por eso Almacen tira su mapa de ids al terminar.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

final class Compaction
{
    public function __construct(
        private Files $archivos,
        private Ids $ids
    ) {
    }

    /**
     * Deja solo los vivos. Devuelve cuantas bajas se retiraron.
     *
     * Se llama con el cerrojo ya cogido: quien compacta no puede competir con
     * quien escribe.
     */
    public function execute(Manifest $m): int
    {
        if ($m->bajas === 0) {
            return 0;
        }
        $anchos = [
            'codigos'  => $m->codeWidth(),
            'vectores' => $m->floatWidth(),
            'ids'      => Manifest::ANCHO_ID,
        ];

        $crudos = [];
        $nuevos = [];
        foreach ($anchos as $cual => $ancho) {
            $crudos[$cual] = $this->archivos->readAll($cual);
            $nuevos[$cual] = '';
        }

        foreach (\array_keys($this->ids->alive()) as $ordinal) {
            foreach ($anchos as $cual => $ancho) {
                $nuevos[$cual] .= \substr($crudos[$cual], $ordinal * $ancho, $ancho);
            }
        }
        foreach (\array_keys($anchos) as $cual) {
            $this->archivos->writeAtomic($cual, $nuevos[$cual]);
        }

        $retiradas = $m->bajas;
        $m->cuenta = \intdiv(\strlen($nuevos['ids']), Manifest::ANCHO_ID);
        $m->bajas  = 0;

        $this->ids->forget();              // los ordinales han cambiado todos
        return $retiradas;
    }
}
