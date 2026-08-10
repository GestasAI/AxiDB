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

    public function existe(): bool
    {
        return $this->archivos->hay('manifiesto');
    }

    public function activar(Manifest $m): void
    {
        $this->archivos->crearDirectorio();
        $this->guardar($m);
    }

    public function desactivar(): void
    {
        $this->manifiesto = null;
        $this->archivos->borrar();
    }

    public function manifiesto(): Manifest
    {
        if ($this->manifiesto !== null) {
            return $this->manifiesto;
        }
        if (!$this->archivos->hay('manifiesto')) {
            throw new Exception('Vector: this collection does not have vectors enabled.');
        }
        $datos = \json_decode($this->archivos->leerTodo('manifiesto'), true);
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
        $m->cuenta = \intdiv($this->archivos->tamaño('ids'), Manifest::ANCHO_ID);

        return $this->manifiesto = $m;
    }

    public function olvidar(): void
    {
        $this->manifiesto = null;
    }

    /**
     * Añade o reemplaza el vector de un documento. Si ya tenia uno, el ordinal
     * viejo se da de baja: se escribe siempre al final, nunca en medio.
     *
     * @param list<float> $vector ya normalizado
     */
    public function poner(string $id, array $vector): int
    {
        /*
         * La dimension se comprueba aqui aunque quien llama ya lo haga: todo el
         * formato se apoya en que cada registro mida lo mismo. Un vector corto
         * descolocaria TODOS los de detras, y no se notaria hasta la siguiente
         * busqueda.
         */
        $vector = Quantizer::validar($vector, $this->manifiesto()->dims);
        $codigo = Quantizer::aBinario($vector);
        $floats = Quantizer::aFloat32($vector);

        return (int) $this->archivos->conCerrojo(function () use ($id, $codigo, $floats) {
            $this->olvidar();                       // releer dentro del cerrojo
            $m = $this->manifiesto();

            $anterior = $this->ordinalDe($id);
            if ($anterior !== null) {
                $this->marcarBaja($anterior, $m);
            }

            $ordinal = $m->cuenta;
            $this->archivos->escribirEn('codigos', $ordinal, $m->anchoCodigo(), $codigo);
            $this->archivos->escribirEn('vectores', $ordinal, $m->anchoFloat(), $floats);
            $this->ids->escribir($ordinal, $id);

            // El manifiesto solo se reescribe si cambiaron las bajas: la cuenta
            // se deduce del tamaño del archivo de ids.
            $m->cuenta++;
            if ($anterior !== null) {
                $this->guardar($m);
            }
            return $ordinal;
        });
    }

    /** True si habia algo que quitar. */
    public function quitar(string $id): bool
    {
        return (bool) $this->archivos->conCerrojo(function () use ($id) {
            $this->olvidar();
            $m       = $this->manifiesto();
            $ordinal = $this->ordinalDe($id);
            if ($ordinal === null) {
                return false;
            }
            $this->marcarBaja($ordinal, $m);
            $this->guardar($m);
            return true;
        });
    }

    /** Todos los codigos binarios pegados, tal y como los quiere la criba. */
    public function codigos(): string
    {
        return $this->archivos->leerTodo('codigos');
    }

    /** @return list<float>|null */
    public function vectorDe(int $ordinal): ?array
    {
        $bytes = $this->archivos->leerTrozo('vectores', $ordinal, $this->manifiesto()->anchoFloat());
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
    public function recorrerVectores(callable $fn): void
    {
        $m     = $this->manifiesto();
        $ancho = $m->anchoFloat();
        $fp    = @\fopen($this->archivos->ruta('vectores'), 'rb');
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
    public function idDe(int $ordinal): ?string
    {
        return $this->ids->de($ordinal);
    }

    /** @return array<int,true> ordinales vivos */
    public function vivos(): array
    {
        return $this->ids->vivos();
    }

    /** @return array<string,int> id => ordinal, sin las bajas */
    public function mapaIds(): array
    {
        return $this->ids->mapa();
    }

    public function ordinalDe(string $id): ?int
    {
        return $this->ids->ordinalDe($id);
    }

    /**
     * Retira las bajas reescribiendo los archivos. Devuelve cuantas eran.
     * El trabajo esta en Compactacion; aqui solo se le da el cerrojo.
     */
    public function compactar(): int
    {
        return (int) $this->archivos->conCerrojo(function () {
            $this->olvidar();
            $m = $this->manifiesto();
            $retiradas = (new Compaction($this->archivos, $this->ids))->ejecutar($m);
            if ($retiradas > 0) {
                $this->guardar($m);
            }
            return $retiradas;
        });
    }

    /* ─────────────────────────────── Interno ─────────────────────────────── */

    private function marcarBaja(int $ordinal, Manifest $m): void
    {
        $this->ids->darDeBaja($ordinal);
        $m->bajas++;
    }

    private function guardar(Manifest $m): void
    {
        $this->archivos->escribirAtomico(
            'manifiesto',
            (string) \json_encode($m->aArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->manifiesto = $m;
    }
}
