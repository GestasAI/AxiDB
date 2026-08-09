<?php
/**
 * AxiDB - el test que hay que pasar para poder decir "base de datos".
 *
 * Se mata el proceso una y otra vez en momentos aleatorios mientras escribe, y
 * despues se revisa documento por documento. Lo que se exige no es que no se
 * pierda la ultima escritura —eso es imposible y nadie lo promete— sino que
 * **ningun documento quede a medias**: cada uno tiene que ser, entero, o el que
 * habia antes o el nuevo.
 *
 * Cada documento lleva dentro la firma de su carga. Un JSON valido con los bytes
 * de otro documento dentro pasaria una comprobacion de "parsea bien" y aqui no
 * pasa. Esa es la diferencia entre comprobar el formato y comprobar el dato.
 *
 * Se tortura a los dos formatos: fs y packed pierden de maneras distintas.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;

/** Rondas minimas por driver. Cada una es lanzar, esperar al azar y matar. */
const RONDAS = 20;

/**
 * Escrituras torturadas que hay que juntar entre los dos drivers.
 *
 * Se tortura HASTA llegar a esta cifra, no un numero fijo de veces. Cuantas
 * escrituras caben en cada ronda depende de lo rapida que sea la maquina, asi
 * que con rondas fijas el total sale unas veces por encima y otras por debajo,
 * y el test se ponia rojo sin que nada estuviera mal.
 */
const META_ESCRITURAS = 1000;

/**
 * Revisa TODOS los documentos y devuelve los que estan mal.
 *
 * @return array{revisados:int, rotos:list<string>}
 */
function revisar(Db $db): array
{
    $rotos = [];
    $n = 0;

    foreach ($db->all('docs') as $doc) {
        $n++;
        $id = (string) ($doc['id'] ?? '(sin id)');

        foreach (['id', '_version', '_createdAt', '_updatedAt', 'firma', 'carga', 'largo'] as $campo) {
            if (!\array_key_exists($campo, $doc)) {
                $rotos[] = "{$id}: le falta {$campo}";
                continue 2;
            }
        }
        if (!\is_string($doc['carga']) || \strlen($doc['carga']) !== $doc['largo']) {
            $rotos[] = "{$id}: la carga mide " . \strlen((string) $doc['carga']) . " y deberia medir {$doc['largo']}";
            continue;
        }
        if (\sha1($doc['carga']) !== $doc['firma']) {
            $rotos[] = "{$id}: la firma no cuadra (contenido mezclado)";
        }
    }
    return ['revisados' => $n, 'rotos' => $rotos];
}

/** Los archivos JSON, leidos a pelo, sin pasar por el motor. */
function jsonRotoEnDisco(string $dir): array
{
    $malos = [];
    foreach (\glob($dir . '/docs/*.json') ?: [] as $archivo) {
        if (\str_contains(\basename($archivo), '.tmp.') || \basename($archivo)[0] === '_') {
            continue;
        }
        $crudo = (string) @\file_get_contents($archivo);
        if (!\is_array(\json_decode($crudo, true))) {
            $malos[] = \basename($archivo);
        }
    }
    return $malos;
}

function temporales(string $dir): array
{
    return \array_map('basename', \glob($dir . '/docs/*.tmp.*') ?: []);
}

/* ─────────────────────────────────────────────────────────────────────────── */

$totalEscrituras = 0;
$totalMuertes    = 0;

