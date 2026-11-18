<?php
require 'functions.php';
verificar_login();

// --- LÓGICA DE FEEDBACK DA IMPORTAÇÃO ---
$resultado_import = null;
if (isset($_SESSION['import_resultado'])) {
    $resultado_import = $_SESSION['import_resultado'];
    unset($_SESSION['import_resultado']); 
}

$id_equipe = $_GET['id'] ?? 0;
$stmt = $db->prepare("SELECT * FROM equipes WHERE id = :id");
$stmt->execute([':id' => $id_equipe]);
$equipe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$equipe) header("Location: painel.php");

$stmt = $db->prepare("SELECT id, nome FROM posicoes WHERE equipe_id = :id ORDER BY id ASC");
$stmt->execute([':id' => $id_equipe]);
$posicoes_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($posicoes_db)) {
    $padroes = ['DIGISAC 01', 'DIGISAC 02', 'WEB 01 SIM1', 'WEB 01 SIM2', 'WEB 02 SIM1', 'WEB 02 SIM2', 'WEB 03 SIM1', 'WEB 03 SIM2'];
    $sqlInsert = "INSERT INTO posicoes (equipe_id, nome) VALUES (:eid, :nome)";
    $stmtInsert = $db->prepare($sqlInsert);
    foreach ($padroes as $nome_pos) {
        $stmtInsert->execute([':eid' => $id_equipe, ':nome' => $nome_pos]);
    }
    header("Refresh:0");
    exit;
} else {
    $lista_posicoes = $posicoes_db;
}

// Verifica se houve requisição de busca
$realizou_busca = isset($_GET['busca_local']);
$busca_local = $_GET['busca_local'] ?? '';

$pagina = filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1;
$itens_por_pagina = 10;
$offset = ($pagina - 1) * $itens_por_pagina;

$resultados_local = [];
$total_paginas = 0;
$total_registros = 0;

