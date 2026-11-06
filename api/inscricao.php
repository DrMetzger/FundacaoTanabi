<?php
/**
 * Concurso de Bolsas 2027 — recebe a ficha de inscrição do site,
 * gera o número da inscrição, guarda uma cópia (CSV) no servidor
 * e envia os dados por e-mail para a secretaria.
 */
declare(strict_types=1);

require __DIR__ . '/lib/comum.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$querJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function responder(bool $ok, array $dados = [], int $status = 200): never
{
    global $querJson;
    http_response_code($status);
    if ($querJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok] + $dados, JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $titulo = $ok ? 'Inscrição enviada!' : 'Não foi possível enviar';
        $texto  = $ok
            ? 'Número da sua inscrição: <strong>' . htmlspecialchars($dados['numero']) . '</strong>. Guarde este número.'
            : htmlspecialchars($dados['erro'] ?? 'Erro.');
        echo "<!doctype html><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>"
           . "<title>$titulo</title><body style='font-family:system-ui;max-width:560px;margin:40px auto;padding:0 16px;line-height:1.6'>"
           . "<h1 style='color:#0b3d91'>$titulo</h1><p>$texto</p><p><a href='../index.html'>Voltar ao site</a></p></body>";
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(false, ['erro' => 'Método não permitido.'], 405);
}

/* ---------- Anti-spam ---------- */
if (!empty($_POST['website'])) {                       // honeypot preenchido = robô
    responder(true, ['numero' => PREFIXO_NUMERO . '0000']);
}
$t0 = (int) ($_POST['t0'] ?? 0);
if ($t0 > 0 && (time() * 1000 - $t0) < 4000) {         // enviado rápido demais
    responder(false, ['erro' => 'Envio muito rápido. Confira os dados e envie novamente.'], 429);
}

/* ---------- Leitura e validação ---------- */
function campo(string $nome, int $max = 160): string
{
    $v = trim((string) ($_POST[$nome] ?? ''));
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';   // remove quebras/controle (evita injeção de cabeçalho)
    return mb_substr($v, 0, $max);
}
function cpfValido(string $cpf): bool
{
    $c = soDigitos($cpf);
    if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
    foreach ([9, 10] as $n) {
        $s = 0;
        for ($i = 0; $i < $n; $i++) $s += (int) $c[$i] * ($n + 1 - $i);
        if ((($s * 10) % 11) % 10 !== (int) $c[$n]) return false;
    }
    return true;
}

$d = [
    'aluno_nome'       => campo('aluno_nome', 120),
    'aluno_nascimento' => campo('aluno_nascimento', 10),
    'aluno_cpf'        => campo('aluno_cpf', 14),
    'aluno_rg'         => campo('aluno_rg', 20),
    'aluno_email'      => campo('aluno_email', 120),
    'escola_atual'     => campo('escola_atual', 120),
    'serie_2026'       => campo('serie_2026', 60),
    'serie_2027'       => campo('serie_2027', 60),
    'resp_nome'        => campo('resp_nome', 120),
    'resp_vinculo'     => campo('resp_vinculo', 40),
    'resp_cpf'         => campo('resp_cpf', 14),
    'whatsapp'         => campo('whatsapp', 20),
    'telefone_alt'     => campo('telefone_alt', 20),
    'email'            => campo('email', 120),
    'outro_contato'    => campo('outro_contato', 160),
    'rua'              => campo('rua', 160),
    'bairro'           => campo('bairro', 80),
    'cidade'           => campo('cidade', 80),
    'cep'              => campo('cep', 9),
    'declaracao'       => campo('declaracao', 3),
];

$obrigatorios = ['aluno_nome', 'aluno_nascimento', 'aluno_cpf', 'escola_atual', 'serie_2026', 'serie_2027',
                 'resp_nome', 'resp_vinculo', 'resp_cpf', 'whatsapp', 'rua', 'bairro', 'cidade', 'cep'];
