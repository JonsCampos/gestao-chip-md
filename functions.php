<?php
// functions.php
session_start();
require 'db.php';

function verificar_login() {
    if (!isset($_SESSION['usuario_id'])) {
        header("Location: index.php");
        exit;
    }
}

function formatar_numero($num) {
    // 1. Limpa tudo que não é dígito (remove +, -, espaços, parênteses)
    $num = preg_replace('/\D/', '', $num);
    $len = strlen($num);
    
    // 2. Aplica a formatação baseada no tamanho
    
    // 13 Dígitos (País + DDD + Celular): +55 (11) 93620-8631
    if ($len === 13) {
        return "+" . substr($num, 0, 2) . " (" . substr($num, 2, 2) . ") " . substr($num, 4, 5) . "-" . substr($num, 9);
    }

    // 12 Dígitos (País + DDD + Fixo): +55 (11) 5196-3908
    if ($len === 12) {
        return "+" . substr($num, 0, 2) . " (" . substr($num, 2, 2) . ") " . substr($num, 4, 4) . "-" . substr($num, 8);
    }
    
    // 11 Dígitos (Celular com DDD): (11) 93620-8631 -> COM ESPAÇO
    if ($len === 11) {
        return "(" . substr($num, 0, 2) . ") " . substr($num, 2, 5) . "-" . substr($num, 7);
    }
    
    // 10 Dígitos (Fixo com DDD): (11) 5196-3908 -> COM ESPAÇO
    if ($len === 10) {
        return "(" . substr($num, 0, 2) . ") " . substr($num, 2, 4) . "-" . substr($num, 6);
    }
    
    // 9 Dígitos (Celular sem DDD): 93620-8631
    if ($len === 9) {
        return substr($num, 0, 5) . '-' . substr($num, 5);
    }
    
    // 8 Dígitos (Fixo/Antigo sem DDD): 5196-3908
    if ($len === 8) {
        return substr($num, 0, 4) . '-' . substr($num, 4);
    }
    
    // Retorna original se não bater padrão
    return $num;
}

function limpar_numero($num) {
    return preg_replace('/[^0-9]/', '', $num);
}

function registrar_log($acao, $detalhe, $alvo_tipo = null, $alvo_id = null) {
    global $db;
    $usuario_id = $_SESSION['usuario_id'] ?? 0;
    $usuario_nome = $_SESSION['usuario_nome'] ?? 'Sistema';

    $sql = "INSERT INTO logs (usuario_id, usuario_nome, acao, detalhe, alvo_tipo, alvo_id, data_hora) 
            VALUES (:uid, :unome, :acao, :detalhe, :otipo, :oid, datetime('now', 'localtime'))";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':uid' => $usuario_id,
        ':unome' => $usuario_nome,
        ':acao' => strtoupper($acao),
        ':detalhe' => $detalhe,
        ':otipo' => $alvo_tipo, 
        ':oid' => $alvo_id
    ]);
}
?>