<?php
try {
    $db = new PDO('sqlite:gestao.db');
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