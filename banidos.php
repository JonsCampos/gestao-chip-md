<?php
require 'functions.php';
verificar_login();

$id_equipe = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM equipes WHERE id = :id");
$stmt->execute([':id' => $id_equipe]);
$equipe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$equipe) { header("Location: painel.php"); exit; }

// data_evento = data da última mudança de STATUS (se não houver, a última movimentação qualquer)
// Assim "Desde" e "Volta em" não mudam por causa de edições/trocas que não alteram o status
$sql = "SELECT c.*, 
        COALESCE(
            (SELECT data_hora FROM logs WHERE alvo_tipo = 'chip' AND alvo_id = c.id AND UPPER(acao) IN ('STATUS','BANIU','DESCARTOU','RETORNOU') ORDER BY id DESC LIMIT 1),
            (SELECT data_hora FROM logs WHERE alvo_tipo = 'chip' AND alvo_id = c.id ORDER BY id DESC LIMIT 1)
        ) as data_evento
        FROM chips c 
        WHERE c.equipe_id = :eid AND c.status IN ('analise', 'spam', 'banido', 'descartado') 
        ORDER BY CASE c.status WHEN 'analise' THEN 1 WHEN 'spam' THEN 2 WHEN 'banido' THEN 3 WHEN 'descartado' THEN 4 ELSE 5 END ASC, data_evento DESC";

