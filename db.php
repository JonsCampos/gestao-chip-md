<?php
try {
    // Banco fica na pasta "database"
    $pastaDb = __DIR__ . '/database';
    $arquivoDb = $pastaDb . '/gestao.db';

    // Segurança: evita criar um banco novo vazio (com admin/123456) se o antigo ainda estiver na raiz
    if (!file_exists($arquivoDb) && file_exists(__DIR__ . '/gestao.db')) {
        die('Mova o arquivo gestao.db para dentro da pasta "database" antes de continuar.');
    }

    if (!is_dir($pastaDb)) {
        mkdir($pastaDb, 0775, true);
    }

    $db = new PDO('sqlite:' . $arquivoDb);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA foreign_keys = ON;");

    // Tabelas existentes
    $db->exec("CREATE TABLE IF NOT EXISTS equipes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL
    )");

    // ADICIONADO: horas_penalidade
    $db->exec("CREATE TABLE IF NOT EXISTS chips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        numero TEXT NOT NULL UNIQUE, 
        equipe_id INTEGER,
        posicao TEXT DEFAULT NULL, 
        status TEXT DEFAULT 'livre',
        contador_spam INTEGER DEFAULT 0,
        contador_analise INTEGER DEFAULT 0,
        contador_digisac INTEGER DEFAULT 0,
        contador_web INTEGER DEFAULT 0,
        maturado INTEGER DEFAULT 0,
        dias_maturacao INTEGER DEFAULT 0,
        horas_penalidade INTEGER DEFAULT 0, 
        FOREIGN KEY (equipe_id) REFERENCES equipes(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS posicoes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        equipe_id INTEGER,
        nome TEXT,
        FOREIGN KEY(equipe_id) REFERENCES equipes(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        usuario TEXT NOT NULL UNIQUE,
        senha TEXT NOT NULL
    )");

    // --- NOVA TABELA: LOGS DE AUDITORIA ---
    $db->exec("CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER,
        usuario_nome TEXT, 
        acao TEXT,         
        alvo_tipo TEXT,    
        alvo_id INTEGER,   
        detalhe TEXT,      
        data_hora DATETIME DEFAULT (DATETIME('now', 'localtime')),
        FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
    )");

    // --- NOVA TABELA: TENTATIVAS DE LOGIN (limite contra força bruta) ---
    $db->exec("CREATE TABLE IF NOT EXISTS login_tentativas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        data_hora DATETIME DEFAULT (DATETIME('now', 'localtime'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_tentativas ON login_tentativas(ip, data_hora)");

    // --- MIGRAÇÃO: equipe do evento guardada no log ---
    // Permite que o relatório conte cada evento na equipe em que o chip estava NAQUELE momento
    $colunasLogs = array_column($db->query("PRAGMA table_info(logs)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('equipe_id', $colunasLogs, true)) {
        $db->exec("ALTER TABLE logs ADD COLUMN equipe_id INTEGER DEFAULT NULL");

        // Mapa NOME (maiúsculo) => id das equipes atuais
        $mapa = [];
        foreach ($db->query("SELECT id, nome FROM equipes")->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $mapa[mb_strtoupper(trim($e['nome']), 'UTF-8')] = (int)$e['id'];
        }
        // Equipes já excluídas: recupera o id pelos logs de criação/exclusão
        $sqlHist = "SELECT alvo_id, detalhe FROM logs WHERE alvo_tipo = 'equipe' AND (detalhe LIKE 'Criou equipe %' OR detalhe LIKE 'Excluiu equipe %')";
        foreach ($db->query($sqlHist)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = mb_strtoupper(trim(preg_replace('/^(Criou|Excluiu) equipe\s+/iu', '', $r['detalhe'])), 'UTF-8');
            if ($n !== '' && !isset($mapa[$n])) $mapa[$n] = (int)$r['alvo_id'];
        }

        $db->beginTransaction();
        $upd = $db->prepare("UPDATE logs SET equipe_id = :eid WHERE id = :id");
        $todos = $db->query("SELECT id, alvo_tipo, alvo_id, detalhe FROM logs")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($todos as $l) {
            $eid = null;
            if ($l['alvo_tipo'] === 'equipe' && !empty($l['alvo_id'])) {
                $eid = (int)$l['alvo_id'];
            } else {
                $nome = null;
                if (preg_match('/Equipe:\s*([^|\[\(]+)/iu', (string)$l['detalhe'], $m)) {
                    $nome = $m[1];
                } elseif (preg_match('/\(Eq:\s*([^)]+)\)/iu', (string)$l['detalhe'], $m)) {
                    $nome = $m[1];
                }
                if ($nome !== null) {
                    $chave = mb_strtoupper(trim($nome), 'UTF-8');
                    if (isset($mapa[$chave])) $eid = $mapa[$chave];
                }
            }
            if ($eid !== null) {
                $upd->execute([':eid' => $eid, ':id' => $l['id']]);
            }
        }
        $db->commit();
    }

    // --- ÍNDICES DE DESEMPENHO ---
    // logs cresce sem parar e é consultada por chip (data_entrada, "Desde", "Volta em"),
    // por equipe/data (relatório PDF) e por data (tela de logs).
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_alvo ON logs(alvo_tipo, alvo_id, id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_equipe_data ON logs(equipe_id, data_hora)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_logs_data ON logs(data_hora)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_chips_equipe_status ON chips(equipe_id, status)");

    // Usuário Padrão
    $stmt = $db->query("SELECT COUNT(*) FROM usuarios");
    if ($stmt->fetchColumn() == 0) {
        $senhaHash = password_hash('123456', PASSWORD_DEFAULT);
        $stmtInsert = $db->prepare("INSERT INTO usuarios (nome, usuario, senha) VALUES ('Administrador', 'admin', :senha)");
        $stmtInsert->execute([':senha' => $senhaHash]);
    }

} catch (PDOException $e) {
    echo "Erro na conexão/banco de dados: " . $e->getMessage();
    exit;
}
?>