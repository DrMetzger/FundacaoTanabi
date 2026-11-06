<?php
/**
 * Concurso de Bolsas 2027 — recebe a ficha de inscrição do site,
 * gera o número da inscrição, guarda uma cópia (CSV) no servidor
 * e envia os dados por e-mail para a secretaria.
 */
declare(strict_types=1);

/* ================== CONFIGURAÇÃO ================== */
const EMAIL_DESTINO   = 'pcorreajr@primeerp.com.br';                // quem recebe as inscrições (pode separar vários por vírgula)
const EMAIL_COPIA     = 'secretariafet@tanabinet.com.br';           // recebe cópia (Cc) de cada inscrição
const EMAIL_SECRETARIA = 'secretariafet@tanabinet.com.br';          // e-mail mostrado às famílias na confirmação
const EMAIL_REMETENTE_PADRAO = 'nao-responda@objetivotanabi.com.br'; // usado quando não há SMTP configurado
const NOME_REMETENTE  = 'Site FET – Concurso de Bolsas';
const PREFIXO_NUMERO  = '2027-';
const ZERAR_NUMERACAO = 'oficial-2026-11-06';  // trocar este texto zera a numeração uma vez (testes vão para a pasta arquivo-*)
const WHATSAPP_SECRETARIA = '(17) 98847-0429';
/* ================================================== */

/* SMTP: api/config.php é gerado no deploy a partir dos secrets SMTP_USER / SMTP_SENHA do GitHub. */
$CFG = is_file(__DIR__ . '/config.php') ? (array) (include __DIR__ . '/config.php') : [];
$CFG += ['smtp_host' => 'ssl://smtp.hostinger.com', 'smtp_port' => 465, 'smtp_user' => '', 'smtp_pass' => ''];
define('EMAIL_REMETENTE', $CFG['smtp_user'] !== '' ? $CFG['smtp_user'] : EMAIL_REMETENTE_PADRAO);

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
function soDigitos(string $v): string { return preg_replace('/\D/', '', $v) ?? ''; }
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

/* ---------- Pasta de dados (fora do public_html quando possível) ---------- */
function pastaDados(): string
{
    $fora = dirname(__DIR__, 2) . '/inscricoes-bolsas-2027';
    if (is_dir($fora) || @mkdir($fora, 0700, true)) {
        if (is_writable($fora)) return $fora;
    }
    $dentro = __DIR__ . '/_dados';
    if (!is_dir($dentro)) @mkdir($dentro, 0700, true);
    if (!is_file($dentro . '/.htaccess')) @file_put_contents($dentro . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_file($dentro . '/index.html')) @file_put_contents($dentro . '/index.html', '');
    return $dentro;
}
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

$secoes = [
    '1. Dados do(a) aluno(a)' => [
        'Nome completo' => $d['aluno_nome'], 'Data de nascimento' => $d['aluno_nascimento'], 'CPF' => $d['aluno_cpf'],
        'RG' => $d['aluno_rg'], 'E-mail do(a) aluno(a)' => $d['aluno_email'], 'Escola atual' => $d['escola_atual'],
        'Ano/série cursado em 2026' => $d['serie_2026'], 'Ano/série pretendido em 2027' => $d['serie_2027'],
    ],
    '2. Responsável legal e contatos' => [
        'Responsável legal' => $d['resp_nome'], 'Vínculo com o aluno' => $d['resp_vinculo'], 'CPF' => $d['resp_cpf'],
        'WhatsApp principal' => $d['whatsapp'], 'Telefone alternativo' => $d['telefone_alt'],
        'E-mail do responsável' => $d['email'], 'Outro contato / responsável' => $d['outro_contato'],
    ],
    '3. Endereço' => [
        'Rua / avenida e número' => $d['rua'], 'Bairro' => $d['bairro'], 'Cidade/UF' => $d['cidade'], 'CEP' => $d['cep'],
    ],
];
$aceite = 'Declaração e ciência aceitas eletronicamente pelo(a) responsável legal ' . $d['resp_nome']
        . ' em ' . $agora->format('d/m/Y \à\s H:i') . ' (IP ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ').';

$pdfConteudo = fichaPdf($numero, $agora, $secoes, $aceite);
$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $d['aluno_nome']))) ?? 'aluno';
$anexo = ['nome' => 'Ficha-Inscricao-' . $numero . '-' . trim($slug, '-') . '.pdf', 'tipo' => 'application/pdf', 'dados' => $pdfConteudo];
@file_put_contents($pasta . '/' . $anexo['nome'], $pdfConteudo);   // cópia do PDF no servidor