foreach ($obrigatorios as $k) {
    if ($d[$k] === '') responder(false, ['erro' => 'Preencha todos os campos obrigatórios.'], 422);
}
if ($d['declaracao'] !== 'sim') responder(false, ['erro' => 'É preciso aceitar a declaração.'], 422);
if (!cpfValido($d['aluno_cpf'])) responder(false, ['erro' => 'CPF do aluno inválido.'], 422);
if (!cpfValido($d['resp_cpf'])) responder(false, ['erro' => 'CPF do responsável inválido.'], 422);
if (strlen(soDigitos($d['whatsapp'])) < 10) responder(false, ['erro' => 'WhatsApp inválido.'], 422);
if (strlen(soDigitos($d['cep'])) !== 8) responder(false, ['erro' => 'CEP inválido.'], 422);
if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) responder(false, ['erro' => 'E-mail do responsável inválido.'], 422);
if ($d['aluno_email'] !== '' && !filter_var($d['aluno_email'], FILTER_VALIDATE_EMAIL)) responder(false, ['erro' => 'E-mail do aluno inválido.'], 422);
$nasc = DateTime::createFromFormat('!Y-m-d', $d['aluno_nascimento']);
if (!$nasc) responder(false, ['erro' => 'Data de nascimento inválida.'], 422);
$d['aluno_nascimento'] = $nasc->format('d/m/Y');

$pasta = pastaDados();

/* ---------- Número sequencial + CSV (com trava) ---------- */
date_default_timezone_set('America/Sao_Paulo');
$agora = new DateTime();
$colunas = ['Nº inscrição', 'Data/hora', 'Aluno', 'Nascimento', 'CPF aluno', 'RG', 'Escola atual', 'Série 2026', 'Série 2027',
            'Responsável', 'Vínculo', 'CPF responsável', 'WhatsApp', 'Telefone alt.', 'E-mail responsável', 'Outro contato',
            'Rua e nº', 'Bairro', 'Cidade/UF', 'CEP', 'IP', 'E-mail aluno'];

$lock = fopen($pasta . '/contador.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX)) responder(false, ['erro' => 'Servidor ocupado. Tente novamente.'], 503);

/* Zera a numeração uma única vez quando ZERAR_NUMERACAO muda: arquiva planilhas e PDFs de teste. */
$marca = $pasta . '/numeracao.txt';
if (trim((string) @file_get_contents($marca)) !== ZERAR_NUMERACAO) {
    $arquivo = $pasta . '/arquivo-' . $agora->format('Y-m-d-His');
    @mkdir($arquivo, 0700, true);
    foreach (array_merge(glob($pasta . '/*.csv') ?: [], glob($pasta . '/*.pdf') ?: [], glob($pasta . '/falhas-email.log') ?: []) as $f) {
        @rename($f, $arquivo . '/' . basename($f));
    }
    ftruncate($lock, 0); rewind($lock); fwrite($lock, '0'); fflush($lock);
    @file_put_contents($marca, ZERAR_NUMERACAO);
}
rewind($lock);
$ultimo = (int) trim((string) stream_get_contents($lock));
$seq = $ultimo + 1;
ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) $seq); fflush($lock);
$numero = PREFIXO_NUMERO . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

$csv = $pasta . '/inscricoes.csv';
// se o cabeçalho mudou (nova coluna), arquiva a planilha antiga e começa uma nova
if (is_file($csv) && ($fh0 = fopen($csv, 'r'))) {
    $cab0 = (string) fgets($fh0); fclose($fh0);
    if (!str_contains($cab0, 'E-mail aluno')) @rename($csv, $pasta . '/inscricoes-ate-' . $agora->format('Y-m-d-His') . '.csv');
}
$novo = !is_file($csv);
if ($fh = fopen($csv, 'a')) {
    if ($novo) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, $colunas, ';'); }
    $linha = [$numero, $agora->format('d/m/Y H:i:s'), $d['aluno_nome'], $d['aluno_nascimento'], $d['aluno_cpf'], $d['aluno_rg'],
              $d['escola_atual'], $d['serie_2026'], $d['serie_2027'], $d['resp_nome'], $d['resp_vinculo'], $d['resp_cpf'],
              $d['whatsapp'], $d['telefone_alt'], $d['email'], $d['outro_contato'], $d['rua'], $d['bairro'], $d['cidade'],
              $d['cep'], $_SERVER['REMOTE_ADDR'] ?? '', $d['aluno_email']];
    // evita fórmulas ao abrir no Excel
    $linha = array_map(fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, $linha);
    fputcsv($fh, $linha, ';');
    fclose($fh);
}
flock($lock, LOCK_UN); fclose($lock);

