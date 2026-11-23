# Gestão de Chips

Sistema web interno para controlar o estoque e o uso de chips (números de telefone) por equipe: conexões, status, histórico, auditoria e relatórios.

Feito em **PHP 8 + SQLite**, sem framework, para rodar facilmente em um servidor local como o XAMPP.

## Funcionalidades

- **Equipes e conexões:** cada equipe tem suas conexões (ex.: DIGISAC 01, WEB 01 SIM1). É possível criar, renomear e excluir conexões.
- **Controle de chips:** cadastro individual ou importação em lote por CSV, com detecção de números duplicados.
- **Status do chip:** `livre`, `uso`, `análise`, `spam`, `banido` e `descartado`. Em análise e spam o sistema calcula a previsão de "volta em" a partir das horas de penalidade.
- **Maturação:** marca o chip como maturado e guarda os dias de maturação.
- **Contadores:** quantas vezes cada chip foi conectado (Digisac/Web) e quantas vezes entrou em spam ou análise.
- **Pesquisa global e por equipe:** ignora máscara (`+`, parênteses, traços e espaços) e tem paginação.
- **Histórico da equipe:** lista de chips em análise, spam, banidos e descartados, com ações de retorno, banimento e descarte.
- **Logs de auditoria:** registra quem fez o quê e quando, com filtros por tipo, período e texto.
- **Relatórios:**
  - **PDF** de desempenho (geral ou por equipe, por período), gerado com Dompdf.
  - **Excel (.xlsx)** com a lista de chips de uma equipe e a data de entrada.
- **Usuários:** cadastro, edição e exclusão de usuários do sistema.

## Segurança

- Senhas com `password_hash` (bcrypt).
- Proteção CSRF em todas as ações que alteram dados (somente POST com token).
- Limite de tentativas de login (5 erros em 15 minutos por IP).
- Cookie de sessão com `HttpOnly` e `SameSite`, e ID de sessão regenerado no login.
- Consultas com PDO e parâmetros (sem SQL montado com entrada do usuário).
- Saída escapada no HTML e nos atributos `onclick`.

## Requisitos

- PHP 8.1 ou superior com as extensões: `pdo_sqlite`, `mbstring`, `dom`, `gd` e `zip`
- Apache (ex.: XAMPP) ou outro servidor web com PHP
- [Composer](https://getcomposer.org/), apenas se for reinstalar as dependências (a pasta `vendor/` já vem no repositório)

## Instalação

1. Clone o repositório dentro do `htdocs` (ou da pasta pública do seu servidor):

   ```bash
   git clone https://github.com/JonsCampos/gestao-chip-md.git
   ```

2. Abra o sistema no navegador, por exemplo `http://localhost/gestao-chip-md/`.

3. Na primeira execução o banco é criado automaticamente em `database/gestao.db`, junto com o usuário padrão:

   | Usuário | Senha    |
   |---------|----------|
   | `admin` | `123456` |

   **Troque essa senha logo no primeiro acesso** (menu de usuários).

4. Se quiser reinstalar as dependências: `composer install`.

## Estrutura do projeto

```
├── assets/
│   ├── img/            # logo e ícone
│   ├── vendor/         # Bootstrap e Font Awesome (servidos localmente)
│   ├── app.js          # funções JS compartilhadas
│   └── style.css       # estilos compartilhados
├── database/           # banco SQLite (não versionado)
├── docs/               # documentação do sistema
├── vendor/             # dependências do Composer (Dompdf)
├── actions.php         # todas as ações (login, chips, equipes, usuários...)
├── banidos.php         # histórico de chips em análise, spam, banidos e descartados
├── db.php              # conexão, criação das tabelas, migrações e índices
├── equipe.php          # tela da equipe e das conexões
├── functions.php       # sessão, CSRF, formatação e registro de logs
├── gerar_excel.php     # exportação .xlsx
├── gerar_pdf.php       # relatório em PDF
├── index.php           # login
├── logs.php            # histórico de auditoria
├── painel.php          # painel principal
├── relatorios.php      # tela de relatórios
└── usuarios.php        # gerenciamento de usuários
```

## Banco de dados

O banco SQLite fica em `database/gestao.db` e **não é versionado** (está no `.gitignore`), porque contém dados reais e hashes de senha.

- Faça backup desse arquivo regularmente.
- Em outro computador, copie o arquivo manualmente para `database/`.
- Se mover o projeto, não deixe o `gestao.db` acessível por URL no servidor.

## Observações

- O sistema é pensado para uso interno e em rede confiável. Não o exponha na internet sem HTTPS e sem restringir o acesso à pasta `database/`.
- Mantenha anotações, backups e documentação interna fora da pasta pública do servidor.

## Tecnologias

PHP 8 · SQLite (PDO) · Bootstrap 5 · Font Awesome · Dompdf