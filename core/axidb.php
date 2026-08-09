<?php
/**
 * AxiDB - punto de entrada unico.
 *
 *   require 'axidb/core/axidb.php';
 *   $db = axidb('./data');
 *
 * Carga el nucleo con un autoloader propio. Sin Composer, sin extensiones mas
 * alla de json, sin configuracion obligatoria.
 *
 * Configuracion opcional: si existe un axidb.json en el directorio de trabajo o
 * junto al nucleo, se leen de el 'data', 'durable', 'key' y 'profile'.
 *
 * Por que las funciones van dentro de un if y no tras un `return` de guardia:
 * PHP eleva las declaraciones de funcion de nivel superior en tiempo de
 * compilacion, antes de ejecutar una sola linea. Un `return` temprano no las
 * evita. Si dos archivos hacen `require` de este, la segunda vez daria
 * "Cannot redeclare axidb()". Declaradas dentro de un condicional no se elevan.
 */

declare(strict_types=1);

if (!\defined('AXIDB_CORE')) {
    \define('AXIDB_CORE', __DIR__);
    \define('AXIDB_VERSION', '0.6.1');

    \spl_autoload_register(static function (string $class): void {
        if (!\str_starts_with($class, 'Axi\\Core\\')) {
            return;
        }
        $rel  = \str_replace('\\', '/', \substr($class, \strlen('Axi\\Core\\')));
        $file = AXIDB_CORE . '/' . $rel . '.php';
        if (\is_file($file)) {
            require_once $file;
        }
    });
}

if (!\function_exists('axidb')) {
    /**
     * Abre (o crea) una base de datos AxiDB.
     *
     * @param string|null $dataPath Directorio de datos. Si se omite, se resuelve
     *                              desde axidb.json y, en su defecto, './data'.
     * @param array       $options  durable, clave, perfil. Manda sobre el axidb.json.
     */
    function axidb(?string $dataPath = null, array $options = []): \Axi\Core\Db
    {
        static $instances = [];

        $config    = axidb_config();
        $dataPath  = $dataPath ?? ($config['data'] ?? 'data');
        /*
         * Lo que se pasa aqui manda sobre el archivo: el axidb.json es para el
         * despliegue y el argumento para dejarlo escrito en el codigo. Con `+=`
         * la clave que ya viene no se pisa, que es justo ese orden.
         */
        $options  += ['durable' => $config['durable'] ?? true];
        foreach (['key', 'profile'] as $ajuste) {
            if (isset($config[$ajuste])) {
                $options += [$ajuste => $config[$ajuste]];
            }
        }

        $key = $dataPath . '|' . \json_encode($options);
        return $instances[$key] ??= new \Axi\Core\Db($dataPath, $options);
    }

    /**
     * Atiende una peticion HTTP contra la base de datos y termina.
     *
     *   require 'axidb/core/axidb.php';
     *   axidb_http(__DIR__ . '/datos', ['origenes' => ['https://miweb.com']]);
     *
     * Las opciones se fusionan con la seccion 'http' de axidb.json, y lo que se
     * pasa aqui manda: el archivo sirve para el despliegue, el argumento para
     * dejarlo escrito en el codigo.
     *
     * @param array $opciones origenes, tokens, token, publicas, soloLectura, abierto
     */
    function axidb_http(?string $dataPath = null, array $opciones = []): void
    {
        $config = axidb_config();
        $puente = new \Axi\Core\Http\Server(
            axidb($dataPath),
            $opciones + (array) ($config['http'] ?? [])
        );
        $puente->atender($_SERVER);
    }

    /**
     * Lee axidb.json si existe. Devuelve [] si no hay. Nunca lanza: la
     * configuracion es opcional por diseño.
     *
     * Se busca en tres sitios, en este orden:
     *
     *   1. dentro de la propia carpeta del nucleo   (axidb/axidb.json)
     *   2. justo al lado de esa carpeta             (../axidb.json)
     *   3. el directorio de trabajo del proceso     (./axidb.json)
     *
     * El primero se añadio porque un evaluador externo, siguiendo la guia al pie
     * de la letra, dejo el archivo donde le parecio razonable y el endpoint no
     * lo encontro. El directorio de trabajo va el ULTIMO a proposito: en una
     * peticion web depende del servidor y de como este configurado, asi que es
     * el sitio menos fiable de los tres para dejar la configuracion.
     */
    function axidb_config(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $candidatos = [
            AXIDB_CORE . '/axidb.json',
            AXIDB_CORE . '/../axidb.json',
            \getcwd() . '/axidb.json',
        ];
        foreach ($candidatos as $path) {
            if (\is_file($path)) {
                $json = \json_decode((string) @\file_get_contents($path), true);
                if (\is_array($json)) {
                    return $cache = $json;
                }
            }
        }
        return $cache = [];
    }
}
