<?php
/**
 * Remote client - plantilla que apunta a un AxiDB remoto via HTTP.
 *
 * Demuestra el Caso A-SDK del plan v1: el codigo es identico al embebido,
 * solo cambia el constructor del Client. La logica del portfolio es la
 * misma; el motor vive en otro servidor.
 *
 * Configura AXI_REMOTE_URL via env var:
 *
 *   AXI_REMOTE_URL=https://mi-axidb.example.com/axidb/api/axi.php \
 *   AXI_REMOTE_TOKEN=<bearer> \
 *   php -S localhost:8000 -t .
 */

declare(strict_types=1);

require __DIR__ . '/../../axi.php';

use Axi\Sdk\Php\Client;

$url   = \getenv('AXI_REMOTE_URL') ?: '';
$token = \getenv('AXI_REMOTE_TOKEN') ?: null;

if ($url === '') {
    \header('Content-Type: text/plain; charset=UTF-8');
    echo "Configura AXI_REMOTE_URL antes de ejecutar este demo.\n";
    echo "Ejemplo: AXI_REMOTE_URL=https://host/axidb/api/axi.php php -S localhost:8000 -t .\n";
    exit;
}

$client = new Client($url, $token);
$col = $client->collection('remote_demo_items');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = \trim((string) ($_POST['title'] ?? ''));
    if ($title !== '') {
        $col->insert(['title' => $title, 'note' => $_POST['note'] ?? '']);
    }
    \header('Location: index.php'); exit;
}

// Probar conectividad antes de listar
$ping = $client->execute(['op' => 'ping']);
$reachable = ($ping['success'] ?? false) === true;
$items = $reachable ? $col->orderBy('_createdAt', 'desc')->limit(50)->get() : [];

?><!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Remote client — AxiDB demo</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 20px; }
        .conn-bad { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 6px; }
        .conn-ok  { background: #d1fae5; color: #065f46; padding: 12px; border-radius: 6px; }
        .item { padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
        form { margin-top: 24px; padding: 16px; background: #f8fafc; border-radius: 6px; display: grid; gap: 8px; }
        form input, form textarea { padding: 8px; border: 1px solid #e2e8f0; border-radius: 4px; font-family: inherit; }
        form button { background: #0284c7; color: white; border: 0; padding: 8px 14px; border-radius: 4px; cursor: pointer; justify-self: start; }
    </style>
</head>
<body>
    <h1>Remote client demo</h1>
    <p>
        Endpoint: <code><?= \htmlspecialchars($url) ?></code><br>
        Token: <?= $token ? 'presente' : '<em>(sin auth)</em>' ?><br>
        Transport: <code><?= \htmlspecialchars($client->transport()->name()) ?></code>
    </p>

    <?php if (!$reachable): ?>
        <div class="conn-bad">
            No alcanza el motor remoto: <?= \htmlspecialchars($ping['error'] ?? '?') ?>
            <br>Codigo: <?= \htmlspecialchars($ping['code'] ?? '?') ?>
        </div>
    <?php else: ?>
        <div class="conn-ok">Motor remoto conectado · ping ok.</div>

        <h2><?= \count($items) ?> item(s)</h2>
        <?php foreach ($items as $it): ?>
            <div class="item">
                <strong><?= \htmlspecialchars($it['title'] ?? '') ?></strong>
                <p><?= \htmlspecialchars($it['note'] ?? '') ?></p>
            </div>
        <?php endforeach; ?>

        <form method="post">
            <h3 style="margin: 0">Anadir item</h3>
            <input name="title" placeholder="Titulo" required>
            <textarea name="note" placeholder="Nota..." rows="3"></textarea>
            <button type="submit">Guardar (HTTP al motor remoto)</button>
        </form>
    <?php endif; ?>

    <hr style="margin-top: 40px">
    <p style="color: #64748b; font-size: 13px">
        Este codigo es identico al ejemplo embebido salvo el constructor:
        <code>new Client($url, $token)</code> en lugar de <code>new Client()</code>.
        Verifica el Caso A-SDK del plan: <strong>el SDK es transport-agnostic</strong>.
    </p>
</body>
</html>
