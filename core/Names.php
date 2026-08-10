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
     * Dispositivos del sistema en Windows. No son nombres: abrir 'nul' como
     * archivo abre el dispositivo, en cualquier version de Windows. Un motor que
     * promete la misma carpeta en todas partes tiene que rechazarlos, porque en
     * Linux si son nombres normales y una carpeta creada alli no se abre aqui.
     */
    private const DISPOSITIVOS = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

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
        // Windows recorta el punto final de cada tramo de ruta: 'carpeta.' y
        // 'carpeta' acaban siendo el mismo directorio. Dos nombres que el motor
        // ve distintos y el disco funde en uno es justo lo que esto impide.
        if (\str_ends_with($value, '.')) {
            throw new InvalidName("AxiDB: {$kind} no puede terminar en punto '{$value}'.");
        }
        if (self::isReservedDevice($value)) {
            throw new InvalidName("AxiDB: {$kind} '{$value}' es un nombre reservado del sistema.");
        }
        return $value;
    }

    /**
     * True si el nombre coincide con un dispositivo reservado de Windows, con o
     * sin extension: Windows trata 'con.json' como el dispositivo CON, asi que
     * se mira el tramo anterior al primer punto.
     */
    public static function isReservedDevice(string $value): bool
    {
        $base = \strtoupper(\explode('.', $value, 2)[0]);
        return \in_array($base, self::DISPOSITIVOS, true);
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
        // La rama literal deja el valor legible en disco, pero solo se toma si
        // el valor NO puede confundirse con la rama de hash ni con un
        // dispositivo del sistema. El prefijo 'h_' pertenece al hash: si un
        // valor lo llevara, alguien podria fabricar a mano el nombre de archivo
        // del cubo de otro valor y ocuparlo (reservar el correo de un tercero en
        // un campo unique). Reservando 'h_' para el hash, las dos ramas quedan en
        // espacios de nombres disjuntos y la funcion es inyectiva.
        if (!\str_starts_with($value, 'h_')
            && !self::isReservedDevice($value)
            && \preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,63}$/', $value)) {
            return self::toPath($value);
        }
        return 'h_' . \sha1($value);
    }
}
