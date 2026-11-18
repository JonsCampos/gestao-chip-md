<?php
require 'functions.php';
verificar_login();

// --- PARÂMETROS ---
$busca = trim($_GET['busca'] ?? '');
$filtro_tipo = $_GET['tipo'] ?? '';
$pagina = filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1;
$itens_por_pagina = 20;
$offset = ($pagina - 1) * $itens_por_pagina;

// --- DATAS (PADRÃO: ÚLTIMOS 7 DIAS) ---
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
$data_fim = $_GET['data_fim'] ?? date('Y-m-d');

// --- QUERY BASE ---
$sql_base = "FROM logs l 
             LEFT JOIN usuarios u_ator ON l.usuario_id = u_ator.id
             
             -- JOIN CHIP + EQUIPE DO CHIP
             LEFT JOIN chips c ON (l.alvo_tipo = 'chip' AND l.alvo_id = c.id) 
             LEFT JOIN equipes ec ON (c.equipe_id = ec.id)

             -- JOIN EQUIPE DIRETA
             LEFT JOIN equipes e ON (l.alvo_tipo = 'equipe' AND l.alvo_id = e.id)
             
             -- JOIN USUÁRIO ALVO
             LEFT JOIN usuarios u_alvo ON ((l.alvo_tipo = 'usuario' OR l.alvo_tipo = 'sistema') AND l.alvo_id = u_alvo.id)
             
             WHERE 1=1";

$params = [];

// 1. Filtro de Data (Sempre aplicado)
$sql_base .= " AND l.data_hora BETWEEN :inicio AND :fim";
$params[':inicio'] = $data_inicio . ' 00:00:00';
$params[':fim'] = $data_fim . ' 23:59:59';

// 2. Filtro por Tipo
if (!empty($filtro_tipo)) {
    $sql_base .= " AND l.alvo_tipo = :tipo";
    $params[':tipo'] = $filtro_tipo;
}

// 3. Filtro de Busca Texto
if (!empty($busca)) {
    $termo = "%$busca%";
    $tem_numero = preg_match('/\d/', $busca);
    $termo_limpo = "%" . preg_replace('/\D/', '', $busca) . "%";
    
    $condicoes = [];
    $condicoes[] = "u_ator.nome LIKE :busca_text";
    $condicoes[] = "u_alvo.nome LIKE :busca_text";
    $condicoes[] = "e.nome LIKE :busca_text";
    $condicoes[] = "ec.nome LIKE :busca_text";
    $condicoes[] = "l.detalhe LIKE :busca_text";

    $params[':busca_text'] = $termo;

    if ($tem_numero) {
        $condicoes[] = "c.numero LIKE :busca_num";
        $params[':busca_num'] = $termo_limpo;
    }
    
    $sql_base .= " AND (" . implode(' OR ', $condicoes) . ")";
}

// Contagem
$stmtCount = $db->prepare("SELECT COUNT(*) " . $sql_base);
$stmtCount->execute($params);
$total_registros = $stmtCount->fetchColumn();
$total_paginas = ceil($total_registros / $itens_por_pagina);

// Busca Final
$sql = "SELECT l.*, 
               l.usuario_nome as nome_ator_backup,
               u_ator.nome as nome_ator_real,
               c.numero as alvo_chip_numero,
               COALESCE(e.nome, ec.nome) as alvo_equipe_nome,
               u_alvo.nome as alvo_usuario_nome
        " . $sql_base . " ORDER BY l.data_hora DESC LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', $itens_por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

function url_filtro($pag, $tipo, $busca, $ini, $fim) {
    return "?pagina=$pag" . 
           (!empty($tipo) ? "&tipo=$tipo" : "") . 
           (!empty($busca) ? "&busca=" . urlencode($busca) : "") .
           "&data_inicio=$ini&data_fim=$fim";
}

