<?php
/** Gera um arquivo .xlsx simples (uma planilha, cabeçalho em negrito, filtro e 1ª linha congelada). */
declare(strict_types=1);

function gerarXlsx(string $tituloAba, array $cabecalho, array $linhas, array $larguras = []): ?string
{
    if (!class_exists('ZipArchive')) return null;
    $x = fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };

    $nCols = count($cabecalho);
    $ult = $col($nCols - 1) . (count($linhas) + 1);
    $rows = '';
    foreach (array_merge([$cabecalho], $linhas) as $r => $linha) {
        $rows .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($linha) as $c => $v) {
            $ref = $col($c) . ($r + 1);
            $rows .= '<c r="' . $ref . '" t="inlineStr"' . ($r === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x((string) $v) . '</t></is></c>';
        }
        $rows .= '</row>';
    }
    $cols = '<cols>';
    for ($i = 0; $i < $nCols; $i++) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($larguras[$i] ?? 18) . '" customWidth="1"/>';
    $cols .= '</cols>';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . $cols . '<sheetData>' . $rows . '</sheetData>'
        . '<autoFilter ref="A1:' . $ult . '"/></worksheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) return null;
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x(mb_substr($tituloAba, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $x(mb_substr($tituloAba, 0, 31)) . '\'!$A$1:$' . preg_replace('/\d+/', '', $ult) . '$' . (count($linhas) + 1) . '</definedName></definedNames></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0B3D91"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $z->close();
    $dados = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $dados;
}

/** Cabeçalho e linhas padrão das inscrições para exportação. */
function planilhaInscricoes(array $inscricoes): array
{
    $mapa = [
        'Nº inscrição' => ['numero', 13], 'Data/hora' => ['data', 19], 'Aluno' => ['aluno_nome', 32], 'Nascimento' => ['aluno_nascimento', 12],
        'CPF aluno' => ['aluno_cpf', 16], 'RG aluno' => ['aluno_rg', 14], 'E-mail aluno' => ['aluno_email', 28], 'Escola atual' => ['escola_atual', 28],
        'Série 2026' => ['serie_2026', 20], 'Série 2027' => ['serie_2027', 20], 'Responsável' => ['resp_nome', 30], 'Vínculo' => ['resp_vinculo', 12],
        'CPF responsável' => ['resp_cpf', 16], 'WhatsApp' => ['whatsapp', 17], 'Telefone alt.' => ['telefone_alt', 17],
        'E-mail responsável' => ['email', 30], 'Outro contato' => ['outro_contato', 24], 'Rua e nº' => ['rua', 30], 'Bairro' => ['bairro', 18],
        'Cidade/UF' => ['cidade', 16], 'CEP' => ['cep', 11],
    ];
    $linhas = [];
    foreach ($inscricoes as $r) {
        $l = [];
        foreach ($mapa as [$k]) $l[] = $r[$k] ?? '';
        $linhas[] = $l;
    }
    return [array_keys($mapa), $linhas, array_column(array_values($mapa), 1)];
}
