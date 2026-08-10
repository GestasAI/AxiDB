<?php
/**
 * AxiDB - Core\Names: de nombre a ruta, con las dos garantias que importan.
 *
 * 1. Nada sale del directorio de datos (validacion anti-traversal).
 * 2. El mismo nombre da el mismo archivo en cualquier sistema operativo.
 *
 * La segunda es la que hace portable a AxiDB. Un directorio en Windows no
 * distingue mayusculas y en Linux si: sin esta clase, un desarrollador que crea
 * la coleccion 'Users' en su portatil se encuentra DOS colecciones al desplegar
 * en Linux, o dos documentos distintos fundidos en uno al volver a Windows.
 * Es el tipo de fallo que solo aparece en produccion y cuesta una noche.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Names
{
    /** Longitud maxima de un identificador. Deja margen para rutas largas. */
    public const MAX = 128;

    /**
     * Caracter reservado para la marca de portabilidad. check() lo rechaza en
     * los nombres de entrada, asi que ningun nombre de usuario puede fingir ser
     * una forma codificada.
     */
    private const MARCA = '~';

    /**
     * Valida un identificador: empieza por alfanumerico y solo contiene
     * [A-Za-z0-9_-.]. Rechaza cadena vacia, '..', barras, byte nulo y
     * cualquier cosa que permita salir del directorio.
     */
    public static function check(string $value, string $kind): string
    {
        if ($value === '') {
            throw new InvalidName("AxiDB: {$kind} vacio.");
        }
        if (\strlen($value) > self::MAX) {
            throw new InvalidName("AxiDB: {$kind} demasiado largo (max " . self::MAX . ').');
        }
        if (\str_contains($value, '..')) {
            throw new InvalidName("AxiDB: {$kind} cannot contain '..'.");
        }
        if (!\preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-.]*$/', $value)) {
            throw new InvalidName("AxiDB: {$kind} invalido '{$value}' (solo [A-Za-z0-9_-.]).");
        }
        return $value;
    }

    /**
     * Nombre de archivo o directorio para un identificador ya validado.
     *
     * Un nombre en minusculas se usa tal cual, que es el caso de practicamente
     * todos los datos reales: cero cambios y el disco sigue siendo legible.
     *
     * Un nombre con mayusculas se baja a minusculas y se le añade una marca
     * derivada del original. Asi 'ABC' y 'abc' producen archivos distintos
     * incluso donde el sistema no distingue mayusculas, y 'ABC' produce SIEMPRE
     * el mismo archivo, aqui y en produccion.
     *
     * La transformacion es inyectiva: dos nombres distintos nunca dan la misma
     * ruta. Si coinciden en minusculas, la marca los separa; si no coinciden,
     * ya los separa el propio nombre.
     */
    public static function toPath(string $value): string
    {
        $minusculas = \strtolower($value);
        if ($minusculas === $value) {
            return $value;
        }
        return $minusculas . self::MARCA . \substr(\sha1($value), 0, 8);
    }

    /** True si el nombre necesita marca de portabilidad (tiene mayusculas). */
    public static function needsMark(string $value): bool
    {
        return \strtolower($value) !== $value;
    }

    /**
     * Convierte el VALOR de un campo en nombre de archivo para el indice.
     *
     * Un valor puede ser cualquier cosa: un correo, un texto con espacios, una
     * ruta. Si es un token inofensivo se usa tal cual —asi el indice se puede
     * inspeccionar a ojo— y si no, se reduce a un hash. En ambos casos pasa por
     * toPath(), porque un valor como 'l_lA' tiene el mismo problema de
     * portabilidad que un nombre de coleccion.
     */
    public static function forValue(string $value): string
    {
        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,63}$/', $value)) {
            return self::toPath($value);
        }
        return 'h_' . \sha1($value);
    }
}
