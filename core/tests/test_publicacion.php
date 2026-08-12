<?php
/**
 * AxiDB - lo que hace falta para que alguien de fuera pueda usarlo.
 *
 * No comprueba codigo, comprueba que el paquete se puede publicar y confiar:
 * licencia de verdad, dependencias declaradas, version coherente entre los tres
 * sitios donde aparece y ninguna credencial dentro.
 *
 * Sin licencia, **nadie puede usar AxiDB legalmente** aunque este publicado. Y
 * una version que dice 0.2.0 en el codigo y 1.0.0 en el registro de cambios es
 * la clase de detalle que hace desconfiar de todo lo demas.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

$raiz = \dirname(__DIR__, 2);          // axidb/

require_once $raiz . '/core/axidb.php';

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] Licencia');

ok('existe LICENSE', \is_file($raiz . '/LICENSE'));
$licencia = (string) @\file_get_contents($raiz . '/LICENSE');

ok('es la Apache 2.0',                \str_contains($licencia, 'Apache License'));
ok('version 2.0, enero de 2004',      \str_contains($licencia, 'Version 2.0, January 2004'));
ok('con el texto completo, no un resumen', \strlen($licencia) > 9000);
ok('trae la concesion de patentes, que es lo que la distingue de MIT',
    \str_contains($licencia, 'Grant of Patent License'));

ok('el titular esta puesto',          \str_contains($licencia, 'Copyright 2026 GestasAI'));
ok('sin el hueco de la plantilla',    !\str_contains($licencia, '[yyyy]')
                                      && !\str_contains($licencia, '[name of copyright owner]'));

ok('existe NOTICE, como pide Apache 2.0', \is_file($raiz . '/NOTICE'));
$aviso = (string) @\file_get_contents($raiz . '/NOTICE');
ok('y nombra al titular', \str_contains($aviso, 'GestasAI'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] composer.json');

ok('existe composer.json', \is_file($raiz . '/composer.json'));
$json = \json_decode((string) @\file_get_contents($raiz . '/composer.json'), true);
ok('y es JSON valido', \is_array($json));

eq('el nombre sigue el formato de Packagist', 1,
    \preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', (string) ($json['name'] ?? '')));
eq('la licencia declarada coincide con el archivo', 'Apache-2.0', $json['license'] ?? null);
ok('declara la version minima de PHP', !empty($json['require']['php']));
ok('y la extension json, que es la unica que usa', isset($json['require']['ext-json']));

/*
 * Cero dependencias no es un eslogan: es la propiedad que hace que copiar la
 * carpeta funcione. Si alguna vez aparece un paquete aqui, el proyecto deja de
 * ser lo que dice ser y este test tiene que enterarse.
 */
$dependencias = \array_filter(
    \array_keys((array) ($json['require'] ?? [])),
    static fn(string $k) => $k !== 'php' && !\str_starts_with($k, 'ext-')
);
eq('cero dependencias de terceros', [], \array_values($dependencias));
ok('y ni siquiera de desarrollo',   empty($json['require-dev']));

$psr4 = (array) ($json['autoload']['psr-4'] ?? []);
ok('declara el autoload PSR-4 del nucleo', isset($psr4['Axi\\Core\\']));
ok('y la ruta existe',                     \is_dir($raiz . '/' . $psr4['Axi\\Core\\']));

foreach ((array) ($json['autoload']['files'] ?? []) as $archivo) {
    ok("el archivo autocargado existe: {$archivo}", \is_file($raiz . '/' . $archivo));
}

/*
 * El nucleo tiene que funcionar SIN Composer. Que exista composer.json es para
 * el ecosistema; si alguien copia la carpeta, no debe hacer falta nada mas.
 */
$conComposer = [];
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz . '/core', FilesystemIterator::SKIP_DOTS)
) as $archivo) {
    // Los tests quedan fuera: este mismo archivo contiene la cadena que busca,
    // y buscarse a si mismo es la forma mas tonta de fallar un test.
    if (\str_contains(\str_replace('\\', '/', (string) $archivo), '/tests/')) {
        continue;
    }
    if ($archivo->getExtension() === 'php'
        && \str_contains((string) \file_get_contents((string) $archivo), 'vendor/autoload')) {
        $conComposer[] = $archivo->getFilename();
    }
}
eq('ningun archivo del nucleo depende de Composer para arrancar', [], $conComposer);

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Registro de cambios y version');

