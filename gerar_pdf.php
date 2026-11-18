<?php
// Define o fuso horário
date_default_timezone_set('America/Sao_Paulo');

require 'vendor/autoload.php';
require 'functions.php';
verificar_login();

use Dompdf\Dompdf;
use Dompdf\Options;

// --- 1. IMAGEM ---
$caminho_imagem = 'logo.png';
$img_base64 = '';
if (file_exists($caminho_imagem)) {
    $tipo = pathinfo($caminho_imagem, PATHINFO_EXTENSION);
    $dados = file_get_contents($caminho_imagem);
    $img_base64 = 'data:image/' . $tipo . ';base64,' . base64_encode($dados);
}

// --- 2. DADOS ---
$data_inicio = $_POST['data_inicio'] ?? date('Y-m-01');
$data_fim = $_POST['data_fim'] ?? date('Y-m-d');
$equipe_id = $_POST['equipe_id'] ?? 'todas';

$inicio_sql = $data_inicio . " 00:00:00";
$fim_sql = $data_fim . " 23:59:59";

// --- 3. PREPARAÇÃO DO FILTRO ---
$nome_equipe_filtro = "Geral (Todas as Equipes)";
$nome_arquivo_prefixo = "Geral"; // Nome padrão para o arquivo
$nome_alvo_normalizado = null; 
$filtro_sql = "";
$params = [':inicio' => $inicio_sql, ':fim' => $fim_sql];

if ($equipe_id != 'todas') {
    $stmtEq = $db->prepare("SELECT nome FROM equipes WHERE id = :id");
    $stmtEq->execute([':id' => $equipe_id]);
    $nome_db = $stmtEq->fetchColumn();
    
    $nome_equipe_filtro = $nome_db;
    $nome_arquivo_prefixo = $nome_db; // Usa o nome da equipe para o arquivo
    $nome_alvo_normalizado = mb_strtoupper(trim($nome_db), 'UTF-8');

    // Filtra logs relacionados à equipe (por ID ou texto)
    $filtro_sql = " AND (
        l.alvo_id = :eid_logs OR 
        ec.id = :eid_chip OR 
        e.id = :eid_eq OR
        l.detalhe LIKE :nome_equipe OR 
        l.detalhe LIKE :nome_equipe_curto
    ) ";
    
    $params[':eid_logs'] = $equipe_id;
    $params[':eid_chip'] = $equipe_id;
    $params[':eid_eq'] = $equipe_id;
    $params[':nome_equipe'] = "%Equipe: $nome_db%";
    $params[':nome_equipe_curto'] = "%(Eq: $nome_db)%";
}

// --- 4. CONSULTA ---
$sql = "SELECT 
            l.acao, 
            l.detalhe, 
            COALESCE(e.nome, ec.nome) as nome_equipe_db 
        FROM logs l
        LEFT JOIN chips c ON (l.alvo_tipo = 'chip' AND l.alvo_id = c.id)
        LEFT JOIN equipes ec ON (c.equipe_id = ec.id)
        LEFT JOIN equipes e ON (l.alvo_tipo = 'equipe' AND l.alvo_id = e.id)
        WHERE l.data_hora BETWEEN :inicio AND :fim 
        $filtro_sql
        ORDER BY l.data_hora ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 5. PROCESSAMENTO ---
$stats = [
    'novos' => 0,
    'conex_digisac' => 0, 'conex_web' => 0, 'total_conexoes' => 0,
    'analise' => 0, 'spam' => 0, 'banido' => 0, 'descartado' => 0
];

$stats_equipes = [];

