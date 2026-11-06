/* Área restrita — grade de inscrições */
(() => {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const semAcento = (v) => String(v ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const digits = (v) => String(v ?? '').replace(/\D/g, '');

  const dados = JSON.parse($('#dados').textContent || '[]').map((r) => {
    const [d, h] = (r.data || '').split(' ');
    const [dd, mm, aa] = (d || '').split('/');
    return { ...r, data_iso: `${aa}-${mm}-${dd} ${h || ''}`, _busca: semAcento([r.numero, r.aluno_nome, r.aluno_cpf, r.resp_nome, r.resp_cpf, r.escola_atual, r.email, r.aluno_email, r.whatsapp, digits(r.aluno_cpf), digits(r.resp_cpf), digits(r.whatsapp)].join(' ')) };
  });

  const POR_PAG = 25;
  const st = { busca: '', serie: '', ord: 'numero', dir: -1, pag: 1, sel: new Set() };

  /* ---------- Snackbar ---------- */
  let snackT;
  const snack = (msg, erro = false) => {
    const s = $('#snack');
    s.innerHTML = `<span class="ms" aria-hidden="true">${erro ? 'error' : 'check_circle'}</span> ${esc(msg)}`;
    s.classList.toggle('is-erro', erro);
    s.classList.add('is-on');
    clearTimeout(snackT);
    snackT = setTimeout(() => s.classList.remove('is-on'), 4500);
  };

  /* ---------- KPIs ---------- */
  const kpis = () => {
    const hoje = new Date();
    const ymd = (dt) => `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
    const h = ymd(hoje);
    const d7 = new Date(hoje); d7.setDate(d7.getDate() - 6);
    const s7 = ymd(d7);
    const nHoje = dados.filter((r) => r.data_iso.startsWith(h)).length;
    const n7 = dados.filter((r) => r.data_iso.slice(0, 10) >= s7).length;
    const cont = {};
    dados.forEach((r) => { cont[r.serie_2027] = (cont[r.serie_2027] || 0) + 1; });
    const top = Object.entries(cont).sort((a, b) => b[1] - a[1])[0];
    $('#kpis').innerHTML = `
      <div class="kpi"><span class="ms" aria-hidden="true">groups</span><strong>${dados.length}</strong><span>inscrições no total</span></div>
      <div class="kpi"><span class="ms" aria-hidden="true">today</span><strong>${nHoje}</strong><span>hoje</span></div>
      <div class="kpi"><span class="ms" aria-hidden="true">date_range</span><strong>${n7}</strong><span>últimos 7 dias</span></div>
      <div class="kpi kpi--texto"><span class="ms" aria-hidden="true">trending_up</span><strong title="${esc(top ? top[0] : '')}">${top ? esc(top[0]) : '—'}</strong><span>série mais procurada${top ? ` (${top[1]})` : ''}</span></div>`;
  };

  /* ---------- Filtro de série ---------- */
  const series = [...new Set(dados.map((r) => r.serie_2027).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR', { numeric: true }));
  $('#fSerie').insertAdjacentHTML('beforeend', series.map((s) => `<option>${esc(s)}</option>`).join(''));

  /* ---------- Lista filtrada ---------- */
  const filtrados = () => {
    const q = semAcento(st.busca.trim());
    const qd = digits(st.busca);
    const out = dados.filter((r) => (!st.serie || r.serie_2027 === st.serie) && (!q || r._busca.includes(q) || (qd.length >= 3 && r._busca.includes(qd))));
    out.sort((a, b) => String(a[st.ord] ?? '').localeCompare(String(b[st.ord] ?? ''), 'pt-BR', { numeric: true }) * st.dir);
    return out;
  };

  /* ---------- Render ---------- */
  const render = () => {
    const lista = filtrados();
    const total = lista.length;
    const paginas = Math.max(1, Math.ceil(total / POR_PAG));
    st.pag = Math.min(st.pag, paginas);
    const pagina = lista.slice((st.pag - 1) * POR_PAG, st.pag * POR_PAG);

    $('#linhas').innerHTML = pagina.map((r) => `
      <tr data-n="${esc(r.numero)}" class="${st.sel.has(r.numero) ? 'is-sel' : ''}">
        <td class="c-check"><label class="cb" onclick="event.stopPropagation()"><input type="checkbox" data-sel="${esc(r.numero)}" ${st.sel.has(r.numero) ? 'checked' : ''} aria-label="Selecionar inscrição ${esc(r.numero)}"><span></span></label></td>
        <td class="t-num"><span class="num">${esc(r.numero)}</span></td>
        <td class="t-data">${esc((r.data || '').slice(0, 10))}<span class="sub">${esc((r.data || '').slice(11, 16))}</span></td>
        <td class="t-aluno">${esc(r.aluno_nome)}<span class="sub">${esc(r.escola_atual)}</span></td>
        <td class="t-serie"><span class="tag">${esc(r.serie_2027)}</span></td>
        <td class="t-resp">${esc(r.resp_nome)} <span class="sub">${esc(r.resp_vinculo)}</span></td>
        <td class="t-contato contato">
          <a class="wa" href="https://wa.me/55${digits(r.whatsapp)}" target="_blank" rel="noopener" onclick="event.stopPropagation()"><span class="ms" aria-hidden="true">chat</span>${esc(r.whatsapp)}</a>
          ${r.email ? `<span class="sub">${esc(r.email)}</span>` : ''}
        </td>
        <td class="c-acoes"><div class="acoes">
          <a class="icon-btn" href="./?acao=pdf&n=${encodeURIComponent(r.numero)}" target="_blank" rel="noopener" title="Ver PDF" aria-label="Ver PDF da inscrição ${esc(r.numero)}" onclick="event.stopPropagation()"><span class="ms" aria-hidden="true">picture_as_pdf</span></a>
          <button class="icon-btn" type="button" data-ver="${esc(r.numero)}" title="Ver ficha" aria-label="Ver ficha ${esc(r.numero)}"><span class="ms" aria-hidden="true">visibility</span></button>
        </div></td>
      </tr>`).join('');

    const vazio = $('#vazio');
    vazio.hidden = total > 0;
    $('#vazioTxt').textContent = dados.length ? 'Nenhuma inscrição encontrada com esse filtro.' : 'Nenhuma inscrição ainda. Elas aparecem aqui assim que forem enviadas pelo site.';

    const ini = total ? (st.pag - 1) * POR_PAG + 1 : 0;
    $('#paginacao').innerHTML = `
      <span>${ini}–${Math.min(st.pag * POR_PAG, total)} de ${total} inscriç${total === 1 ? 'ão' : 'ões'}${total !== dados.length ? ` (filtradas de ${dados.length})` : ''}</span>
      <span class="pg">
        <button class="icon-btn" data-pag="-1" ${st.pag <= 1 ? 'disabled' : ''} aria-label="Página anterior"><span class="ms" aria-hidden="true">chevron_left</span></button>
        <button class="icon-btn" data-pag="1" ${st.pag >= paginas ? 'disabled' : ''} aria-label="Próxima página"><span class="ms" aria-hidden="true">chevron_right</span></button>
      </span>`;

    $$('#grade th[data-ord]').forEach((th) => th.setAttribute('aria-sort', th.dataset.ord === st.ord ? (st.dir === 1 ? 'ascending' : 'descending') : 'none'));

    const visiveis = pagina.map((r) => r.numero);
    const marcados = visiveis.filter((n) => st.sel.has(n)).length;
    const todos = $('#selTodos');
    todos.checked = visiveis.length > 0 && marcados === visiveis.length;
    todos.indeterminate = marcados > 0 && marcados < visiveis.length;

    $('#selecao').hidden = st.sel.size === 0;
    $('#selTxt').textContent = `${st.sel.size} inscriç${st.sel.size === 1 ? 'ão selecionada' : 'ões selecionadas'}`;
  };

  /* ---------- Eventos da grade ---------- */
  let tBusca;
  $('#busca').addEventListener('input', (e) => { clearTimeout(tBusca); tBusca = setTimeout(() => { st.busca = e.target.value; st.pag = 1; render(); }, 150); });
  $('#fSerie').addEventListener('change', (e) => { st.serie = e.target.value; st.pag = 1; render(); });
  $$('#grade th[data-ord]').forEach((th) => th.addEventListener('click', () => {
    if (st.ord === th.dataset.ord) st.dir *= -1; else { st.ord = th.dataset.ord; st.dir = th.dataset.ord === 'numero' || th.dataset.ord === 'data_iso' ? -1 : 1; }
    render();
  }));
  $('#paginacao').addEventListener('click', (e) => { const b = e.target.closest('[data-pag]'); if (b && !b.disabled) { st.pag += +b.dataset.pag; render(); } });
  $('#linhas').addEventListener('change', (e) => {
    const n = e.target.dataset.sel; if (!n) return;
    e.target.checked ? st.sel.add(n) : st.sel.delete(n);
    render();
  });
  $('#selTodos').addEventListener('change', (e) => {
    $$('#linhas [data-sel]').forEach((cb) => (e.target.checked ? st.sel.add(cb.dataset.sel) : st.sel.delete(cb.dataset.sel)));
    render();
  });
  $('#selLimpar').addEventListener('click', () => { st.sel.clear(); render(); });
  $('#linhas').addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-n]');
    if (tr) abrirFicha(tr.dataset.n);
  });

  /* ---------- Diálogos ---------- */
  $$('[data-fechar]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  $$('dialog').forEach((d) => d.addEventListener('click', (e) => { if (e.target === d) d.close(); }));

  const SECOES = [
    ['Dados do(a) aluno(a)', [['Nome completo', 'aluno_nome'], ['Data de nascimento', 'aluno_nascimento'], ['CPF', 'aluno_cpf'], ['RG', 'aluno_rg'], ['E-mail do(a) aluno(a)', 'aluno_email'], ['Escola atual', 'escola_atual'], ['Série cursada em 2026', 'serie_2026'], ['Série pretendida em 2027', 'serie_2027']]],
    ['Responsável legal e contatos', [['Responsável legal', 'resp_nome'], ['Vínculo', 'resp_vinculo'], ['CPF', 'resp_cpf'], ['WhatsApp', 'whatsapp'], ['Telefone alternativo', 'telefone_alt'], ['E-mail do responsável', 'email'], ['Outro contato', 'outro_contato']]],
    ['Endereço', [['Rua / avenida e número', 'rua'], ['Bairro', 'bairro'], ['Cidade/UF', 'cidade'], ['CEP', 'cep']]],
  ];
  let fichaAtual = null;
  const abrirFicha = (n) => {
    const r = dados.find((x) => x.numero === n); if (!r) return;
    fichaAtual = r;
    $('#fichaNum').textContent = `Inscrição ${r.numero} · ${r.data}`;
    $('#fichaTitulo').textContent = r.aluno_nome;
    $('#fichaCorpo').innerHTML = SECOES.map(([t, campos]) => `
      <section class="ficha-sec"><h3>${t}</h3><dl>${campos.map(([rot, k]) => `<dt>${rot}</dt><dd>${esc(r[k] || '—')}</dd>`).join('')}</dl></section>`).join('')
      + `<p class="aceite"><span class="ms" aria-hidden="true">verified</span> Declaração aceita eletronicamente pelo(a) responsável em ${esc(r.data)}${r.ip ? ` (IP ${esc(r.ip)})` : ''}.</p>`;
    $('#fichaPdf').href = `./?acao=pdf&n=${encodeURIComponent(r.numero)}`;
    $('#fichaWa').href = `https://wa.me/55${digits(r.whatsapp)}`;
    $('#dlgFicha').showModal();
  };
  $('#fichaEmail').addEventListener('click', () => { $('#dlgFicha').close(); abrirEmail('ficha'); });

  /* ---------- Exportar Excel ---------- */
  const idsEscopo = (escopo) => escopo === 'sel' ? [...st.sel] : escopo === 'filtro' ? filtrados().map((r) => r.numero) : [];
  const escopoPadrao = () => (st.sel.size ? 'sel' : (st.busca || st.serie) ? 'filtro' : 'todas');
  $('#btnExcel').addEventListener('click', () => {
    if (!dados.length) return snack('Ainda não há inscrições para exportar.', true);
    const esc0 = escopoPadrao();
    $('#xlsxIds').value = idsEscopo(esc0).join(',');
    $('#formXlsx').submit();
    snack(esc0 === 'sel' ? `Exportando ${st.sel.size} selecionada(s)…` : esc0 === 'filtro' ? `Exportando ${filtrados().length} filtrada(s)…` : `Exportando todas as ${dados.length} inscrições…`);
  });

  /* ---------- Enviar por e-mail ---------- */
  let modoEmail = 'lista';
  const abrirEmail = (modo) => {
    modoEmail = modo;
    $('#emailErro').hidden = true;
    if (modo === 'ficha') {
      $('#emailTitulo').textContent = `Enviar ficha ${fichaAtual.numero}`;
      $('#escopo').hidden = true;
      $('#emailDica').innerHTML = '<span class="ms" aria-hidden="true">attach_file</span> Vai com a ficha completa no corpo do e-mail e o PDF em anexo.';
    } else {
      if (!dados.length) return snack('Ainda não há inscrições para enviar.', true);
      $('#emailTitulo').textContent = 'Enviar inscrições';
      $('#escopo').hidden = false;
      const nf = filtrados().length;
      const ops = [['todas', 'Todas as inscrições', dados.length], ['filtro', 'Somente as filtradas', nf], ['sel', 'Somente as selecionadas', st.sel.size]];
      const pad = escopoPadrao();
      $('#escopo').innerHTML = '<legend>O que enviar</legend>' + ops.map(([v, t, n]) => {
        const off = n === 0 || (v === 'filtro' && !(st.busca || st.serie));
        return `<label class="opcao" aria-disabled="${off}"><input type="radio" name="escopo" value="${v}" ${v === pad ? 'checked' : ''} ${off ? 'disabled' : ''}> ${t}<small>${n}</small></label>`;
      }).join('');
      $('#emailDica').innerHTML = '<span class="ms" aria-hidden="true">attach_file</span> Vai com a lista no corpo do e-mail e a planilha Excel em anexo.';
    }
    $('#dlgEmail').showModal();
    $('#emailPara').focus();
  };
  $('#btnEmail').addEventListener('click', () => abrirEmail('lista'));

  $('#formEmail').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('#emailEnviar');
    const fd = new FormData();
    fd.append('csrf', window.CSRF);
    fd.append('para', $('#emailPara').value);
    fd.append('mensagem', $('#emailMsg').value);
    if (modoEmail === 'ficha') { fd.append('tipo', 'ficha'); fd.append('n', fichaAtual.numero); }
    else { fd.append('tipo', 'lista'); fd.append('ids', idsEscopo(($('input[name="escopo"]:checked') || {}).value || 'todas').join(',')); }
    btn.disabled = true; $('.lbl', btn).textContent = 'Enviando…';
    try {
      const r = await fetch('./?acao=enviar', { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
      const j = await r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
      if (!j.ok) throw new Error(j.erro || 'Falha ao enviar.');
      $('#dlgEmail').close();
      snack(j.msg);
    } catch (err) {
      $('#emailErro').textContent = err.message;
      $('#emailErro').hidden = false;
    } finally {
      btn.disabled = false; $('.lbl', btn).textContent = 'Enviar';
    }
  });

  kpis();
  render();
})();