$stmt = $db->prepare($sql);
$stmt->execute([':eid' => $id_equipe]);
$chips_ruins = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stats = ['total' => count($chips_ruins), 'analise' => 0, 'spam' => 0, 'banido' => 0, 'descartado' => 0];
foreach ($chips_ruins as $c) { if (isset($stats[$c['status']])) $stats[$c['status']]++; }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Histórico: <?= htmlspecialchars($equipe['nome']) ?> - Gestão de Chips</title>
    <link rel="icon" href="assets/img/icone.png" type="image/png">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4 shadow-sm">
    <div class="container position-relative">
        <a class="navbar-brand fw-bold fs-5" href="painel.php">
            <img src="assets/img/logo.png" width="30" height="30" class="d-inline-block align-text-top me-2">
            Gestão de Chips
        </a>

        <span class="navbar-text text-white fs-5 fw-bold position-absolute top-50 start-50 translate-middle d-none d-md-block">
            Histórico: <?= htmlspecialchars($equipe['nome']) ?>
        </span>

        <div class="d-flex align-items-center">
            <span class="text-white me-3 fs-5 fw-bold d-none d-lg-block"><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin') ?></span>
            
            <a href="logs.php" class="btn btn-sm btn-outline-light me-2 fw-bold" title="Ver Histórico Geral">
                <i class="fas fa-history"></i> Logs
            </a>

            <a href="usuarios.php" class="btn btn-sm btn-outline-light me-2" title="Gerenciar Usuários"><i class="fas fa-user-cog"></i></a>
            <a href="actions.php?acao=logout" class="btn btn-sm btn-danger fw-bold">Sair</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <a href="equipe.php?id=<?= $id_equipe ?>" class="btn btn-secondary" title="Voltar para a equipe"><i class="fas fa-arrow-left"></i> Voltar para Equipe</a>
    </div>

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-custom-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-clipboard-list"></i> Lista de Exceções</h6>
            <div style="width: 300px;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-secondary text-white border-secondary"><i class="fas fa-search"></i></span>
                    <input type="text" id="filtroInput" class="form-control" placeholder="Somente números..." maxlength="20" oninput="mascaraNumero(this)">
                    <button class="btn btn-danger" type="button" id="btnLimpar" style="display: none;" title="Limpar filtro">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover table-padrao mb-0">
                <thead>
                    <tr>
                        <th class="text-center">Número</th>
                        <th class="text-center">Status</th>
                        <th class="text-center" width="140px">Desde</th>
                        <th class="text-center" width="140px">Volta em</th>
                        <th class="text-center">Maturação</th>
                        <th class="text-center">Digisac</th>
                        <th class="text-center">Web</th>
                        <th class="text-center">Spam</th>
                        <th class="text-center">Análise</th>
                        <th class="text-center" width="200px">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabelaCorpo">
                    <?php foreach($chips_ruins as $chip): ?>
                        <tr class="linha-numero" data-status="<?= htmlspecialchars($chip['status']) ?>">
                            <td class="text-center fw-bold numero-texto"><?= formatar_numero($chip['numero']) ?></td>
                            <td class="text-center">
                                <?php 
                                    $st = $chip['status']; $badge = 'bg-secondary';
                                    if($st == 'analise') $badge = 'bg-info text-dark';
                                    elseif($st == 'spam') $badge = 'bg-warning text-dark';
                                    elseif($st == 'banido') $badge = 'bg-danger';
                                    elseif($st == 'descartado') $badge = 'bg-dark';
                                ?>
                                <span class="badge badge-status <?= $badge ?> w-100"><?= strtoupper($st) ?></span>
                            </td>
                            
                            <td class="text-center fw-bold">
                                <?php if(!empty($chip['data_evento'])): ?>
                                    <i class="far fa-clock small me-1 fw-normal text-muted"></i><?= date('d/m H:i', strtotime($chip['data_evento'])) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td class="text-center fw-bold">
                                <?php 
                                    if (($chip['status'] == 'spam' || $chip['status'] == 'analise') && !empty($chip['data_evento'])) {
                                        $horas = (float)($chip['horas_penalidade'] ?? 0);
                                        if ($horas > 0) {
                                            $timestamp_volta = strtotime($chip['data_evento']) + ($horas * 3600);
                                            echo '<i class="fas fa-undo-alt small me-1 text-muted"></i>' . date('d/m H:i', $timestamp_volta);
                                        } else {
                                            echo "-";
                                        }
                                    } else {
                                        echo "-";
                                    }
                                ?>
                            </td>

                            <td class="text-center"><?= $chip['maturado'] ? "<span class='badge bg-success'>" . (int)$chip['dias_maturacao'] . " dias</span>" : "<span class='text-muted small'>-</span>" ?></td>
                            <td class="fw-bold"><?= $chip['contador_digisac'] ?></td>
                            <td class="fw-bold"><?= $chip['contador_web'] ?></td>
                            <td class="fw-bold text-muted"><?= $chip['contador_spam'] ?></td>
                            <td class="fw-bold text-muted"><?= $chip['contador_analise'] ?></td>
                            <td class="text-center">
                                <?php if($chip['status'] == 'banido' || $chip['status'] == 'descartado'): ?>
                                    <button class="btn btn-sm btn-light text-muted border w-100" disabled>Sem ação</button>
                                <?php else: ?>
                                    <div class="d-flex justify-content-center gap-2">
                                        <button class="btn btn-sm btn-success fw-bold" onclick="confirmarAcaoBanidos('retornou', <?= (int)$chip['id'] ?>, 'Número voltou?', 'bg-success text-white')" title="Retornar para o estoque (Livre)">Retornou</button>
                                        <button class="btn btn-sm btn-danger fw-bold" onclick="confirmarAcaoBanidos('banir', <?= (int)$chip['id'] ?>, 'Número banido?', 'bg-danger text-white')" title="Marcar como Banido">Baniu</button>
                                        <button class="btn btn-sm btn-dark fw-bold" onclick="confirmarAcaoBanidos('descartar', <?= (int)$chip['id'] ?>, 'Descartar permanentemente?', 'bg-dark text-white')" title="Descartar permanentemente">Descartar</button>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div id="areaMensagens">
                <?php if(empty($chips_ruins)): ?>
                    <div id="msgListaVazia" class="p-5 text-center text-muted">Nenhum número encontrado.</div>
                <?php endif; ?>

                <div id="msgBuscaSemResultados" class="p-5 text-center text-muted" style="display: none;">
                    Número <strong id="textoTermo"></strong> não encontrado.
                </div>
            </div>

        </div>
    </div>

    <div class="text-center text-muted small mb-5 pt-2 border-top">
        <span class="me-3">Total: <strong id="count-total"><?= $stats['total'] ?></strong></span>
        <span class="me-3">Análise: <strong id="count-analise"><?= $stats['analise'] ?></strong></span>
        <span class="me-3">Spam: <strong id="count-spam"><?= $stats['spam'] ?></strong></span>
        <span class="me-3">Banido: <strong id="count-banido"><?= $stats['banido'] ?></strong></span>
        <span>Descartado: <strong id="count-descartado"><?= $stats['descartado'] ?></strong></span>
    </div>

