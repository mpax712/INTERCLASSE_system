<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_user($email, $password)) {
        flash('Login realizado com sucesso.');
        redirect('index.php?page=dashboard');
    }

    flash('E-mail ou senha inválidos.', 'danger');
}
?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 mb-3">Entrar</h1>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input class="form-control" type="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Senha</label>
                        <div class="input-group">
                            <input class="form-control" type="password" name="password" id="login-password" required>
                            <button class="btn btn-outline-secondary" type="button" id="toggle-password" aria-label="Mostrar senha">Mostrar</button>
                        </div>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Entrar</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const password = document.getElementById('login-password');
    const toggle = document.getElementById('toggle-password');
    if (!password || !toggle) return;

    toggle.addEventListener('click', () => {
        const shouldShow = password.type === 'password';
        password.type = shouldShow ? 'text' : 'password';
        toggle.textContent = shouldShow ? 'Ocultar' : 'Mostrar';
        toggle.setAttribute('aria-label', shouldShow ? 'Ocultar senha' : 'Mostrar senha');
    });
})();
</script>
