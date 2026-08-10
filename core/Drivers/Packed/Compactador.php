<?php
/**
 * AxiDB - Packed\Compactador: recupera el espacio muerto del log.
 *
 * Como solo se añade al final, cada modificacion deja atras la version anterior
 * y cada borrado deja el documento entero. Eso es lo que hace rapida la
 * escritura, y el precio es que el archivo crece con el uso aunque el numero de
 * documentos no cambie.
 *
 * La compactacion reescribe el log dejando solo lo vivo. Es la unica operacion
 * del driver que no es un simple añadido, asi que se hace bajo el lock de
 * escritura y sobre un temporal: hasta el rename final, el log bueno sigue
 * siendo el de siempre. Si el proceso muere en medio, no se ha perdido nada;
 * solo queda un temporal que barre sweep().
 */

declare(strict_types=1);

namespace Axi\Core\Drivers\Packed;

use Axi\Core\Exception;
use Axi\Core\Meta;

final class Compactador
{
    /** Se compacta cuando el espacio muerto pasa de este porcentaje. */
    public const UMBRAL = 0.30;

    public function __construct(
        private Log $log,
        private Offsets $offsets
    ) {
    }

    /** Cuanto del archivo es espacio muerto, entre 0 y 1. */
    public function deadRatio(): float
    {
        $total = $this->log->size();
        if ($total === 0) {
            return 0.0;
        }
        $vivo = 0;
        foreach ($this->offsets->map() as [$desplazamiento, $longitud]) {
            $vivo += $longitud;
        }
        return \max(0.0, ($total - $vivo) / $total);
    }

    public function isNeeded(): bool
    {
        return $this->deadRatio() > self::UMBRAL;
    }

    /**
     * Reescribe el log con lo vivo. Devuelve los bytes recuperados.
     * Quien llama debe tener el lock de escritura de la coleccion.
     */
    public function compact(): int
    {
        $antes = $this->log->size();
        if ($antes === 0) {
            return 0;
        }

        $tmp = $this->log->path() . '.tmp.' . \bin2hex(\random_bytes(4));
        $fp  = @\fopen($tmp, 'wb');
        if (!$fp) {
            throw new Exception('Packed: could not create the compaction temporary file.');
        }

        $nuevoMapa = [];
        try {
            $desplazamiento = 0;
            // Se recorren los ids vivos y se lee cada uno por su desplazamiento
            // actual: asi se copia la ultima version de cada documento y nada mas.
            foreach ($this->offsets->map() as $id => [$desde, $largo]) {
                $doc = $this->log->readAt($desde, $largo);
                if ($doc === null) {
                    continue;                    // ilegible: no se arrastra
                }
                $linea = Meta::codificarPlano($doc) . "\n";
                if (\fwrite($fp, $linea) === false) {
                    throw new Exception('Packed: write failed during compaction.');
                }
                $nuevoMapa[$id] = [$desplazamiento, \strlen($linea)];
                $desplazamiento += \strlen($linea);
            }
            \fflush($fp);
            if (\function_exists('fsync')) {
                @\fsync($fp);
            }
        } catch (\Throwable $e) {
            \fclose($fp);
            @\unlink($tmp);
            throw $e;
        }
        \fclose($fp);

        /*
         * Hasta este reemplazo, el log bueno sigue siendo el viejo.
         *
         * Puede fallar legitimamente: en Windows no se renombra sobre un archivo
         * que otro proceso tenga abierto. Compactar es mantenimiento oportunista,
         * no correccion, asi que se deja todo como estaba y se informa de que no
         * se recupero nada. La proxima vez saldra.
         */
        try {
            $this->log->replaceWith($tmp);
        } catch (Exception) {
            @\unlink($tmp);
            return 0;
        }
        $this->offsets->rewrite($nuevoMapa);

        return \max(0, $antes - $this->log->size());
    }
}
