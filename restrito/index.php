<?php
/**
 * Área restrita — consulta das inscrições do Concurso de Bolsas 2027.
 * Login: secrets USR_SITE / PSW_SITE (gerados no deploy em api/config.php, senha guardada como hash).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/api/lib/comum.php';
require_once dirname(__DIR__) . '/api/lib/ficha.php';
require_once dirname(__DIR__) . '/api/lib/xlsx.php';

date_default_timezone_set('America/Sao_Paulo');
$pasta = pastaDados();

header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('FETADM');
session_set_cookie_params(['lifetime' => 0, 'path' => '/restrito/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

const SESSAO_MAX_OCIOSA = 7200; // 2 h
const LOGIN_MAX_FALHAS = 5;
const LOGIN_JANELA = 900;       // 15 min

$configurado = $CFG['admin_user'] !== '' && $CFG['admin_hash'] !== '';
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$acao = (string) ($_GET['acao'] ?? $_POST['acao'] ?? '');
$e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

function csrfOk(): bool
{
    $t = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function json(array $d, int $s = 200): never
{
    http_response_code($s);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function ipCliente(): string { return (string) ($_SERVER['REMOTE_ADDR'] ?? '-'); }

/* ---------- Limite de tentativas de login ---------- */
function falhasArquivo(): string { global $pasta; return $pasta . '/login-falhas.json'; }
function falhasRecentes(): int
{
    $f = json_decode((string) @file_get_contents(falhasArquivo()), true) ?: [];
    $ip = ipCliente();
    return count(array_filter($f[$ip] ?? [], fn($t) => $t > time() - LOGIN_JANELA));
}
function registrarFalha(): void
{
    $f = json_decode((string) @file_get_contents(falhasArquivo()), true) ?: [];
    $ip = ipCliente();
    $f[$ip] = array_values(array_filter($f[$ip] ?? [], fn($t) => $t > time() - LOGIN_JANELA));
    $f[$ip][] = time();
    @file_put_contents(falhasArquivo(), json_encode($f), LOCK_EX);
}
function limparFalhas(): void
{
    $f = json_decode((string) @file_get_contents(falhasArquivo()), true) ?: [];
    unset($f[ipCliente()]);
    @file_put_contents(falhasArquivo(), json_encode($f), LOCK_EX);
}

