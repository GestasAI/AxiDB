<?php
/**
 * AxiDB - de donde salen los vectores.
 *
 * Y sobre todo: **que la suite entera funcione sin internet**. Un proyecto cuyos
 * tests llaman a una API ajena no es reproducible —falla el dia que esa API
 * cambie, se caiga o empiece a cobrar— y encima no se puede ejecutar en un tren.
 *
 * De los cinco proveedores, cuatro necesitan red y uno no. El que no la necesita
 * es el que usa la suite. De los otros se comprueba lo unico que se puede
 * comprobar sin llamarlos: que se configuran bien y que se quejan claro cuando
 * les falta algo.
 */

declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

use Axi\Core\Vector\Embedders\Hash;
use Axi\Core\Vector\Embedders\Remoto;

/* ─────────────────────────────────────────────────────────────────────────── */
section('A] El generador que no sale a internet');

$hash = new Hash(256);

eq('dice cuantas dimensiones da',   256, $hash->dims());
eq('y como se llama',        'hash:256', $hash->nombre());
ok('y que es local',                     $hash->esLocal());

$v = $hash->vector('poda de olivos en invierno');
eq('devuelve las dimensiones prometidas', 256, \count($v));
ok('con numeros de verdad', \is_float($v[0]) || \is_int($v[0]));

eq('el mismo texto da siempre el mismo vector', $v, $hash->vector('poda de olivos en invierno'));
ok('y otro texto da otro distinto', $v !== $hash->vector('cambiar el aceite del coche'));

eq('el vector de un texto vacio son ceros',
    \array_sum(\array_map('abs', $hash->vector(''))), 0.0);

/* ─────────────────────────────────────────────────────────────────────────── */
section('B] Y se parece a lo que tiene que parecerse');

/*
 * El Hash no entiende de significado: no sabe que "medico" y "doctor" son lo
 * mismo. Lo que si hace es acercar textos que comparten palabras, y eso basta
 * para probar la maquinaria de debajo.
 */
$parecido = static function (string $a, string $b) use ($hash): float {
    $va = \Axi\Core\Vector\Quantizer::normalizar($hash->vector($a));
    $vb = \Axi\Core\Vector\Quantizer::normalizar($hash->vector($b));
    return \Axi\Core\Vector\Quantizer::coseno($va, $vb);
};

$mismo   = $parecido('poda de arboles frutales', 'poda de arboles frutales en invierno');
$distinto = $parecido('poda de arboles frutales', 'cambio de aceite del motor');
\printf("    parecido entre textos afines: %.2f | entre textos ajenos: %.2f\n", $mismo, $distinto);

ok('dos textos con palabras comunes se parecen', $mismo > 0.5);
ok('y dos sin nada en comun, no',                $distinto < 0.3);
ok('y lo primero mas que lo segundo',            $mismo > $distinto);

ok('el plural se parece al singular por los trigramas', $parecido('tomate', 'tomates') > 0.6);
ok('y las mayusculas dan igual', $parecido('Tomate', 'tomate') > 0.99);

// Los acentos se pliegan a proposito: la gente busca sin tildes, y un catalogo
// escrito con ellas no aparaceria nunca.
ok('buscar sin tilde encuentra lo escrito con tilde', $parecido('ÁRBOL', 'arbol') > 0.99);
ok('y al reves',                                     $parecido('árbol', 'ARBOL') > 0.99);
ok('la eñe tambien',                                 $parecido('niño', 'nino') > 0.99);

throws('unas dimensiones que no son multiplo de ocho se rechazan',
    static fn() => new Hash(100));

/* ─────────────────────────────────────────────────────────────────────────── */
section('C] Los cuatro que necesitan red: solo la configuracion');

$ollama = new Remoto('ollama');
eq('ollama trae su modelo por defecto', 'ollama:nomic-embed-text', $ollama->nombre());
eq('y sus dimensiones',                                       768, $ollama->dims());
ok('ollama cuenta como local: corre en tu maquina',                $ollama->esLocal());

$openai = new Remoto('openai', ['clave' => 'sk-de-mentira']);
eq('openai',   'openai:text-embedding-3-small', $openai->nombre());
eq('con sus dimensiones',                 1536, $openai->dims());
ok('y no es local',                            !$openai->esLocal());

$gemini = new Remoto('gemini', ['clave' => 'x']);
eq('gemini', 'gemini:text-embedding-004', $gemini->nombre());

/*
 * Voyage y no "claude": Anthropic NO publica una API de embeddings. Su
 * documentacion remite a Voyage para esto, asi que ese es el proveedor que
 * cubre ese ecosistema. Escribir un cliente contra un endpoint que no existe
 * solo serviria para fallar en produccion.
 */
$voyage = new Remoto('voyage', ['clave' => 'x']);
eq('voyage, que es lo que recomienda Anthropic', 'voyage:voyage-3', $voyage->nombre());
eq('con sus dimensiones',                                     1024, $voyage->dims());

$aMedida = new Remoto('openai', ['clave' => 'x', 'modelo' => 'text-embedding-3-large', 'dims' => 3072]);
eq('se puede cambiar el modelo', 'openai:text-embedding-3-large', $aMedida->nombre());
eq('y las dimensiones',                                     3072, $aMedida->dims());

throws('un proveedor que no existe se dice al momento',
    static fn() => new Remoto('inventado'));
throws('y falta la clave de API, tambien',
    static fn() => new Remoto('openai'));

$mensaje = '';
try {
    new Remoto('gemini');
} catch (\Axi\Core\Exception $e) {
    $mensaje = $e->getMessage();
}
ok('diciendo cual falta', \str_contains($mensaje, 'gemini') && \str_contains($mensaje, 'clave'));

/* ─────────────────────────────────────────────────────────────────────────── */
section('D] La suite no ha tocado la red');

/*
 * No se puede demostrar un negativo desde dentro del proceso, pero si se puede
 * comprobar lo que lo garantiza: que el generador por defecto es local y que
 * ningun archivo del nucleo usa curl.
 */
$db = new \Axi\Core\Db(tmpdir('embedder'), ['durable' => false]);
$m  = $db->enableVectors('cosas');
ok('el generador por defecto es el local', \str_starts_with($m['fuente'], 'hash:'));

$conRed = [];
foreach (\glob(\dirname(__DIR__) . '/Vector/*.php') ?: [] as $archivo) {
    $codigo = (string) \file_get_contents($archivo);
    if (\str_contains($codigo, 'curl_') || \str_contains($codigo, 'fsockopen')) {
        $conRed[] = \basename($archivo);
    }
}
eq('ningun archivo del motor vectorial abre conexiones', [], $conRed);

summary();
