<?php
require 'functions.php';
verificar_login();

// Verifica se houve requisição de busca
$realizou_busca = isset($_GET['busca']);
$busca = $_GET['busca'] ?? ''; 

$pagina = filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1;
$itens_por_pagina = 10;
$offset = ($pagina - 1) * $itens_por_pagina;

$resultados = [];
$total_paginas = 0;
$total_registros = 0;

if ($realizou_busca) {
    if (empty($busca)) {
        $parametro_busca = "%%";
    } else {
        $termo_limpo = limpar_numero($busca);
        $parametro_busca = empty($termo_limpo) ? "%$busca%" : "%$termo_limpo%";
    }

    // SQL ROBUSTO: Ignora traços, espaços, parenteses E O SINAL DE MAIS (+)
    $sql_filtros = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.numero, '-', ''), ' ', ''), '(', ''), ')', ''), '+', '') LIKE :busca";

    $sql_count = "SELECT COUNT(*) 
                  FROM chips c 
                  LEFT JOIN equipes e ON c.equipe_id = e.id 
                  WHERE $sql_filtros";
                  
    $stmt_count = $db->prepare($sql_count);
    $stmt_count->execute([':busca' => $parametro_busca]);
    $total_registros = $stmt_count->fetchColumn();
    $total_paginas = ceil($total_registros / $itens_por_pagina);

    // SQL COM ORDENAÇÃO DUPLA
    // data_evento = última movimentação qualquer; data_status = última mudança de STATUS (usada no "volta em")
    $sql = "SELECT c.*, e.nome as nome_equipe, e.id as id_equipe,
            (SELECT data_hora FROM logs WHERE alvo_tipo = 'chip' AND alvo_id = c.id ORDER BY id DESC LIMIT 1) as data_evento,
            (SELECT data_hora FROM logs WHERE alvo_tipo = 'chip' AND alvo_id = c.id AND UPPER(acao) IN ('STATUS','BANIU','DESCARTOU','RETORNOU') ORDER BY id DESC LIMIT 1) as data_status
            FROM chips c 
            LEFT JOIN equipes e ON c.equipe_id = e.id 
            WHERE $sql_filtros
            ORDER BY CASE c.status WHEN 'uso' THEN 1 WHEN 'livre' THEN 2 WHEN 'analise' THEN 3 WHEN 'spam' THEN 4 WHEN 'banido' THEN 5 WHEN 'descartado' THEN 6 ELSE 7 END ASC, data_evento DESC
            LIMIT :limit OFFSET :offset";
            
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':busca', $parametro_busca);
    $stmt->bindValue(':limit', $itens_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$equipes = $db->query("SELECT * FROM equipes ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Painel - Gestão de Chips</title>
    <link rel="icon" href="assets/img/icone.png" type="image/png">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-5 shadow-sm">
    <div class="container position-relative">
        
        <a class="navbar-brand fw-bold fs-5" href="painel.php">
            <img src="assets/img/logo.png" width="30" height="30" class="d-inline-block align-text-top me-2">
            Gestão de Chips
        </a>

        <div class="position-absolute top-50 start-50 translate-middle d-none d-md-block">
            <a href="relatorios.php" class="btn btn-outline-light fw-bold px-4">
                <i class="fas fa-file-pdf me-2"></i> Relatórios
            </a>
        </div>

        <div class="d-flex align-items-center">
            <span class="text-white me-3 fs-5 fw-bold d-none d-lg-block"><?= htmlspecialchars($_SESSION['usuario_nome']) ?></span>
            
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

<div class="container">

    <div class="card shadow-sm mb-4 border-0">
        <div class="card-custom-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-search"></i> Pesquisa Global</h6>
        </div>
        <div class="card-body p-3">
            <form action="painel.php" method="GET" class="row g-2 align-items-center">
                <div class="col-md-10">
                    <div class="input-group">
                        <input type="text" name="busca" class="form-control" placeholder="Somente números..." value="<?= htmlspecialchars($busca) ?>" maxlength="20" oninput="mascaraNumero(this)">
                        <?php if($realizou_busca): ?>
                            <a href="painel.php" class="btn btn-danger" title="Limpar pesquisa"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary fw-bold" title="Realizar pesquisa">Pesquisar</button>
                </div>
            </form>

            <?php if ($realizou_busca): ?>
                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="text-primary mb-0">
                        <?php if(empty($busca)): ?>
                            Listando <strong>todos</strong> os registros
                        <?php else: ?>
                            Resultados para: "<strong><?= htmlspecialchars($busca) ?></strong>"
                        <?php endif; ?>
                    </h6>
                    <span class="badge bg-secondary"><?= $total_registros ?> encontrados</span>
                </div>
                
                <?php if (count($resultados) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-padrao mb-0">
                            <thead>
                                <tr>
                                    <th>Número</th>
                                    <th>Equipe</th>
                                    <th>Status</th>
                                    <th>Maturação</th>
                                    <th>Digisac</th>
                                    <th>Web</th>
                                    <th>Spam</th>
                                    <th>Análise</th>
                                    <th width="180px">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($resultados as $res): ?>
                                    <tr>
                                        <td class="fw-bold"><?= formatar_numero($res['numero']) ?></td>
                                        <td class="td-equipe">
                                            <?php if($res['nome_equipe']): ?>
                                                <span class="fw-bold text-uppercase small"><?= htmlspecialchars($res['nome_equipe']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">SEM EQUIPE</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $st = $res['status']; $badge = 'bg-secondary';
                                                if($st == 'livre') $badge = 'bg-success';
                                                elseif($st == 'uso') $badge = 'bg-primary';
                                                elseif($st == 'banido') $badge = 'bg-danger';
                                                elseif($st == 'spam') $badge = 'bg-warning text-dark';
                                                elseif($st == 'analise') $badge = 'bg-info text-dark';
                                                elseif($st == 'descartado') $badge = 'bg-dark';
                                            ?>
                                            <span class="badge badge-status <?= $badge ?>"><?= strtoupper($st) ?></span>
                                            
                                            <?php if (!empty($res['data_evento'])): ?>
                                                <span class="text-muted small ms-2"><i class="far fa-clock"></i> <?= date('d/m H:i', strtotime($res['data_evento'])) ?></span>
                                                
                                                <?php 
                                                    // CÁLCULO VOLTA EM (SPAM/ANALISE) - parte da última mudança de STATUS
                                                    if (($st == 'spam' || $st == 'analise')) {
                                                        $horas = (float)($res['horas_penalidade'] ?? 0);
                                                        if ($horas > 0) {
                                                            $base_volta = !empty($res['data_status']) ? $res['data_status'] : $res['data_evento'];
                                                            $volta = strtotime($base_volta) + ($horas * 3600);
                                                            echo ' - <span class="small text-muted" title="Previsão de volta"><i class="fas fa-undo-alt me-1"></i>' . date('d/m H:i', $volta) . '</span>';
                                                        }
                                                    }
                                                ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($res['maturado']): ?>
                                                <span class="badge bg-success"><?= (int)$res['dias_maturacao'] ?> dias</span>
                                            <?php else: ?>
                                                <span class="text-muted small">Não</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold"><?= $res['contador_digisac'] ?></td>
                                        <td class="fw-bold"><?= $res['contador_web'] ?></td>
                                        <td class="fw-bold text-muted"><?= $res['contador_spam'] ?></td>
                                        <td class="fw-bold text-muted"><?= $res['contador_analise'] ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary btn-action" 
                                                onclick="editarNumero(
                                                    <?= (int)$res['id'] ?>, 
                                                    <?= js_attr($res['numero']) ?>, 
                                                    <?= (int)($res['equipe_id'] ?? 0) ?>, 
                                                    <?= js_attr($res['status']) ?>, 
                                                    <?= js_attr($res['posicao'] ?? '') ?>, 
                                                    <?= js_attr($res['nome_equipe'] ?? '') ?>
                                                )" 
                                                title="Editar ou Corrigir número"><i class="fas fa-pen"></i></button>
                                            
                                            <?php if($res['id_equipe']): ?>
                                                <a href="equipe.php?id=<?= (int)$res['id_equipe'] ?>" class="btn btn-sm btn-outline-dark" title="Ir para a equipe deste número">Ir para equipe</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if($total_paginas > 1): ?>
                    <?php
                        $max_links = 10;
                        $inicio = max(1, $pagina - floor($max_links / 2));
                        $fim = min($total_paginas, $pagina + floor($max_links / 2));
                        if ($fim - $inicio < $max_links - 1) {
                            if ($inicio == 1) {
                                $fim = min($total_paginas, $inicio + $max_links - 1);
                            } elseif ($fim == $total_paginas) {
                                $inicio = max(1, $fim - $max_links + 1);
                            }
                        }
                    ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?busca=<?= urlencode($busca) ?>&pagina=1" title="Ir para a primeira página">&laquo; Primeira</a>
                            </li>
                            <li class="page-item <?= ($pagina <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?busca=<?= urlencode($busca) ?>&pagina=<?= $pagina - 1 ?>">Anterior</a>
                            </li>

                            <?php for($i = $inicio; $i <= $fim; $i++): ?>
                                <li class="page-item <?= ($pagina == $i) ? 'active' : '' ?>">
                                    <a class="page-link" href="?busca=<?= urlencode($busca) ?>&pagina=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?busca=<?= urlencode($busca) ?>&pagina=<?= $pagina + 1 ?>">Próxima</a>
                            </li>
                            <li class="page-item <?= ($pagina >= $total_paginas) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?busca=<?= urlencode($busca) ?>&pagina=<?= $total_paginas ?>" title="Ir para a última página">Última &raquo;</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="alert alert-warning text-center py-2 mb-0">Nenhum número encontrado.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-users"></i> Equipes</h4>
        <div>
            <button class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#modalEquipe" title="Criar nova equipe"><i class="fas fa-plus"></i> Nova Equipe</button>
            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalChip" title="Adicionar novo número ao sistema"><i class="fas fa-plus"></i> Novo Número</button>
        </div>
    </div>
    
    <div class="card shadow-sm border-0 mb-5">
        <div class="list-group list-group-flush">
            <?php foreach($equipes as $eq): ?>
                <div class="list-group-item list-group-item-action p-3 d-flex justify-content-between align-items-center">
                    <a href="equipe.php?id=<?= (int)$eq['id'] ?>" class="text-decoration-none text-dark flex-grow-1" title="Abrir equipe <?= htmlspecialchars($eq['nome']) ?>">
                        <h6 class="mb-0 fw-bold"><?= htmlspecialchars($eq['nome']) ?></h6>
                    </a>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary me-1" onclick="editarEquipe(<?= (int)$eq['id'] ?>, <?= js_attr($eq['nome']) ?>)" title="Editar nome da equipe"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-outline-danger" onclick="confirmarExclusaoEquipe(<?= (int)$eq['id'] ?>, <?= js_attr($eq['nome']) ?>)" title="Excluir equipe"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="text-center text-muted small mb-5">
        Total de Equipes: <strong><?= count($equipes) ?></strong>
    </div>
</div>

<div class="modal fade" id="modalEquipe" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="add_equipe"><div class="modal-header bg-primary text-white"><h5 class="modal-title">Nova Equipe</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" name="nome" class="form-control" required placeholder="Nome"></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Salvar</button></div></form></div></div></div>

<div class="modal fade" id="modalChip" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="add_chip"><div class="modal-header bg-success text-white"><h5 class="modal-title">Novo Chip</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Número</label><input type="text" name="numero" class="form-control" required placeholder="Somente números..." maxlength="20" oninput="mascaraNumero(this)"></div><div class="mb-3"><label>Equipe</label><select name="equipe_id" class="form-select" required><option value="">Selecione...</option><?php foreach($equipes as $eq): ?><option value="<?= (int)$eq['id'] ?>"><?= htmlspecialchars($eq['nome']) ?></option><?php endforeach; ?></select></div><div class="mb-3 form-check"><input type="checkbox" class="form-check-input" id="chkMaturadoIndex" name="maturado" onchange="toggleDias('chkMaturadoIndex', 'divDiasIndex')"><label class="form-check-label fw-bold" for="chkMaturadoIndex">Número Maturado?</label></div><div class="mb-3" id="divDiasIndex" style="display: none;"><label>Quantos dias de maturação?</label><input type="number" name="dias_maturacao" class="form-control" placeholder="Ex: 5"></div></div><div class="modal-footer"><button type="submit" class="btn btn-success">Salvar</button></div></form></div></div></div>

<div class="modal fade" id="modalEditEquipe" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form action="actions.php" method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="edit_equipe"><input type="hidden" name="id" id="edit_id"><div class="modal-header bg-secondary text-white"><h5 class="modal-title">Editar Equipe</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" name="nome" id="edit_nome" class="form-control" required></div><div class="modal-footer"><button type="submit" class="btn btn-secondary">Atualizar</button></div></form></div></div></div>

<div class="modal fade" id="modalEditNumero" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="actions.php" method="POST" id="formEditNumero">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="edit_chip">
                <input type="hidden" name="chip_id" id="edit_chip_id">
                <input type="hidden" id="edit_equipe_original">
                <input type="hidden" id="edit_status_atual">
                <input type="hidden" id="edit_posicao_atual">
                <input type="hidden" id="edit_nome_equipe_original">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Editar Chip</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Número:</label>
                        <input type="text" name="numero" id="edit_chip_numero" class="form-control fs-5 fw-bold" required maxlength="20" oninput="mascaraNumero(this)">
                    </div>
                    <div class="mb-3">
                        <label>Equipe:</label>
                        <select name="equipe_id" id="edit_chip_equipe" class="form-select" required>
                            <option value="">-- SEM EQUIPE --</option>
                            <?php foreach($equipes as $eq): ?>
                                <option value="<?= (int)$eq['id'] ?>"><?= htmlspecialchars($eq['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" onclick="validarEdicao()" class="btn btn-primary fw-bold">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalErroDuplicado" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow"><div class="modal-header bg-danger text-white border-0"><h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Atenção</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center py-4"><h5 class="fw-bold mb-0">Número já cadastrado!</h5></div><div class="modal-footer justify-content-center border-0"><button type="button" class="btn btn-secondary w-50" data-bs-dismiss="modal">Fechar</button></div></div></div></div>
<div class="modal fade" id="modalConfirmacaoExclusao" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow"><div class="modal-header bg-danger text-white border-0"><h5 class="modal-title">Excluir Equipe?</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center py-4"><p id="textoConfirmacaoExclusao" class="fw-bold mb-0"></p><small class="text-danger">Os chips serão mantidos sem equipe. Chips em uso serão liberados.</small></div><div class="modal-footer justify-content-center border-0 gap-2"><a href="#" id="linkConfirmacaoExclusao" class="btn btn-success px-4 fw-bold">SIM</a><button type="button" class="btn btn-danger px-4 fw-bold" data-bs-dismiss="modal">NÃO</button></div></div></div></div>

<div class="modal fade" id="modalAlertaBloqueio" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title"><i class="fas fa-ban"></i> Ação Bloqueada</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="fs-5" id="msgAlertaBloqueio"></p>
            </div>
            <div class="modal-footer justify-content-center border-0">
                <button type="button" class="btn btn-secondary w-50" onclick="voltarParaEdicao(true)">Entendi</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalConfirmacaoTroca" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title">Mover Chip?</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="mb-0">O chip será movido para:</p>
                <h5 class="fw-bold my-2" id="nomeNovaEquipeConfirm"></h5>
                <p class="small text-muted mb-0">Tem certeza?</p>
            </div>
            <div class="modal-footer justify-content-center border-0 gap-2">
                <button type="button" class="btn btn-success px-4 fw-bold" onclick="document.getElementById('formEditNumero').submit()">SIM</button>
                <button type="button" class="btn btn-danger px-4 fw-bold" onclick="voltarParaEdicao(false)">NÃO</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/app.js"></script>
<script>
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;

    function editarEquipe(id, nome) { document.getElementById('edit_id').value = id; document.getElementById('edit_nome').value = nome; new bootstrap.Modal(document.getElementById('modalEditEquipe')).show(); }
    
    let modalEditInstance = null;
    let modalBlockInstance = null;
    let modalConfirmInstance = null;

    function editarNumero(id, numero, equipeId, status, posicao, nomeEquipe) { 
        document.getElementById('edit_chip_id').value = id; 
        document.getElementById('edit_chip_numero').value = numero; 
        document.getElementById('edit_chip_equipe').value = equipeId; 
        
        document.getElementById('edit_equipe_original').value = equipeId;
        document.getElementById('edit_status_atual').value = status;
        document.getElementById('edit_posicao_atual').value = posicao;
        document.getElementById('edit_nome_equipe_original').value = nomeEquipe;

        var el = document.getElementById('modalEditNumero');
        modalEditInstance = new bootstrap.Modal(el);
        modalEditInstance.show(); 
    }

    function validarEdicao() {
        let equipeOriginal = document.getElementById('edit_equipe_original').value;
        let equipeNova = document.getElementById('edit_chip_equipe').value;
        let status = document.getElementById('edit_status_atual').value;
        let posicao = document.getElementById('edit_posicao_atual').value;
        
        if (equipeOriginal != equipeNova) {
            modalEditInstance.hide();

            if (status === 'banido' || status === 'descartado') {
                document.getElementById('msgAlertaBloqueio').innerText = "Não é possível mover chips Banidos ou Descartados.";
                var elBlock = document.getElementById('modalAlertaBloqueio');
                modalBlockInstance = new bootstrap.Modal(elBlock);
                modalBlockInstance.show();
                return;
            }
            if (posicao && posicao.trim() !== "") {
                document.getElementById('msgAlertaBloqueio').innerText = "Chip ativo na conexão " + posicao + ". Desvincule antes de mover.";
                var elBlock = document.getElementById('modalAlertaBloqueio');
                modalBlockInstance = new bootstrap.Modal(elBlock);
                modalBlockInstance.show();
                return;
            }
            
            let select = document.getElementById('edit_chip_equipe');
            let nomeNova = select.options[select.selectedIndex].text;
            document.getElementById('nomeNovaEquipeConfirm').innerText = nomeNova;
            
            var elConfirm = document.getElementById('modalConfirmacaoTroca');
            modalConfirmInstance = new bootstrap.Modal(elConfirm);
            modalConfirmInstance.show();
        } else {
            document.getElementById('formEditNumero').submit();
        }
    }

    function voltarParaEdicao(isBloqueio) {
        if (isBloqueio && modalBlockInstance) {
            modalBlockInstance.hide();
        } else if (modalConfirmInstance) {
            modalConfirmInstance.hide();
        }
        if (modalEditInstance) {
            modalEditInstance.show();
        }
    }

    // Exclusão de equipe (via POST + CSRF)
    let idEquipeExcluir = 0;
    function confirmarExclusaoEquipe(id, nome) {
        idEquipeExcluir = id;
        document.getElementById('textoConfirmacaoExclusao').innerText = "Apagar " + nome + "?";
        new bootstrap.Modal(document.getElementById('modalConfirmacaoExclusao')).show();
    }
    document.getElementById('linkConfirmacaoExclusao').addEventListener('click', function(e) {
        e.preventDefault();
        postAcao({ acao: 'delete_equipe', id: idEquipeExcluir });
    });

    const urlParams = new URLSearchParams(window.location.search); 
    if(urlParams.get('erro') === 'duplicado') { new bootstrap.Modal(document.getElementById('modalErroDuplicado')).show(); }
    if(urlParams.get('erro') === 'bloqueio_status') { 
        document.getElementById('msgAlertaBloqueio').innerText = "Ação bloqueada pelo servidor: Status inválido.";
        new bootstrap.Modal(document.getElementById('modalAlertaBloqueio')).show(); 
    }
    if(urlParams.get('erro') === 'bloqueio_ativo') { 
        document.getElementById('msgAlertaBloqueio').innerText = "Ação bloqueada pelo servidor: Chip em uso.";
        new bootstrap.Modal(document.getElementById('modalAlertaBloqueio')).show(); 
    }
</script>
</body>
</html>