/* ---------- Login / logout ---------- */
$erroLogin = '';
if ($acao === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$configurado) {
        $erroLogin = 'Acesso ainda não configurado.';
    } elseif (!csrfOk()) {
        $erroLogin = 'Sessão expirada. Tente novamente.';
    } elseif (falhasRecentes() >= LOGIN_MAX_FALHAS) {
        $erroLogin = 'Muitas tentativas. Aguarde 15 minutos e tente novamente.';
    } else {
        $u = trim((string) ($_POST['usuario'] ?? ''));
        $p = (string) ($_POST['senha'] ?? '');
        if (hash_equals(strtolower($CFG['admin_user']), strtolower($u)) && password_verify($p, $CFG['admin_hash'])) {
            limparFalhas();
            session_regenerate_id(true);
            $_SESSION['adm'] = $CFG['admin_user'];
            $_SESSION['visto'] = time();
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            header('Location: ./');
            exit;
        }
        registrarFalha();
        usleep(600000);
        $erroLogin = 'Usuário ou senha incorretos.';
    }
}
if ($acao === 'sair') {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

$logado = isset($_SESSION['adm']) && (time() - (int) ($_SESSION['visto'] ?? 0)) < SESSAO_MAX_OCIOSA;
if (isset($_SESSION['adm']) && !$logado) { $_SESSION = []; session_destroy(); session_start(); $csrf = $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
if ($logado) $_SESSION['visto'] = time();

/* ---------- Ações (somente logado) ---------- */
if ($logado && $acao !== '') {
    $todas = lerInscricoes($pasta);
    $porNumero = array_column($todas, null, 'numero');
    $filtrar = function (string $ids) use ($todas, $porNumero): array {
        if (trim($ids) === '') return $todas;
        $sel = [];
        foreach (explode(',', $ids) as $n) if (isset($porNumero[trim($n)])) $sel[] = $porNumero[trim($n)];
        return $sel;
    };
    $aceiteDe = fn(array $r): string => 'Declaração e ciência aceitas eletronicamente pelo(a) responsável legal ' . $r['resp_nome']
        . ' em ' . str_replace(' ', ' às ', substr($r['data'], 0, 16)) . ($r['ip'] !== '' ? ' (IP ' . $r['ip'] . ')' : '') . '.';
    $pdfDe = function (array $r) use ($pasta, $aceiteDe): array {
        $existentes = glob($pasta . '/Ficha-Inscricao-' . $r['numero'] . '-*.pdf') ?: [];
        if ($existentes) return [basename($existentes[0]), (string) file_get_contents($existentes[0])];
        $quando = DateTime::createFromFormat('d/m/Y H:i:s', $r['data']) ?: new DateTime();
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $r['aluno_nome']))), '-');
        return ["Ficha-Inscricao-{$r['numero']}-$slug.pdf", fichaPdf($r['numero'], $quando, fichaSecoes($r), $aceiteDe($r))];
    };
    $nomeArquivo = 'Inscricoes-Concurso-Bolsas-2027-' . date('Y-m-d-His') . '.xlsx';

    if ($acao === 'pdf') {
        $n = (string) ($_GET['n'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}$/', $n) || !isset($porNumero[$n])) { http_response_code(404); exit('Inscrição não encontrada.'); }
        [$nome, $dados] = $pdfDe($porNumero[$n]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . $nome . '"');
        echo $dados;
        exit;
    }

    if ($acao === 'xlsx' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfOk()) { http_response_code(403); exit('Sessão expirada. Recarregue a página.'); }
        [$cab, $linhas, $larg] = planilhaInscricoes($filtrar((string) ($_POST['ids'] ?? '')));
        $x = gerarXlsx('Inscrições', $cab, $linhas, $larg);
        if ($x === null) { // sem ZipArchive: CSV compatível com Excel
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . str_replace('.xlsx', '.csv', $nomeArquivo) . '"');
            $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, $cab, ';');
            foreach ($linhas as $l) fputcsv($o, $l, ';');
            exit;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        echo $x;
        exit;
    }

    if ($acao === 'enviar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfOk()) json(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], 403);
        $para = listaEmails((string) ($_POST['para'] ?? ''));
        if (!$para) json(['ok' => false, 'erro' => 'Informe ao menos um e-mail válido.'], 422);
        if (count($para) > 10) json(['ok' => false, 'erro' => 'Máximo de 10 destinatários.'], 422);
        $msgExtra = trim(mb_substr((string) ($_POST['mensagem'] ?? ''), 0, 1000));
        $h = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $contato = 'WhatsApp ' . WHATSAPP_SECRETARIA . ' · ' . $h(EMAIL_SECRETARIA);

        if (($_POST['tipo'] ?? '') === 'ficha') {
            $n = (string) ($_POST['n'] ?? '');
            if (!isset($porNumero[$n])) json(['ok' => false, 'erro' => 'Inscrição não encontrada.'], 404);
            $r = $porNumero[$n];
            [$nome, $dados] = $pdfDe($r);
            $quando = DateTime::createFromFormat('d/m/Y H:i:s', $r['data']) ?: new DateTime();
            $intro = 'Segue a ficha de inscrição <strong>' . $h($n) . '</strong>, enviada pela área restrita do site. A ficha também está em <strong>PDF anexo</strong>.'
                   . ($msgExtra !== '' ? '<br><br>' . nl2br($h($msgExtra)) : '');
            $ok = enviarEmail($para, [], "Concurso de Bolsas 2027 – Inscrição $n – {$r['aluno_nome']}",
                fichaEmailHtml('Ficha de inscrição', $intro, $n, $quando, fichaSecoes($r), $aceiteDe($r),
                    'Chamar responsável no WhatsApp', 'https://wa.me/55' . soDigitos($r['whatsapp']), $contato),
                fichaEmailTexto("Ficha de inscrição $n." . ($msgExtra !== '' ? "\n\n$msgExtra" : ''), $n, $quando, fichaSecoes($r), $aceiteDe($r)),
                $r['email'] !== '' ? $r['email'] : '',
                [['nome' => $nome, 'tipo' => 'application/pdf', 'dados' => $dados]]);
            json($ok ? ['ok' => true, 'msg' => 'Ficha ' . $n . ' enviada para ' . implode(', ', $para) . '.'] : ['ok' => false, 'erro' => 'Falha ao enviar o e-mail.'], $ok ? 200 : 500);
        }

        $sel = $filtrar((string) ($_POST['ids'] ?? ''));
        if (!$sel) json(['ok' => false, 'erro' => 'Nenhuma inscrição para enviar.'], 422);
        [$cab, $linhas, $larg] = planilhaInscricoes($sel);
        $x = gerarXlsx('Inscrições', $cab, $linhas, $larg);
        $anexo = $x !== null
            ? ['nome' => $nomeArquivo, 'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'dados' => $x]
            : ['nome' => str_replace('.xlsx', '.csv', $nomeArquivo), 'tipo' => 'text/csv; charset=utf-8',
               'dados' => "\xEF\xBB\xBF" . implode("\n", array_map(fn($l) => implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $l)), array_merge([$cab], $linhas)))];
        $tab = '';
        foreach ($sel as $i => $r) {
            $bg = $i % 2 ? '#f7f9fd' : '#ffffff';
            $tab .= '<tr style="background:' . $bg . '">'
                  . '<td style="padding:8px 10px;border-bottom:1px solid #e3e8f2;font:bold 13px Arial,sans-serif;color:#0b3d91;white-space:nowrap">' . $h($r['numero']) . '</td>'
                  . '<td style="padding:8px 10px;border-bottom:1px solid #e3e8f2;font:13px Arial,sans-serif">' . $h($r['aluno_nome']) . '</td>'
                  . '<td style="padding:8px 10px;border-bottom:1px solid #e3e8f2;font:13px Arial,sans-serif">' . $h($r['serie_2027']) . '</td>'
                  . '<td style="padding:8px 10px;border-bottom:1px solid #e3e8f2;font:13px Arial,sans-serif">' . $h($r['resp_nome']) . '<br><span style="color:#4a5468">' . $h($r['whatsapp']) . '</span></td></tr>';
        }
        $qtd = count($sel);
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#eef2f8">'
              . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#eef2f8"><tr><td align="center" style="padding:24px 10px">'
              . '<table role="presentation" width="680" cellpadding="0" cellspacing="0" style="width:100%;max-width:680px;background:#fff;border-radius:14px;overflow:hidden">'
              . '<tr><td bgcolor="#0b3d91" style="padding:22px 28px"><table role="presentation" width="100%"><tr><td width="76"><img src="' . FICHA_LOGO_URL . '" width="64" height="64" alt="FET" style="display:block;border-radius:50%;background:#fff"></td>'
              . '<td style="font:bold 18px Arial,sans-serif;color:#fff">Fundação Educacional de Tanabi<div style="font:13px Arial,sans-serif;color:#dbe6ff;padding-top:4px">Área restrita · Concurso de Bolsas 2027</div></td></tr></table></td></tr>'
              . '<tr><td bgcolor="#f4b400" style="padding:12px 28px;font:bold 15px Arial,sans-serif;color:#1f1600">' . $qtd . ' inscriç' . ($qtd === 1 ? 'ão' : 'ões') . ' · planilha Excel em anexo</td></tr>'
              . '<tr><td style="padding:22px 28px 6px;font:15px/1.55 Arial,sans-serif;color:#172033">Segue a relação de inscrições do Concurso de Bolsas 2027, gerada em ' . date('d/m/Y \à\s H:i') . '. Todos os dados estão na <strong>planilha anexa</strong>.'
              . ($msgExtra !== '' ? '<br><br>' . nl2br($h($msgExtra)) : '') . '</td></tr>'
              . '<tr><td style="padding:12px 28px 24px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">'
              . '<tr style="background:#0b3d91"><td style="padding:9px 10px;font:bold 12px Arial,sans-serif;color:#fff">Nº</td><td style="padding:9px 10px;font:bold 12px Arial,sans-serif;color:#fff">Aluno(a)</td><td style="padding:9px 10px;font:bold 12px Arial,sans-serif;color:#fff">Série 2027</td><td style="padding:9px 10px;font:bold 12px Arial,sans-serif;color:#fff">Responsável</td></tr>'
              . $tab . '</table></td></tr>'
              . '<tr><td bgcolor="#082d6b" style="padding:18px 28px;font:12px/1.6 Arial,sans-serif;color:#c9d6f2"><strong style="color:#fff">Fundação Educacional de Tanabi</strong><br>' . $h(FICHA_ENDERECO) . '<br>' . $h(FICHA_CNPJ)
              . '<div style="padding-top:8px;font-size:11px;color:#9fb2d9">Dados pessoais protegidos pela LGPD. Não encaminhe a terceiros.</div></td></tr>'
              . '</table></td></tr></table></body></html>';
        $texto = "Inscrições do Concurso de Bolsas 2027 ($qtd). Planilha em anexo.\n\n" . ($msgExtra !== '' ? "$msgExtra\n\n" : '')
               . implode("\n", array_map(fn($r) => "{$r['numero']} - {$r['aluno_nome']} - {$r['serie_2027']} - {$r['resp_nome']} {$r['whatsapp']}", $sel));
        $ok = enviarEmail($para, [], "Concurso de Bolsas 2027 – $qtd inscriç" . ($qtd === 1 ? 'ão' : 'ões') . ' – ' . date('d/m/Y'), $html, $texto, '', [$anexo]);
        json($ok ? ['ok' => true, 'msg' => "$qtd inscriç" . ($qtd === 1 ? 'ão enviada' : 'ões enviadas') . ' para ' . implode(', ', $para) . '.'] : ['ok' => false, 'erro' => 'Falha ao enviar o e-mail.'], $ok ? 200 : 500);
    }

    http_response_code(400);
    exit('Ação inválida.');
}
if (!$logado && $acao !== '' && $acao !== 'login') { header('Location: ./'); exit; }