</div>

<div class="modal fade" id="modalConfirmacao" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-0 shadow"><div id="headerModalBanidos" class="modal-header border-0"><h5 class="modal-title">Confirmar Ação</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body fs-5 py-4 text-center" id="textoConfirmacao"></div><div class="modal-footer justify-content-center border-0 gap-2"><a href="#" id="linkConfirmacao" class="btn btn-success px-4 fw-bold">SIM</a><button type="button" class="btn btn-danger px-4 fw-bold" data-bs-dismiss="modal">NÃO</button></div></div></div></div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/app.js"></script>
<script>
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    const ID_EQUIPE = <?= $id_equipe ?>;

    const input = document.getElementById('filtroInput');
    const btnLimpar = document.getElementById('btnLimpar');
    const linhas = document.querySelectorAll('.linha-numero');
    const countTotal = document.getElementById('count-total');
    const countAnalise = document.getElementById('count-analise');
    const countSpam = document.getElementById('count-spam');
    const countBanido = document.getElementById('count-banido');
    const countDescartado = document.getElementById('count-descartado');

    function filtrar() {
        let termo = input.value.toLowerCase();
        let termoLimpo = termo.replace(/[^0-9]/g, '');
        let temTexto = termo.length > 0;
        
        btnLimpar.style.display = temTexto ? 'block' : 'none';
        
        let msgVazia = document.getElementById('msgListaVazia');
        if(msgVazia) msgVazia.style.display = temTexto ? 'none' : '';

        let t = 0, a = 0, s = 0, b = 0, d = 0;
        let visiveis = 0;

        linhas.forEach(function(linha) {
            let numeroTexto = linha.querySelector('.numero-texto').textContent.toLowerCase();
            let numeroLimpo = numeroTexto.replace(/[^0-9]/g, '');
            let status = linha.getAttribute('data-status');

            if (numeroTexto.includes(termo) || numeroLimpo.includes(termoLimpo)) {
                linha.style.display = '';
                visiveis++;
                t++;
                if (status === 'analise') a++;
                if (status === 'spam') s++;
                if (status === 'banido') b++;
                if (status === 'descartado') d++;
            } else {
                linha.style.display = 'none';
            }
        });

        countTotal.innerText = t;
        countAnalise.innerText = a;
        countSpam.innerText = s;
        countBanido.innerText = b;
        countDescartado.innerText = d;

        let msgBusca = document.getElementById('msgBuscaSemResultados');
        if(msgBusca) {
            if(temTexto && visiveis === 0) {
                msgBusca.style.display = "";
                document.getElementById('textoTermo').innerText = input.value;
            } else {
                msgBusca.style.display = "none";
            }
        }
    }

    input.addEventListener('input', filtrar);
    btnLimpar.addEventListener('click', function() { input.value = ''; filtrar(); input.focus(); });

    // Ação aguardando confirmação no modal
    let acaoPendente = null;

    function confirmarAcaoBanidos(tipo, idChip, texto, classeCor) {
        document.getElementById('textoConfirmacao').innerText = texto;
        document.getElementById('headerModalBanidos').className = 'modal-header border-0 ' + classeCor;
        
        let acaoPhp = '';
        if (tipo === 'retornou') {
            acaoPhp = 'retornou_chip';
        } else if (tipo === 'banir') {
            acaoPhp = 'banir_chip_lista';
        } else {
            acaoPhp = 'descartar_chip';
        }

        acaoPendente = { acao: acaoPhp, id: idChip, equipe_id: ID_EQUIPE };
        new bootstrap.Modal(document.getElementById('modalConfirmacao')).show();
    }

    document.getElementById('linkConfirmacao').addEventListener('click', function(e) {
        e.preventDefault();
        if (acaoPendente) postAcao(acaoPendente);
    });
</script>
</body>
</html>