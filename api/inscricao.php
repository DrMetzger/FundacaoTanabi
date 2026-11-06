<?php
/**
 * Concurso de Bolsas 2027 — recebe a ficha de inscrição do site,
 * gera o número da inscrição, guarda uma cópia (CSV) no servidor
 * e envia os dados por e-mail para a secretaria.
 */
declare(strict_types=1);

/* ================== CONFIGURAÇÃO ================== */
const EMAIL_DESTINO   = 'secretariafet@tanabinet.com.br';           // quem recebe as inscrições (pode separar vários por vírgula)
const EMAIL_REMETENTE = 'nao-responda@objetivotanabi.com.br';       // precisa ser do domínio do site
const NOME_REMETENTE  = 'Site FET – Concurso de Bolsas';
const PREFIXO_NUMERO  = '2027-';
const WHATSAPP_SECRETARIA = '(17) 98847-0429';
/* ================================================== */

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
if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) responder(false, ['erro' => 'E-mail inválido.'], 422);
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
            'Responsável', 'Vínculo', 'CPF responsável', 'WhatsApp', 'Telefone alt.', 'E-mail', 'Outro contato',
            'Rua e nº', 'Bairro', 'Cidade/UF', 'CEP', 'IP'];

$lock = fopen($pasta . '/contador.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX)) responder(false, ['erro' => 'Servidor ocupado. Tente novamente.'], 503);
$ultimo = (int) trim((string) stream_get_contents($lock));
$seq = $ultimo + 1;
ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) $seq); fflush($lock);
$numero = PREFIXO_NUMERO . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

$csv = $pasta . '/inscricoes.csv';
$novo = !is_file($csv);
if ($fh = fopen($csv, 'a')) {
    if ($novo) { fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, $colunas, ';'); }
    $linha = [$numero, $agora->format('d/m/Y H:i:s'), $d['aluno_nome'], $d['aluno_nascimento'], $d['aluno_cpf'], $d['aluno_rg'],
              $d['escola_atual'], $d['serie_2026'], $d['serie_2027'], $d['resp_nome'], $d['resp_vinculo'], $d['resp_cpf'],
              $d['whatsapp'], $d['telefone_alt'], $d['email'], $d['outro_contato'], $d['rua'], $d['bairro'], $d['cidade'],
              $d['cep'], $_SERVER['REMOTE_ADDR'] ?? ''];
    // evita fórmulas ao abrir no Excel
    $linha = array_map(fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, $linha);
    fputcsv($fh, $linha, ';');
    fclose($fh);
}
flock($lock, LOCK_UN); fclose($lock);

/* ---------- E-mail para a secretaria ---------- */
$h = fn(string $v): string => htmlspecialchars($v === '' ? '—' : $v, ENT_QUOTES, 'UTF-8');
$secoes = [
    '1. Dados do(a) aluno(a)' => [
        'Nome completo' => $d['aluno_nome'], 'Nascimento' => $d['aluno_nascimento'], 'CPF' => $d['aluno_cpf'],
        'RG' => $d['aluno_rg'], 'Escola atual' => $d['escola_atual'],
        'Ano/série cursado em 2026' => $d['serie_2026'], 'Ano/série pretendido em 2027' => $d['serie_2027'],
    ],
    '2. Responsável legal e contatos' => [
        'Responsável legal' => $d['resp_nome'], 'Vínculo' => $d['resp_vinculo'], 'CPF' => $d['resp_cpf'],
        'WhatsApp principal' => $d['whatsapp'], 'Telefone alternativo' => $d['telefone_alt'],
        'E-mail' => $d['email'], 'Outro contato' => $d['outro_contato'],
    ],
    '3. Endereço' => [
        'Rua/avenida e nº' => $d['rua'], 'Bairro' => $d['bairro'], 'Cidade/UF' => $d['cidade'], 'CEP' => $d['cep'],
    ],
];

$html = '<div style="font-family:Arial,sans-serif;max-width:640px;color:#172033">'
      . '<div style="background:#0b3d91;color:#fff;padding:18px 22px;border-radius:10px 10px 0 0">'
      . '<div style="font-size:13px;opacity:.85">FUNDAÇÃO EDUCACIONAL DE TANABI</div>'
      . '<div style="font-size:20px;font-weight:bold">Concurso de Bolsas 2027 – Ficha de inscrição</div></div>'
      . '<div style="background:#f4b400;padding:10px 22px;font-weight:bold">Nº da inscrição: ' . $h($numero)
      . ' &nbsp;·&nbsp; Data: ' . $agora->format('d/m/Y H:i') . '</div>'
      . '<div style="border:1px solid #dde3ee;border-top:0;padding:8px 22px 18px;border-radius:0 0 10px 10px">';
