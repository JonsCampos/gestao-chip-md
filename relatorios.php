<?php
require 'functions.php';
verificar_login();

// Busca as equipes para o filtro
$stmt = $db->query("SELECT id, nome FROM equipes ORDER BY nome ASC");
$equipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatórios - Gestão de Chips</title>
    <link rel="icon" href="assets/img/icone.png" type="image/png">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <style>
        .card-header-custom { background-color: #212529; color: white; }
        .btn-filter { font-size: 0.8rem; font-weight: 600; margin-bottom: 5px; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4 shadow-sm">
    <div class="container position-relative">
        <a class="navbar-brand fw-bold fs-5" href="painel.php">
            <img src="assets/img/logo.png" width="30" height="30" class="d-inline-block align-text-top me-2">
            Gestão de Chips
        </a>
        <span class="navbar-text text-white fs-5 fw-bold position-absolute top-50 start-50 translate-middle d-none d-lg-block">
            Relatórios Gerenciais
        </span>
        <div class="d-flex align-items-center">
            <span class="text-white me-3 fs-5 fw-bold d-none d-md-block"><?= htmlspecialchars($_SESSION['usuario_nome']) ?></span>
            <a href="logs.php" class="btn btn-sm btn-outline-light me-2 fw-bold" title="Histórico Geral">
                <i class="fas fa-history"></i> Logs
            </a>
            <a href="usuarios.php" class="btn btn-sm btn-outline-light me-2" title="Gerenciar Usuários">
                <i class="fas fa-user-cog"></i>
            </a>
            <a href="actions.php?acao=logout" class="btn btn-sm btn-danger fw-bold">Sair</a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    
    <div class="d-flex justify-content-between mb-4">
        <a href="painel.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <div class="row justify-content-center g-4">
        
        <div class="col-md-6">
            <div class="card shadow border-0 h-100">
                <div class="card-header card-header-custom py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-file-pdf me-2"></i>Relatório de Desempenho (PDF)</h5>
                </div>
                <div class="card-body p-4 d-flex flex-column">
                    <form action="gerar_pdf.php" method="POST" target="_blank" class="flex-grow-1 d-flex flex-column">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted text-uppercase">Períodos Rápidos:</label>
                            <div class="d-flex flex-wrap gap-1">
                                <button type="button" class="btn btn-sm btn-outline-dark btn-filter" onclick="setPeriodo('hoje')">Hoje</button>
                                <button type="button" class="btn btn-sm btn-outline-dark btn-filter" onclick="setPeriodo('ontem')">Ontem</button>
                                <button type="button" class="btn btn-sm btn-outline-dark btn-filter" onclick="setPeriodo('7dias')">Últimos 7 dias</button>
                                <button type="button" class="btn btn-sm btn-outline-dark btn-filter" onclick="setPeriodo('mes_atual')">Mês Atual</button>
                            </div>
                        </div>

                        <hr class="mb-4">

                        <div class="row">
                            <div class="col-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Data Inicial</label>
                                    <input type="date" name="data_inicio" id="data_inicio" class="form-control" required value="<?= date('Y-m-01') ?>">
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Data Final</label>
                                    <input type="date" name="data_fim" id="data_fim" class="form-control" required value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Filtrar por Equipe</label>
                            <select name="equipe_id" class="form-select">
                                <option value="todas">TODAS</option>
                                <?php foreach($equipes as $eq): ?>
                                    <option value="<?= $eq['id'] ?>"><?= htmlspecialchars($eq['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Geral ou por equipe específica.</div>
                        </div>

                        <div class="d-grid mt-auto">
                            <button type="submit" class="btn btn-danger btn-lg fw-bold">
                                <i class="fas fa-file-download me-2"></i> Gerar PDF
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow border-0 h-100">
                <div class="card-header bg-success text-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-file-excel me-2"></i>Lista de Chips da Equipe (Excel)</h5>
                </div>
                <div class="card-body p-4 d-flex flex-column">
                    
                    <div class="flex-grow-1">
                        <p class="text-muted">Gera uma planilha <strong>.xlsx</strong> contendo todos os chips atualmente vinculados à equipe selecionada, ordenados pela data de entrada no sistema.</p>
                        <hr>
                        <form action="gerar_excel.php" method="POST" id="formExcel">
                            <div class="mb-4">
                                <label class="form-label fw-bold">Selecione a Equipe <span class="text-danger">*</span></label>
                                <select name="equipe_id" class="form-select form-select-lg" required>
                                    <option value="" disabled <?= (array_search('DISPARO', array_column($equipes, 'nome')) === false) ? 'selected' : '' ?>>-- Escolha uma Equipe --</option>
                                    <?php foreach($equipes as $eq): ?>
                                        <option value="<?= $eq['id'] ?>" <?= (strtoupper($eq['nome']) === 'DISPARO') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($eq['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Este relatório requer uma equipe específica.</div>
                            </div>
                        </form>
                    </div>

                    <div class="d-grid mt-auto">
                        <button type="submit" form="formExcel" class="btn btn-success btn-lg fw-bold">
                            <i class="fas fa-file-excel me-2"></i> Baixar Arquivo .xlsx
                        </button>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
    function setPeriodo(tipo) {
        const hoje = new Date();
        let inicio = new Date();
        let fim = new Date();

        const formatar = (data) => {
            const y = data.getFullYear();
            const m = String(data.getMonth() + 1).padStart(2, '0');
            const d = String(data.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        };

        if (tipo === 'hoje') { } 
        else if (tipo === 'ontem') {
            inicio.setDate(hoje.getDate() - 1);
            fim.setDate(hoje.getDate() - 1);
        } 
        else if (tipo === '3dias') {
            inicio.setDate(hoje.getDate() - 2);
        } 
        else if (tipo === '7dias') {
            inicio.setDate(hoje.getDate() - 6);
        } 
        else if (tipo === 'mes_atual') {
            inicio = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
        } 
        else if (tipo === '3meses') {
            inicio = new Date(hoje.getFullYear(), hoje.getMonth() - 2, 1);
        }

        document.getElementById('data_inicio').value = formatar(inicio);
        document.getElementById('data_fim').value = formatar(fim);
    }
</script>
</body>
</html>