/* ---------- Conteúdo da ficha ---------- */
require_once __DIR__ . '/lib/ficha.php';

$secoes = fichaSecoes($d);
$aceite = 'Declaração e ciência aceitas eletronicamente pelo(a) responsável legal ' . $d['resp_nome']
        . ' em ' . $agora->format('d/m/Y \à\s H:i') . ' (IP ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ').';

$pdfConteudo = fichaPdf($numero, $agora, $secoes, $aceite);
$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $d['aluno_nome']))) ?? 'aluno';
$anexo = ['nome' => 'Ficha-Inscricao-' . $numero . '-' . trim($slug, '-') . '.pdf', 'tipo' => 'application/pdf', 'dados' => $pdfConteudo];
@file_put_contents($pasta . '/' . $anexo['nome'], $pdfConteudo);   // cópia do PDF no servidor

/* ---------- 1) Ficha para a equipe (destino + cópia) ---------- */
$internos = listaEmails(EMAIL_DESTINO, EMAIL_COPIA);
$para = listaEmails(EMAIL_DESTINO);
$cc = array_values(array_diff($internos, $para));
$wa = soDigitos($d['whatsapp']);
$h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$contatoEquipe = 'WhatsApp ' . WHATSAPP_SECRETARIA . ' · ' . $h(EMAIL_SECRETARIA);

$enviado = enviarEmail(
    $para, $cc,
    "Concurso de Bolsas 2027 – Inscrição $numero – {$d['aluno_nome']}",
    fichaEmailHtml('Nova inscrição recebida',
        'Uma nova ficha de inscrição foi enviada pelo site. Os dados completos estão abaixo e também no <strong>PDF anexo</strong>.',
        $numero, $agora, $secoes, $aceite, 'Chamar responsável no WhatsApp', "https://wa.me/55$wa", $contatoEquipe),
    fichaEmailTexto('Nova inscrição recebida pelo site.', $numero, $agora, $secoes, $aceite),
    $d['email'] !== '' ? $d['email'] : $d['aluno_email'],
    [$anexo]
);
if (!$enviado) {
    @file_put_contents($pasta . '/falhas-email.log', $agora->format('c') . " falha ao enviar $numero\n", FILE_APPEND);
}

/* ---------- 2) Confirmação para responsável e aluno (exceto e-mails da equipe, sem duplicar) ---------- */
$familia = array_values(array_diff(listaEmails($d['email'], $d['aluno_email']), $internos));
if ($familia) {
    enviarEmail(
        $familia, [],
        "Inscrição $numero confirmada – Concurso de Bolsas 2027 – FET",
        fichaEmailHtml('Inscrição recebida!',
            'Olá, <strong>' . $h($d['resp_nome']) . '</strong>. Recebemos a inscrição de <strong>' . $h($d['aluno_nome'])
            . '</strong> no Concurso de Bolsas 2027. Guarde o número <strong>' . $h($numero) . '</strong>. Confira abaixo os dados enviados; a ficha também segue em PDF anexo.',
            $numero, $agora, $secoes, $aceite, 'Falar com a secretaria no WhatsApp', 'https://wa.me/5517988470429', $contatoEquipe),
        fichaEmailTexto("Olá, {$d['resp_nome']}. Recebemos a inscrição de {$d['aluno_nome']}. Guarde o número $numero.", $numero, $agora, $secoes, $aceite),
        EMAIL_SECRETARIA,
        [$anexo]
    );
}

// A inscrição já está gravada no servidor; mesmo se o e-mail falhar, o número é válido.
responder(true, ['numero' => $numero, 'email' => $enviado]);
