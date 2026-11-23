<?php
// actions.php
header("Content-Type: text/html; charset=utf-8");
require 'functions.php';

$acao = $_POST['acao'] ?? '';
// Logout continua aceitando GET (só encerra a própria sessão)
if ($acao === '' && ($_GET['acao'] ?? '') === 'logout') {
    $acao = 'logout';
}

// --- PROTEÇÃO GLOBAL: tudo exige login, POST e token CSRF (exceto login/logout) ---
if (!in_array($acao, ['login', 'logout'], true)) {
    verificar_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        die('Método não permitido.');
    }
    if (!csrf_validar()) {
        http_response_code(403);
        die('Token de segurança inválido ou expirado. <a href="painel.php">Voltar ao painel</a>');
    }
}

function converter_horas($entrada) {
    if (strpos($entrada, ':') !== false) {
        list($h, $m) = explode(':', $entrada);
        return (float)$h + ((float)$m / 60);
    }
    return (float)str_replace(',', '.', $entrada);
}

function get_nome_equipe($db, $id) {
    if (!$id) return "Sem Equipe";
    $stmt = $db->prepare("SELECT nome FROM equipes WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetchColumn() ?: "Desconhecida";
}

try {
    // --- 0. AUTENTICAÇÃO ---
    if ($acao == 'login') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: index.php");
            exit;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $usuario = $_POST['usuario'] ?? '';
        $senha = $_POST['senha'] ?? '';

        // Limpeza de tentativas antigas
        $db->exec("DELETE FROM login_tentativas WHERE data_hora < datetime('now', 'localtime', '-1 day')");

        // Limite: 5 erros em 15 minutos por IP
        $stmtLim = $db->prepare("SELECT COUNT(*) FROM login_tentativas WHERE ip = :ip AND data_hora > datetime('now', 'localtime', '-15 minutes')");
        $stmtLim->execute([':ip' => $ip]);
        if ($stmtLim->fetchColumn() >= 5) {
            header("Location: index.php?erro=bloqueado");
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM usuarios WHERE usuario = :user");
        $stmt->execute([':user' => $usuario]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($senha, $user['senha'])) {
            session_regenerate_id(true);
            $db->prepare("DELETE FROM login_tentativas WHERE ip = :ip")->execute([':ip' => $ip]);

            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['usuario_nome'] = $user['nome'];
            registrar_log('Login', 'Usuário realizou login', 'sistema', $user['id']);
            header("Location: painel.php");
        } else {
            $db->prepare("INSERT INTO login_tentativas (ip) VALUES (:ip)")->execute([':ip' => $ip]);
            header("Location: index.php?erro=dados_invalidos");
        }
        exit;
    }
    elseif ($acao == 'logout') {
        if (isset($_SESSION['usuario_id'])) {
            registrar_log('Logout', 'Usuário saiu do sistema', 'sistema', $_SESSION['usuario_id']);
        }
        $_SESSION = [];
        session_destroy();
        header("Location: index.php");
        exit;
    }

    // --- 1. CHIPS ---
    elseif ($acao == 'add_chip') {
        $numero = $_POST['numero'];
        $numero_formatado = formatar_numero($numero); 
        $numero_limpo = preg_replace("/\D/", "", $numero); // Só números (sem +)

        $equipe_id = $_POST['equipe_id'];
        $maturado = isset($_POST['maturado']) ? 1 : 0;
        $dias = $_POST['dias_maturacao'] ?? 0;
        $origem = $_POST['origem'] ?? 'painel';

        // Verificação robusta: Remove +, (, ), -, e espaços do banco para comparar
        $sql_clean = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(numero, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '')";
        
        $stmt = $db->prepare("SELECT count(*) FROM chips WHERE $sql_clean = :n");
        $stmt->execute([':n' => $numero_limpo]);
        
        if ($stmt->fetchColumn() > 0) {
            // CORRIGIDO: o redirect do painel não tinha "?" e o aviso de duplicado nunca aparecia
            $redirect = ($origem == 'equipe') ? "equipe.php?id=" . (int)$equipe_id . "&erro=duplicado" : "painel.php?erro=duplicado";
            header("Location: $redirect");
            exit;
        }

        $sql = "INSERT INTO chips (numero, equipe_id, status, maturado, dias_maturacao) VALUES (:n, :e, 'livre', :m, :d)";
        $stmt = $db->prepare($sql);
        $stmt->execute([':n' => $numero_formatado, ':e' => $equipe_id, ':m' => $maturado, ':d' => $dias]);
        
        $id_chip = $db->lastInsertId();
        $nome_equipe = get_nome_equipe($db, $equipe_id);
        registrar_log('Cadastrou', "Chip $numero_formatado | Equipe: $nome_equipe", 'chip', $id_chip);

        if ($origem == 'equipe') header("Location: equipe.php?id=$equipe_id");
        else header("Location: painel.php");
    }

    // --- IMPORTAÇÃO CSV ---
    elseif ($acao == 'importar_csv') {
        $equipe_id = $_POST['equipe_id'];
        $nome_equipe = get_nome_equipe($db, $equipe_id); 
        
        $lista_sucesso = [];
        $lista_duplicados = [];

        if (isset($_FILES['arquivo_csv']) && $_FILES['arquivo_csv']['error'] == 0) {
            $arquivo = $_FILES['arquivo_csv']['tmp_name'];
            $handle = fopen($arquivo, "r");
            
            if ($handle) {
                while (($linha = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    // Remove TUDO que não for dígito (inclusive o +)
                    $raw_num = preg_replace("/\D/", "", $linha[0]);
                    $len = strlen($raw_num);
                    
                    // Valida tamanho: Aceita de 8 a 13 dígitos
                    if ($len < 8 || $len > 13) continue; 

                    $numero_formatado = formatar_numero($raw_num);

                    // SQL Clean incluindo a remoção do '+'
                    $sql_clean_col = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(c.numero, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '')";
                    
                    $stmtCheck = $db->prepare("SELECT e.nome FROM chips c LEFT JOIN equipes e ON c.equipe_id = e.id WHERE $sql_clean_col = :raw LIMIT 1");
                    $stmtCheck->execute([':raw' => $raw_num]);
                    $equipe_existente = $stmtCheck->fetchColumn();
                    
                    if ($equipe_existente === false) {
                        $stmtIns = $db->prepare("INSERT INTO chips (numero, equipe_id, status, maturado) VALUES (:n, :e, 'livre', 0)");
                        $stmtIns->execute([':n' => $numero_formatado, ':e' => $equipe_id]);
                        
                        $id_novo_chip = $db->lastInsertId();
                        $lista_sucesso[] = $numero_formatado;

                        registrar_log('Importou', "Chip $numero_formatado | Equipe: $nome_equipe | Importado via CSV", 'chip', $id_novo_chip);

                    } else {
                        $nome_eq = $equipe_existente ?: 'Sem Equipe';
                        $lista_duplicados[] = [
                            'numero' => $numero_formatado,
                            'equipe' => $nome_eq
                        ];
                    }
                }
                fclose($handle);
                
                if (count($lista_sucesso) > 0) {
                    registrar_log('Lote CSV', "Processo de importação concluído: " . count($lista_sucesso) . " chips.", 'equipe', $equipe_id);
                }
            }
        }

        $_SESSION['import_resultado'] = [
            'sucesso' => $lista_sucesso,
            'duplicados' => $lista_duplicados
        ];

        header("Location: equipe.php?id=$equipe_id");
    }

    // --- EDIÇÃO DE CHIP ---
    elseif ($acao == 'edit_chip') {
        $id = $_POST['chip_id'];
        $numero = $_POST['numero'];
        $numero = formatar_numero($numero);

        $nova_equipe = $_POST['equipe_id'];
        $origem = $_POST['origem'] ?? 'painel';
        $busca_local = $_POST['busca_local'] ?? ''; 

        $stmt = $db->prepare("SELECT * FROM chips WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $chipAtual = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$chipAtual) {
            header("Location: painel.php");
            exit;
        }

        if ($chipAtual['status'] == 'banido' || $chipAtual['status'] == 'descartado') {
            if ($chipAtual['equipe_id'] != $nova_equipe) {
                header("Location: painel.php?erro=bloqueio_status"); exit;
            }
        }
        if (!empty($chipAtual['posicao']) && $chipAtual['equipe_id'] != $nova_equipe) {
            header("Location: painel.php?erro=bloqueio_ativo"); exit;
        }

        // NOVO: impede salvar um número que já existe em OUTRO chip (ignorando máscara)
        $numero_limpo = preg_replace("/\D/", "", $numero);
        $sql_clean = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(numero, '(', ''), ')', ''), '-', ''), ' ', ''), '+', '')";
        $stmtDup = $db->prepare("SELECT count(*) FROM chips WHERE $sql_clean = :n AND id != :id");
        $stmtDup->execute([':n' => $numero_limpo, ':id' => $id]);
        if ($stmtDup->fetchColumn() > 0) {
            $redirectDup = ($origem == 'equipe')
                ? "equipe.php?id=" . (int)$chipAtual['equipe_id'] . "&erro=duplicado"
                : "painel.php?erro=duplicado";
            header("Location: $redirectDup");
            exit;
        }

        $sql = "UPDATE chips SET numero = :n, equipe_id = :e WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':n' => $numero, ':e' => $nova_equipe, ':id' => $id]);

        if ($chipAtual['numero'] != $numero) {
            registrar_log('Editou', "Alterou número de {$chipAtual['numero']} para $numero", 'chip', $id);
        }
        if ($chipAtual['equipe_id'] != $nova_equipe) {
            $nomeAntiga = get_nome_equipe($db, $chipAtual['equipe_id']);
            $nomeNova = get_nome_equipe($db, $nova_equipe);
            registrar_log('Moveu', "Transferiu chip {$chipAtual['numero']} | De: $nomeAntiga | Para: $nomeNova", 'chip', $id);
        }

        $params = $origem == 'equipe' ? "?id={$chipAtual['equipe_id']}&busca_local=" . urlencode($busca_local) : "";
        header("Location: " . ($origem == 'equipe' ? "equipe.php" : "painel.php") . $params);
    }

    // --- STATUS (ANALISE/SPAM/BANIDO) ---
    elseif ($acao == 'alterar_status') {
        $id = $_POST['id'] ?? 0;
        $tipo = $_POST['tipo'] ?? '';
        $equipe_id = (int)($_POST['equipe_id'] ?? 0);
        $horas_input = $_POST['horas'] ?? 0;

        // Só aceita status válidos
        if (!in_array($tipo, ['analise', 'spam', 'banido'], true)) {
            header("Location: painel.php");
            exit;
        }

        $horas_decimal = 0;
        if (!empty($horas_input)) {
            $horas_decimal = converter_horas($horas_input);
        }

        if ($horas_decimal == 0 && $tipo == 'analise') {
            $horas_decimal = 24;
            $horas_input = "24:00"; 
        }

        $sql_contador = "";
        if ($tipo == 'spam' || $tipo == 'analise') {
            $campo_contador = "contador_" . $tipo;
            $sql_contador = ", $campo_contador = $campo_contador + 1";
        }
        
        $stmt = $db->prepare("SELECT c.status, c.posicao, c.numero, e.nome as nome_equipe FROM chips c LEFT JOIN equipes e ON c.equipe_id = e.id WHERE c.id = :id");
        $stmt->execute([':id' => $id]);
        $chip = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $detalhe = "Chip {$chip['numero']} | Equipe: {$chip['nome_equipe']} | alterado para " . strtoupper($tipo);
        
        if ($horas_decimal > 0) $detalhe .= " ({$horas_input}h)";
        if (!empty($chip['posicao'])) $detalhe .= " [Estava em: {$chip['posicao']}]";

        $sql = "UPDATE chips SET status = :st, posicao = NULL, horas_penalidade = :hp $sql_contador WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':st' => $tipo, ':id' => $id, ':hp' => $horas_decimal]);
        
        registrar_log('Status', $detalhe, 'chip', $id);
        header("Location: equipe.php?id=$equipe_id");
    }

    // --- AÇÕES DE HISTÓRICO ---
    elseif ($acao == 'retornou_chip') {
        $id = $_POST['id'];
        $equipe_id = (int)$_POST['equipe_id'];
        
        $stmt = $db->prepare("SELECT c.numero, c.status as status_antigo, e.nome as nome_equipe FROM chips c LEFT JOIN equipes e ON c.equipe_id = e.id WHERE c.id = :id");
        $stmt->execute([':id' => $id]);
        $chip = $stmt->fetch(PDO::FETCH_ASSOC);

        $db->prepare("UPDATE chips SET status = 'livre', horas_penalidade = 0 WHERE id = :id")->execute([':id' => $id]);
        registrar_log('Retornou', "Chip {$chip['numero']} | Equipe: {$chip['nome_equipe']} | recuperado de " . strtoupper($chip['status_antigo']) . " para LIVRE", 'chip', $id);
        header("Location: banidos.php?id=$equipe_id");
    }
    elseif ($acao == 'banir_chip_lista') {
        $id = $_POST['id'];
        $equipe_id = (int)$_POST['equipe_id'];
        
        $stmt = $db->prepare("SELECT c.numero, c.status as status_antigo, e.nome as nome_equipe FROM chips c LEFT JOIN equipes e ON c.equipe_id = e.id WHERE c.id = :id");
        $stmt->execute([':id' => $id]);
        $chip = $stmt->fetch(PDO::FETCH_ASSOC);

        $db->prepare("UPDATE chips SET status = 'banido' WHERE id = :id")->execute([':id' => $id]);
        registrar_log('Baniu', "Chip {$chip['numero']} | Equipe: {$chip['nome_equipe']} | movido de " . strtoupper($chip['status_antigo']) . " para BANIDO", 'chip', $id);
        header("Location: banidos.php?id=$equipe_id");
    }
    elseif ($acao == 'descartar_chip') {
        $id = $_POST['id'];
        $equipe_id = (int)$_POST['equipe_id'];
        
        $stmt = $db->prepare("SELECT c.numero, e.nome as nome_equipe FROM chips c LEFT JOIN equipes e ON c.equipe_id = e.id WHERE c.id = :id");
        $stmt->execute([':id' => $id]);
        $chip = $stmt->fetch(PDO::FETCH_ASSOC);

        $db->prepare("UPDATE chips SET status = 'descartado' WHERE id = :id")->execute([':id' => $id]);
        registrar_log('Descartou', "Chip {$chip['numero']} | Equipe: {$chip['nome_equipe']} | descartado definitivamente", 'chip', $id);
        header("Location: banidos.php?id=$equipe_id");
    }

    // --- EQUIPES ---
    elseif ($acao == 'add_equipe') {
        $nome = $_POST['nome'];
        $stmt = $db->prepare("INSERT INTO equipes (nome) VALUES (:nome)");
        $stmt->execute([':nome' => $nome]);
        $id_eq = $db->lastInsertId();
        $padroes = ['DIGISAC 01', 'DIGISAC 02', 'WEB 01 SIM1', 'WEB 01 SIM2', 'WEB 02 SIM1', 'WEB 02 SIM2', 'WEB 03 SIM1', 'WEB 03 SIM2'];
        $sqlInsert = "INSERT INTO posicoes (equipe_id, nome) VALUES (:eid, :nome)";
        $stmtInsert = $db->prepare($sqlInsert);
        foreach ($padroes as $nome_pos) { $stmtInsert->execute([':eid' => $id_eq, ':nome' => $nome_pos]); }
        registrar_log('Criou', "Criou equipe $nome", 'equipe', $id_eq);
        header("Location: painel.php");
    }
    elseif ($acao == 'edit_equipe') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $nomeAntigo = get_nome_equipe($db, $id);
        $stmt = $db->prepare("UPDATE equipes SET nome = :nome WHERE id = :id");
        $stmt->execute([':nome' => $nome, ':id' => $id]);
        registrar_log('Editou', "Renomeou equipe de $nomeAntigo para $nome", 'equipe', $id);
        header("Location: painel.php");
    }
    elseif ($acao == 'delete_equipe') {
        $id = (int)$_POST['id'];
        $nome = get_nome_equipe($db, $id);

        // NOVO: libera os chips "em uso" da equipe (antes ficavam "em uso" sem equipe)
        $stmtUso = $db->prepare("UPDATE chips SET status = 'livre', posicao = NULL WHERE equipe_id = :id AND status = 'uso'");
        $stmtUso->execute([':id' => $id]);
        $liberados = $stmtUso->rowCount();

        $stmt = $db->prepare("DELETE FROM equipes WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $detalhe = "Excluiu equipe $nome";
        if ($liberados > 0) $detalhe .= " ($liberados chips em uso foram liberados)";
        registrar_log('Excluiu', $detalhe, 'equipe', $id);
        header("Location: painel.php");
    }

    // --- CONEXÕES ---
    elseif ($acao == 'add_posicao') {
        $equipe_id = $_POST['equipe_id'];
        $nome = $_POST['nome'];
        $stmt = $db->prepare("INSERT INTO posicoes (equipe_id, nome) VALUES (:eid, :nome)");
        $stmt->execute([':eid' => $equipe_id, ':nome' => $nome]);
        registrar_log('Criou', "Nova conexão: $nome", 'equipe', $equipe_id);
        header("Location: equipe.php?id=$equipe_id");
    }
    elseif ($acao == 'edit_posicao') {
        $equipe_id = $_POST['equipe_id'];
        $id_posicao = $_POST['id_posicao'];
        $novo_nome = $_POST['nome'];
        $nome_atual = $_POST['nome_atual'];
        $stmt = $db->prepare("SELECT count(*) FROM posicoes WHERE equipe_id = :eid AND nome = :nome");
        $stmt->execute([':eid' => $equipe_id, ':nome' => $nome_atual]);
        $stmt = $db->prepare("UPDATE posicoes SET nome = :nome WHERE id = :id");
        $stmt->execute([':nome' => $novo_nome, ':id' => $id_posicao]);
        $stmtUpdateChips = $db->prepare("UPDATE chips SET posicao = :novo WHERE equipe_id = :eid AND posicao = :antigo");
        $stmtUpdateChips->execute([':novo' => $novo_nome, ':eid' => $equipe_id, ':antigo' => $nome_atual]);
        registrar_log('Editou', "Renomeou de: $nome_atual | Para: $novo_nome", 'equipe', $equipe_id);
        header("Location: equipe.php?id=$equipe_id");
    }
    elseif ($acao == 'delete_posicao') {
        $id_posicao = $_POST['id_posicao'];
        $equipe_id = (int)$_POST['equipe_id'];
        $stmt = $db->prepare("SELECT nome FROM posicoes WHERE id = :id");
        $stmt->execute([':id' => $id_posicao]);
        $pos = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pos) {
            $stmtCheck = $db->prepare("SELECT count(*) FROM chips WHERE equipe_id = :eid AND posicao = :nome");
            $stmtCheck->execute([':eid' => $equipe_id, ':nome' => $pos['nome']]);
            if ($stmtCheck->fetchColumn() > 0) { header("Location: equipe.php?id=$equipe_id&erro=posicao_ocupada"); exit; }
            $stmt = $db->prepare("DELETE FROM posicoes WHERE id = :id");
            $stmt->execute([':id' => $id_posicao]);
            registrar_log('Excluiu', "Removeu conexão: {$pos['nome']}", 'equipe', $equipe_id);
        }
        header("Location: equipe.php?id=$equipe_id");
    }

    // --- CONECTAR ---
    elseif ($acao == 'definir_slot') {
        $equipe_id = $_POST['equipe_id'];
        $posicao = $_POST['posicao'];
        $chip_id = $_POST['chip_id'];

        $db->prepare("UPDATE chips SET status = 'livre', posicao = NULL WHERE equipe_id = :eid AND posicao = :pos")
           ->execute([':eid' => $equipe_id, ':pos' => $posicao]);

        $sql_incremento = "";
        if (stripos($posicao, 'DIGISAC') !== false) {
            $sql_incremento = ", contador_digisac = contador_digisac + 1";
        } elseif (stripos($posicao, 'WEB') !== false) {
            $sql_incremento = ", contador_web = contador_web + 1";
        }

        $sql = "UPDATE chips SET status = 'uso', posicao = :pos, equipe_id = :eid $sql_incremento WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':pos' => $posicao, ':eid' => $equipe_id, ':id' => $chip_id]);
        
        $stmtC = $db->prepare("SELECT c.numero, e.nome as nome_equipe FROM chips c LEFT JOIN equipes e ON c.equipe_id = e.id WHERE c.id = :id");
        $stmtC->execute([':id' => $chip_id]);
        $dados = $stmtC->fetch(PDO::FETCH_ASSOC);

        registrar_log('Conectou', "Chip {$dados['numero']} | Equipe: {$dados['nome_equipe']} | conectado em: $posicao", 'chip', $chip_id);
        header("Location: equipe.php?id=$equipe_id");
    }

    // --- DESVINCULAR ---
    elseif ($acao == 'desvincular_chip') {
        $id = $_POST['id'];
        $equipe_id = (int)$_POST['equipe_id'];
        $decrementar = $_POST['decrementar'] ?? 0;
        
        $stmt = $db->prepare("SELECT c.posicao, c.numero, e.nome as nome_equipe FROM chips c LEFT JOIN equipes e ON c.equipe_id = e.id WHERE c.id = :id");
        $stmt->execute([':id' => $id]);
        $dados = $stmt->fetch(PDO::FETCH_ASSOC);
        $posicao_atual = $dados['posicao'];

        $sql = "UPDATE chips SET status = 'livre', posicao = NULL WHERE id = :id";
        $db->prepare($sql)->execute([':id' => $id]);
        
        if ($decrementar == 1 && !empty($posicao_atual)) {
            if (stripos($posicao_atual, 'DIGISAC') !== false) {
                $db->prepare("UPDATE chips SET contador_digisac = CASE WHEN contador_digisac > 0 THEN contador_digisac - 1 ELSE 0 END WHERE id = :id")->execute([':id' => $id]);
            } elseif (stripos($posicao_atual, 'WEB') !== false) {
                $db->prepare("UPDATE chips SET contador_web = CASE WHEN contador_web > 0 THEN contador_web - 1 ELSE 0 END WHERE id = :id")->execute([':id' => $id]);
            }
        }
        
        registrar_log('Desvinculou', "Chip {$dados['numero']} | Equipe: {$dados['nome_equipe']} | removido de: {$dados['posicao']}", 'chip', $id);
        header("Location: equipe.php?id=$equipe_id");
    }

    // --- USUÁRIOS ---
    elseif ($acao == 'add_usuario') {
        $nome = $_POST['nome'];
        $usuario = $_POST['usuario'];

        // Login precisa ser único: avisa em vez de estourar erro do banco
        $stmtDup = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = :u");
        $stmtDup->execute([':u' => $usuario]);
        if ($stmtDup->fetchColumn() > 0) {
            header("Location: usuarios.php?erro=usuario_existe");
            exit;
        }

        $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO usuarios (nome, usuario, senha) VALUES (:n, :u, :s)");
        $stmt->execute([':n' => $nome, ':u' => $usuario, ':s' => $senha]);
        registrar_log('Criou', "Criou usuário $usuario", 'usuario', $db->lastInsertId());
        header("Location: usuarios.php");
    }
    elseif ($acao == 'edit_usuario') {
        $id = $_POST['id'];
        $nome = $_POST['nome'];
        $usuario = $_POST['usuario'];
        $senha = $_POST['senha'];

        // Login precisa ser único (ignora o próprio usuário)
        $stmtDup = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = :u AND id != :id");
        $stmtDup->execute([':u' => $usuario, ':id' => $id]);
        if ($stmtDup->fetchColumn() > 0) {
            header("Location: usuarios.php?erro=usuario_existe");
            exit;
        }

        $sql = "UPDATE usuarios SET nome = :n, usuario = :u" . (!empty($senha) ? ", senha = :s" : "") . " WHERE id = :id";
        $params = [':n' => $nome, ':u' => $usuario, ':id' => $id];
        if (!empty($senha)) $params[':s'] = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        registrar_log('Editou', "Editou dados do usuário $usuario", 'usuario', $id);
        header("Location: usuarios.php");
    }
    elseif ($acao == 'delete_usuario') {
        $id = (int)$_POST['id'];
        if ($id == $_SESSION['usuario_id']) {
            header("Location: usuarios.php?erro=proprio_usuario");
            exit;
        }

        // NOVO: busca quem está sendo excluído para registrar no log
        $stmtU = $db->prepare("SELECT nome, usuario FROM usuarios WHERE id = :id");
        $stmtU->execute([':id' => $id]);
        $alvoUser = $stmtU->fetch(PDO::FETCH_ASSOC);

        if ($alvoUser) {
            $stmt = $db->prepare("DELETE FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $id]);
            registrar_log('Excluiu', "Excluiu usuário {$alvoUser['usuario']} ({$alvoUser['nome']})", 'usuario', $id);
        }
        header("Location: usuarios.php");
    }

} catch (PDOException $e) {
    // Detalhe técnico vai para o log do Apache; a tela mostra só um aviso simples
    error_log('Erro em actions.php (' . $acao . '): ' . $e->getMessage());
    http_response_code(500);
    echo 'Não foi possível concluir a operação. <a href="painel.php">Voltar ao painel</a>';
}
?>