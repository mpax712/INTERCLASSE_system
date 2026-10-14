<?php
require_login();
ensure_suggestions_schema();

$user = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type = $_POST['type'] ?? 'melhoria';
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (!in_array($type, ['bug', 'melhoria', 'outro'], true)) {
        $errors[] = 'Tipo de sugestão inválido.';
    }

    if (strlen($title) < 4) {
        $errors[] = 'Informe um título com pelo menos 4 caracteres.';
    }

    if (strlen($description) < 10) {
        $errors[] = 'Descreva melhor a sugestão ou problema.';
    }

    if (!$errors) {
        $stmt = db()->prepare('INSERT INTO suggestions (user_id, type, title, description) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user['id'] ?? null, $type, $title, $description]);
        flash('Sugestão enviada. Obrigado por ajudar a melhorar o sistema.');
        redirect('index.php?page=suggestions');
    }
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Sugestões</h1>
        <p class="text-muted mb-0">Relate bugs ou ideias de melhoria para o sistema.</p>
    </div>
    <?php if (has_role(['admin'])): ?>
        <a class="btn btn-outline-primary" href="index.php?page=suggestion_records">Ver sugestões</a>
    <?php endif; ?>
</div>

<form method="post" class="card shadow-sm">
    <div class="card-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="type">Tipo</label>
                <select class="form-select" id="type" name="type" required>
                    <option value="melhoria">Sugestão de melhoria</option>
                    <option value="bug">Bug</option>
                    <option value="outro">Outro</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="title">Título</label>
                <input class="form-control" id="title" name="title" maxlength="160" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="description">Descrição</label>
                <textarea class="form-control" id="description" name="description" rows="6" required></textarea>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white text-end">
        <button class="btn btn-primary">Enviar sugestão</button>
    </div>
</form>
