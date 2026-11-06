<?php
/** Montagem da ficha de inscrição: PDF e e-mail HTML. */
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';

const FICHA_INSTITUICAO = 'FUNDAÇÃO EDUCACIONAL DE TANABI';
const FICHA_ENDERECO    = 'Rua José Siriani, 927 – Centro – Tanabi/SP – CEP 15170-000';
const FICHA_CNPJ        = 'CNPJ 53.222.485/0001-24';
const FICHA_SITE        = 'objetivotanabi.com.br';
const FICHA_LOGO_URL    = 'https://objetivotanabi.com.br/assets/img/logo.png';
const FICHA_DECLARACAO  = 'Declaro que as informações prestadas são verdadeiras e que estou ciente de que a inscrição não garante a concessão de bolsa, a qual dependerá dos critérios previstos no regulamento do concurso. Estou ciente de que os dados informados serão utilizados pela Fundação Educacional de Tanabi para inscrição, organização da prova e comunicação sobre o processo seletivo.';
const FICHA_AVISO       = 'Informações sobre data, horário, local da prova e critérios de seleção serão divulgadas pela Fundação.';

/**
 * @param array<string, array<string,string>> $secoes
 */
function fichaPdf(string $numero, DateTimeInterface $quando, array $secoes, string $aceite): string
{
    $pdf = new PdfSimples();
    $pdf->novaPagina();
    $W = PdfSimples::W;
    $mx = 40;
    $larg = $W - 2 * $mx;

    $cabecalho = function () use ($pdf, $W, $mx, $numero, $quando) {
        $pdf->retangulo(0, 0, $W, 92, '#0b3d91');
        $pdf->retangulo($mx - 2, 14, 66, 66, '#ffffff');
        $pdf->imagemJpeg(__DIR__ . '/../logo-pdf.jpg', $mx, 16, 62, 62);
        $pdf->texto($mx + 80, 40, FICHA_INSTITUICAO, 16, true, '#ffffff');
        $pdf->texto($mx + 80, 58, FICHA_ENDERECO, 9, false, '#dbe6ff');
        $pdf->texto($mx + 80, 72, FICHA_CNPJ . '  ·  ' . FICHA_SITE, 9, false, '#dbe6ff');
        $pdf->retangulo(0, 92, $W, 34, '#f4b400');
        $pdf->texto($mx, 114, 'CONCURSO DE BOLSAS 2027  |  FICHA DE INSCRIÇÃO', 12, true, '#1f1600');
        $pdf->textoDireita($W - $mx, 114, 'Nº ' . $numero . '  ·  ' . $quando->format('d/m/Y H:i'), 11, true, '#0b3d91');
    };
    $rodape = function (int $pag) use ($pdf, $W, $mx, $quando) {
        $y = PdfSimples::H - 34;
        $pdf->linha($mx, $y - 12, $W - $mx, $y - 12, '#c5cddd');
        $pdf->texto($mx, $y, 'Documento gerado pelo site ' . FICHA_SITE . ' em ' . $quando->format('d/m/Y \à\s H:i') . '.', 8, false, '#4a5468');
        $pdf->textoDireita($W - $mx, $y, 'Página ' . $pag, 8, false, '#4a5468');
    };

    $pag = 1;
    $cabecalho();
    $y = 144;
    $limite = PdfSimples::H - 70;
    $garantir = function (float $altura) use (&$y, &$pag, $limite, $pdf, $cabecalho, $rodape) {
        if ($y + $altura > $limite) {
            $rodape($pag);
            $pdf->novaPagina();
            $pag++;
            $cabecalho();
            $y = 150;
        }
    };

    $colRot = 190;
    foreach ($secoes as $titulo => $itens) {
        $garantir(60);
        $pdf->retangulo($mx, $y, $larg, 24, '#e9eef8');
        $pdf->retangulo($mx, $y, 4, 24, '#0b3d91');
        $pdf->texto($mx + 14, $y + 16, mb_strtoupper($titulo), 10.5, true, '#0b3d91');
        $y += 30;
        $i = 0;
        foreach ($itens as $rot => $val) {
            $val = $val === '' ? '—' : $val;
            $linhas = $pdf->quebrar($val, 10.5, $larg - $colRot - 16, true);
            $alt = max(19, 6 + 13 * count($linhas));
            $garantir($alt);
            if ($i++ % 2 === 1) $pdf->retangulo($mx, $y, $larg, $alt, '#f7f9fd');
            $pdf->texto($mx + 12, $y + 13, $rot, 9.5, false, '#4a5468');
            foreach ($linhas as $k => $l) $pdf->texto($mx + $colRot, $y + 13 + 13 * $k, $l, 10.5, true, '#172033');
            $pdf->linha($mx, $y + $alt, $mx + $larg, $y + $alt, '#e3e8f2');
            $y += $alt;
        }
        $y += 10;
    }

    // Declaração
    $decl = $pdf->quebrar(FICHA_DECLARACAO, 9.5, $larg - 24);
    $garantir(100 + 12 * count($decl));
    $pdf->retangulo($mx, $y, $larg, 24, '#e9eef8');
    $pdf->retangulo($mx, $y, 4, 24, '#0b3d91');
    $pdf->texto($mx + 14, $y + 16, '4. DECLARAÇÃO E CIÊNCIA', 10.5, true, '#0b3d91');
    $y += 36;
    foreach ($decl as $l) { $pdf->texto($mx + 12, $y, $l, 9.5, false, '#172033'); $y += 12; }
    $y += 8;
    $pdf->retangulo($mx + 12, $y - 10, 12, 12, '#0b3d91');
    $pdf->texto($mx + 14.6, $y - 0.5, 'X', 9, true, '#ffffff');
    foreach ($pdf->quebrar($aceite, 9.5, $larg - 50, true) as $l) { $pdf->texto($mx + 32, $y, $l, 9.5, true, '#172033'); $y += 13; }
    $y += 10;
    $garantir(30);
    foreach ($pdf->quebrar(FICHA_AVISO, 9, $larg - 24) as $l) { $pdf->texto($mx + 12, $y, $l, 9, false, '#4a5468'); $y += 12; }

    $rodape($pag);
    return $pdf->saida();
}