foreach ($logs as $log) {
    $acao = strtoupper($log['acao']);
    $detalhe = strtoupper($log['detalhe']);
    
    // 1. Identificação da Equipe
    $nome_eq = null;

    if (!empty($log['nome_equipe_db'])) {
        $nome_eq = $log['nome_equipe_db'];
    } else {
        if (preg_match('/EQUIPE:\s*([^|\[\(]+)/iu', $detalhe, $matches)) {
            $nome_eq = trim($matches[1]);
        } elseif (preg_match('/\(EQ:\s*([^)]+)\)/iu', $detalhe, $matches)) {
            $nome_eq = trim($matches[1]);
        }
    }

    if ($nome_eq) {
        $nome_eq = mb_strtoupper(trim($nome_eq), 'UTF-8');
    } else {
        if (strpos($acao, 'CADASTROU') !== false || strpos($acao, 'CONECTOU') !== false || strpos($acao, 'STATUS') !== false || strpos($acao, 'IMPORTOU') !== false) {
            $nome_eq = "INDEFINIDO / SISTEMA";
        }
    }

    if ($equipe_id != 'todas') {
        if ($nome_eq !== $nome_alvo_normalizado) {
            continue; 
        }
    }

    // 2. Classificação
    $is_novo = ($acao == 'CADASTROU' || $acao == 'IMPORTOU' || ($acao == 'CRIOU' && strpos($detalhe, 'CHIP') !== false));
    $is_conexao = ($acao == 'CONECTOU');
    $is_digisac = ($is_conexao && strpos($detalhe, 'DIGISAC') !== false);
    $is_web = ($is_conexao && strpos($detalhe, 'WEB') !== false);
    
    $is_status_change = ($acao == 'STATUS');
    $is_spam = ($is_status_change && strpos($detalhe, 'SPAM') !== false);
    $is_analise = ($is_status_change && (strpos($detalhe, 'ANALISE') !== false || strpos($detalhe, 'ANÁLISE') !== false));
    $is_banido = ($acao == 'BANIU' || ($is_status_change && strpos($detalhe, 'BANIDO') !== false));
    $is_descartado = ($acao == 'DESCARTOU' || ($is_status_change && strpos($detalhe, 'DESCARTADO') !== false));

    // 3. Incremento Geral
    if ($is_novo) $stats['novos']++;
    if ($is_conexao) {
        $stats['total_conexoes']++;
        if ($is_digisac) $stats['conex_digisac']++;
        if ($is_web) $stats['conex_web']++;
    }
    if ($is_spam) $stats['spam']++;
    if ($is_analise) $stats['analise']++;
    if ($is_banido) $stats['banido']++;
    if ($is_descartado) $stats['descartado']++;

    // 4. Incremento Equipe
    if ($equipe_id == 'todas' && $nome_eq) {
        if (!isset($stats_equipes[$nome_eq])) {
            $stats_equipes[$nome_eq] = [
                'novos' => 0, 'conexoes' => 0, 'digisac' => 0, 'web' => 0,
                'analise' => 0, 'spam' => 0, 'banido' => 0, 'descartado' => 0
            ];
        }
        if ($is_novo) $stats_equipes[$nome_eq]['novos']++;
        if ($is_conexao) {
            $stats_equipes[$nome_eq]['conexoes']++;
            if ($is_digisac) $stats_equipes[$nome_eq]['digisac']++;
            if ($is_web) $stats_equipes[$nome_eq]['web']++;
        }
        if ($is_spam) $stats_equipes[$nome_eq]['spam']++;
        if ($is_analise) $stats_equipes[$nome_eq]['analise']++;
        if ($is_banido) $stats_equipes[$nome_eq]['banido']++;
        if ($is_descartado) $stats_equipes[$nome_eq]['descartado']++;
    }
}
ksort($stats_equipes);

$total_status_eventos = $stats['analise'] + $stats['spam'] + $stats['banido'] + $stats['descartado'];

