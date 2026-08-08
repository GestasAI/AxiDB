<?php
/**
 * AxiDB - hello.php: demo minima del SDK (Caso B + A-SDK).
 *
 * Uso:
 *   php axidb/examples/hello.php            # modo embebido (sin red)
 *   AXI_URL=http://host/axidb/api/axi.php \
 *     php axidb/examples/hello.php          # modo HTTP remoto
 *
 * Cambia de embebido a remoto sin tocar la logica: solo el constructor.
 */

require __DIR__ . '/../axi.php';

use Axi\Sdk\Php\Client;

$client = \getenv('AXI_URL')
    ? new Client(\getenv('AXI_URL'))
    : new Client();

echo "Transport: " . $client->transport()->name() . "\n";

$notas = $client->collection('hello_demo');

// Limpia run anterior (idempotente).
foreach ($notas->get() as $doc) {
    $notas->delete($doc['_id'], hard: true);
}

// CRUD en 5 lineas.
$a = $notas->insert(['title' => 'Primera', 'tags' => ['intro']]);
$b = $notas->insert(['title' => 'Segunda', 'tags' => ['demo']]);
$notas->update($a['data']['_id'], ['body' => 'editada']);
$list  = $notas->orderBy('title')->get();
$count = $notas->count();

echo "Insertadas: {$count}. Primera por orden: {$list[0]['title']}\n";
echo "Primera con body: '{$list[0]['body']}'\n";

$notas->delete($a['data']['_id'], hard: true);
$notas->delete($b['data']['_id'], hard: true);

echo "OK (embebido/HTTP — mismo codigo)\n";
