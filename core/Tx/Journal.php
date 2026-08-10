<?php
/**
 * AxiDB - Tx\Journal: lo que se va a hacer, escrito antes de hacerlo.
 *
 * Una transaccion no puede ser atomica sobre varios archivos si se escribe
 * directamente: siempre hay un instante en el que van dos de cinco. Lo que si
 * se puede hacer atomico es UNA cosa —crear un archivo— y apoyarlo todo en ella.
 *
 * Por eso hay una marca de confirmacion. El diario se escribe entero primero, y
 * solo cuando esta completo y en el disco aparece `_hecho`. Ese archivo es la
 * frontera:
 *
 *   no esta   la transaccion no ocurrio. Se tira el diario.
 *   esta      la transaccion ocurrio. Se termina de aplicar, aunque el proceso
 *             haya muerto con la mitad escrita.
 *
 * Aplicar dos veces la misma operacion deja el mismo resultado —se escribe el
 * documento entero, no un incremento—, asi que repetir la aplicacion tras un
 * corte es seguro. Ahi esta la gracia: no hace falta saber por donde se quedo.
 */

declare(strict_types=1);

namespace Axi\Core\Tx;

use Axi\Core\Exception;

final class Journal
{
    public const CARPETA = '_tx';

    private const PLAN  = 'plan.json';
    private const HECHO = '_hecho';

    private string $dir;

    public function __construct(private string $base, private string $id)
    {
        $this->dir = $base . '/' . self::CARPETA . '/' . $id;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Deja el plan escrito y en el disco. Todavia no confirma nada.
     *
     * El fsync no es opcional aqui, cueste lo que cueste: si el plan se queda en
     * la cache del sistema y la marca de confirmacion llega antes al disco, un
     * corte dejaria una transaccion confirmada cuyo contenido no existe. Ese es
     * el unico estado del que no se puede salir.
     *
     * @param list<array{coleccion:string, id:string, accion:string, datos?:array}> $operaciones
     */
    public function record(array $operaciones): void
    {
        if (!\is_dir($this->dir) && !@\mkdir($this->dir, 0755, true) && !\is_dir($this->dir)) {
            throw new Exception("Tx: could not create the journal in {$this->dir}.");
        }
        $json = \json_encode(
            ['version' => 1, 'operaciones' => $operaciones],
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new Exception('Tx: could not serialise the plan: ' . \json_last_error_msg());
        }

        $path = $this->dir . '/' . self::PLAN;
        $fp   = @\fopen($path, 'wb');
        if (!$fp || \fwrite($fp, $json) !== \strlen($json)) {
            if ($fp) {
                \fclose($fp);
            }
            throw new Exception("Tx: could not write the plan to {$path}.");
        }
        \fflush($fp);
        @\fsync($fp);
        \fclose($fp);
    }

    /**
     * La frontera. A partir de aqui la transaccion ocurrio, aunque todavia no
     * se haya escrito ni un documento.
     */
    public function confirmar(): void
    {
        $path = $this->dir . '/' . self::HECHO;
        $fp   = @\fopen($path, 'wb');
        if (!$fp) {
            throw new Exception("Tx: could not commit at {$path}.");
        }
        \fflush($fp);
        @\fsync($fp);
        \fclose($fp);
    }

    public function isCommitted(): bool
    {
        return \is_file($this->dir . '/' . self::HECHO);
    }

    /**
     * Las operaciones anotadas. Lista vacia si el plan no se lee: un diario
     * ilegible se trata como no confirmado, que es el lado seguro.
     *
     * @return list<array{coleccion:string, id:string, accion:string, datos?:array}>
     */
    public function operaciones(): array
    {
        $json = \json_decode((string) @\file_get_contents($this->dir . '/' . self::PLAN), true);
        $ops  = \is_array($json) ? ($json['operaciones'] ?? []) : [];
        return \is_array($ops) ? \array_values(\array_filter($ops, 'is_array')) : [];
    }

    /** Se llama cuando ya no hace falta: aplicado del todo, o descartado. */
    public function delete(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\unlink($this->dir . '/' . self::HECHO);
        @\rmdir($this->dir);
    }

    /**
     * Aparta un diario que no se ha podido aplicar, en vez de borrarlo.
     *
     * La recuperacion lo usa cuando un diario confirmado revienta al aplicarse:
     * moverlo a `_tx/fallidos/` deja la base abrirse en vez de quedar tapiada, y
     * conserva el plan para poder mirar que paso. Si se borrara sin mas, se
     * perderia la unica pista de una transaccion que se dio por buena y no se
     * pudo completar.
     */
    public function quarantine(): void
    {
        $fallidos = $this->base . '/' . self::CARPETA . '/fallidos';
        @\mkdir($fallidos, 0755, true);
        $destino = $fallidos . '/' . $this->id;
        if (!@\rename($this->dir, $destino)) {
            $this->delete();                // si no se puede apartar, al menos no bloquea
        }
    }

    /**
     * Los diarios que hay sin terminar. Cada uno es una transaccion que se
     * quedo a medias porque el proceso murio.
     *
     * @return list<self>
     */
    public static function pendientes(string $base): array
    {
        $raiz = $base . '/' . self::CARPETA;
        if (!\is_dir($raiz)) {
            return [];
        }
        $fuera = [];
        foreach (\scandir($raiz) ?: [] as $entrada) {
            // 'fallidos' guarda los diarios apartados por la recuperacion: no es
            // una transaccion pendiente, y volver a intentarlo seria el bucle que
            // este cambio existe para romper.
            if ($entrada === '.' || $entrada === '..' || $entrada === 'fallidos') {
                continue;
            }
            if (\is_dir($raiz . '/' . $entrada)) {
                $fuera[] = new self($base, $entrada);
            }
        }
        return $fuera;
    }
}