// --- 6. HTML ---
$html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Helvetica, sans-serif; color: #333; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 15px; position: relative; min-height: 80px; }
        .header-logo { position: absolute; left: 15px; top: 25px; width: 70px; height: auto; }
        .titulo { font-size: 24px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; color: #000; margin-top: 15px; }
        .subtitulo { font-size: 14px; color: #555; }
        .card-box { width: 100%; margin-bottom: 30px; text-align: center; }
        .card { display: inline-block; width: 18%; margin: 0 0.5%; padding: 15px 0; background-color: #fff; border: 1px solid #ccc; border-radius: 5px; vertical-align: top; }
        .card-n { font-size: 26px; font-weight: bold; display: block; margin-bottom: 5px; }
        .card-l { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #777; font-weight: bold; }
        .section-header { font-size: 14px; font-weight: bold; text-transform: uppercase; background-color: #eee; padding: 8px 10px; border-left: 5px solid #333; margin-top: 25px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background-color: #333; color: white; padding: 8px; text-align: left; }
        td { border: 1px solid #ddd; padding: 8px; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 9px; color: #aaa; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <img src="' . $img_base64 . '" class="header-logo">
        <div class="titulo">RELATÓRIO DE CHIPS</div>
        <div class="subtitulo">
            Equipe: <strong>' . htmlspecialchars($nome_equipe_filtro) . '</strong><br>
            Período: ' . date('d/m/Y', strtotime($data_inicio)) . ' até ' . date('d/m/Y', strtotime($data_fim)) . '
        </div>
    </div>

    <div class="card-box">
        <div class="card" style="border-bottom: 4px solid #198754;">
            <span class="card-n" style="color: #198754;">+' . $stats['novos'] . '</span>
            <span class="card-l">Novos Chips</span>
        </div>
        <div class="card" style="border-bottom: 4px solid #0d6efd;">
            <span class="card-n" style="color: #0d6efd;">' . $stats['total_conexoes'] . '</span>
            <span class="card-l">Conexões</span>
        </div>
        <div class="card" style="border-bottom: 4px solid #0dcaf0;">
            <span class="card-n" style="color: #0dcaf0;">' . $stats['analise'] . '</span>
            <span class="card-l">Em Análise</span>
        </div>
        <div class="card" style="border-bottom: 4px solid #ffc107;">
            <span class="card-n" style="color: #d39e00;">' . $stats['spam'] . '</span>
            <span class="card-l">Spam</span>
        </div>
        <div class="card" style="border-bottom: 4px solid #dc3545;">
            <span class="card-n" style="color: #dc3545;">' . $stats['banido'] . '</span>
            <span class="card-l">Banidos</span>
        </div>
    </div>

    <div class="section-header">Detalhamento de Conexões</div>
    <table>
        <thead><tr><th>Origem</th><th>Quantidade</th><th>% do Total</th></tr></thead>
        <tbody>
            <tr><td>DIGISAC</td><td>' . $stats['conex_digisac'] . '</td><td>' . ($stats['total_conexoes'] > 0 ? round(($stats['conex_digisac'] / $stats['total_conexoes']) * 100, 1) : 0) . '%</td></tr>
            <tr><td>WEB</td><td>' . $stats['conex_web'] . '</td><td>' . ($stats['total_conexoes'] > 0 ? round(($stats['conex_web'] / $stats['total_conexoes']) * 100, 1) : 0) . '%</td></tr>
        </tbody>
    </table>

    <div class="section-header">Status e Perdas</div>
    <table>
        <thead><tr><th>Situação</th><th>Ocorrências</th><th>% Relativa</th></tr></thead>
        <tbody>
            <tr><td><strong>EM ANÁLISE</strong></td><td>' . $stats['analise'] . '</td><td>' . ($total_status_eventos > 0 ? round(($stats['analise'] / $total_status_eventos) * 100, 1) : 0) . '%</td></tr>
            <tr><td><strong>SPAM</strong></td><td>' . $stats['spam'] . '</td><td>' . ($total_status_eventos > 0 ? round(($stats['spam'] / $total_status_eventos) * 100, 1) : 0) . '%</td></tr>
            <tr><td><strong>BANIDO</strong></td><td>' . $stats['banido'] . '</td><td>' . ($total_status_eventos > 0 ? round(($stats['banido'] / $total_status_eventos) * 100, 1) : 0) . '%</td></tr>
            <tr><td><strong>DESCARTADO</strong></td><td>' . $stats['descartado'] . '</td><td>' . ($total_status_eventos > 0 ? round(($stats['descartado'] / $total_status_eventos) * 100, 1) : 0) . '%</td></tr>
        </tbody>
    </table>
';

if ($equipe_id == 'todas' && count($stats_equipes) > 0) {
    $html .= '
    <div class="section-header">Desempenho por Equipe</div>
    <table>
        <thead><tr><th width="25%">Equipe</th><th align="center">Novos</th><th align="center">Conexões</th><th align="center">Digisac</th><th align="center">Web</th><th align="center">Análise</th><th align="center">Spam</th><th align="center">Banidos</th><th align="center">Desc.</th></tr></thead>
        <tbody>';
    foreach ($stats_equipes as $nome => $d) {
        $html .= '<tr>
            <td><strong>' . htmlspecialchars($nome) . '</strong></td>
            <td align="center">' . ($d['novos'] > 0 ? "+".$d['novos'] : "0") . '</td>
            <td align="center" style="font-weight:bold;">' . $d['conexoes'] . '</td>
            <td align="center" style="color: #555;">' . $d['digisac'] . '</td>
            <td align="center" style="color: #555;">' . $d['web'] . '</td>
            <td align="center">' . $d['analise'] . '</td>
            <td align="center">' . $d['spam'] . '</td>
            <td align="center">' . $d['banido'] . '</td>
            <td align="center" style="color: #555; font-weight:bold;">' . $d['descartado'] . '</td>
        </tr>';
    }
    $html .= '</tbody></table><div style="font-size: 10px; color: #666; margin-top: 5px; text-align: right;">* Equipes sem movimentação no período não são listadas.</div>';
}

$html .= '<div class="footer">Gerado automaticamente em ' . date('d/m/Y H:i') . ' | Usuário: ' . $_SESSION['usuario_nome'] . '</div></body></html>';

// --- GERA O PDF E DEFINE O NOME ---
$options = new Options();
$options->setIsRemoteEnabled(true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Sanitiza o nome para evitar erros no arquivo
$nome_limpo = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nome_arquivo_prefixo);
$nome_arquivo = $nome_limpo . "_" . date('dmY_Hi') . ".pdf";

$dompdf->stream($nome_arquivo, ["Attachment" => true]);
?>