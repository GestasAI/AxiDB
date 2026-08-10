<?php
/**
 * AxiDB - Vector\Precision: cuanto se busca antes de responder.
 *
 * La busqueda tiene dos pasadas: una criba binaria barata sobre todos los
 * vectores y un coseno exacto sobre los que sobreviven. Cuantos sobreviven es
 * LA perilla: mas candidatos es mas recall y mas tiempo, y no hay una respuesta
 * buena para todo el mundo.
 *
 * Los tres modos salen de medir, no de estimar. 10.000 vectores de 768
 * dimensiones, recall@10 contra el coseno exacto:
 *
 *                        embeddings reales   vectores aleatorios      ms
 *   rapida (200)               100%                  84%              37
 *   equilibrada (2000)         100%                 100%             108
 *   exacta (todos)             100%                 100%             732
 *
 * Con embeddings de verdad, `rapida` ya acierta todo: los textos parecidos caen
 * cerca y la criba no se deja nada. La columna del medio es el caso feo —ruido
 * uniforme, que no lo genera ningun modelo— y es la que justifica que existan
 * los otros dos modos.
 *
 * Por que no hay un modo int8. Se penso guardar los vectores tambien con un byte
 * por dimension, a medio camino entre el bit y el float32. Se descarto al medir:
 * subir candidatos sobre la criba que ya existe llega al mismo 100% sin un byte
 * de disco nuevo, y en PHP un producto escalar de 768 enteros por vector no se
 * puede resolver con una tabla de consulta como si se hace con el Hamming, asi
 * que habria salido mas lento ademas de mas grande.
 *
 * `exacta` no gana en la tabla, y aun asi tiene sentido: es la unica que NO
 * depende de como sean los datos. Las otras dos son buenas apuestas medidas;
 * esta es una garantia.
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

use Axi\Core\Exception;

final class Precision
{
    public const RAPIDA      = 'rapida';
    public const EQUILIBRADA = 'equilibrada';
    public const EXACTA      = 'exacta';

    public const MODOS = [self::RAPIDA, self::EQUILIBRADA, self::EXACTA];

    public const POR_DEFECTO = self::RAPIDA;

    /**
     * Candidatos por resultado pedido y suelo, para cada modo.
     *
     * El suelo importa mas que el factor: pedir 3 resultados con 60 candidatos
     * da mal recall aunque la proporcion parezca generosa, porque la criba se
     * equivoca en terminos absolutos, no relativos.
     */
    private const CUANTOS = [
        self::RAPIDA      => ['por' => 20,  'minimo' => 200],
        self::EQUILIBRADA => ['por' => 200, 'minimo' => 2000],
    ];

    public static function valida(string $modo): string
    {
        if (!\in_array($modo, self::MODOS, true)) {
            throw new Exception(
                "Vector: precision desconocida '{$modo}'. Admitidas: " . \implode(', ', self::MODOS) . '.'
            );
        }
        return $modo;
    }

    /**
     * Cuantos candidatos pasan a la segunda pasada.
     *
     * En `exacta` se devuelve null, que significa "todos, sin criba". No es un
     * numero muy grande: es que ahi no se hace la primera pasada.
     */
    public static function candidates(string $modo, int $k): ?int
    {
        if ($modo === self::EXACTA) {
            return null;
        }
        $c = self::CUANTOS[$modo];
        return \max($c['minimo'], $k * $c['por']);
    }
}