$inscricoes = $logado ? array_reverse(lerInscricoes($pasta)) : [];
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $logado ? 'Inscrições' : 'Entrar' ?> | Área restrita · FET</title>
  <meta name="theme-color" content="#0b3d91">
  <link rel="icon" type="image/png" href="../assets/img/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700;800&family=Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,500,1,0&display=block" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css?v=dev">
  <link rel="stylesheet" href="restrito.css?v=dev">
</head>
<?php if (!$logado): ?>
<body class="adm adm--login">
  <main class="login">
    <div class="login__card">
      <img class="login__logo" src="../assets/img/logo.png" alt="Fundação Educacional de Tanabi – Objetivo" width="120" height="120">
      <span class="pill-adm"><span class="ms" aria-hidden="true">lock</span> Área restrita</span>
      <h1>Bem-vindo(a)</h1>
      <p class="login__sub">Acesse para consultar as inscrições do Concurso de Bolsas 2027.</p>
      <?php if (!$configurado): ?>
        <p class="alerta"><span class="ms" aria-hidden="true">info</span> O acesso ainda não foi configurado no servidor (secrets USR_SITE e PSW_SITE).</p>
      <?php endif; ?>
      <?php if ($erroLogin !== ''): ?>
        <p class="alerta alerta--erro" role="alert"><span class="ms" aria-hidden="true">error</span> <?= $e($erroLogin) ?></p>
      <?php endif; ?>
      <form method="post" action="./?acao=login" class="login__form" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <div class="field">
          <input id="usuario" name="usuario" type="text" placeholder=" " autocomplete="username" required autofocus value="<?= $e((string) ($_POST['usuario'] ?? '')) ?>">
          <label for="usuario">Usuário</label>
        </div>
        <div class="field field--senha">
          <input id="senha" name="senha" type="password" placeholder=" " autocomplete="current-password" required>
          <label for="senha">Senha</label>
          <button type="button" class="icon-btn olho" aria-label="Mostrar senha" onclick="const s=document.getElementById('senha');const v=s.type==='password';s.type=v?'text':'password';this.firstElementChild.textContent=v?'visibility_off':'visibility';this.setAttribute('aria-label',v?'Ocultar senha':'Mostrar senha')"><span class="ms" aria-hidden="true">visibility</span></button>
        </div>
        <button class="btn btn--filled btn--lg btn--block" type="submit"><span class="ms" aria-hidden="true">login</span> Entrar</button>
      </form>
      <a class="login__voltar" href="../"><span class="ms" aria-hidden="true">arrow_back</span> Voltar ao site</a>
    </div>
  </main>
