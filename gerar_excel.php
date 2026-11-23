<?php
// gerar_excel.php
require 'functions.php';
verificar_login();

/**
 * Classe Minimalista para gerar XLSX
 */
class SimpleXLSXWriter {
    public static function download(array $rows, string $fileName = 'export.xlsx') {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer = new self($tempFile);
        $writer->writeSheet($rows);
        $writer->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($tempFile));
        readfile($tempFile);
        unlink($tempFile);
    }

    private $zip;
    private $filename;

    public function __construct($filename) {
        $this->filename = $filename;
        $this->zip = new ZipArchive();
        $this->zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    }

    public function writeSheet(array $rows) {
        // [Content_Types].xml
        $this->zip->addFromString('[Content_Types].xml', 
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                <Default Extension="xml" ContentType="application/xml"/>
                <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
                <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
            </Types>');

        // _rels/.rels
        $this->zip->addFromString('_rels/.rels', 
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
            </Relationships>');

        // xl/_rels/workbook.xml.rels
        $this->zip->addFromString('xl/_rels/workbook.xml.rels', 
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
            </Relationships>');

        // xl/workbook.xml
        $this->zip->addFromString('xl/workbook.xml', 
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
                <sheets>
                    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
                </sheets>
            </workbook>');

        // xl/worksheets/sheet1.xml (DADOS + LARGURA DAS COLUNAS)
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
            <cols>
                <col min="1" max="1" width="20" customWidth="1"/>
                <col min="2" max="2" width="22" customWidth="1"/>
            </cols>
            <sheetData>';
        
        foreach ($rows as $row) {
            $xml .= '<row>';
            foreach ($row as $cell) {
                $val = htmlspecialchars((string)$cell, ENT_XML1, 'UTF-8');
                $xml .= '<c t="inlineStr"><is><t>' . $val . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        
        $xml .= '</sheetData></worksheet>';
        $this->zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    }

    public function close() {
        $this->zip->close();
    }
}

// ---------------- Lógica da página ----------------

$equipe_id = $_POST['equipe_id'] ?? '';

// Validação
if (empty($equipe_id) || $equipe_id === 'todas') {
    die("Erro: Por favor, selecione uma equipe específica para gerar este relatório.");
}
$equipe_id = (int)$equipe_id;

// Busca nome da equipe
$stmt = $db->prepare("SELECT nome FROM equipes WHERE id = :id");
$stmt->execute([':id' => $equipe_id]);
$nome_equipe = $stmt->fetchColumn();

if (!$nome_equipe) {
    die("Equipe não encontrada.");
}

// Query de dados (data de entrada = primeiro log do chip)
$sql = "SELECT c.numero, 
        (SELECT data_hora FROM logs WHERE alvo_tipo = 'chip' AND alvo_id = c.id ORDER BY id ASC LIMIT 1) as data_entrada
        FROM chips c
        WHERE c.equipe_id = :eid
        ORDER BY data_entrada DESC";

$stmt = $db->prepare($sql);
$stmt->execute([':eid' => $equipe_id]);
$chips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepara os dados para o Excel
$dadosExcel = [];
$dadosExcel[] = ['Número', 'Data']; 

foreach ($chips as $chip) {
    // 1. Remove tudo que não for número (tira parenteses, espaços, traços, etc)
    $apenas_numeros = preg_replace('/\D/', '', $chip['numero']);
    $qtd_digitos = strlen($apenas_numeros);
    
    // 2. Aplica a regra de formatação solicitada para o Excel
    if ($qtd_digitos === 12 || $qtd_digitos === 13) {
        // Para 12 ou 13 dígitos: Adiciona o + na frente
        $numero = '+' . $apenas_numeros;
    } else {
        // Para 8, 9, 10 ou 11 dígitos: Mantém apenas os números
        $numero = $apenas_numeros;
    }

    $data_entrada = '-';
    if (!empty($chip['data_entrada'])) {
        $data_entrada = date('d/m/Y H:i:s', strtotime($chip['data_entrada']));
    }
    
    $dadosExcel[] = [$numero, $data_entrada];
}

// PADRÃO DE NOME: CHIPS_NOME-EQUIPE-DATA.xlsx
$nome_limpo = preg_replace('/[^A-Za-z0-9]/', '-', mb_strtoupper($nome_equipe, 'UTF-8'));
$nome_arquivo = "CHIPS_" . $nome_limpo . "-" . date('d-m-Y') . ".xlsx";

SimpleXLSXWriter::download($dadosExcel, $nome_arquivo);
exit;