ok('existe CHANGELOG.md', \is_file($raiz . '/CHANGELOG.md'));
$cambios = (string) @\file_get_contents($raiz . '/CHANGELOG.md');

ok('la version del codigo esta definida', \defined('AXIDB_VERSION'));
$version = \AXIDB_VERSION;
\printf("    version del nucleo: %s\n", $version);

eq('la version es semver', 1, \preg_match('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/i', $version));

ok("el registro de cambios tiene la entrada de {$version}",
    \str_contains($cambios, "## [{$version}]"));

/*
 * Y el README dice la misma version que el codigo.
 *
 * Se quedo diciendo 0.6.0 con el motor ya en 0.6.1, y esa es la primera linea
 * que lee quien llega: si ahi pone una version vieja, todo lo que siga se lee
 * con desconfianza. Tambien se comprueba el numero de archivos de test, que se
 * anuncia en la misma seccion y envejece igual de rapido.
 */
$portada = (string) @\file_get_contents($raiz . '/README.md');
ok("el README anuncia la version {$version}", \str_contains($portada, "Version {$version}"));

// Todos los archivos de test, incluidos los de ataque (test_sec_*): desde que la
// hoja de seguridad cerro en verde son parte del gate permanente y cuentan.
$cuantos = \count(\glob($raiz . '/core/tests/test_*.php') ?: []);
ok("el README dice cuantos archivos de test hay ({$cuantos})",
    \str_contains($portada, "{$cuantos} archivos de test"));

// La primera entrada del registro tiene que ser la version actual: un changelog
// donde lo ultimo publicado no esta arriba deja de servir para lo que sirve.
\preg_match('/^## \[([^\]]+)\]/m', $cambios, $primera);
eq('y es la primera de la lista', $version, $primera[1] ?? null);

ok('el historico del motor viejo esta marcado como tal',
    \str_contains($cambios, 'engine 1.0.0'));
ok('para que su 1.0.0 no se confunda con el nucleo',
    \str_contains($cambios, 'Historico del motor antiguo'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Nada que no deba publicarse');

$sospechosos = [];
$mirar = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz . '/core', FilesystemIterator::SKIP_DOTS)
);
/*
 * Una credencial es una ASIGNACION CON VALOR, no una palabra suelta.
 *
 * El patron buscaba antes 'secret' en cualquier posicion, y salto con la frase
 * "deja el secreto accesible por la puerta de atras": prosa en castellano,
 * donde secreto contiene secret. Un guardian que da falsos positivos por
 * escribir en el idioma del proyecto acaba desactivado o rodeado, y entonces
 * ya no guarda nada.
 *
 * Se exige la forma que tiene una credencial de verdad: la palabra, un igual o
 * una flecha, y un literal entrecomillado detras.
 */
$patrones = '/(password|passwd|secret|api[_-]?key|token)[\'"]?\s*(=>|=|:)\s*[\'"][^\'"\s]{8,}[\'"]/i';

foreach ($mirar as $archivo) {
    if ($archivo->getExtension() !== 'php' && $archivo->getExtension() !== 'js') {
        continue;
    }
    if (\str_contains(\str_replace('\\', '/', (string) $archivo), '/tests/')) {
        continue;                       // los tests usan tokens de mentira a proposito
    }
    $contenido = (string) \file_get_contents((string) $archivo);
    if (\preg_match($patrones, $contenido, $m)) {
        $sospechosos[] = $archivo->getFilename() . ': ' . $m[0];
    }
}
eq('ni una credencial en el nucleo', [], $sospechosos);

ok('no viaja ningun axidb.json con configuracion dentro',
    !\is_file($raiz . '/axidb.json'));
ok('ni un directorio vendor',        !\is_dir($raiz . '/vendor'));

/*
 * Archivos sueltos en la raiz: aparecen solos y se cuelan en el paquete sin que
 * nadie los añada. Un php que falla deja su `php_errors.log`; un ejemplo de la
 * documentacion que escribe con ruta relativa deja su `clientes.csv`.
 *
 * La primera version solo miraba los `.log`, y por eso un `clientes.csv` y un
 * `clientes.json` estuvieron ahi hasta que se vieron a ojo al comparar con el
 * repositorio. Ahora se mira al reves: en la raiz solo puede haber lo que se
 * espera, y cualquier otra cosa se señala.
 */