/**
 * E-mail HTML (tabelas + estilos inline, compatível com Outlook/Gmail).
 * @param array<string, array<string,string>> $secoes
 */
function fichaEmailHtml(string $titulo, string $introHtml, string $numero, DateTimeInterface $quando, array $secoes,
                        string $aceite, string $botaoTexto, string $botaoUrl, string $contatoHtml): string
{
    $h = fn(string $v): string => htmlspecialchars($v === '' ? '—' : $v, ENT_QUOTES, 'UTF-8');
    $corpo = '';
    foreach ($secoes as $tit => $itens) {
        $corpo .= '<tr><td style="padding:22px 28px 6px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
                . '<tr><td style="border-left:4px solid #0b3d91;background:#e9eef8;padding:9px 14px;font:bold 13px Arial,Helvetica,sans-serif;color:#0b3d91;text-transform:uppercase;letter-spacing:.5px">' . $h($tit) . '</td></tr></table>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">';
        $i = 0;
        foreach ($itens as $rot => $val) {
            $bg = ($i++ % 2) ? '#f7f9fd' : '#ffffff';
            $corpo .= '<tr style="background:' . $bg . '"><td width="40%" valign="top" style="padding:9px 14px;border-bottom:1px solid #e3e8f2;font:13px Arial,Helvetica,sans-serif;color:#4a5468">' . $h($rot) . '</td>'
                    . '<td valign="top" style="padding:9px 14px;border-bottom:1px solid #e3e8f2;font:bold 14px Arial,Helvetica,sans-serif;color:#172033">' . $h($val) . '</td></tr>';
        }
        $corpo .= '</table></td></tr>';
    }

    $botao = $botaoUrl === '' ? '' :
        '<tr><td align="center" style="padding:8px 28px 26px"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td bgcolor="#1fa855" style="border-radius:24px"><a href="' . $h($botaoUrl) . '" style="display:inline-block;padding:13px 26px;font:bold 15px Arial,Helvetica,sans-serif;color:#ffffff;text-decoration:none;border-radius:24px">' . $h($botaoTexto) . '</a></td>'
        . '</tr></table></td></tr>';

    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . $h($titulo) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef2f8">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $h("Inscrição $numero – Concurso de Bolsas 2027") . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef2f8"><tr><td align="center" style="padding:24px 10px">'
        . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(16,30,60,.08)">'
        // Topo
        . '<tr><td bgcolor="#0b3d91" style="padding:22px 28px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td width="76" valign="middle"><img src="' . FICHA_LOGO_URL . '" width="64" height="64" alt="FET" style="display:block;border:0;border-radius:50%;background:#ffffff"></td>'
        . '<td valign="middle" style="font:bold 18px Arial,Helvetica,sans-serif;color:#ffffff">Fundação Educacional de Tanabi'
        . '<div style="font:13px Arial,Helvetica,sans-serif;color:#dbe6ff;padding-top:4px">Objetivo · Educação Infantil, Fundamental e Médio</div></td>'
        . '</tr></table></td></tr>'
        . '<tr><td bgcolor="#f4b400" style="padding:12px 28px;font:bold 15px Arial,Helvetica,sans-serif;color:#1f1600">'
        . 'Concurso de Bolsas 2027 &nbsp;|&nbsp; Inscrição Nº ' . $h($numero)
        . '<span style="font-weight:normal;font-size:13px"> &nbsp;·&nbsp; ' . $quando->format('d/m/Y H:i') . '</span></td></tr>'
        // Introdução
        . '<tr><td style="padding:24px 28px 4px;font:15px/1.55 Arial,Helvetica,sans-serif;color:#172033">'
        . '<div style="font:bold 21px Arial,Helvetica,sans-serif;color:#0b3d91;padding-bottom:8px">' . $h($titulo) . '</div>' . $introHtml . '</td></tr>'
        . $corpo
        // Declaração
        . '<tr><td style="padding:18px 28px 18px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td style="background:#e3f6ea;border-radius:10px;padding:12px 16px;font:bold 13px Arial,Helvetica,sans-serif;color:#13683a">&#10004; ' . $h($aceite) . '</td></tr></table>'
        . '<p style="margin:12px 0 0;font:12px/1.5 Arial,Helvetica,sans-serif;color:#4a5468">' . $h(FICHA_AVISO) . '</p>'
        . '<p style="margin:6px 0 0;font:12px/1.5 Arial,Helvetica,sans-serif;color:#4a5468">&#128206; A ficha completa segue em PDF anexo a este e-mail.</p></td></tr>'
        . $botao
        // Rodapé
        . '<tr><td bgcolor="#082d6b" style="padding:20px 28px;font:12px/1.6 Arial,Helvetica,sans-serif;color:#c9d6f2">'
        . '<strong style="color:#ffffff">Fundação Educacional de Tanabi</strong><br>' . $h(FICHA_ENDERECO) . '<br>' . $contatoHtml . '<br>'
        . $h(FICHA_CNPJ) . ' · <a href="https://' . FICHA_SITE . '" style="color:#f4b400;text-decoration:none">' . FICHA_SITE . '</a>'
        . '<div style="padding-top:10px;font-size:11px;color:#9fb2d9">Mensagem automática enviada pelo site. Os dados desta ficha são tratados conforme a LGPD, apenas para o processo seletivo.</div>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Versão texto puro (para clientes sem HTML). */
function fichaEmailTexto(string $intro, string $numero, DateTimeInterface $quando, array $secoes, string $aceite): string
{
    $t = "FUNDAÇÃO EDUCACIONAL DE TANABI\nCONCURSO DE BOLSAS 2027 - FICHA DE INSCRIÇÃO\nNº $numero - " . $quando->format('d/m/Y H:i') . "\n\n$intro\n";
    foreach ($secoes as $tit => $itens) {
        $t .= "\n" . mb_strtoupper($tit) . "\n";
        foreach ($itens as $rot => $val) $t .= "  $rot: " . ($val === '' ? '—' : $val) . "\n";
    }
    return $t . "\n[X] $aceite\n\n" . FICHA_AVISO . "\n\n--\n" . FICHA_ENDERECO . "\n" . FICHA_CNPJ . " - " . FICHA_SITE . "\n";
}
