<?php
/** Configuração e funções compartilhadas (inscrição e área restrita). */
declare(strict_types=1);

/* ================== CONFIGURAÇÃO ================== */
const EMAIL_DESTINO   = 'pcorreajr@primeerp.com.br';                // quem recebe as inscrições (pode separar vários por vírgula)
const EMAIL_COPIA     = 'secretariafet@tanabinet.com.br';           // recebe cópia (Cc) de cada inscrição
const EMAIL_SECRETARIA = 'secretariafet@tanabinet.com.br';          // e-mail mostrado às famílias na confirmação
const EMAIL_REMETENTE_PADRAO = 'nao-responda@objetivotanabi.com.br'; // usado quando não há SMTP configurado
const NOME_REMETENTE  = 'Site FET – Concurso de Bolsas';
const PREFIXO_NUMERO  = '2027-';
const INSCRICOES_ABERTAS = false;  // true reabre a ficha pública (a área restrita sempre pode incluir)
const ZERAR_NUMERACAO = 'oficial-2026-11-06-v2';  // trocar este texto zera a numeração uma vez (testes vão para a pasta arquivo-*)
const WHATSAPP_SECRETARIA = '(17) 98847-0429';
/* ================================================== */

/* SMTP: api/config.php é gerado no deploy a partir dos secrets SMTP_USER / SMTP_SENHA do GitHub. */
$CFG = is_file(dirname(__DIR__) . '/config.php') ? (array) (include dirname(__DIR__) . '/config.php') : [];
$CFG += ['smtp_host' => 'ssl://smtp.hostinger.com', 'smtp_port' => 465, 'smtp_user' => '', 'smtp_pass' => ''];
define('EMAIL_REMETENTE', $CFG['smtp_user'] !== '' ? $CFG['smtp_user'] : EMAIL_REMETENTE_PADRAO);

$CFG += ['admin_user' => '', 'admin_hash' => ''];

function soDigitos(string $v): string { return preg_replace('/\D/', '', $v) ?? ''; }

/* ---------- Pasta de dados (fora do public_html quando possível) ---------- */
function pastaDados(): string
{
    $fora = dirname(__DIR__, 3) . '/inscricoes-bolsas-2027';
    if (is_dir($fora) || @mkdir($fora, 0700, true)) {
        if (is_writable($fora)) return $fora;
    }
    $dentro = dirname(__DIR__) . '/_dados';
    if (!is_dir($dentro)) @mkdir($dentro, 0700, true);
    if (!is_file($dentro . '/.htaccess')) @file_put_contents($dentro . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_file($dentro . '/index.html')) @file_put_contents($dentro . '/index.html', '');
    return $dentro;
}

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


/** Seções da ficha a partir dos dados (chaves do formulário). */
function fichaSecoes(array $d): array
{
    $d += ['aluno_email' => ''];
    return [
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
}

/** Colunas da planilha (ordem do CSV) → chaves do formulário. */
const COLUNAS_CSV = [
    'Nº inscrição' => 'numero', 'Data/hora' => 'data', 'Aluno' => 'aluno_nome', 'Nascimento' => 'aluno_nascimento',
    'CPF aluno' => 'aluno_cpf', 'RG' => 'aluno_rg', 'Escola atual' => 'escola_atual', 'Série 2026' => 'serie_2026',
    'Série 2027' => 'serie_2027', 'Responsável' => 'resp_nome', 'Vínculo' => 'resp_vinculo', 'CPF responsável' => 'resp_cpf',
    'WhatsApp' => 'whatsapp', 'Telefone alt.' => 'telefone_alt', 'E-mail responsável' => 'email', 'E-mail' => 'email',
    'Outro contato' => 'outro_contato', 'Rua e nº' => 'rua', 'Bairro' => 'bairro', 'Cidade/UF' => 'cidade', 'CEP' => 'cep',
    'IP' => 'ip', 'E-mail aluno' => 'aluno_email',
];

/** Lê todas as inscrições oficiais (planilha atual). */
function lerInscricoes(string $pasta): array
{
    $csv = $pasta . '/inscricoes.csv';
    if (!is_file($csv) || !($fh = fopen($csv, 'r'))) return [];
    if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);   // pula o BOM do Excel
    $cab = fgetcsv($fh, 0, ';', '"', '');
    if (!$cab) { fclose($fh); return []; }
    $chaves = array_map(fn($c) => COLUNAS_CSV[$c] ?? $c, $cab);
    $out = [];
    while (($l = fgetcsv($fh, 0, ';', '"', '')) !== false) {
        if (count($l) < 3) continue;
        $r = [];
        foreach ($chaves as $i => $k) $r[$k] = ltrim((string) ($l[$i] ?? ''), "'");
        $r += ['aluno_email' => '', 'email' => '', 'ip' => ''];
        $out[] = $r;
    }
    fclose($fh);
    return $out;
}

/**
 * Zera a numeração uma única vez quando ZERAR_NUMERACAO muda: move planilhas e PDFs
 * para uma pasta arquivo-<data> e volta o contador para 0. Usa a trava do contador.
 * @param resource|null $lock trava já aberta (inscrição) ou null (abre a própria)
 */