$texto = "CONCURSO DE BOLSAS 2027 - FICHA DE INSCRIÇÃO\nNº da inscrição: $numero\nData: " . $agora->format('d/m/Y H:i') . "\n";
foreach ($secoes as $titulo => $itens) {
    $html .= '<h3 style="color:#0b3d91;margin:18px 0 8px;font-size:15px">' . $h($titulo) . '</h3>'
           . '<table style="width:100%;border-collapse:collapse;font-size:14px">';
    $texto .= "\n$titulo\n";
    foreach ($itens as $rot => $val) {
        $html .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #eef1f6;color:#4a5468;width:42%">' . $h($rot)
               . '</td><td style="padding:6px 8px;border-bottom:1px solid #eef1f6;font-weight:bold">' . $h($val) . '</td></tr>';
        $texto .= "  $rot: " . ($val === '' ? '—' : $val) . "\n";
    }
    $html .= '</table>';
}
$wa = soDigitos($d['whatsapp']);
$html .= '<p style="margin-top:18px;font-size:14px">✔ Declaração e ciência aceitas pelo responsável legal.</p>'
       . '<p><a href="https://wa.me/55' . $wa . '" style="background:#1fa855;color:#fff;padding:10px 16px;border-radius:20px;text-decoration:none;font-weight:bold">Chamar responsável no WhatsApp</a></p>'
       . '</div></div>';
$texto .= "\nDeclaração e ciência aceitas pelo responsável legal.\n";

function enviarEmail(string $para, string $assunto, string $html, string $texto, string $replyTo = ''): bool
{
    $limite = 'b' . bin2hex(random_bytes(12));
    $cab  = 'From: =?UTF-8?B?' . base64_encode(NOME_REMETENTE) . '?= <' . EMAIL_REMETENTE . ">\r\n";
    if ($replyTo !== '') $cab .= "Reply-To: $replyTo\r\n";
    $cab .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$limite\"\r\n";
    $corpo = "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
           . chunk_split(base64_encode($texto))
           . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
           . chunk_split(base64_encode($html))
           . "--$limite--";
    $assuntoCod = '=?UTF-8?B?' . base64_encode($assunto) . '?=';
    return @mail($para, $assuntoCod, $corpo, $cab, '-f' . EMAIL_REMETENTE);
}

$enviado = enviarEmail(
    EMAIL_DESTINO,
    "[Concurso de Bolsas 2027] Inscrição $numero – {$d['aluno_nome']}",
    $html, $texto,
    $d['email'] !== '' ? $d['email'] : ''
);

if (!$enviado) {
    @file_put_contents($pasta . '/falhas-email.log', $agora->format('c') . " falha ao enviar $numero\n", FILE_APPEND);
}

/* ---------- Confirmação para o responsável (se informou e-mail) ---------- */
if ($d['email'] !== '') {
    $conf = '<div style="font-family:Arial,sans-serif;max-width:560px;color:#172033">'
          . '<h2 style="color:#0b3d91">Inscrição recebida!</h2>'
          . '<p>Olá, ' . $h($d['resp_nome']) . '. Recebemos a inscrição de <strong>' . $h($d['aluno_nome'])
          . '</strong> no <strong>Concurso de Bolsas 2027</strong> da Fundação Educacional de Tanabi.</p>'
          . '<p style="font-size:18px">Nº da inscrição: <strong>' . $h($numero) . '</strong></p>'
          . '<p>Informações sobre data, horário, local da prova e critérios de seleção serão divulgadas pela Fundação.</p>'
          . '<p>Dúvidas: WhatsApp ' . WHATSAPP_SECRETARIA . ' · ' . EMAIL_DESTINO . '</p></div>';
    enviarEmail($d['email'], "Inscrição $numero – Concurso de Bolsas 2027 – FET", $conf,
        "Inscrição recebida! Nº $numero - Concurso de Bolsas 2027 - Fundação Educacional de Tanabi. Dúvidas: WhatsApp " . WHATSAPP_SECRETARIA,
        EMAIL_DESTINO);
}

// A inscrição já está gravada no servidor; mesmo se o e-mail falhar, o número é válido.
responder(true, ['numero' => $numero, 'email' => $enviado]);