/** Envia via SMTP autenticado (SSL). Retorna true se o servidor aceitou a mensagem. */
function smtpEnviar(array $cfg, array $destinos, string $mensagem, ?string &$erro = null): bool
{
    $erro = null;
    $sock = @stream_socket_client($cfg['smtp_host'] . ':' . $cfg['smtp_port'], $en, $es, 15);
    if (!$sock) { $erro = "conexão: $es"; return false; }
    stream_set_timeout($sock, 30);
    $ler = function () use ($sock): string {
        $r = '';
        while (($l = fgets($sock, 1024)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $r;
    };
    $cmd = function (?string $c, array $ok) use ($sock, $ler, &$erro): bool {
        if ($c !== null) fwrite($sock, $c . "\r\n");
        $r = $ler();
        if (!in_array((int) substr($r, 0, 3), $ok, true)) {
            $erro = trim(($c !== null && (str_starts_with($c, 'AUTH') || strlen($c) > 200) ? substr($c, 0, 10) . '…' : (string) $c) . ' -> ' . $r);
            return false;
        }
        return true;
    };
    $host = $_SERVER['SERVER_NAME'] ?? 'objetivotanabi.com.br';
    $ok = $cmd(null, [220]) && $cmd("EHLO $host", [250])
       && $cmd('AUTH LOGIN', [334]) && $cmd(base64_encode($cfg['smtp_user']), [334]) && $cmd(base64_encode($cfg['smtp_pass']), [235])
       && $cmd('MAIL FROM:<' . $cfg['smtp_user'] . '>', [250]);
    foreach ($destinos as $d) { $ok = $ok && $cmd('RCPT TO:<' . $d . '>', [250, 251]); }
    $dados = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $mensagem));
    $dados = str_replace("\n", "\r\n", $dados);
    $ok = $ok && $cmd('DATA', [354]) && $cmd($dados . "\r\n.", [250]);
    @fwrite($sock, "QUIT\r\n"); fclose($sock);
    return $ok;
}

/** Lista de e-mails separados por vírgula → array limpo, sem repetição. */
function listaEmails(string ...$listas): array
{
    $out = [];
    foreach ($listas as $l) foreach (explode(',', $l) as $e) {
        $e = strtolower(trim($e));
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out, true)) $out[] = $e;
    }
    return $out;
}

function enviarEmail(array $para, array $cc, string $assunto, string $html, string $texto, string $replyTo = '', array $anexos = []): bool
{
    global $CFG, $pasta;
    $cc = array_values(array_diff($cc, $para));
    $alt = 'a' . bin2hex(random_bytes(10));
    $mix = 'm' . bin2hex(random_bytes(10));
    $de = '=?UTF-8?B?' . base64_encode(NOME_REMETENTE) . '?= <' . EMAIL_REMETENTE . '>';
    $assuntoCod = '=?UTF-8?B?' . base64_encode($assunto) . '?=';

    $cab = "From: $de\r\n";
    if ($cc) $cab .= 'Cc: ' . implode(', ', $cc) . "\r\n";
    if ($replyTo !== '') $cab .= "Reply-To: $replyTo\r\n";
    $cab .= "MIME-Version: 1.0\r\n";

    $alternativo = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . chunk_split(base64_encode($texto))
                 . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . chunk_split(base64_encode($html))
                 . "--$alt--\r\n";
    if ($anexos) {
        $cab .= "Content-Type: multipart/mixed; boundary=\"$mix\"\r\n";
        $corpo = "--$mix\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n" . $alternativo;
        foreach ($anexos as $a) {
            $corpo .= "--$mix\r\nContent-Type: {$a['tipo']}; name=\"{$a['nome']}\"\r\nContent-Transfer-Encoding: base64\r\n"
                    . "Content-Disposition: attachment; filename=\"{$a['nome']}\"\r\n\r\n" . chunk_split(base64_encode($a['dados']));
        }
        $corpo .= "--$mix--";
    } else {
        $cab .= "Content-Type: multipart/alternative; boundary=\"$alt\"\r\n";
        $corpo = $alternativo;
    }

    if ($CFG['smtp_user'] !== '' && $CFG['smtp_pass'] !== '') {
        $msg = 'Date: ' . date('r') . "\r\nTo: " . implode(', ', $para) . "\r\nSubject: $assuntoCod\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . substr(strrchr(EMAIL_REMETENTE, '@'), 1) . ">\r\n"
             . $cab . "\r\n" . $corpo;
        if (smtpEnviar($CFG, array_merge($para, $cc), $msg, $erro)) return true;
        @file_put_contents($pasta . '/falhas-email.log', date('c') . " SMTP falhou ($erro); tentando mail()\n", FILE_APPEND);
    }
    return @mail(implode(', ', $para), $assuntoCod, $corpo, $cab, '-f' . EMAIL_REMETENTE);
}

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
