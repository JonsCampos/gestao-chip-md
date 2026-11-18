<?php
require 'functions.php';
verificar_login();

$stmt = $db->query("SELECT * FROM usuarios ORDER BY nome ASC");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Usuários - Gestão de Chips</title>
    <link rel="icon" href="icone.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4 shadow-sm">
    <div class="container position-relative">
        <a class="navbar-brand fw-bold fs-5" href="painel.php">
            <img src="logo.png" width="30" height="30" class="d-inline-block align-text-top me-2">
            Gestão de Chips
        </a>

        <span class="navbar-text text-white fs-5 fw-bold position-absolute top-50 start-50 translate-middle d-none d-md-block">
            Gerenciar Usuários
        </span>

        <div class="d-flex align-items-center">
            <span class="text-white me-3 fs-5 fw-bold d-none d-lg-block"><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin') ?></span>
            
            <a href="logs.php" class="btn btn-sm btn-outline-light me-2 fw-bold" title="Histórico de Logs">
                <i class="fas fa-history"></i> Logs
            </a>

            <a href="actions.php?acao=logout" class="btn btn-sm btn-danger fw-bold">Sair</a>
        </div>
    </div>
</nav>

<div class="container">
    
    <div class="mb-4">
        <a href="painel.php" class="btn btn-secondary" title="Voltar ao Painel"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-users-cog"></i> Lista de Usuários</h4>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAddUser"><i class="fas fa-plus"></i> Novo Usuário</button>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Nome</th>
                        <th>Usuário (Login)</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($usuarios as $u): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($u['nome']) ?></td>
                        <td><?= htmlspecialchars($u['usuario']) ?></td>
                        <td class="text-end pe-3">
                            <button class="btn btn-sm btn-primary me-1" onclick="editarUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nome']) ?>', '<?= htmlspecialchars($u['usuario']) ?>')"><i class="fas fa-pen"></i></button>
                            <?php if($u['id'] != $_SESSION['usuario_id']): ?>
                                <button class="btn btn-sm btn-danger" onclick="deletarUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['nome']) ?>')"><i class="fas fa-trash"></i></button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled title="Você não pode se excluir"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAddUser" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><input type="hidden" name="acao" value="add_usuario"><div class="modal-header bg-success text-white"><h5 class="modal-title">Novo Usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" required></div><div class="mb-3"><label>Usuário (Login)</label><input type="text" name="usuario" class="form-control" required></div><div class="mb-3"><label>Senha</label><input type="password" name="senha" class="form-control" required></div></div><div class="modal-footer"><button type="submit" class="btn btn-success">Salvar</button></div></form></div></div></div>

<div class="modal fade" id="modalEditUser" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><input type="hidden" name="acao" value="edit_usuario"><input type="hidden" name="id" id="edit_id"><div class="modal-header bg-primary text-white"><h5 class="modal-title">Editar Usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Nome</label><input type="text" name="nome" id="edit_nome" class="form-control" required></div><div class="mb-3"><label>Usuário (Login)</label><input type="text" name="usuario" id="edit_usuario" class="form-control" required></div><div class="mb-3"><label>Nova Senha (deixe em branco para manter)</label><input type="password" name="senha" class="form-control" placeholder="******"></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>

<div class="modal fade" id="modalDelUser" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-danger text-white"><h5 class="modal-title">Excluir?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><p>Excluir usuário <strong id="del_nome"></strong>?</p></div><div class="modal-footer justify-content-center"><a href="#" id="link_del" class="btn btn-danger">Sim, Excluir</a></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editarUser(id, nome, usuario) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_nome').value = nome;
    document.getElementById('edit_usuario').value = usuario;
    new bootstrap.Modal(document.getElementById('modalEditUser')).show();
}
function deletarUser(id, nome) {
    document.getElementById('del_nome').innerText = nome;
    document.getElementById('link_del').href = 'actions.php?acao=delete_usuario&id=' + id;
    new bootstrap.Modal(document.getElementById('modalDelUser')).show();
}
</script>
</body>
</html>