<?php
/**
 * Notas - editor.php: ver, editar y borrar una nota concreta.
 */

declare(strict_types=1);

require __DIR__ . '/../../axi.php';

use Axi\Sdk\Php\Client;

$db = new Client();
$col = $db->collection('notas_demo');

$id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!\is_string($id) || $id === '') {
    \header('Location: index.php'); exit;
}

// POST acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update') {
        $col->update($id, [
            'title'  => \trim((string) ($_POST['title'] ?? '')),
            'body'   => \trim((string) ($_POST['body'] ?? '')),
            'pinned' => !empty($_POST['pinned']),
        ]);
        \header('Location: editor.php?id=' . \urlencode($id) . '&ok=1'); exit;
    }
    if ($action === 'delete') {
        $col->delete($id, hard: true);
        \header('Location: index.php?del=' . \urlencode($id)); exit;
    }
}

$nota = $col->where('_id', '=', $id)->first();
if ($nota === null) {
    \header('Location: index.php'); exit;
}

\header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar: <?= \htmlspecialchars($nota['title'] ?? '') ?></title>
    <link rel="stylesheet" href="notas.css">
</head>
<body>
<header class="app-header">
    <h1>Editar nota</h1>
    <a href="index.php" class="btn-ghost">← Volver</a>
</header>

<main>
    <?php if (isset($_GET['ok'])): ?>
        <div class="banner ok">Cambios guardados.</div>
    <?php endif; ?>

    <article class="editor">
        <form method="post" class="form-stacked">
            <input type="hidden" name="id" value="<?= \htmlspecialchars($id) ?>">
            <input type="hidden" name="action" value="update">

            <label>Titulo
                <input name="title" value="<?= \htmlspecialchars($nota['title'] ?? '') ?>" maxlength="120" required>
            </label>

            <label>Cuerpo
                <textarea name="body" rows="10" required><?= \htmlspecialchars($nota['body'] ?? '') ?></textarea>
            </label>

            <label class="checkbox">
                <input type="checkbox" name="pinned" <?= !empty($nota['pinned']) ? 'checked' : '' ?>>
                Anclada
            </label>

            <div class="meta-info">
                <span>Id: <code><?= \htmlspecialchars($id) ?></code></span>
                <span>Version: <strong><?= (int) ($nota['_version'] ?? 1) ?></strong></span>
                <span>Creada: <?= \htmlspecialchars($nota['_createdAt'] ?? '?') ?></span>
                <span>Actualizada: <?= \htmlspecialchars($nota['_updatedAt'] ?? '?') ?></span>
            </div>

            <div class="actions">
                <button type="submit" class="btn-primary">Guardar</button>
            </div>
        </form>

        <form method="post" class="form-danger"
              onsubmit="return confirm('Borrar esta nota? Es irreversible.');">
            <input type="hidden" name="id" value="<?= \htmlspecialchars($id) ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn-danger">Borrar definitivamente</button>
        </form>
    </article>
</main>

<footer class="app-footer">
    <span>AxiDB v1 · embebido</span>
</footer>
<script src="notas.js" defer></script>
</body>
</html>
