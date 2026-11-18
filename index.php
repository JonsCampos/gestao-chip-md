<?php
session_start();
// Se já estiver logado, manda para o painel
if (isset($_SESSION['usuario_id'])) {
    header("Location: painel.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Login - Gestão de Chips</title>
    <link rel="icon" href="icone.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { width: 100%; max-width: 400px; padding: 20px; border-radius: 10px; }
        .brand-logo { width: 60px; height: 60px; object-fit: contain; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="card login-card shadow border-0">
    <div class="card-body text-center">
        <img src="logo.png" class="brand-logo" alt="Logo">
        <h4 class="mb-4 fw-bold text-secondary">Gestão de Chips</h4>
        
        <?php if(isset($_GET['erro']) && $_GET['erro'] == 'dados_invalidos'): ?>
            <div class="alert alert-danger py-2 small">Usuário ou senha incorretos.</div>
        <?php endif; ?>

        <form action="actions.php" method="POST">
            <input type="hidden" name="acao" value="login">
            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="usuario" name="usuario" placeholder="Usuário" required autofocus>
                <label for="usuario">Usuário</label>
            </div>
            <div class="form-floating mb-4">
                <input type="password" class="form-control" id="senha" name="senha" placeholder="Senha" required>
                <label for="senha">Senha</label>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">ENTRAR</button>
        </form>
    </div>
    <div class="card-footer bg-white border-0 text-center text-muted small pb-3">
        &copy; <?= date('Y') ?> Sistema Interno
    </div>
</div>

</body>
</html>