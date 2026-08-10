<?php
/**
 * AxiDB - Embedders\Hash: vectores sin modelo, sin red y sin sorpresas.
 *
 * No entiende de significado. Trocea el texto en palabras y en grupos de tres
 * letras, y reparte cada trozo por las dimensiones segun su hash. Dos textos que
 * comparten palabras salen cerca; dos textos sin nada en comun salen lejos.
 *
 * **No sirve para busqueda semantica de verdad**: "medico" y "doctor" no se
 * parecen en nada para este generador. Sirve para otras dos cosas, y las dos
 * importan:
 *
 * 1. Que la suite entera corra sin internet y sin instalar nada. Un proyecto
 *    cuyos tests necesitan una API ajena no es reproducible: falla el dia que
 *    esa API cambie, se caiga o cobre.
 * 2. Que se pueda probar el motor vectorial —almacenamiento, criba, recall,
 *    compactacion— sin mezclarlo con la calidad de un modelo.
 *
 * Es determinista: el mismo texto da siempre el mismo vector, aqui y en la CI.
 */

declare(strict_types=1);

namespace Axi\Core\Vector\Embedders;

use Axi\Core\Exception;
use Axi\Core\Vector\Embedder;

final class Hash implements Embedder
{
    public function __construct(private int $dims = 256)
    {
        if ($dims < 8 || $dims % 8 !== 0) {
            throw new Exception("Hash: dimensions must be a multiple of 8; got {$dims}.");
        }
    }

    public function vector(string $texto): array
    {
        $vector = \array_fill(0, $this->dims, 0.0);

        foreach ($this->trozos($texto) as $trozo => $veces) {
            // Dos hashes distintos por trozo: uno elige la dimension y otro el
            // signo. Sin el signo, todos los trozos sumarian en positivo y
            // cualquier par de textos largos acabaria pareciendose.
            $h    = \crc32($trozo);
            $pos  = $h % $this->dims;
            $sig  = (\crc32('s' . $trozo) & 1) === 0 ? 1.0 : -1.0;

            // Raiz del numero de repeticiones: que una palabra salga veinte
            // veces no la hace veinte veces mas importante.
            $vector[$pos] += $sig * \sqrt($veces);
        }
        return $vector;
    }

    public function dims(): int
    {
        return $this->dims;
    }

    public function nombre(): string
    {
        return 'hash:' . $this->dims;
    }

    public function esLocal(): bool
    {
        return true;
    }

    /**
     * Palabras y grupos de tres letras. Los grupos de tres hacen que "tomate" y
     * "tomates" se parezcan, que es lo minimo que se le pide a esto.
     *
     * Sin mbstring: esa extension no viene siempre con PHP y el nucleo solo se
     * permite `json`. Las minusculas y el troceado en caracteres se hacen con
     * PCRE en modo unicode, que es parte del propio PHP.
     *
     * @return array<string,int> trozo => cuantas veces aparece
     */
    private function trozos(string $texto): array
    {
        $texto = self::aMinusculas(\trim($texto));
        if ($texto === '') {
            return [];
        }
        $trozos = [];

        foreach (\preg_split('/[^\p{L}\p{N}]+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            $trozos['p:' . $palabra] = ($trozos['p:' . $palabra] ?? 0) + 1;

            // Caracteres, no bytes: cortar "ñ" por la mitad daria trozos que no
            // se repiten nunca y el vector perderia esa palabra.
            $letras = \preg_split('//u', $palabra, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $largo  = \count($letras);
            for ($i = 0; $i + 3 <= $largo; $i++) {
                $tri = $letras[$i] . $letras[$i + 1] . $letras[$i + 2];
                $trozos['t:' . $tri] = ($trozos['t:' . $tri] ?? 0) + 1;
            }
        }
        return $trozos;
    }

    /**
     * Minusculas y sin acentos.
     *
     * Dos cosas a la vez, y la segunda es una decision: 'árbol' y 'arbol' pasan
     * a ser la misma palabra. Se hace porque **la gente busca sin tildes**, y un
     * catalogo escrito con ellas no aparaceria nunca. Se pierde la distincion
     * entre pares como 'papa' y 'papá', que en una busqueda de texto libre
     * importa mucho menos que encontrar lo que se busca.
     *
     * Sin mbstring ni iconv: strtolower() solo sabe de ASCII, asi que las letras
     * acentuadas se traducen con una tabla. Cubre el castellano y los idiomas
     * latinos de al lado; lo que no este se queda igual y como mucho pierde una
     * coincidencia.
     */
    private static function aMinusculas(string $texto): string
    {
        static $tabla = [
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'À' => 'a', 'È' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u', 'Ç' => 'c',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
            'Â' => 'a', 'Ê' => 'e', 'Î' => 'i', 'Ô' => 'o', 'Û' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'Ä' => 'a', 'Ë' => 'e', 'Ï' => 'i', 'Ö' => 'o', 'Å' => 'a', 'Ã' => 'a', 'Õ' => 'o',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'å' => 'a', 'ã' => 'a', 'õ' => 'o',
        ];
        return \strtr(\strtolower($texto), $tabla);
    }
}
