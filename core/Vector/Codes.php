<?php
/**
 * AxiDB - Vector\Codes: la criba rapida.
 *
 * Recibe todos los codigos binarios pegados en una sola cadena y devuelve los
 * ordinales mas parecidos a la consulta. Es el bucle mas caliente del motor
 * vectorial: se ejecuta sobre TODOS los vectores en cada busqueda.
 *
 * Tres decisiones, las tres medidas y no supuestas:
 *
 * 1. Los codigos viven en UNA cadena, no en un array de cadenas. Un array de
 *    50.000 elementos cuesta megabytes solo en cabeceras de PHP; una cadena de
 *    4,8 MB es 4,8 MB.
 *
 * 2. La distancia se calcula con XOR de cadenas —que PHP hace en C— y una tabla
 *    de popcount. XOR pone a 1 los bits en los que los dos codigos difieren, y
 *    contar esos unos es la distancia de Hamming.
 *
 * 3. La tabla es de 16 bits y se guarda como CADENA de 65.536 bytes, no como
 *    array. En array ocupa 22 MB; en cadena, 68 KB, y va igual de rapido. Con
 *    array no cabria en el presupuesto de memoria del motor.
 *
 * Medido con 10.000 vectores de 768 dimensiones:
 *   tabla de 8 bits en array   30,5 ms
 *   tabla de 16 bits en array  24,4 ms   pero +22 MB
 *   tabla de 16 bits en cadena 22,8 ms   y +68 KB   <- esta
 */

declare(strict_types=1);

namespace Axi\Core\Vector;

final class Codes
{
    /** 65.536 bytes: en la posicion w esta cuantos bits a 1 tiene w. */
    private static ?string $tabla = null;

    /**
     * Los ordinales mas cercanos a la consulta, de menor a mayor distancia.
     *
     * @param string    $blob     todos los codigos, uno detras de otro
     * @param string    $consulta el codigo de la consulta
     * @param int       $cuantos  cuantos ordinales devolver
     * @param bool[]    $vivos    ordinal => true. Si esta vacio, valen todos
     * @return list<int>
     */
    public static function masCercanos(
        string $blob,
        string $consulta,
        int $cuantos,
        array $vivos = []
    ): array {
        $tabla   = self::tabla();
        $ancho   = \strlen($consulta);
        $total   = $ancho > 0 ? \intdiv(\strlen($blob), $ancho) : 0;
        $palabras = \intdiv($ancho, 2);
        $filtrar = $vivos !== [];

        if ($total === 0 || $cuantos <= 0) {
            return [];
        }

        $distancias = [];
        for ($i = 0; $i < $total; $i++) {
            if ($filtrar && !isset($vivos[$i])) {
                continue;
            }
            $x = \substr($blob, $i * $ancho, $ancho) ^ $consulta;
            $d = 0;
            foreach (\unpack('v' . $palabras, $x) as $w) {
                $d += \ord($tabla[$w]);
            }
            $distancias[$i] = $d;
        }

        \asort($distancias);
        return \array_slice(\array_keys($distancias), 0, $cuantos);
    }

    /** Distancia de Hamming entre dos codigos. Para tests y diagnostico. */
    public static function distancia(string $a, string $b): int
    {
        $tabla = self::tabla();
        $x     = $a ^ $b;
        $d     = 0;
        foreach (\unpack('C*', $x) as $byte) {
            $d += \ord($tabla[$byte]);
        }
        return $d;
    }

    /**
     * La tabla se construye una vez por proceso y cuesta unos 2 ms. Hacerlo en
     * cada busqueda seria mas caro que la busqueda misma.
     */
    private static function tabla(): string
    {
        if (self::$tabla !== null) {
            return self::$tabla;
        }
        $ocho = [];
        for ($b = 0; $b < 256; $b++) {
            $n = 0;
            for ($v = $b; $v !== 0; $v >>= 1) {
                $n += $v & 1;
            }
            $ocho[$b] = $n;
        }
        $tabla = '';
        for ($w = 0; $w < 65536; $w++) {
            $tabla .= \chr($ocho[$w & 255] + $ocho[$w >> 8]);
        }
        return self::$tabla = $tabla;
    }
}