$permitidos = [
    'README.md', 'CHANGELOG.md', 'SECURITY.md', 'CONTRIBUTING.md',
    'LICENSE', 'LICENSE.md', 'NOTICE',
    'axi.php', 'axi.js', 'composer.json', '.gitignore', '.editorconfig',
];
$sueltos = [];
foreach (\glob($raiz . '/*') ?: [] as $entrada) {
    $nombre = \basename($entrada);
    if (!\is_dir($entrada) && !\in_array($nombre, $permitidos, true)) {
        $sueltos[] = $nombre;
    }
}
ok('ningun archivo suelto en la raiz'
    . ($sueltos === [] ? '' : ' -> sobra ' . \implode(', ', $sueltos)
       . ' (git puede ignorarlos, pero copiar la carpeta no)'),
    $sueltos === []);

/*
 * `.claude/` son ajustes locales de quien desarrolla y es normal que existan
 * aqui. Lo que no puede pasar es que lleguen al servidor: git los ignora, pero
 * el build copia con robocopy, que no mira el .gitignore. Ya se subieron una
 * vez, asi que la exclusion se comprueba en lugar de confiarse.
 */
$build = \dirname($raiz) . '/build.ps1';
if (\is_file($build)) {
    $texto = (string) \file_get_contents($build);
    ok('el build excluye los ajustes locales del agente', \str_contains($texto, '".claude"'));
    ok('y tambien el motor archivado',                    \str_contains($texto, '"_archivo"'));
} else {
    // Fuera del repositorio de origen no hay build que comprobar, y no pasa nada.
    ok('(sin build.ps1 al lado: nada que comprobar aqui)', true);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('D2] Ningun documento apunta a algo que no se publica');

/*
 * Lo destapo un evaluador externo al que se le dio solo la carpeta: el indice de
 * guias enlazaba a `_archivo/docs/` y el README hablaba de `engine/`, y ninguno
 * de los dos viaja en el paquete. Enlaces rotos justo para quien mas confia en
 * la documentacion, que es el que acaba de llegar.
 *
 * Lo que se publica es lo que hay en la carpeta MENOS lo que el build excluye.
 */
$noSePublica = ['_archivo', 'engine/', 'axidb/axi.php', 'axidb/api/', 'axidb/plugins/'];
$vivos = \array_merge(
    [$raiz . '/README.md'],
    \glob($raiz . '/docs/guide/*.md') ?: [],
    \glob($raiz . '/examples/README.md') ?: []
);

$menciones = [];
$rotos     = [];

foreach ($vivos as $doc) {
    $texto = (string) \file_get_contents($doc);
    $corto = \str_replace(\str_replace('\\', '/', $raiz) . '/', '', \str_replace('\\', '/', $doc));

    foreach ($noSePublica as $ausente) {
        if (\str_contains($texto, $ausente)) {
            $menciones[] = "{$corto} menciona '{$ausente}'";
        }
    }

    // Y que cada enlace relativo apunte a algo que existe de verdad.
    \preg_match_all('/\]\(([^)#][^)]*)\)/', $texto, $m);
    foreach ($m[1] ?? [] as $destino) {
        if (\preg_match('#^[a-z]+://#i', $destino)) {
            continue;
        }
        $destino = \explode('#', $destino)[0];
        if ($destino === '' || \file_exists(\dirname($doc) . '/' . $destino)) {
            continue;
        }
        $rotos[] = "{$corto} -> {$destino}";
    }
}

eq('ningun documento publicado nombra lo que no se publica', [], $menciones);
eq('ni enlaza a un archivo que no existe',                   [], $rotos);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] El README de portada');

ok('existe README.md', \is_file($raiz . '/README.md'));
$readme = (string) @\file_get_contents($raiz . '/README.md');

ok('dice como instalarlo',        \str_contains($readme, 'require'));
ok('enlaza la licencia',          \stripos($readme, 'apache') !== false);
ok('y dice que no tiene dependencias',
    \stripos($readme, 'sin dependencias') !== false || \stripos($readme, 'cero dependencias') !== false);

summary();
