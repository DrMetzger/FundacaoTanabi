# Fundação Educacional de Tanabi

Site institucional da FET (Objetivo Tanabi): HTML, CSS e JS estáticos, sem build.

- `index.html`: página única
- `assets/css/style.css`: estilos (inspirados no Material Design 3, mobile-first)
- `assets/js/main.js`: menu, acessibilidade, mapa e formulário (WhatsApp/e-mail). O número do WhatsApp fica na constante `WHATSAPP` no topo do arquivo.

## Deploy

Todo push na branch `main` publica o site via FTP (`.github/workflows/deploy.yml`) na raiz da conta FTP (`/public_html`).
Secrets usados: `IP_FTP`, `USER_FTP`, `SENHA_FTP`. Também dá para rodar manualmente na aba **Actions → Deploy via FTP → Run workflow**.

## Concurso de Bolsas (e-mail)

**Inscrições encerradas.** A ficha pública está fechada (`INSCRICOES_ABERTAS = false` em `api/lib/comum.php`); a área restrita continua com consulta e edição.

A ficha `concurso-de-bolsas.html` envia para `api/inscricao.php`, que gera o número da inscrição, guarda uma cópia em
`inscricoes-bolsas-2027/inscricoes.csv` (fora do `public_html`) e envia e-mail para pcorreajr@primeerp.com.br (constante EMAIL_DESTINO).

O e-mail sai por SMTP autenticado (`smtp.hostinger.com`, porta 465/SSL) usando os secrets `SMTP_USER` e `SMTP_SENHA`.
No deploy, o workflow gera `api/config.php` com esses valores (arquivo fora do git e bloqueado pelo `.htaccess`).
Sem os secrets, o envio usa o `mail()` do PHP.
