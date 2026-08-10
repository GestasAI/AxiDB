<?php
/**
 * AxiDB - Http\Cors: que navegadores pueden llamar al puente.
 *
 * La comparacion es exacta sobre esquema, host y puerto, nunca sobre la cadena
 * entera. `strpos($origen, 'miweb.com') !== false` da por bueno
 * `https://miweb.com.attacker.net`, que es un dominio del atacante. El mismo
 * fallo, con las mismas consecuencias, se ha escrito mil veces.
 *
 * Sin origenes declarados no se responde ninguna cabecera CORS: el navegador
 * bloquea la peticion desde otra pagina y la del propio sitio funciona igual,
 * porque el mismo origen no pasa por CORS.
 */

declare(strict_types=1);

namespace Axi\Core\Http;

final class Cors
{
    /** @var list<string> origenes normalizados a esquema://host[:puerto] */
    private array $permitidos;

    /** @param list<string> $origenes */
    public function __construct(array $origenes)
    {
        $this->permitidos = \array_values(\array_filter(
            \array_map([self::class, 'normalizar'], $origenes)
        ));
    }

    /** True si el origen esta declarado. Un origen vacio (misma pagina) no lo esta. */
    public function allows(?string $origen): bool
    {
        $origen = self::normalizar((string) $origen);
        return $origen !== '' && \in_array($origen, $this->permitidos, true);
    }

    /**
     * Cabeceras a devolver. Vacio si el origen no vale: no responder cabeceras
     * es lo que hace que el navegador bloquee, y es la respuesta correcta.
     *
     * @return array<string, string>
     */
    public function headersFor(?string $origen): array
    {
        if (!$this->allows($origen)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin'  => self::normalizar((string) $origen),
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Max-Age'       => '600',
            // Sin esta, un proxy podria servirle a un origen la respuesta que
            // se cacheo para otro, con sus cabeceras CORS incluidas.
            'Vary'                         => 'Origin',
        ];
    }

    /**
     * esquema://host[:puerto], en minusculas y sin puerto por defecto. Devuelve
     * cadena vacia si no es un origen utilizable.
     */
    public static function normalizar(string $origen): string
    {
        $origen = \trim($origen);
        if ($origen === '' || $origen === 'null') {
            return '';
        }
        $partes = \parse_url($origen);
        if (!\is_array($partes) || empty($partes['scheme']) || empty($partes['host'])) {
            return '';
        }
        $esquema = \strtolower($partes['scheme']);
        $host    = \strtolower($partes['host']);
        $puerto  = isset($partes['port']) ? (int) $partes['port'] : null;

        if (($esquema === 'http' && $puerto === 80) || ($esquema === 'https' && $puerto === 443)) {
            $puerto = null;
        }
        return $esquema . '://' . $host . ($puerto !== null ? ':' . $puerto : '');
    }
}