function aplicarZeramento(string $pasta, $lock = null): void
{
    $marca = $pasta . '/numeracao.txt';
    if (trim((string) @file_get_contents($marca)) === ZERAR_NUMERACAO) return;
    $proprio = $lock === null;
    if ($proprio) {
        $lock = @fopen($pasta . '/contador.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) return;
        if (trim((string) @file_get_contents($marca)) === ZERAR_NUMERACAO) { flock($lock, LOCK_UN); fclose($lock); return; }
    }
    $arquivo = $pasta . '/arquivo-' . date('Y-m-d-His');
    @mkdir($arquivo, 0700, true);
    foreach (array_merge(glob($pasta . '/*.csv') ?: [], glob($pasta . '/*.pdf') ?: [], glob($pasta . '/falhas-email.log') ?: []) as $f) {
        @rename($f, $arquivo . '/' . basename($f));
    }
    ftruncate($lock, 0); rewind($lock); fwrite($lock, '0'); fflush($lock);
    @file_put_contents($marca, ZERAR_NUMERACAO);
    if ($proprio) { flock($lock, LOCK_UN); fclose($lock); }
}

/** Ordem oficial das colunas da planilha (rótulo => chave). */
const CSV_CAMPOS = [
    'Nº inscrição' => 'numero', 'Data/hora' => 'data', 'Aluno' => 'aluno_nome', 'Nascimento' => 'aluno_nascimento',
    'CPF aluno' => 'aluno_cpf', 'RG' => 'aluno_rg', 'Escola atual' => 'escola_atual', 'Série 2026' => 'serie_2026',
    'Série 2027' => 'serie_2027', 'Responsável' => 'resp_nome', 'Vínculo' => 'resp_vinculo', 'CPF responsável' => 'resp_cpf',
    'WhatsApp' => 'whatsapp', 'Telefone alt.' => 'telefone_alt', 'E-mail responsável' => 'email', 'Outro contato' => 'outro_contato',
    'Rua e nº' => 'rua', 'Bairro' => 'bairro', 'Cidade/UF' => 'cidade', 'CEP' => 'cep', 'IP' => 'ip', 'E-mail aluno' => 'aluno_email',
];

function linhaCsv(array $r): array
{
    $l = [];
    foreach (CSV_CAMPOS as $k) {
        $v = (string) ($r[$k] ?? '');
        $l[] = preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;   // evita fórmulas no Excel
    }
    return $l;
}

/** Regrava a planilha inteira (arquivo temporário + rename). Chamar com a trava do contador. */
function gravarInscricoes(string $pasta, array $linhas): bool
{
    $tmp = $pasta . '/inscricoes.tmp';
    if (!($fh = fopen($tmp, 'w'))) return false;
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, array_keys(CSV_CAMPOS), ';');
    foreach ($linhas as $r) fputcsv($fh, linhaCsv($r), ';');
    fclose($fh);
    return rename($tmp, $pasta . '/inscricoes.csv');
}

/** Executa $fn com a trava exclusiva do contador; $fn recebe o handle da trava. */
function comTrava(string $pasta, callable $fn)
{
    $lock = fopen($pasta . '/contador.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) return null;
    try { return $fn($lock); } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Próximo número da inscrição (usar dentro de comTrava). */
function proximoNumero($lock): string
{
    rewind($lock);
    $seq = (int) trim((string) stream_get_contents($lock)) + 1;
    ftruncate($lock, 0); rewind($lock); fwrite($lock, (string) $seq); fflush($lock);
    return PREFIXO_NUMERO . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

/** Remove PDFs guardados de uma inscrição (são gerados de novo a partir dos dados). */
function apagarPdfs(string $pasta, string $numero, string $destino = ''): void
{
    foreach (glob($pasta . '/Ficha-Inscricao-' . $numero . '-*.pdf') ?: [] as $f) {
        if ($destino !== '') { @mkdir($destino, 0700, true); @rename($f, $destino . '/' . basename($f)); }
        else @unlink($f);
    }
}

/** Nome normalizado para comparação (sem acentos, minúsculo, espaços simples). */
function chaveNome(string $nome): string
{
    $n = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($nome)));
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $n));
}

/**
 * Procura inscrição já existente do mesmo aluno: mesmo CPF do aluno, ou mesmo nome + data de nascimento.
 * @return array{registro: array, motivo: string}|null
 */
function buscarDuplicada(array $lista, array $d, string $ignorarNumero = ''): ?array
{
    $cpf = soDigitos((string) ($d['aluno_cpf'] ?? ''));
    $nome = chaveNome((string) ($d['aluno_nome'] ?? ''));
    $nasc = trim((string) ($d['aluno_nascimento'] ?? ''));
    foreach ($lista as $r) {
        if ($ignorarNumero !== '' && ($r['numero'] ?? '') === $ignorarNumero) continue;
        if (strlen($cpf) === 11 && soDigitos((string) ($r['aluno_cpf'] ?? '')) === $cpf) return ['registro' => $r, 'motivo' => 'cpf'];
        if ($nome !== '' && $nasc !== '' && chaveNome((string) ($r['aluno_nome'] ?? '')) === $nome && trim((string) ($r['aluno_nascimento'] ?? '')) === $nasc) {
            return ['registro' => $r, 'motivo' => 'nome'];
        }
    }
    return null;
}
