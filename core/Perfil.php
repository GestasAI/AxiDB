<?php
/**
 * AxiDB - Core\Perfil: para que es esta base de datos.
 *
 *   $db = new Axi\Core\Db('./datos', ['perfil' => 'core']);
 *
 * Un perfil no es una version del motor. Es una declaracion de intenciones que
 * el motor hace cumplir: "esto es un blog, aqui no hay vectores". Si el codigo
 * usa algo de fuera, se para y lo dice, en vez de crecer sin que nadie decida.
 *
 * **Lo que un perfil NO hace, dicho de entrada:** no carga menos codigo. El
 * autoloader ya es perezoso —una clase que no se usa no se lee del disco— asi
 * que un "cargador de modulos" no ahorraria ni un byte y seria teatro. Lo que
 * ahorra es superficie: menos cosas que aprender, y un aviso cuando el proyecto
 * se sale de lo que dijo ser.
 *
 * Los tres son acumulativos, y cambiar de uno a otro es cambiar una linea: los
 * datos no se tocan, no hay volcado ni migracion. Un blog que empieza a vender
 * pasa de `core` a `docs` y sigue leyendo exactamente los mismos archivos.
 */

declare(strict_types=1);

namespace Axi\Core;

final class Perfil
{
    public const CORE = 'core';
    public const DOCS = 'docs';
    public const IA   = 'ai';

    /**
     * Sin perfil declarado esta todo disponible.
     *
     * Es lo que habia antes de que los perfiles existieran, y una instalacion
     * que actualiza no puede empezar a fallar por una funcion que ya usaba.
     */
    public const TODO = 'todo';

    /** Que trae cada perfil, ademas de lo del anterior. */
    private const CAPAS = [
        self::CORE => ['documentos', 'indices', 'consultas', 'sql', 'copias', 'salud'],
        self::DOCS => ['esquema', 'caducidad', 'unicidad', 'transacciones', 'relaciones', 'cifrado'],
        self::IA   => ['vectores', 'agentes'],
    ];

    private const ORDEN = [self::CORE, self::DOCS, self::IA];

    /** Para el mensaje de error: en que perfil aparece cada cosa. */
    private const DONDE_ESTA = [
        'esquema'       => self::DOCS, 'caducidad' => self::DOCS,
        'unicidad'      => self::DOCS, 'cifrado'   => self::DOCS,
        'transacciones' => self::DOCS, 'relaciones' => self::DOCS,
        'vectores'      => self::IA,   'agentes'   => self::IA,
    ];

    private array $activas;

    public function __construct(public readonly string $nombre)
    {
        if ($nombre === self::TODO) {
            $this->activas = [];                // vacio significa "no se comprueba nada"
            return;
        }
        if (!\in_array($nombre, self::ORDEN, true)) {
            throw new Exception(
                "Perfil desconocido '{$nombre}'. Hay: " . \implode(', ', self::ORDEN)
                . ", o ninguno para tenerlo todo."
            );
        }
        $this->activas = [];
        foreach (self::ORDEN as $capa) {
            $this->activas = [...$this->activas, ...self::CAPAS[$capa]];
            if ($capa === $nombre) {
                break;
            }
        }
    }

    public function tiene(string $funcion): bool
    {
        return $this->activas === [] || \in_array($funcion, $this->activas, true);
    }

    /**
     * Se para si esa funcion no esta en el perfil, y dice como arreglarlo.
     *
     * El mensaje lleva las tres cosas que hacen falta para resolverlo sin ir a
     * buscar la documentacion: que se ha intentado, en que perfil vive, y la
     * linea exacta que hay que cambiar. Un error que solo dice "no permitido"
     * obliga a una busqueda que se podia haber ahorrado.
     */
    public function exigir(string $funcion, string $comoSeLlama): void
    {
        if ($this->tiene($funcion)) {
            return;
        }
        $necesario = self::DONDE_ESTA[$funcion] ?? self::IA;

        throw new Exception(
            "{$comoSeLlama} necesita el perfil '{$necesario}' y esta base usa '{$this->nombre}'. "
            . "Cambialo al abrirla: new Db(\$dir, ['perfil' => '{$necesario}']). "
            . 'Los datos no se tocan: un perfil solo dice que partes del motor se usan.'
        );
    }

    /** @return list<string> lo que este perfil deja usar */
    public function funciones(): array
    {
        return $this->activas === [] ? \array_merge(...\array_values(self::CAPAS)) : $this->activas;
    }
}
