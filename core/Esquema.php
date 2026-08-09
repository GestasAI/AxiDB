<?php
/**
 * AxiDB - Core\Esquema: las reglas que un documento tiene que cumplir.
 *
 * Opcional y por coleccion. Una coleccion sin esquema admite cualquier cosa, que
 * es como funcionaba AxiDB hasta ahora y como sigue funcionando por defecto: el
 * esquema se pide, no se impone.
 *
 *   $db->defineSchema('clientes', [
 *       'correo' => ['tipo' => 'texto',  'obligatorio' => true],
 *       'edad'   => ['tipo' => 'entero'],
 *       'activo' => ['tipo' => 'bool',   'defecto' => true],
 *   ]);
 *
 * Un campo que no aparece en el esquema se guarda igual. Declarar tres campos no
 * cierra la puerta a los demas: eso seria otra cosa y se llamaria esquema
 * cerrado. Aqui se declara lo que importa y el resto pasa.
 *
 * Los tipos van en castellano y son pocos a proposito. Un vocabulario grande
 * obliga a recordar cual es el nombre exacto de cada cosa; con seis, se
 * adivinan.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Esquema
{
    public const TIPOS = ['texto', 'entero', 'decimal', 'numero', 'bool', 'lista', 'mapa'];

    /** @param array<string, array{tipo?:string, obligatorio?:bool, defecto?:mixed}> $reglas */
    public function __construct(private array $reglas)
    {
    }

    /**
     * Comprueba que las reglas tengan sentido, antes de guardarlas.
     *
     * Se valida al declarar y no al escribir: un esquema con un tipo mal escrito
     * no fallaria hasta la primera alta, y el error hablaria del documento en
     * vez de hablar de la declaracion, que es donde esta el problema.
     */
    public static function validarReglas(array $reglas): array
    {
        $limpias = [];
        foreach ($reglas as $campo => $regla) {
            $campo = Names::check((string) $campo, 'campo');
            $regla = \is_array($regla) ? $regla : ['tipo' => $regla];

            $tipo = isset($regla['tipo']) ? (string) $regla['tipo'] : null;
            if ($tipo !== null && !\in_array($tipo, self::TIPOS, true)) {
                throw new Exception(
                    "Esquema: el tipo '{$tipo}' de '{$campo}' no existe. Hay: "
                    . \implode(', ', self::TIPOS) . '.'
                );
            }
            $limpia = [];
            if ($tipo !== null) {
                $limpia['tipo'] = $tipo;
            }
            if (!empty($regla['obligatorio'])) {
                $limpia['obligatorio'] = true;
            }
            if (\array_key_exists('defecto', $regla)) {
                $limpia['defecto'] = $regla['defecto'];
                if ($tipo !== null && !self::esDelTipo($regla['defecto'], $tipo)) {
                    throw new Exception(
                        "Esquema: el valor por defecto de '{$campo}' no es un {$tipo}."
                    );
                }
            }
            $limpias[$campo] = $limpia;
        }
        return $limpias;
    }

    /**
     * Rellena los valores por defecto que falten y comprueba las reglas.
     *
     * Recibe el documento ENTERO tal y como va a quedar, no solo lo que cambia.
     * Con una actualizacion parcial hay que mirar el resultado: si no, quitar un
     * campo obligatorio pasaria desapercibido porque no viene en el cambio.
     *
     * @return array el documento con los valores por defecto puestos
     */
    public function aplicar(string $coleccion, string $id, array $documento): array
    {
        foreach ($this->reglas as $campo => $regla) {
            if (!\array_key_exists($campo, $documento) && \array_key_exists('defecto', $regla)) {
                $documento[$campo] = $regla['defecto'];
            }
            $valor = $documento[$campo] ?? null;

            if ($valor === null || $valor === '') {
                if (!empty($regla['obligatorio'])) {
                    throw new Exception(
                        "'{$coleccion}/{$id}': falta el campo obligatorio '{$campo}'."
                    );
                }
                continue;               // sin valor y no obligatorio: no hay tipo que comprobar
            }
            if (isset($regla['tipo']) && !self::esDelTipo($valor, $regla['tipo'])) {
                throw new Exception(
                    "'{$coleccion}/{$id}': el campo '{$campo}' tiene que ser {$regla['tipo']} "
                    . 'y llego ' . self::nombreDelTipo($valor) . '.'
                );
            }
        }
        return $documento;
    }

    public function vacio(): bool
    {
        return $this->reglas === [];
    }

    /**
     * Un entero vale como decimal —3 es un decimal perfectamente valido— pero un
     * decimal no vale como entero. La asimetria es la de las matematicas, no un
     * descuido.
     */
    private static function esDelTipo(mixed $valor, string $tipo): bool
    {
        return match ($tipo) {
            'texto'   => \is_string($valor),
            'entero'  => \is_int($valor),
            'decimal' => \is_float($valor) || \is_int($valor),
            'numero'  => \is_int($valor) || \is_float($valor),
            'bool'    => \is_bool($valor),
            'lista'   => \is_array($valor) && \array_is_list($valor),
            'mapa'    => \is_array($valor) && !\array_is_list($valor),
            default   => true,
        };
    }

    private static function nombreDelTipo(mixed $valor): string
    {
        return match (true) {
            \is_string($valor) => 'texto',
            \is_bool($valor)   => 'bool',
            \is_int($valor)    => 'entero',
            \is_float($valor)  => 'decimal',
            \is_array($valor)  => \array_is_list($valor) ? 'lista' : 'mapa',
            default            => \get_debug_type($valor),
        };
    }
}