$css_padrao = "
<style>
    .card-custom-header { background-color: #212529; color: white; padding: 10px 15px; }
    .badge-acao { font-size: 0.8em; font-weight: bold; min-width: 110px; padding: 7px 0; display: inline-block; text-align: center; }
    .badge-alvo { font-size: 0.75em; text-transform: uppercase; background-color: #f8f9fa; color: #212529; border: 1px solid #dee2e6; margin-right: 6px; }
    .table td { vertical-align: middle; padding-top: 12px; padding-bottom: 12px; }
    .search-container { max-width: 800px; } 
    .log-pipe { color: #ccc; margin: 0 5px; }
    .log-meta { color: #666; }
    .texto-alvo { font-weight: 600; color: #495057; white-space: nowrap; } 
    .badge-equipe-contexto { font-size: 0.7em; background-color: #6c757d; color: white; margin-left: 5px; vertical-align: middle; }
</style>
";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Logs - Gestão de Chips</title>
    <link rel="icon" href="icone.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <?= $css_padrao ?>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4 shadow-sm">
    <div class="container position-relative">
        <a class="navbar-brand fw-bold fs-5" href="painel.php">
            <img src="logo.png" width="30" height="30" class="d-inline-block align-text-top me-2">
            Gestão de Chips
        </a>
        <span class="navbar-text text-white fs-5 fw-bold position-absolute top-50 start-50 translate-middle d-none d-lg-block">
            Histórico de Logs
        </span>
        <div class="d-flex align-items-center">
            <span class="text-white me-3 fs-5 fw-bold d-none d-md-block"><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin') ?></span>
            <a href="usuarios.php" class="btn btn-sm btn-outline-light me-2" title="Gerenciar Usuários"><i class="fas fa-user-cog"></i></a>
            <a href="actions.php?acao=logout" class="btn btn-sm btn-danger fw-bold">Sair</a>
        </div>
    </div>
</nav>

<div class="container pb-5 mb-5">
    
    <div class="mb-4">
        <a href="painel.php" class="btn btn-secondary" title="Voltar ao Painel"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center justify-content-between">
                
                <div class="col-12 col-xl-auto">
                    <div class="btn-group w-100" role="group">
                        <a href="<?= url_filtro(1, '', $busca, $data_inicio, $data_fim) ?>" class="btn btn-sm <?= empty($filtro_tipo) ? 'btn-dark' : 'btn-outline-dark' ?>">Todos</a>
                        <a href="<?= url_filtro(1, 'chip', $busca, $data_inicio, $data_fim) ?>" class="btn btn-sm <?= $filtro_tipo == 'chip' ? 'btn-dark' : 'btn-outline-dark' ?>">Chips</a>
                        <a href="<?= url_filtro(1, 'equipe', $busca, $data_inicio, $data_fim) ?>" class="btn btn-sm <?= $filtro_tipo == 'equipe' ? 'btn-dark' : 'btn-outline-dark' ?>">Equipes</a>
                        <a href="<?= url_filtro(1, 'usuario', $busca, $data_inicio, $data_fim) ?>" class="btn btn-sm <?= $filtro_tipo == 'usuario' ? 'btn-dark' : 'btn-outline-dark' ?>">Usuários</a>
                        <a href="<?= url_filtro(1, 'sistema', $busca, $data_inicio, $data_fim) ?>" class="btn btn-sm <?= $filtro_tipo == 'sistema' ? 'btn-dark' : 'btn-outline-dark' ?>">Sistema</a>
                    </div>
                </div>

                <div class="col-12 col-xl-auto search-container flex-grow-1">
                    <form action="logs.php" method="GET" class="d-flex gap-2 flex-wrap flex-md-nowrap">
                        <?php if(!empty($filtro_tipo)): ?><input type="hidden" name="tipo" value="<?= htmlspecialchars($filtro_tipo) ?>"><?php endif; ?>
                        
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-muted">De</span>
                            <input type="date" name="data_inicio" class="form-control" value="<?= $data_inicio ?>" required>
                        </div>

                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light text-muted">Até</span>
                            <input type="date" name="data_fim" class="form-control" value="<?= $data_fim ?>" required>
                        </div>

                        <div class="input-group input-group-sm w-100">
                            <input type="text" name="busca" class="form-control" placeholder="Pesquisar..." value="<?= htmlspecialchars($busca) ?>">
                            <?php if(!empty($busca)): ?>
                                <a href="logs.php<?= !empty($filtro_tipo) ? '?tipo='.$filtro_tipo : '' ?>" class="btn btn-danger" title="Limpar e resetar data"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-custom-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-list"></i> Registros: <strong><?= $total_registros ?></strong></h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="160">Data/Hora</th>
                        <th width="15%">Usuário</th>
                        <th width="10%" class="text-center">Ação</th>
                        <th width="30%">Alvo</th>
                        <th>Detalhes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($logs as $log): ?>
                        <tr>
                            <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($log['data_hora'])) ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($log['nome_ator_real'] ?? $log['nome_ator_backup']) ?></td>
                            <td class="text-center">
                                <?php
                                    $cor = 'secondary';
                                    $txtAcao = strtoupper($log['acao']);
                                    if(strpos($txtAcao, 'CRIOU') !== false || strpos($txtAcao, 'CADASTROU') !== false || strpos($txtAcao, 'CONECTOU') !== false || strpos($txtAcao, 'IMPORTOU') !== false) $cor = 'success';
                                    elseif(strpos($txtAcao, 'EXCLUIU') !== false || strpos($txtAcao, 'BANIU') !== false || strpos($txtAcao, 'DESVINCULOU') !== false) $cor = 'danger';
                                    elseif(strpos($txtAcao, 'EDITOU') !== false || strpos($txtAcao, 'MOVEU') !== false) $cor = 'primary';
                                    elseif(strpos($txtAcao, 'LOGIN') !== false) $cor = 'info';
                                    elseif(strpos($txtAcao, 'STATUS') !== false) $cor = 'warning text-dark';
                                ?>
                                <span class="badge badge-acao bg-<?= $cor ?>"><?= $txtAcao ?></span>
                            </td>
                            <td>
                                <span class="badge badge-alvo"><?= strtoupper($log['alvo_tipo']) ?></span>
                                <span class="texto-alvo">
                                    <?php 
                                        if ($log['alvo_tipo'] == 'chip') {
                                            $chip_show = !empty($log['alvo_chip_numero']) ? formatar_numero($log['alvo_chip_numero']) : "#{$log['alvo_id']}";
                                            echo $chip_show;
                                            
                                            if (!empty($log['alvo_equipe_nome'])) {
                                                echo " <span class='badge badge-equipe-contexto' title='Equipe deste chip'>{$log['alvo_equipe_nome']}</span>";
                                            }
                                        } 
                                        elseif ($log['alvo_tipo'] == 'equipe') {
                                            echo !empty($log['alvo_equipe_nome']) ? htmlspecialchars($log['alvo_equipe_nome']) : "#{$log['alvo_id']}";
                                        }
                                        elseif ($log['alvo_tipo'] == 'usuario' || $log['alvo_tipo'] == 'sistema') {
                                            echo !empty($log['alvo_usuario_nome']) ? htmlspecialchars($log['alvo_usuario_nome']) : "#{$log['alvo_id']}";
                                        }
                                        else {
                                            echo "#" . $log['alvo_id'];
                                        }
                                    ?>
                                </span>
                            </td>
                            <td class="text-secondary small">
                                <?php 
                                    $texto = htmlspecialchars($log['detalhe']);
                                    
                                    // REGEX CORRIGIDA: Detecta 8, 9, 10 ou 11 dígitos isolados e formata
                                    $texto = preg_replace_callback('/\b(\d{8,11})\b/', function($m) { 
                                        return formatar_numero($m[1]); 
                                    }, $texto);

                                    $texto = preg_replace('/\(Eq:\s*(.+?)\)/', '| Equipe: $1', $texto);
                                    $texto = str_replace('|', '<span class="log-pipe">|</span>', $texto);
                                    $texto = preg_replace('/\[Estava em:(.*?)\]/', '<span class="log-meta">[Estava em:$1]</span>', $texto);
                                    
                                    if(!empty($busca)) {
                                        $texto = preg_replace('/('.preg_quote($busca, '/').')/i', '<mark class="p-0 bg-warning fw-bold">$1</mark>', $texto);
                                    }
                                    echo $texto;
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">Nenhum registro encontrado para esse período/busca.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if($total_paginas > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= url_filtro(1, $filtro_tipo, $busca, $data_inicio, $data_fim) ?>">&laquo; Primeira</a>
                </li>
                <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= url_filtro($pagina - 1, $filtro_tipo, $busca, $data_inicio, $data_fim) ?>">Anterior</a>
                </li>
                <?php 
                    $max_links = 10;
                    $inicio = max(1, $pagina - floor($max_links / 2));
                    $fim = min($total_paginas, $pagina + floor($max_links / 2));
                    if ($fim - $inicio < $max_links - 1) {
                        if ($inicio == 1) $fim = min($total_paginas, $inicio + $max_links - 1);
                        elseif ($fim == $total_paginas) $inicio = max(1, $fim - $max_links + 1);
                    }
                ?>
                <?php for($i = $inicio; $i <= $fim; $i++): ?>
                    <li class="page-item <?= ($pagina == $i) ? 'active' : '' ?>">
                        <a class="page-link" href="<?= url_filtro($i, $filtro_tipo, $busca, $data_inicio, $data_fim) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= url_filtro($pagina + 1, $filtro_tipo, $busca, $data_inicio, $data_fim) ?>">Próxima</a>
                </li>
                <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= url_filtro($total_paginas, $filtro_tipo, $busca, $data_inicio, $data_fim) ?>">Última &raquo;</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>