foreach (['fs', 'packed'] as $driver) {
    section("Tortura del driver {$driver}: " . RONDAS . ' muertes en momentos aleatorios');

    $dir = tmpdir('tortura_' . $driver);
    $db  = new Db($dir, ['durable' => false]);
    if ($driver !== 'fs') {
        $db->storage()->declararDriver('docs', $driver);
    }
    $db->storage()->cerrar();

    $muertes   = 0;
    $escritos  = 0;
    $rotos     = [];
    $tmpVistos = 0;

    // Al menos RONDAS vueltas, y las que hagan falta hasta juntar la mitad de
    // la meta con este driver. El tope evita que una maquina lentisima lo deje
    // corriendo para siempre.
    $meta = \intdiv(META_ESCRITURAS, 2);

    for ($ronda = 0; $ronda < RONDAS * 3 && ($ronda < RONDAS || $escritos * 2 < $meta); $ronda++) {
        $h = spawn(__DIR__ . '/_worker_tortura.php', [$dir, $driver, 't' . $ronda, $ronda]);

        // Entre 0,2 y 0,9 s. El limite inferior no es capricho: el interprete
        // tarda unos 100 ms en arrancar, y matar antes de eso mide el arranque
        // de PHP, no la escritura. El superior mantiene el test por debajo de
        // los treinta segundos.
        \usleep(200000 + ($ronda * 5449) % 700000);
        killNow($h);
        $muertes++;

        $tmpVistos += \count(temporales($dir));

        // Se abre una instancia nueva en cada ronda: leer con el mismo objeto
        // que nunca vio la muerte del otro proceso no probaria gran cosa.
        $lector = new Db($dir, ['durable' => false]);
        $revision = revisar($lector);
        $escritos = \max($escritos, $revision['revisados']);
        $rotos    = \array_merge($rotos, $revision['rotos']);
        $lector->storage()->cerrar();
    }

    // Cada vuelta del worker hace dos escrituras: el documento nuevo y el que
    // se reescribe siempre. Los documentos vivos son, por tanto, la mitad de
    // las escrituras que llegaron a completarse.
    $escrituras       = $escritos * 2;
    $totalEscrituras += $escrituras;
    $totalMuertes    += $muertes;

    \printf("    %d muertes, %d documentos vivos, ~%d escrituras, %d temporales por el camino\n",
        $muertes, $escritos, $escrituras, $tmpVistos);

    eq('ninguna muerte dejo un documento a medias', [], \array_slice($rotos, 0, 5));
    ok('se escribieron documentos de verdad (' . $escritos . ')', $escritos >= 50);

    if ($driver === 'fs') {
        eq('ni un archivo JSON ilegible en el disco', [], jsonRotoEnDisco($dir));
    }

    /* ─── El estado sigue siendo utilizable, no solo integro ─────────────── */
    $db = new Db($dir, ['durable' => false]);

    $antes = $db->count('docs');
    $nuevo = $db->insert('docs', ['firma' => \sha1('x'), 'carga' => 'x', 'largo' => 1, 'n' => -1], 'despues');
    eq('se puede seguir escribiendo tras todas las muertes', 'despues', $nuevo['id']);
    eq('y el recuento sube en uno', $antes + 1, $db->count('docs'));

    $db->index('docs', 'ronda');
    $porRonda = $db->verifyIndexes('docs')['ronda'] ?? [];
    eq('el indice se reconstruye entero', 0, $porRonda['faltan'] ?? -1);

    $r = revisar($db);
    eq('y tras reindexar sigue sin haber nada roto', [], \array_slice($r['rotos'], 0, 5));

    /* ─── Los temporales se barren ───────────────────────────────────────── */
    $db->storage()->sweep('docs', 0);
    eq('el barrido no deja temporales', [], temporales($dir));

    $r = revisar($db);
    ok('y el barrido no se llevo por delante ningun documento', $r['revisados'] >= $antes);

    $db->storage()->cerrar();
    rmrf($dir);
}

/* ─────────────────────────────────────────────────────────────────────────── */
section('El volumen tambien cuenta');

/*
 * Diez muertes no demuestran nada: con pocas escrituras es facil que ninguna
 * caiga dentro de la ventana peligrosa y el test pase por suerte. El objetivo
 * de la ola hablaba del orden de mil escrituras, y se comprueba en lugar de
 * darse por hecho.
 */
\printf("    %d escrituras torturadas en total, con %d muertes\n", $totalEscrituras, $totalMuertes);
ok('se llego a las mil escrituras torturadas: ' . $totalEscrituras,
    $totalEscrituras >= META_ESCRITURAS);

/* ─────────────────────────────────────────────────────────────────────────── */
section('Lo que este test NO promete');

/*
 * Decirlo aqui, en el codigo, y no solo en la documentacion: la ultima escritura
 * en curso cuando llega la muerte puede perderse. Eso no es un fallo, es como
 * funciona cualquier base de datos sin escritura sincrona. Lo que se promete es
 * que lo que hay en el disco esta entero.
 */
$dir = tmpdir('tortura_promesa');
$db  = new Db($dir, ['durable' => false]);
$db->insert('docs', ['firma' => \sha1('a'), 'carga' => 'a', 'largo' => 1, 'n' => 1], 'unico');

$h = spawn(__DIR__ . '/_worker_tortura.php', [$dir, 'fs', 'p', 99]);
\usleep(120000);
killNow($h);

$lector = new Db($dir, ['durable' => false]);
$viejo  = $lector->get('docs', 'unico');
ok('un documento que no se estaba escribiendo no se toca', ($viejo['carga'] ?? null) === 'a');

$revision = revisar($lector);
eq('y de los que si se escribian, ninguno quedo a medias', [], \array_slice($revision['rotos'], 0, 5));
$lector->storage()->cerrar();
rmrf($dir);

summary();
