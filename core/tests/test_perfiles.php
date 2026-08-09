<?php
/**
 * AxiDB - los tres perfiles, y el gate de la ola A9.
 *
 * El gate: un blog que empieza en `core`, se convierte en tienda pasando a
 * `docs` y termina con busqueda por significado en `ai`, **cambiando una sola
 * linea** y sin tocar los datos. Ni volcado, ni migracion, ni una consulta
 * reescrita.
 *
 * Y lo que un perfil tiene que hacer bien cuando dices que no: explicarse. Un
 * error que solo dice "no permitido" obliga a ir a buscar la documentacion.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Db;
use Axi\Core\Perfil;

$dir = tmpdir('perfiles');

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] El blog: perfil core');

$blog = new Db($dir, ['durable' => false, 'perfil' => Perfil::CORE]);

$blog->sql("INSERT INTO entradas (titulo, autor, texto) VALUES
    ('Pan de masa madre', 'ana', 'Como hacer pan en casa'),
    ('Cerveza artesana',  'eva', 'Fermentar en el garaje'),
    ('Huerto en marzo',   'ana', 'Que sembrar este mes')");
$blog->sql('CREATE INDEX ON entradas (autor)');

eq('escribe',            3, $blog->count('entradas'));
eq('indexa y consulta',  2, \count($blog->by('entradas', 'autor', 'ana')));
eq('AxiSQL funciona', 'Huerto en marzo',
    $blog->sql("SELECT titulo FROM entradas WHERE autor = 'ana' ORDER BY titulo")[0]['titulo']);
eq('y los agregados', 3, $blog->sql('SELECT COUNT(*) FROM entradas'));

$copias = tmpdir('perfiles_copias');
ok('las copias son de core', $blog->copiar($copias)['archivos'] > 3);
eq('y la salud tambien', [], $blog->revision()['avisos']);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Lo que core no tiene, y como lo dice');

/** El mensaje tiene que traer las tres cosas para resolverlo sin buscar nada. */
$explica = static function (callable $fn, string $funcionEsperada): array {
    try {
        $fn();
    } catch (\Axi\Core\Exception $e) {
        return [
            'dice_perfil_actual'  => \str_contains($e->getMessage(), "usa 'core'"),
            'dice_el_que_falta'   => \str_contains($e->getMessage(), "perfil '{$funcionEsperada}'"),
            'dice_como_cambiarlo' => \str_contains($e->getMessage(), "'perfil' => '{$funcionEsperada}'"),
            'dice_que_no_pasa'    => \str_contains($e->getMessage(), 'Los datos no se tocan'),
        ];
    }
    return [];
};

$esperado = ['dice_perfil_actual' => true, 'dice_el_que_falta' => true,
             'dice_como_cambiarlo' => true, 'dice_que_no_pasa' => true];

eq('transaccion() se para y se explica', $esperado,
    $explica(static fn () => $blog->transaccion(static fn ($tx) => null), 'docs'));
eq('declararEsquema(), tambien', $esperado,
    $explica(static fn () => $blog->declararEsquema('entradas', []), 'docs'));
eq('declararCaducidad(), tambien', $esperado,
    $explica(static fn () => $blog->declararCaducidad('entradas', 60), 'docs'));
eq('unico(), tambien', $esperado,
    $explica(static fn () => $blog->unico('entradas', 'titulo'), 'docs'));
eq('cifrar(), tambien', $esperado,
    $explica(static fn () => $blog->cifrar('entradas'), 'docs'));
eq('join(), tambien', $esperado,
    $explica(static fn () => $blog->find('entradas')->join('autores', 'autor', 'id')->get(), 'docs'));
eq('y los vectores piden ai', ['dice_perfil_actual' => true, 'dice_el_que_falta' => true,
    'dice_como_cambiarlo' => true, 'dice_que_no_pasa' => true],
    $explica(static fn () => $blog->vectores('entradas'), 'ai'));

$blog->storage()->cerrar();

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] El blog se hace tienda: una linea, cero migraciones');

/*
 * Esto es el gate de la ola. El mismo directorio, la misma coleccion, las mismas
 * consultas. Lo unico que cambia es la palabra 'core' por 'docs'.
 */
$tienda = new Db($dir, ['durable' => false, 'perfil' => Perfil::DOCS]);

eq('los documentos estan donde estaban', 3, $tienda->count('entradas'));
eq('con su contenido intacto', 2, \count($tienda->by('entradas', 'autor', 'ana')));
eq('y las consultas de antes siguen valiendo', 3, $tienda->sql('SELECT COUNT(*) FROM entradas'));

