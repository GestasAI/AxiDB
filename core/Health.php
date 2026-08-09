<?php
/**
 * AxiDB - Core\Health: que esta pasando ahi dentro.
 *
 * Lo que hace falta para poner esto en produccion y no enterarse de los
 * problemas por un cliente. Tres preguntas, en tres niveles:
 *
 *   describir()     que forma tienen los documentos de una coleccion
 *   estadisticas()  cuanto ocupa, cuanto sobra, como estan sus indices
 *   revision()      un vistazo a la base entera, con avisos
 *
 * `revision()` esta pensada para un cron o un panel: devuelve una lista de
 * avisos, cada uno con su gravedad y con QUE HACER. Un diagnostico que dice
 * "hay un problema" y no dice cual ni como se arregla no sirve de nada a las
 * tres de la mañana.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Health
{
    /** Por encima de esta proporcion de espacio muerto, conviene compactar. */
    private const BASURA_AVISO = 0.25;

    public function __construct(private Db $db)
    {
    }

    /**
     * Todo lo que se sabe de una coleccion sin abrir sus documentos uno a uno.
     *
     * @return array{coleccion:string, documentos:int, driver:string, durabilidad:string,
     *               cifrada:bool, caducidad:int, unicos:list<string>, indices:list<string>,
     *               vectores:bool, bytes:int, proporcionMuerta:float}
     */
    public function stats(string $coleccion): array
    {
        $almacen = $this->db->storage();
        $dir     = $almacen->dir($coleccion);

        return [
            'coleccion'        => $coleccion,
            'documentos'       => $this->db->count($coleccion),
            'driver'           => $almacen->driverDe($coleccion),
            'durabilidad'      => $almacen->durabilidadDe($coleccion),
            'cifrada'          => $almacen->isEncrypted($coleccion),
            'caducidad'        => $almacen->caducidadDe($coleccion),
            'unicos'           => $this->db->uniques($coleccion),
            'indices'          => $this->db->indexes($coleccion),
            'vectores'         => \is_dir($dir . '/_vec'),
            'bytes'            => self::pesoDe($dir),
            'proporcionMuerta' => $almacen->proporcionMuerta($coleccion),
        ];
    }

    /**
     * Una revision de toda la base, con los avisos que salgan.
     *
     * @return array{colecciones:int, documentos:int, bytes:int, avisos:list<array>}
     */
    public function checkup(): array
    {
        $avisos      = [];
        $documentos  = 0;
        $bytes       = 0;
        $colecciones = $this->db->collections();

        foreach ($colecciones as $coleccion) {
            $e = $this->stats($coleccion);
            $documentos += $e['documentos'];
            $bytes      += $e['bytes'];

            $avisos = [...$avisos, ...$this->avisosDe($coleccion, $e)];
        }

        return [
            'colecciones' => \count($colecciones),
            'documentos'  => $documentos,
            'bytes'       => $bytes,
            'avisos'      => $avisos,
        ];
    }

    /** @return list<array{coleccion:string, gravedad:string, que:string, hacer:string}> */
    private function avisosDe(string $coleccion, array $e): array
    {
        $avisos = [];

        if ($e['proporcionMuerta'] > self::BASURA_AVISO) {
            $avisos[] = self::aviso($coleccion, 'atencion',
                'el ' . \round($e['proporcionMuerta'] * 100) . '% del archivo es espacio muerto',
                "compactar('{$coleccion}')");
        }

        foreach ($this->db->verifyIndexes($coleccion) as $campo => $revision) {
            if (!empty($revision['ilegible'])) {
                $avisos[] = self::aviso($coleccion, 'grave',
                    "el indice '{$campo}' es de una version anterior y no se puede mantener",
                    'borrarlo y volver a crearlo');
                continue;
            }
            if (($revision['faltan'] ?? 0) > 0) {
                $avisos[] = self::aviso($coleccion, 'grave',
                    "al indice de '{$campo}' le faltan {$revision['faltan']} documentos, "
                    . 'asi que by() no los encuentra',
                    "reindex('{$coleccion}')");
            }
            if (($revision['sobran'] ?? 0) > 0) {
                $avisos[] = self::aviso($coleccion, 'atencion',
                    "el indice de '{$campo}' tiene {$revision['sobran']} entradas sin documento; "
                    . 'si el campo es unico, esos valores estan bloqueados sin estarlo',
                    "reindex('{$coleccion}')");
            }
        }
        return $avisos;
    }

    /**
     * @return array{coleccion:string, gravedad:string, que:string, hacer:string}
     */
    private static function aviso(string $coleccion, string $gravedad, string $que, string $hacer): array
    {
        return ['coleccion' => $coleccion, 'gravedad' => $gravedad, 'que' => $que, 'hacer' => $hacer];
    }

    /** Lo que ocupa un directorio con todo lo que lleva dentro. */
    private static function pesoDe(string $dir): int
    {
        if (!\is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $archivo) {
            if ($archivo->isFile()) {
                $total += (int) $archivo->getSize();
            }
        }
        return $total;
    }
}