if ($realizou_busca) {
    if (empty($busca_local)) {
        $parametro_busca = "%%"; 
    } else {
        $termo_limpo = limpar_numero($busca_local);
        $parametro_busca = "%" . (!empty($termo_limpo) ? $termo_limpo : $busca_local) . "%";
    }

    // SQL ATUALIZADO: Ignora +, parenteses, etc
    $sql_filtros = "equipe_id = :eid AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(numero, '-', ''), ' ', ''), '(', ''), ')', ''), '+', '') LIKE :busca";

    $sql_count = "SELECT COUNT(*) FROM chips WHERE $sql_filtros";
    $stmt_count = $db->prepare($sql_count);
    $stmt_count->execute([':eid' => $id_equipe, ':busca' => $parametro_busca]);
    $total_registros = $stmt_count->fetchColumn();
    $total_paginas = ceil($total_registros / $itens_por_pagina);

    // SQL COM DATA EVENTO
    $sql = "SELECT c.*, 
            (SELECT data_hora FROM logs WHERE alvo_tipo = 'chip' AND alvo_id = c.id ORDER BY id DESC LIMIT 1) as data_evento
            FROM chips c
            WHERE $sql_filtros
            ORDER BY CASE c.status WHEN 'uso' THEN 1 WHEN 'livre' THEN 2 WHEN 'analise' THEN 3 WHEN 'spam' THEN 4 WHEN 'banido' THEN 5 WHEN 'descartado' THEN 6 ELSE 7 END ASC, data_evento DESC
            LIMIT :limit OFFSET :offset";
            
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':eid', $id_equipe);
    $stmt->bindValue(':busca', $parametro_busca);
    $stmt->bindValue(':limit', $itens_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $resultados_local = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmt = $db->prepare("SELECT c.*, 
                      (SELECT data_hora FROM logs WHERE alvo_tipo = 'chip' AND alvo_id = c.id AND acao LIKE '%CONECTOU%' ORDER BY id DESC LIMIT 1) as data_conexao
                      FROM chips c WHERE c.equipe_id = :eid AND c.status = 'uso' AND c.posicao IS NOT NULL");
$stmt->execute([':eid' => $id_equipe]);
$chips_em_uso = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $chips_em_uso[$row['posicao']] = $row; }

$stmt = $db->prepare("SELECT * FROM chips WHERE equipe_id = :eid AND status = 'livre'");
$stmt->execute([':eid' => $id_equipe]);
$chips_livres = $stmt->fetchAll(PDO::FETCH_ASSOC);

$css_padrao = "<style>
    .card-custom-header { background-color: #212529; color: white; padding: 10px 15px; }
    .table-padrao th { background-color: #212529; color: white; vertical-align: middle; border: 1px solid #32383e; text-align: center; }
    .table-padrao td { vertical-align: middle; border: 1px solid #dee2e6; background-color: white; text-align: center; }
    .badge-status { font-size: 0.85em; padding: 6px 10px; }
    .btn-action { margin-right: 5px; } 
    .linha-definir, .linha-definir td, .linha-definir span, .linha-definir input { cursor: pointer !important; } 
    .linha-definir:hover { background-color: #e9ecef; } 
    .btn-icon-mini { width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; border-radius: 4px; border: none; } 
    .btn-icon-mini:hover { opacity: 0.8; }
</style>";
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Equipe: <?= htmlspecialchars($equipe['nome']) ?> - Gestão de Chips</title>
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
            Equipe: <?= htmlspecialchars($equipe['nome']) ?>
        </span>
        <div class="d-flex align-items-center">
            <span class="text-white me-3 fs-5 fw-bold d-none d-md-block"><?= htmlspecialchars($_SESSION['usuario_nome']) ?></span>
            <a href="logs.php" class="btn btn-sm btn-outline-light me-2 fw-bold text-white" title="Ver Histórico Geral"><i class="fas fa-history"></i> Logs</a>
            <a href="usuarios.php" class="btn btn-sm btn-outline-light me-2" title="Gerenciar Usuários"><i class="fas fa-user-cog"></i></a>
            <a href="actions.php?acao=logout" class="btn btn-sm btn-danger fw-bold">Sair</a>
        </div>
    </div>
</nav>

<div class="container mb-5">
    
    <div class="d-flex justify-content-between mb-3">
        <a href="painel.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddPosicao"><i class="fas fa-network-wired"></i> Nova Conexão</button>
            <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalImportarChips" title="Importar múltiplos números via CSV"><i class="fas fa-file-import"></i> Importar Números</button>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAddChipLocal"><i class="fas fa-plus"></i> Novo Número</button>
            <a href="banidos.php?id=<?= $id_equipe ?>" class="btn btn-dark"><i class="fas fa-list"></i> Histórico</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4 border-0">
        <div class="card-custom-header"><h6 class="mb-0"><i class="fas fa-search"></i> Pesquisa na Equipe</h6></div>
        <div class="card-body p-3">
            <form action="equipe.php" method="GET" class="row g-2 align-items-center">
                <input type="hidden" name="id" value="<?= $id_equipe ?>">
                <div class="col-md-10">
                    <div class="input-group">
                        <input type="text" name="busca_local" class="form-control" placeholder="Somente números..." value="<?= htmlspecialchars($busca_local) ?>" maxlength="20" oninput="mascaraNumero(this)">
                        <?php if($realizou_busca): ?>
                            <a href="equipe.php?id=<?= $id_equipe ?>" class="btn btn-danger" title="Limpar e Voltar"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-2 d-grid"><button type="submit" class="btn btn-primary fw-bold">Pesquisar</button></div>
            </form>

            <?php if($realizou_busca): ?>
                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="text-primary mb-0">
                        <?php if(empty($busca_local)): ?>
                            Listando <strong>todos</strong> os registros
                        <?php else: ?>
                            Resultados para: "<strong><?= htmlspecialchars($busca_local) ?></strong>"
                        <?php endif; ?>
                    </h6>
                    <span class="badge bg-secondary"><?= $total_registros ?> encontrados</span>
                </div>

                <?php if (count($resultados_local) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-padrao mb-0">
                            <thead><tr><th>Número</th><th>Status</th><th>Maturação</th><th>Digisac</th><th>Web</th><th>Spam</th><th>Análise</th><th>Ação</th></tr></thead>
                            <tbody>
                                <?php foreach($resultados_local as $res): ?>
                                    <tr>
                                        <td class="fw-bold"><?= formatar_numero($res['numero']) ?></td>
                                        <td>
                                            <?php 
                                                $st = $res['status']; $badge = 'bg-secondary';
                                                if($st == 'livre') $badge = 'bg-success'; elseif($st == 'uso') $badge = 'bg-primary'; elseif($st == 'banido') $badge = 'bg-danger'; elseif($st == 'spam') $badge = 'bg-warning text-dark'; elseif($st == 'analise') $badge = 'bg-info text-dark'; elseif($st == 'descartado') $badge = 'bg-dark';
                                            ?>
                                            <span class="badge badge-status <?= $badge ?>"><?= strtoupper($st) ?></span>
                                            
                                            <?php if (!empty($res['data_evento'])): ?>
                                                <span class="text-muted small ms-2" title="Última alteração">
                                                    <i class="far fa-clock"></i> <?= date('d/m H:i', strtotime($res['data_evento'])) ?>
                                                </span>
                                                <?php 
                                                    if (($st == 'spam' || $st == 'analise')) {
                                                        $horas = (float)($res['horas_penalidade'] ?? 0);
                                                        if ($horas > 0) {
                                                            $volta = strtotime($res['data_evento']) + ($horas * 3600);
                                                            echo ' - <span class="small text-muted" title="Previsão de volta"><i class="fas fa-undo-alt me-1"></i>' . date('d/m H:i', $volta) . '</span>';
                                                        }
                                                    }
                                                ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $res['maturado'] ? "<span class='badge bg-success'>{$res['dias_maturacao']} dias</span>" : "<span class='text-muted small'>-</span>" ?></td>
                                        <td><?= $res['contador_digisac'] ?></td><td><?= $res['contador_web'] ?></td><td><?= $res['contador_spam'] ?></td><td><?= $res['contador_analise'] ?></td>
                                        <td><button class="btn btn-sm btn-outline-primary" onclick="editarNumero(<?= $res['id'] ?>, '<?= $res['numero'] ?>')" title="Editar número"><i class="fas fa-pen"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if($total_paginas > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="?id=<?= $id_equipe ?>&busca_local=<?= urlencode($busca_local) ?>&pagina=1">&laquo; Primeira</a></li>
                            <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="?id=<?= $id_equipe ?>&busca_local=<?= urlencode($busca_local) ?>&pagina=<?= $pagina - 1 ?>">Anterior</a></li>
                            <?php for($i = max(1, $pagina - 5); $i <= min($total_paginas, $pagina + 5); $i++): ?>
                                <li class="page-item <?= ($pagina == $i) ? 'active' : '' ?>"><a class="page-link" href="?id=<?= $id_equipe ?>&busca_local=<?= urlencode($busca_local) ?>&pagina=<?= $i ?>"><?= $i ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>"><a class="page-link" href="?id=<?= $id_equipe ?>&busca_local=<?= urlencode($busca_local) ?>&pagina=<?= $pagina + 1 ?>">Próxima</a></li>
                            <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>"><a class="page-link" href="?id=<?= $id_equipe ?>&busca_local=<?= urlencode($busca_local) ?>&pagina=<?= $total_paginas ?>">Última &raquo;</a></li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                <?php else: ?><div class="alert alert-warning text-center py-2 mb-0">Nenhum número encontrado.</div><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover table-padrao mb-0">
                <thead><tr><th width="30%">Conexão</th><th>Número Atual</th><th width="30%">Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($lista_posicoes as $item_posicao): ?>
                        <?php 
                            $posicao = $item_posicao['nome']; $id_posicao_db = $item_posicao['id'];
                            $chip = $chips_em_uso[$posicao] ?? null; $tem_chip = !empty($chip); 
                        ?>
                        <tr>
                            <td class="text-secondary align-middle">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold fs-6 ps-2"><?= $posicao ?></span>
                                    <div class="d-flex gap-1"><button class="btn-icon-mini bg-primary text-white" onclick="editarPosicao(<?= $id_posicao_db ?>, '<?= $posicao ?>')"><i class="fas fa-pen"></i></button><button class="btn-icon-mini bg-danger text-white" onclick="confirmarExclusaoPosicao(<?= $id_posicao_db ?>, '<?= $posicao ?>')"><i class="fas fa-trash"></i></button></div>
                                </div>
                            </td>
                            <td class="<?= $tem_chip ? 'text-primary fw-bold fs-5' : 'text-muted fst-italic' ?>">
                                <?= $tem_chip ? formatar_numero($chip['numero']) : '--- Vazio ---' ?>
                                <?php if ($tem_chip && !empty($chip['data_conexao'])): ?>
                                    <span class="text-muted fw-normal ms-2 fs-6">
                                        <i class="far fa-clock"></i> <?= date('d/m H:i', strtotime($chip['data_conexao'])) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2">
                                <?php if ($tem_chip): ?>
                                    <div class="btn-group me-2"><button class="btn btn-sm btn-danger fw-bold" onclick="confirmarDesvinculo(<?= $chip['id'] ?>)"><i class="fas fa-times"></i></button><button class="btn btn-sm btn-secondary fw-bold" onclick="abrirModalTroca('<?= $posicao ?>')">Trocar</button></div>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-info text-dark fw-bold" onclick="confirmarAcao('analise', <?= $chip['id'] ?>, 'Número em análise?', 'bg-info text-dark')">Análise</button>
                                        <button class="btn btn-sm btn-warning fw-bold" onclick="abrirModalSpam(<?= $chip['id'] ?>)">Spam</button>
                                        <button class="btn btn-sm btn-danger fw-bold" onclick="confirmarAcao('banido', <?= $chip['id'] ?>, 'Número banido?', 'bg-danger text-white')">Banido</button>
                                    </div>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-success w-75 fw-bold" onclick="abrirModalTroca('<?= $posicao ?>')">Adicionar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($resultado_import): ?>
<div class="modal fade" id="modalResultadoImport" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-clipboard-check me-2"></i>Relatório da Importação</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row text-center mb-4">
                    <div class="col-6">
                        <div class="p-3 border rounded bg-success-subtle text-success">
                            <h2 class="fw-bold mb-0"><?= count($resultado_import['sucesso']) ?></h2>
                            <small class="fw-bold text-uppercase">Adicionados com Sucesso</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 border rounded bg-danger-subtle text-danger">
                            <h2 class="fw-bold mb-0"><?= count($resultado_import['duplicados']) ?></h2>
                            <small class="fw-bold text-uppercase">Duplicados (Ignorados)</small>
                        </div>
                    </div>
                </div>

                <?php if (count($resultado_import['duplicados']) > 0): ?>
                    <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-circle"></i> Lista de Duplicados:</h6>
                    <div class="table-responsive border rounded" style="max-height: 250px; overflow-y: auto;">
                        <table class="table table-sm table-striped mb-0" id="tabelaDuplicados">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Número</th>
                                    <th>Equipe Atual</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultado_import['duplicados'] as $dup): ?>
                                    <tr class="linha-duplicado">
                                        <td class="fw-bold text-muted"><?= formatar_numero($dup['numero']) ?></td>
                                        <td class="text-uppercase small fw-bold"><?= htmlspecialchars($dup['equipe']) ?></td>
                                        <td><span class="badge bg-danger">Já existe</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div id="paginacaoDup" class="d-flex justify-content-between align-items-center mt-2" style="display: none !important;">
                        <button class="btn btn-sm btn-outline-secondary" onclick="mudarPagina(-1)">Anterior</button>
                        <span class="small fw-bold">Página <span id="pagAtual">1</span> de <span id="pagTotal">1</span></span>
                        <button class="btn btn-sm btn-outline-secondary" onclick="mudarPagina(1)">Próxima</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer justify-content-center border-0">
                <button type="button" class="btn btn-dark px-4 fw-bold" data-bs-dismiss="modal">Entendi</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalAddChipLocal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><input type="hidden" name="acao" value="add_chip"><input type="hidden" name="equipe_id" value="<?= $id_equipe ?>"><input type="hidden" name="origem" value="equipe"><div class="modal-header bg-success text-white"><h5 class="modal-title">Novo Número</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label>Número</label><input type="text" name="numero" class="form-control mb-3" required placeholder="Somente números..." maxlength="20" oninput="mascaraNumero(this)"><div class="mb-3 form-check ps-4 text-start"><input type="checkbox" class="form-check-input" id="chkMaturadoLocal" name="maturado" onchange="toggleDias('chkMaturadoLocal', 'divDiasLocal')"><label class="form-check-label fw-bold" for="chkMaturadoLocal">Número Maturado?</label></div><div class="mb-3" id="divDiasLocal" style="display: none;"><label>Quantos dias?</label><input type="number" name="dias_maturacao" class="form-control" placeholder="Ex: 5"></div></div><div class="modal-footer"><button type="submit" class="btn btn-success">Salvar</button></div></form></div></div></div>
<div class="modal fade" id="modalAddPosicao" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><input type="hidden" name="acao" value="add_posicao"><input type="hidden" name="equipe_id" value="<?= $id_equipe ?>"><div class="modal-header bg-primary text-white"><h5 class="modal-title">Nova Conexão</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label>Nome da Conexão</label><input type="text" name="nome" class="form-control" required placeholder="Ex: WHATSAPP 01"></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>
<div class="modal fade" id="modalEditPosicao" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><input type="hidden" name="acao" value="edit_posicao"><input type="hidden" name="equipe_id" value="<?= $id_equipe ?>"><input type="hidden" name="id_posicao" id="edit_pos_id"><input type="hidden" name="nome_atual" id="edit_pos_nome_atual"><div class="modal-header bg-primary text-white"><h5 class="modal-title">Editar Conexão</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><label>Nome da Conexão</label><input type="text" name="nome" id="edit_pos_nome" class="form-control" required></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Atualizar</button></div></form></div></div></div>
<div class="modal fade" id="modalConfirmDeletePosicao" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow"><div class="modal-header bg-danger text-white border-0"><h5 class="modal-title">Excluir Conexão?</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center py-4"><p class="fs-5 mb-0" id="textoDeletePosicao"></p></div><div class="modal-footer justify-content-center border-0 gap-2"><a href="#" id="linkDeletePosicao" class="btn btn-success px-4 fw-bold">SIM</a><button type="button" class="btn btn-danger px-4 fw-bold" data-bs-dismiss="modal">NÃO</button></div></div></div></div>
<div class="modal fade" id="modalErroPosicao" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow"><div class="modal-header bg-danger text-white border-0"><h5 class="modal-title">Atenção</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center py-4"><h5 class="fw-bold mb-3">Ação bloqueada!</h5><p class="mb-0">Existe um número vinculado a esta conexão.</p></div><div class="modal-footer justify-content-center border-0"><button type="button" class="btn btn-secondary w-50" data-bs-dismiss="modal">Fechar</button></div></div></div></div>
<div class="modal fade" id="modalSelect" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content"><form action="actions.php" method="POST"><input type="hidden" name="acao" value="definir_slot"><input type="hidden" name="equipe_id" value="<?= $id_equipe ?>"><input type="hidden" name="posicao" id="modal_posicao_input"><div class="modal-header bg-primary text-white"><h5 class="modal-title">Definir: <span id="modal_posicao_titulo" class="fw-bold"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="p-3 bg-light border-bottom"><div class="input-group"><input type="text" id="filtroDefinir" class="form-control" placeholder="Somente números..." autocomplete="off" maxlength="20" oninput="mascaraNumero(this)"><button class="btn btn-danger" type="button" id="btnLimparDefinir" style="display: none;"><i class="fas fa-times"></i></button></div></div><div style="max-height: 300px; overflow-y: auto;"><table class="table table-hover mb-0 align-middle"><tbody id="tabelaDefinir"><?php foreach ($chips_livres as $livre): ?><tr class="linha-definir" onclick="this.querySelector('input').checked = true;"><td width="40px" class="ps-3 text-center"><input class="form-check-input" type="radio" name="chip_id" value="<?= $livre['id'] ?>" required></td><td class="fw-bold fs-5 text-start ps-3 numero-texto"><?= formatar_numero($livre['numero']) ?></td></tr><?php endforeach; ?><?php if(empty($chips_livres)): ?><tr id="msgListaVazia"><td colspan="2" class="text-center p-4 text-muted">Nenhum número livre encontrado.</td></tr><?php endif; ?><tr id="msgBuscaSemResultados" style="display: none;"><td colspan="2" class="text-center p-4 text-muted">Número <strong id="textoTermo"></strong> não encontrado.</td></tr></tbody></table></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary px-4">Salvar</button></div></form></div></div></div>
<div class="modal fade" id="modalConfirmacao" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow"><div id="headerModalConfirmacao" class="modal-header border-0"><h5 class="modal-title">Confirmar Ação</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body fs-5 py-4 text-center" id="textoConfirmacao"></div><div class="modal-footer justify-content-center border-0 gap-2"><a href="#" id="linkConfirmacao" class="btn btn-success px-4 fw-bold">SIM</a><button type="button" class="btn btn-danger px-4 fw-bold" data-bs-dismiss="modal">NÃO</button></div></div></div></div>
<div class="modal fade" id="modalDesvincular" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow"><div class="modal-header bg-danger text-white border-0"><h5 class="modal-title">Remover da Conexão?</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center pt-4 pb-4"><p class="fs-5 mb-4">O número ficará <strong>LIVRE</strong>.</p><div class="d-flex justify-content-center"><div class="form-check d-flex align-items-center gap-2"><input class="form-check-input m-0" type="checkbox" id="chkDecrementar" style="transform: scale(1.2); cursor: pointer;" checked><label class="form-check-label fw-bold mb-0" for="chkDecrementar" style="cursor: pointer;">Diminuir contador?</label></div></div></div><div class="modal-footer justify-content-center border-0 gap-2"><button type="button" class="btn btn-success px-4 fw-bold" onclick="executarDesvinculo()">SIM</button><button type="button" class="btn btn-danger px-4 fw-bold" data-bs-dismiss="modal">NÃO</button></div></div></div></div>

<div class="modal fade" id="modalSpam" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 bg-warning text-dark">
                <h5 class="modal-title fw-bold">Confirmar Ação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body fs-5 py-4 text-center">
                <p class="mb-3">Número tomou spam?</p>
                <div class="d-flex justify-content-center align-items-center gap-2">
                    <span class="fs-5">HORAS:</span>
                    <input type="text" id="spam_horas" class="form-control form-control-sm text-center fs-5" style="width: 100px;" placeholder="Ex: 4:30" maxlength="5" oninput="mascaraHoras(this)" onblur="formatarInputHoras(this)">
                </div>
            </div>
            <div class="modal-footer justify-content-center border-0 gap-2">
                <button type="button" class="btn btn-success px-4 fw-bold" onclick="confirmarSpam()">SIM</button>
                <button type="button" class="btn btn-danger px-4 fw-bold" data-bs-dismiss="modal">NÃO</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalImportarChips" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form action="actions.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="importar_csv">
                <input type="hidden" name="equipe_id" value="<?= $id_equipe ?>">
                <div class="modal-header bg-secondary text-white border-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-file-csv me-2"></i>Importar Números (CSV)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Arquivo CSV</label>
                        <input type="file" name="arquivo_csv" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer justify-content-center border-0">
                    <button type="submit" class="btn btn-secondary px-4 fw-bold">Importar Agora</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAlertaSpam" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 bg-danger text-white">
                <h5 class="modal-title fw-bold">Atenção</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body fs-5 py-4 text-center">
                <p class="mb-0">Por favor, informe o tempo de spam.</p>
            </div>
            <div class="modal-footer justify-content-center border-0">
                <button type="button" class="btn btn-secondary px-4 fw-bold" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditNumero" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><input type="hidden" name="acao" value="edit_chip"><input type="hidden" name="chip_id" id="edit_chip_id"><input type="hidden" name="origem" value="equipe"><input type="hidden" name="equipe_id" value="<?= $id_equipe ?>">
<input type="hidden" name="busca_local" value="<?= htmlspecialchars($busca_local) ?>">
<div class="modal-header bg-primary text-white"><h5 class="modal-title">Editar Chip</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Número:</label><input type="text" name="numero" id="edit_chip_numero" class="form-control fs-5 fw-bold" required maxlength="20" oninput="mascaraNumero(this)"></div></div><div class="modal-footer justify-content-center"><button type="submit" class="btn btn-primary fw-bold">Salvar Alterações</button></div></form></div></div></div>

<div class="modal fade" id="modalErroDuplicado" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow"><div class="modal-header bg-danger text-white border-0"><h5 class="modal-title">Atenção</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center py-4"><h5 class="fw-bold mb-0">Número já cadastrado!</h5></div><div class="modal-footer justify-content-center border-0"><button type="button" class="btn btn-secondary w-50" data-bs-dismiss="modal">Fechar</button></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let paginaAtualDup = 1; const itensPorPaginaDup = 5;
    function iniciarPaginacaoDup() { const linhas = document.querySelectorAll('.linha-duplicado'); const total = linhas.length; if (total > itensPorPaginaDup) { document.getElementById('paginacaoDup').style.display = 'flex'; document.getElementById('pagTotal').innerText = Math.ceil(total / itensPorPaginaDup); mostrarPaginaDup(1); } else { linhas.forEach(l => l.style.display = ''); } }
    function mostrarPaginaDup(pag) { const linhas = document.querySelectorAll('.linha-duplicado'); const total = linhas.length; const totalPags = Math.ceil(total / itensPorPaginaDup); if (pag < 1) pag = 1; if (pag > totalPags) pag = totalPags; paginaAtualDup = pag; document.getElementById('pagAtual').innerText = pag; const inicio = (pag - 1) * itensPorPaginaDup; const fim = inicio + itensPorPaginaDup; linhas.forEach((linha, index) => { if (index >= inicio && index < fim) { linha.style.display = ''; } else { linha.style.display = 'none'; } }); }
    function mudarPagina(delta) { mostrarPaginaDup(paginaAtualDup + delta); }
    document.addEventListener("DOMContentLoaded", function() { var elModal = document.getElementById('modalResultadoImport'); if (elModal) { new bootstrap.Modal(elModal).show(); iniciarPaginacaoDup(); } });

    const inputDefinir = document.getElementById('filtroDefinir'); const btnLimparDefinir = document.getElementById('btnLimparDefinir'); let idChipDesvincular = 0; let idChipSpam = 0;
    function filtrarDefinir() { var termo = inputDefinir.value.toLowerCase(); var termoLimpo = termo.replace(/[^0-9]/g, ''); var visiveis = 0; var temTexto = termo.length > 0; btnLimparDefinir.style.display = temTexto ? 'block' : 'none'; var msgVazia = document.getElementById('msgListaVazia'); if(msgVazia) msgVazia.style.display = temTexto ? 'none' : ''; document.querySelectorAll('.linha-definir').forEach(function(linha) { var numeroTexto = linha.querySelector('.numero-texto').innerText.toLowerCase(); var numeroLimpo = numeroTexto.replace(/[^0-9]/g, ''); if (numeroTexto.includes(termo) || numeroLimpo.includes(termoLimpo)) { linha.style.display = ""; visiveis++; } else { linha.style.display = "none"; } }); var msgBusca = document.getElementById('msgBuscaSemResultados'); if(msgBusca) { if(temTexto && visiveis === 0) { msgBusca.style.display = ""; document.getElementById('textoTermo').innerText = inputDefinir.value; } else { msgBusca.style.display = "none"; } } }
    inputDefinir.addEventListener('input', filtrarDefinir); btnLimparDefinir.addEventListener('click', function() { inputDefinir.value = ''; filtrarDefinir(); inputDefinir.focus(); });
    function abrirModalTroca(posicao) { document.getElementById('modal_posicao_input').value = posicao; document.getElementById('modal_posicao_titulo').innerText = posicao; inputDefinir.value = ''; filtrarDefinir(); document.querySelectorAll('input[name="chip_id"]').forEach(r => r.checked = false); new bootstrap.Modal(document.getElementById('modalSelect')).show(); }
    function toggleDias(chkId, divId) { var chk = document.getElementById(chkId); var div = document.getElementById(divId); var input = div.querySelector('input'); if(chk.checked) { div.style.display = "block"; input.required = true; } else { div.style.display = "none"; input.required = false; input.value = ""; } }
    function confirmarAcao(tipo, idChip, pergunta, classeCor) { document.getElementById('textoConfirmacao').innerText = pergunta; document.getElementById('headerModalConfirmacao').className = 'modal-header border-0 ' + classeCor; document.getElementById('linkConfirmacao').href = "actions.php?acao=alterar_status&equipe_id=<?= $id_equipe ?>&id=" + idChip + "&tipo=" + tipo; new bootstrap.Modal(document.getElementById('modalConfirmacao')).show(); }
    function abrirModalSpam(idChip) { idChipSpam = idChip; document.getElementById('spam_horas').value = ""; new bootstrap.Modal(document.getElementById('modalSpam')).show(); }
    function confirmarSpam() { let input = document.getElementById('spam_horas'); formatarInputHoras(input); let horas = input.value; if (!horas || horas === '0:00') { new bootstrap.Modal(document.getElementById('modalAlertaSpam')).show(); return; } window.location.href = "actions.php?acao=alterar_status&equipe_id=<?= $id_equipe ?>&id=" + idChipSpam + "&tipo=spam&horas=" + horas; }
    function mascaraHoras(input) { let v = input.value.replace(/\D/g, ""); if (v.length > 4) v = v.slice(0, 4); if (v.length > 2) { v = v.replace(/^(\d{2})(\d)/, "$1:$2"); } input.value = v; }
    function formatarInputHoras(input) { let val = input.value.trim(); if(!val) return; val = val.replace(/[^0-9:]/g, ''); if(!val.includes(':')) { input.value = val + ":00"; } else { let partes = val.split(':'); let hora = partes[0]; let min = partes[1]; if(min === undefined || min === "") { min = "00"; } else if (min.length === 1) { min = min + "0"; } input.value = hora + ":" + min; } }
    function confirmarDesvinculo(idChip) { idChipDesvincular = idChip; document.getElementById('chkDecrementar').checked = true; new bootstrap.Modal(document.getElementById('modalDesvincular')).show(); }
    function executarDesvinculo() { let decrementar = document.getElementById('chkDecrementar').checked ? 1 : 0; window.location.href = "actions.php?acao=desvincular_chip&id=" + idChipDesvincular + "&equipe_id=<?= $id_equipe ?>&decrementar=" + decrementar; }
    function editarNumero(id, numero) { document.getElementById('edit_chip_id').value = id; document.getElementById('edit_chip_numero').value = numero; new bootstrap.Modal(document.getElementById('modalEditNumero')).show(); }
    function editarPosicao(id, nome) { document.getElementById('edit_pos_id').value = id; document.getElementById('edit_pos_nome').value = nome; document.getElementById('edit_pos_nome_atual').value = nome; new bootstrap.Modal(document.getElementById('modalEditPosicao')).show(); }
    function confirmarExclusaoPosicao(id, nome) { document.getElementById('textoDeletePosicao').innerText = "Apagar conexão " + nome + "?"; document.getElementById('linkDeletePosicao').href = "actions.php?acao=delete_posicao&equipe_id=<?= $id_equipe ?>&id_posicao=" + id; new bootstrap.Modal(document.getElementById('modalConfirmDeletePosicao')).show(); }
    
    // --- NOVA MÁSCARA DINÂMICA (ATUALIZADA) ---
    function mascaraNumero(input) {
        let v = input.value.replace(/\D/g, ""); // Remove não-números
        
        if (v.length > 13) v = v.slice(0, 13); // Trava em 13 dígitos

        // 13 Digitos: +55 (11) 91234-5678
        if (v.length > 12) {
            v = v.replace(/^(\d{2})(\d{2})(\d{5})(\d{4})/, "+$1 ($2) $3-$4");
        } 
        // 12 Digitos: +55 (11) 5196-3908
        else if (v.length > 11) {
            v = v.replace(/^(\d{2})(\d{2})(\d{4})(\d{4})/, "+$1 ($2) $3-$4");
        }
        // 11 Dígitos: (11) 91234-5678 (COM ESPAÇO)
        else if (v.length > 10) { 
            v = v.replace(/^(\d{2})(\d{5})(\d{4})/, "($1) $2-$3");
        } 
        // 10 Dígitos: (11) 1234-5678 (COM ESPAÇO)
        else if (v.length > 9) { 
            v = v.replace(/^(\d{2})(\d{4})(\d{4})/, "($1) $2-$3");
        } 
        // 9 Dígitos: 91234-5678
        else if (v.length > 8) { 
            v = v.replace(/^(\d{5})(\d{4})/, "$1-$2");
        } 
        // 8 Dígitos: 1234-5678
        else if (v.length > 4) { 
            v = v.replace(/^(\d{4})(\d{0,4})/, "$1-$2");
        }
        
        input.value = v;
    }

    const urlParams = new URLSearchParams(window.location.search); if(urlParams.get('erro') === 'duplicado') { new bootstrap.Modal(document.getElementById('modalErroDuplicado')).show(); } if(urlParams.get('erro') === 'posicao_ocupada') { new bootstrap.Modal(document.getElementById('modalErroPosicao')).show(); }
</script>
</body>
</html>