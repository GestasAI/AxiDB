<?php
/**
 * AxiDB - Vector\Store: los vectores en disco, con paso fijo.
 *
 *   _vec/manifiesto.json   una docena de campos, se lee en cada busqueda
 *   _vec/codigos.bin       N x (dims/8) bytes   los codigos binarios
 *   _vec/vectores.f32      N x dims x 4 bytes   los vectores de verdad
 *   _vec/ids.bin           N x 64 bytes         que documento es cada ordinal
 *
 * Todo con paso fijo: el vector numero k empieza SIEMPRE en k * ancho, asi que
 * se calcula la posicion y se lee. Esa es la razon de que los vectores no vayan
 * dentro del JSON del documento: alli habria que analizar 768 numeros por
 * documento en cada lectura, tambien cuando la consulta no va de vectores.
 *
 * Las bajas no borran nada: se pone el id a ceros y ese mismo archivo dice quien
 * esta vivo. De recoger el hueco se ocupa Compactacion.
 *
 * Un solo juego de archivos por coleccion, sin trocear. La especificacion
 * hablaba de trozos de 65.536 vectores; se deja para cuando alguien tenga esa
 * cantidad y pueda medirse.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

use Axi\Core\Exception;

final class Store
{
    private Files $archivos;
    private Ids $ids;
    private ?Manifest $manifiesto = null;

    public function __construct(string $dir)
    {
        $this->archivos = new Files($dir);
        $this->ids      = new Ids($this->archivos);
    }

    public function hasIndex(): bool
    {
        return $this->archivos->exists('manifiesto');
    }

    public function enable(Manifest $m): void
    {
        $this->archivos->makeDir();
        $this->persist($m);
    }

    public function disable(): void
    {
        $this->manifiesto = null;
        $this->archivos->delete();
    }

    public function manifest(): Manifest
    {
        if ($this->manifiesto !== null) {
            return $this->manifiesto;
        }
        if (!$this->archivos->exists('manifiesto')) {
            throw new Exception('Vector: this collection does not have vectors enabled.');
        }
        $datos = \json_decode($this->archivos->readAll('manifiesto'), true);
        if (!\is_array($datos)) {
            throw new Exception('Vector: the manifest is unreadable.');
        }
        $m = Manifest::desdeArray($datos);

        /*
         * Cuantos hay NO se lee del manifiesto: se deduce del tamaño del archivo
         * de ids, que son 64 bytes por registro.
         *
         * Asi no hay que reescribir el manifiesto en cada alta. Con 10.000
         * vectores eso eran 10.000 escrituras atomicas —temporal y renombrado—
         * de un archivo que solo cambiaba en un numero. Indexar bajo de 19,7 ms
         * por vector a menos de 1.
         */
        $m->cuenta = \intdiv($this->archivos->size('ids'), Manifest::ANCHO_ID);

        return $this->manifiesto = $m;
    }

    public function forget(): void
    {
        $this->manifiesto = null;
    }

    /**
     * Añade o reemplaza el vector de un documento. Si ya tenia uno, el ordinal
     * viejo se da de baja: se escribe siempre al final, nunca en medio.
     *
     * @param list<float> $vector ya normalizado
     */
    public function put(string $id, array $vector): int
    {
        /*
         * La dimension se comprueba aqui aunque quien llama ya lo haga: todo el
         * formato se apoya en que cada registro mida lo mismo. Un vector corto
         * descolocaria TODOS los de detras, y no se notaria hasta la siguiente
         * busqueda.
         */
        $vector = Quantizer::validar($vector, $this->manifest()->dims);
        $codigo = Quantizer::aBinario($vector);
        $floats = Quantizer::aFloat32($vector);

        return (int) $this->archivos->withLock(function () use ($id, $codigo, $floats) {
            $this->forget();                       // releer dentro del cerrojo
            $m = $this->manifest();

            $anterior = $this->ordinalOf($id);
            if ($anterior !== null) {
                $this->markDead($anterior, $m);
            }

            $ordinal = $m->cuenta;
            $this->archivos->writeAt('codigos', $ordinal, $m->codeWidth(), $codigo);
            $this->archivos->writeAt('vectores', $ordinal, $m->floatWidth(), $floats);
            $this->ids->writeTo($ordinal, $id);

            // El manifiesto solo se reescribe si cambiaron las bajas: la cuenta
            // se deduce del tamaño del archivo de ids.
            $m->cuenta++;
            if ($anterior !== null) {
                $this->persist($m);
            }
            return $ordinal;
        });
    }

    /** True si habia algo que quitar. */
    public function remove(string $id): bool
    {
        return (bool) $this->archivos->withLock(function () use ($id) {
            $this->forget();
            $m       = $this->manifest();
            $ordinal = $this->ordinalOf($id);
            if ($ordinal === null) {
                return false;
            }
            $this->markDead($ordinal, $m);
            $this->persist($m);
            return true;
        });
    }

    /** Todos los codigos binarios pegados, tal y como los quiere la criba. */
    public function codes(): string
    {
        return $this->archivos->readAll('codigos');
    }

    /** @return list<float>|null */
    public function vectorOf(int $ordinal): ?array
    {
        $bytes = $this->archivos->readChunk('vectores', $ordinal, $this->manifest()->floatWidth());
        return $bytes === '' ? null : Quantizer::desdeFloat32($bytes);
    }

    /**
     * Recorre todos los vectores con un unico descriptor abierto.
     *
     * Por memoria, no por velocidad: 50.000 vectores de 768 dimensiones en un
     * array de PHP son cientos de megabytes; de uno en uno, el pico es el de
     * un solo vector.
     *
     * @param callable(int, list<float>): void $fn recibe ordinal y vector
     */
    public function eachVector(callable $fn): void
    {
        $m     = $this->manifest();
        $ancho = $m->floatWidth();
        $fp    = @\fopen($this->archivos->pathOf('vectores'), 'rb');
        if (!$fp) {
            return;
        }
        try {
            for ($ordinal = 0; $ordinal < $m->cuenta; $ordinal++) {
                $bytes = (string) \fread($fp, $ancho);
                if (\strlen($bytes) !== $ancho) {
                    return;
                }
                $fn($ordinal, Quantizer::desdeFloat32($bytes));
            }
        } finally {
            \fclose($fp);
        }
    }

    /** El id de un ordinal, o null si esa posicion esta de baja. */
    public function idOf(int $ordinal): ?string
    {
        return $this->ids->of($ordinal);
    }

    /** @return array<int,true> ordinales vivos */
    public function alive(): array
    {
        return $this->ids->alive();
    }

    /** @return array<string,int> id => ordinal, sin las bajas */
    public function idMap(): array
    {
        return $this->ids->map();
    }

    public function ordinalOf(string $id): ?int
    {
        return $this->ids->ordinalOf($id);
    }

    /**
     * Retira las bajas reescribiendo los archivos. Devuelve cuantas eran.
     * El trabajo esta en Compactacion; aqui solo se le da el cerrojo.
     */
    public function compact(): int
    {
        return (int) $this->archivos->withLock(function () {
            $this->forget();
            $m = $this->manifest();
            $retiradas = (new Compaction($this->archivos, $this->ids))->execute($m);
            if ($retiradas > 0) {
                $this->persist($m);
            }
            return $retiradas;
        });
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    private function markDead(int $ordinal, Manifest $m): void
    {
        $this->ids->markDeleted($ordinal);
        $m->bajas++;
    }

    private function persist(Manifest $m): void
    {
        $this->archivos->writeAtomic(
            'manifiesto',
            (string) \json_encode($m->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->manifiesto = $m;
    }
}