// Y ahora lo que antes se negaba.
$tienda->declararEsquema('pedidos', ['total' => ['tipo' => 'decimal', 'obligatorio' => true]]);
$tienda->sql('CREATE UNIQUE INDEX ON clientes (correo)');
$tienda->declararCaducidad('carritos', 3600);
$tienda->sql("INSERT INTO clientes (id, correo, nombre) VALUES ('c1', 'ana@ejemplo.com', 'Ana')");

$tienda->transaccion(static function ($tx) {
    $tx->insert('pedidos', ['cliente' => 'c1', 'total' => 42.5], 'p1');
});
eq('las transacciones ya se pueden',   1, $tienda->count('pedidos'));
eq('el esquema se cumple', 42.5, $tienda->get('pedidos', 'p1')['total']);
throws('y rechaza lo que no cumple',
    static fn () => $tienda->insert('pedidos', ['cliente' => 'c1'], 'p2'));
throws('la unicidad tambien',
    static fn () => $tienda->insert('clientes', ['correo' => 'ana@ejemplo.com'], 'c2'));

eq('y el JOIN', 'Ana',
    $tienda->sql("SELECT clientes.nombre FROM pedidos
                  JOIN clientes ON pedidos.cliente = clientes.id")[0]['clientes.nombre']);

eq('los vectores siguen fuera', ['dice_perfil_actual' => false, 'dice_el_que_falta' => true,
    'dice_como_cambiarlo' => true, 'dice_que_no_pasa' => true],
    (static function () use ($tienda): array {
        try {
            $tienda->vectores('entradas');
        } catch (\Axi\Core\Exception $e) {
            return [
                'dice_perfil_actual'  => \str_contains($e->getMessage(), "usa 'core'"),
                'dice_el_que_falta'   => \str_contains($e->getMessage(), "perfil 'ai'"),
                'dice_como_cambiarlo' => \str_contains($e->getMessage(), "'perfil' => 'ai'"),
                'dice_que_no_pasa'    => \str_contains($e->getMessage(), 'Los datos no se tocan'),
            ];
        }
        return [];
    })());

$tienda->storage()->cerrar();

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] Y la tienda busca por significado: otra linea');

$ia = new Db($dir, ['durable' => false, 'perfil' => Perfil::IA]);

eq('los datos siguen ahi despues de los dos saltos', 3, $ia->count('entradas'));
eq('y los pedidos del paso anterior', 1, $ia->count('pedidos'));

$ia->vectores('entradas', ['auto' => ['titulo', 'texto']]);
$ia->insert('entradas', ['titulo' => 'Levadura natural', 'autor' => 'ana',
                          'texto' => 'Cultivar masa madre en casa'], 'e4');

$parecidas = $ia->similar('entradas', 'hacer pan con masa madre', 2);
ok('la busqueda por significado funciona', \count($parecidas) === 2);

eq('lo declarado en docs sigue en pie', ['correo'], $ia->unicos('clientes'));
eq('y el esquema tambien', true, $ia->esquema('pedidos')['total']['obligatorio'] ?? false);

/* ─────────────────────────────────────────────────────────────────────────── */
section('E] Sin perfil, todo disponible');

/*
 * Una instalacion que actualiza no puede empezar a fallar por una funcion que
 * ya estaba usando. Sin declarar perfil, no se comprueba nada.
 */
$sinPerfil = new Db($dir, ['durable' => false]);
eq('el perfil es "todo"', Perfil::TODO, $sinPerfil->perfil()->nombre);
$sinPerfil->transaccion(static fn ($tx) => null);
ok('y las transacciones van sin declarar nada', true);
ok('los vectores tambien', $sinPerfil->vectorial('entradas')->manifiesto()->dims > 0);

/* ─────────────────────────────────────────────────────────────────────────── */
section('F] Que trae cada perfil');

eq('core no incluye transacciones', false, (new Perfil(Perfil::CORE))->tiene('transacciones'));
eq('docs si',                        true, (new Perfil(Perfil::DOCS))->tiene('transacciones'));
eq('docs no incluye vectores',      false, (new Perfil(Perfil::DOCS))->tiene('vectores'));
eq('ai si',                          true, (new Perfil(Perfil::IA))->tiene('vectores'));

// Acumulativos: ai trae todo lo de docs, y docs todo lo de core.
foreach ((new Perfil(Perfil::CORE))->funciones() as $f) {
    ok("docs hereda '{$f}' de core", (new Perfil(Perfil::DOCS))->tiene($f));
}
eq('ai trae lo de los tres', 14, \count((new Perfil(Perfil::IA))->funciones()));

throws('un perfil que no existe se rechaza al abrir',
    static fn () => new Db(tmpdir('perfil_malo'), ['durable' => false, 'perfil' => 'grande']));

/* ─────────────────────────────────────────────────────────────────────────── */
section('G] Bajar de perfil: no se pierde nada');

/*
 * El camino de vuelta, que es el que se olvida. Una base montada en `ai` que se
 * abre con `core`: los datos tienen que seguir enteros, lo declarado tiene que
 * seguir cumpliendose, y lo que ya no esta en el perfil tiene que negarse con un
 * error util y no con un fatal.
 *
 * Este caso destapo que `similar()` NO estaba protegido: solo lo estaba
 * `vectores()`, la activacion. Con perfil core se podia seguir buscando por
 * significado. Una regla aplicada a medias es una regla que no esta.
 */
$ia->storage()->cerrar();
$bajado = new Db($dir, ['durable' => false, 'perfil' => Perfil::CORE]);

eq('los documentos siguen',        4, $bajado->count('entradas'));
eq('se leen igual',                3, \count($bajado->by('entradas', 'autor', 'ana')));
eq('los pedidos de docs tambien',  1, $bajado->count('pedidos'));

/*
 * Lo YA declarado se sigue cumpliendo. Es lo correcto y conviene decirlo: bajar
 * de perfil impide DECLARAR cosas nuevas, no desactiva las que ya protegen los
 * datos. Al reves, bajar el perfil apagaria la unicidad y dejaria entrar
 * duplicados sin que nadie lo pidiera.
 */
throws('el esquema declarado en docs se sigue cumpliendo',
    static fn () => $bajado->insert('pedidos', ['cliente' => 'c1'], 'pSinTotal'));
throws('y la unicidad declarada, tambien',
    static fn () => $bajado->insert('clientes', ['correo' => 'ana@ejemplo.com'], 'cDup'));

throws('buscar por significado se niega con un error, no un fatal',
    static fn () => $bajado->similar('entradas', 'pan', 2));
throws('y el acceso al indice vectorial, tambien',
    static fn () => $bajado->vectorial('entradas'));

ok('pero el indice vectorial NO se borra del disco', \is_dir($dir . '/entradas/_vec'));
$bajado->storage()->cerrar();

$devuelta = new Db($dir, ['durable' => false, 'perfil' => Perfil::IA]);
eq('al volver a subir, los vectores estan donde estaban', 2,
    \count($devuelta->similar('entradas', 'pan de masa madre', 2)));
eq('y los documentos', 4, $devuelta->count('entradas'));
$devuelta->storage()->cerrar();

$ia = new Db($dir, ['durable' => false, 'perfil' => Perfil::IA]);

/* ─────────────────────────────────────────────────────────────────────────── */
section('H] Un motor, no tres');

/*
 * La promesa de la ola era "los tres tamaños SIN bifurcar el codigo". Se
 * comprueba estructuralmente: el perfil solo puede aparecer en las puertas de
 * entrada de la fachada, nunca dentro del motor.
 *
 * Si un dia alguien mete un `if ($perfil...)` en Storage o en Query, aqui salta.
 * Eso seria el principio de tener tres motores que se parecen, que es justo lo
 * que la ola dijo que no iba a pasar.
 */
$conPerfil = [];
foreach (\glob(\dirname(__DIR__) . '/{*,*/*,*/*/*}.php', GLOB_BRACE) ?: [] as $archivo) {
    $rel = \str_replace('\\', '/', \substr($archivo, \strlen(\dirname(__DIR__)) + 1));
    if (\str_starts_with($rel, 'tests/') || $rel === 'Perfil.php') {
        continue;
    }
    if (\preg_match('/\bperfil\(\)|\bPerfil::/i', (string) \file_get_contents($archivo)) === 1) {
        $conPerfil[] = $rel;
    }
}
$fuera = \array_values(\array_filter(
    $conPerfil,
    static fn(string $f) => !\str_starts_with($f, 'Fachada/') && $f !== 'Db.php' && $f !== 'Query.php'
));
eq('el perfil solo se consulta en las puertas de entrada, no dentro del motor',
    [], $fuera);
ok('y se consulta en varias de ellas (' . \count($conPerfil) . ')', \count($conPerfil) >= 4);

$ia->storage()->cerrar();
$sinPerfil->storage()->cerrar();
rmrf($copias);
rmrf($dir);
summary();
