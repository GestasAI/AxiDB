<?php
/**
 * Portfolio - plantilla minima embebida (Caso B del plan v1).
 *
 * Una sola pagina, lista proyectos guardados en AxiDB.
 * Ejemplo de uso 1:1 con el SDK fluent. ~50 lineas de logica.
 */

declare(strict_types=1);

require __DIR__ . '/../../axi.php';

use Axi\Sdk\Php\Client;

$db = (new Client())->collection('portfolio_projects');

// Manejar POST de creacion (form al final de la pagina)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = \trim((string) ($_POST['name'] ?? ''));
    $tagline = \trim((string) ($_POST['tagline'] ?? ''));
    $url = \trim((string) ($_POST['url'] ?? ''));
    if ($name !== '') {
        $db->insert(['name' => $name, 'tagline' => $tagline, 'url' => $url]);
    }
    \header('Location: index.php'); exit;
}

$projects = $db->orderBy('_createdAt', 'desc')->get();
?><!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mi portfolio — AxiDB demo</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 40px auto; padding: 0 20px; color: #1e293b; }
        h1 { font-size: 28px; }
        .project { padding: 16px 0; border-bottom: 1px solid #e2e8f0; }
        .project h2 { font-size: 18px; }
        .project a { color: #0284c7; text-decoration: none; }
        .project a:hover { text-decoration: underline; }
        .tagline { color: #64748b; margin-top: 4px; }
        form { margin-top: 32px; padding: 20px; background: #f8fafc; border-radius: 8px; display: grid; gap: 8px; }
        form input { padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 4px; }
        form button { background: #0284c7; color: white; border: 0; padding: 8px 16px; border-radius: 4px; cursor: pointer; justify-self: start; }
        .empty { color: #64748b; font-style: italic; padding: 24px 0; }
    </style>
</head>
<body>
    <h1>Mi portfolio</h1>
    <p style="color: #64748b">Powered by AxiDB embebido. <?= \count($projects) ?> proyecto(s).</p>

    <?php if (empty($projects)): ?>
        <p class="empty">Aun no hay proyectos. Anade el primero abajo.</p>
    <?php else: foreach ($projects as $p): ?>
        <div class="project">
            <h2>
                <?php if (!empty($p['url'])): ?>
                    <a href="<?= \htmlspecialchars($p['url']) ?>" target="_blank" rel="noopener">
                        <?= \htmlspecialchars($p['name']) ?>
                    </a>
                <?php else: ?>
                    <?= \htmlspecialchars($p['name']) ?>
                <?php endif; ?>
            </h2>
            <p class="tagline"><?= \htmlspecialchars($p['tagline'] ?? '') ?></p>
        </div>
    <?php endforeach; endif; ?>

    <form method="post">
        <h2 style="font-size: 16px">Anadir proyecto</h2>
        <input name="name" placeholder="Nombre" required>
        <input name="tagline" placeholder="Tagline">
        <input name="url" type="url" placeholder="https://...">
        <button type="submit">Guardar</button>
    </form>
</body>
</html>
