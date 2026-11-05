# Fundação Educacional de Tanabi

Site institucional da FET (Objetivo Tanabi): HTML, CSS e JS estáticos, sem build.

- `index.html`: página única
- `assets/css/style.css`: estilos (inspirados no Material Design 3, mobile-first)
- `assets/js/main.js`: menu, acessibilidade, mapa e formulário (WhatsApp/e-mail). O número do WhatsApp fica na constante `WHATSAPP` no topo do arquivo.

## Deploy

Todo push na branch `main` publica o site via FTP (`.github/workflows/deploy.yml`) na raiz da conta FTP (`/public_html`).
Secrets usados: `IP_FTP`, `USER_FTP`, `SENHA_FTP`. Também dá para rodar manualmente na aba **Actions → Deploy via FTP → Run workflow**.
