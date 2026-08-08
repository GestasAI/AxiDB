<?php
/**
 * Notas - app demo de AxiDB embebido (Caso D del plan v1).
 *
 * Lista todas las notas, busca por texto, formulario inline para crear.
 * Click en una nota -> editor.php para editar/borrar.
 *
 * Stack: PHP + AxiDB embebido + HTML/CSS/JS vanilla. Sin frameworks.
 */

declare(strict_types=1);

require __DIR__ . '/../../axi.php';

use Axi\Sdk\Php\Client;

$db = new Client();
$col = $db->collection('notas_demo');

// Manejar POST de creacion
$created = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = \trim((string) ($_POST['title'] ?? ''));
    $body  = \trim((string) ($_POST['body'] ?? ''));
    if ($title !== '' && $body !== '') {
        $r = $col->insert(['title' => $title, 'body' => $body, 'pinned' => false]);
        $created = $r['data']['_id'] ?? null;
    }
    // Redirect post-post para que F5 no re-cree
    \header('Location: index.php' . ($created ? '?ok=' . \urlencode($created) : ''));
    exit;
}

// Lista + busqueda
$q = \trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $sql = "SELECT * FROM notas_demo WHERE title CONTAINS '" . \str_replace("'", "''", $q) . "'"
         . " OR body CONTAINS '" . \str_replace("'", "''", $q) . "'"
         . " ORDER BY _updatedAt DESC LIMIT 100";
    $res = $db->sql($sql);
    $notas = $res['data']['items'] ?? [];
} else {
    $notas = $col->orderBy('_updatedAt', 'desc')->limit(100)->get();
}

// Filtra soft-deleted
$notas = \array_values(\array_filter($notas, fn($n) => !isset($n['_deletedAt'])));

\header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notas — AxiDB demo</title>
    <link rel="stylesheet" href="notas.css">
</head>
<body>
<header class="app-header">
    <h1>Notas <span class="muted">— AxiDB demo</span></h1>
    <form method="get" class="search">
        <input type="search" name="q" value="<?= \htmlspecialchars($q) ?>"
               placeholder="Buscar en titulo o cuerpo..." autocomplete="off">
        <?php if ($q !== ''): ?>
            <a href="index.php" class="btn-ghost">Limpiar</a>
        <?php endif; ?>
    </form>
</header>

<main>
    <?php if (isset($_GET['ok'])): ?>
        <div class="banner ok">Nota creada: <?= \htmlspecialchars($_GET['ok']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['del'])): ?>
        <div class="banner ok">Nota borrada.</div>
    <?php endif; ?>

    <section class="creator">
        <h2>Nueva nota</h2>
        <form method="post" class="form-inline">
            <input type="hidden" name="action" value="create">
            <input name="title" placeholder="Titulo" maxlength="120" required>
            <textarea name="body" rows="3" placeholder="Cuerpo de la nota..." required></textarea>
            <button type="submit" class="btn-primary">Crear</button>
        </form>
    </section>

    <section class="list">
        <h2><?= \count($notas) ?> nota<?= \count($notas) === 1 ? '' : 's' ?>
            <?php if ($q !== ''): ?>
                <span class="muted">para "<?= \htmlspecialchars($q) ?>"</span>
            <?php endif; ?>
        </h2>

        <?php if (empty($notas)): ?>
            <p class="empty">
                <?= $q !== '' ? 'Sin coincidencias.' : 'No hay notas todavia. Crea la primera arriba.' ?>
            </p>
        <?php else: ?>
            <ul class="notas-grid">
                <?php foreach ($notas as $n): ?>
                    <li class="nota-card">
                        <a href="editor.php?id=<?= \urlencode($n['_id']) ?>" class="nota-link">
                            <h3><?= \htmlspecialchars($n['title'] ?? '(sin titulo)') ?></h3>
                            <p class="body-preview"><?= \htmlspecialchars(\mb_substr($n['body'] ?? '', 0, 200)) ?></p>
                            <footer>
                                <span class="ts"><?= \htmlspecialchars($n['_updatedAt'] ?? $n['_createdAt'] ?? '') ?></span>
                                <span class="ver">v<?= (int) ($n['_version'] ?? 1) ?></span>
                            </footer>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>

<footer class="app-footer">
    <span>AxiDB v1 · Caso B (embebido)</span>
    <span><?= \count($notas) ?> nota<?= \count($notas) === 1 ? '' : 's' ?> · sin DB externa · sin framework</span>
</footer>
<script src="notas.js" defer></script>
</body>
</html>