</body>
<?php else: ?>
<body class="adm">
  <header class="topbar adm-bar is-scrolled">
    <div class="container topbar__inner">
      <a class="brand" href="./">
        <img src="../assets/img/emblema.png" alt="" width="44" height="45">
        <span class="brand__text"><strong>Área restrita</strong><span>Fundação Educacional de Tanabi</span></span>
      </a>
      <div class="topbar__actions">
        <span class="adm-user"><span class="ms" aria-hidden="true">account_circle</span> <?= $e((string) $_SESSION['adm']) ?></span>
        <a class="btn btn--outlined btn--sm" href="./?acao=sair"><span class="ms" aria-hidden="true">logout</span> Sair</a>
      </div>
    </div>
  </header>

  <main class="container adm-main">
    <div class="adm-head">
      <div>
        <span class="eyebrow">Concurso de Bolsas 2027</span>
        <h1>Inscrições</h1>
      </div>
      <a class="btn btn--text" href="../concurso-de-bolsas.html" target="_blank" rel="noopener"><span class="ms" aria-hidden="true">open_in_new</span> Ver ficha pública</a>
    </div>

    <section class="kpis" id="kpis" aria-label="Resumo"></section>

    <section class="painel">
      <div class="toolbar">
        <div class="busca">
          <span class="ms" aria-hidden="true">search</span>
          <input id="busca" type="search" placeholder="Buscar por nome, CPF, nº, responsável, escola…" aria-label="Buscar inscrições">
        </div>
        <select id="fSerie" aria-label="Filtrar por série 2027"><option value="">Todas as séries 2027</option></select>
        <div class="toolbar__acoes">
          <button class="btn btn--tonal" id="btnExcel" type="button"><span class="ms" aria-hidden="true">table_view</span> <span class="so-desk">Exportar</span>Excel</button>
          <button class="btn btn--filled" id="btnEmail" type="button"><span class="ms" aria-hidden="true">forward_to_inbox</span> <span class="so-desk">Enviar por</span><span class="so-mobile">Enviar</span>e-mail</button>
        </div>
      </div>
      <div class="selecao" id="selecao" hidden>
        <span id="selTxt"></span>
        <button class="btn btn--text btn--sm" id="selLimpar" type="button">Limpar seleção</button>
      </div>

      <div class="grade-wrap">
        <table class="grade" id="grade">
          <thead>
            <tr>
              <th class="c-check"><label class="cb"><input type="checkbox" id="selTodos" aria-label="Selecionar todas as linhas exibidas"><span></span></label></th>
              <th data-ord="numero" aria-sort="descending">Nº</th>
              <th data-ord="data_iso">Data</th>
              <th data-ord="aluno_nome">Aluno(a)</th>
              <th data-ord="serie_2027">Série 2027</th>
              <th data-ord="resp_nome">Responsável</th>
              <th>Contato</th>
              <th class="c-acoes"><span class="sr">Ações</span></th>
            </tr>
          </thead>
          <tbody id="linhas"></tbody>
        </table>
        <div class="vazio" id="vazio" hidden>
          <span class="ms" aria-hidden="true">inbox</span>
          <p id="vazioTxt">Nenhuma inscrição ainda.</p>
        </div>
      </div>

      <div class="paginacao" id="paginacao"></div>
    </section>
  </main>

  <!-- Detalhes -->
  <dialog class="dlg dlg--lado" id="dlgFicha" aria-labelledby="fichaTitulo">
    <div class="dlg__head">
      <div><span class="eyebrow" id="fichaNum"></span><h2 id="fichaTitulo"></h2></div>
      <button class="icon-btn" data-fechar aria-label="Fechar"><span class="ms" aria-hidden="true">close</span></button>
    </div>
    <div class="dlg__body" id="fichaCorpo"></div>
    <div class="dlg__foot">
      <a class="btn btn--tonal" id="fichaPdf" target="_blank" rel="noopener"><span class="ms" aria-hidden="true">picture_as_pdf</span> Ver PDF</a>
      <a class="btn btn--whats" id="fichaWa" target="_blank" rel="noopener"><span class="ms" aria-hidden="true">chat</span> WhatsApp</a>
      <button class="btn btn--filled" id="fichaEmail" type="button"><span class="ms" aria-hidden="true">forward_to_inbox</span> Enviar ficha</button>
    </div>
  </dialog>

  <!-- Enviar por e-mail -->
  <dialog class="dlg" id="dlgEmail" aria-labelledby="emailTitulo">
    <form id="formEmail" method="dialog">
      <div class="dlg__head">
        <div><span class="eyebrow">Enviar por e-mail</span><h2 id="emailTitulo">Enviar inscrições</h2></div>
        <button class="icon-btn" type="button" data-fechar aria-label="Fechar"><span class="ms" aria-hidden="true">close</span></button>
      </div>
      <div class="dlg__body">
        <fieldset class="escopo" id="escopo">
          <legend>O que enviar</legend>
        </fieldset>
        <div class="field">
          <input id="emailPara" name="para" type="text" placeholder=" " required value="<?= $e(EMAIL_DESTINO) ?>">
          <label for="emailPara">Para (separe vários e-mails por vírgula)</label>
        </div>
        <div class="field">
          <textarea id="emailMsg" name="mensagem" rows="3" placeholder=" " maxlength="1000"></textarea>
          <label for="emailMsg">Mensagem (opcional)</label>
        </div>
        <p class="dica" id="emailDica"><span class="ms" aria-hidden="true">attach_file</span> Vai com a planilha Excel em anexo.</p>
        <p class="form__error" id="emailErro" role="alert" hidden></p>
      </div>
      <div class="dlg__foot">
        <button class="btn btn--outlined" type="button" data-fechar>Cancelar</button>
        <button class="btn btn--filled" type="submit" id="emailEnviar"><span class="ms" aria-hidden="true">send</span> <span class="lbl">Enviar</span></button>
      </div>
    </form>
  </dialog>

  <form id="formXlsx" method="post" action="./?acao=xlsx" hidden>
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="ids" id="xlsxIds">
  </form>

  <div class="snack" id="snack" role="status" aria-live="polite"></div>

  <script type="application/json" id="dados"><?= json_encode($inscricoes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script>window.CSRF = <?= json_encode($csrf) ?>;</script>
  <script src="restrito.js?v=dev" defer></script>
</body>
<?php endif; ?>
</html>
