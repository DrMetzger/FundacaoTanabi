/* Concurso de Bolsas — ficha de inscrição */
(() => {
  'use strict';
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];

  const ano = $('#ano'); if (ano) ano.textContent = new Date().getFullYear();
  const topbar = $('#topo');
  window.addEventListener('scroll', () => topbar.classList.toggle('is-scrolled', scrollY > 8), { passive: true });

  const form = $('#ficha');
  const err = $('#fichaError');
  const btn = $('#fichaSubmit');
  $('#t0').value = String(Date.now());

  /* Máscaras */
  const digits = (v) => v.replace(/\D/g, '');
  const masks = {
    cpf: (v) => digits(v).slice(0, 11).replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'),
    tel: (v) => {
      const d = digits(v).slice(0, 11);
      if (d.length <= 2) return d.length ? `(${d}` : '';
      if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
      if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
      return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
    },
    cep: (v) => digits(v).slice(0, 8).replace(/(\d{5})(\d)/, '$1-$2')
  };
  $$('[data-mask]').forEach((el) => el.addEventListener('input', () => { el.value = masks[el.dataset.mask](el.value); }));

  const cpfValido = (v) => {
    const c = digits(v);
    if (c.length !== 11 || /^(\d)\1{10}$/.test(c)) return false;
    for (const n of [9, 10]) {
      let s = 0;
      for (let i = 0; i < n; i++) s += +c[i] * (n + 1 - i);
      if (((s * 10) % 11) % 10 !== +c[n]) return false;
    }
    return true;
  };

  /* CEP → endereço (ViaCEP) */
  const cep = $('#cep');
  const hint = $('#cepHint');
  cep.addEventListener('input', async () => {
    const d = digits(cep.value);
    if (d.length !== 8) return;
    hint.textContent = 'Buscando endereço…';
    try {
      const r = await fetch(`https://viacep.com.br/ws/${d}/json/`);
      const j = await r.json();
      if (j.erro) { hint.textContent = 'CEP não encontrado. Preencha manualmente.'; return; }
      const rua = $('#rua');
      if (j.logradouro && !rua.value) rua.value = `${j.logradouro}, `;
      if (j.bairro) $('#bairro').value = j.bairro;
      if (j.localidade) $('#cidade').value = `${j.localidade}/${j.uf}`;
      hint.textContent = 'Endereço encontrado. Confira e informe o número.';
      rua.focus();
      rua.setSelectionRange(rua.value.length, rua.value.length);
    } catch {
      hint.textContent = 'Não foi possível buscar o CEP. Preencha manualmente.';
    }
  });

  /* Validação */
  const setInvalid = (el, bad) => {
    const box = el.closest('.field') || el.closest('.check');
    if (box) box.classList.toggle('is-invalid', bad);
    el.setAttribute('aria-invalid', String(bad));
  };
  const validar = () => {
    const erros = [];
    $$('[required]', form).forEach((el) => {
      let bad = el.type === 'checkbox' ? !el.checked : !el.value.trim();
      if (!bad && el.dataset.mask === 'cpf' && !cpfValido(el.value)) { bad = true; erros.push(`CPF inválido em "${el.labels[0].textContent.replace(' *', '')}".`); }
      if (!bad && el.dataset.mask === 'tel' && digits(el.value).length < 10) { bad = true; erros.push('WhatsApp incompleto.'); }
      if (!bad && el.dataset.mask === 'cep' && digits(el.value).length !== 8) { bad = true; erros.push('CEP incompleto.'); }
      setInvalid(el, bad);
    });
    for (const [id, nome] of [['#aluno_email', 'E-mail do aluno inválido.'], ['#email', 'E-mail do responsável inválido.']]) {
      const email = $(id);
      const emailBad = email.value.trim() !== '' && !email.checkValidity();
      setInvalid(email, emailBad);
      if (emailBad) erros.push(nome);
    }
    const qtd = $$('[aria-invalid="true"]', form).length;
    if (qtd && !erros.length) erros.push('Preencha os campos obrigatórios destacados.');
    return erros;
  };
  form.addEventListener('input', (e) => { if (e.target.getAttribute('aria-invalid') === 'true') setInvalid(e.target, false); });
  form.addEventListener('change', (e) => { if (e.target.getAttribute('aria-invalid') === 'true') setInvalid(e.target, false); });

  /* Envio */
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const erros = validar();
    if (erros.length) {
      err.textContent = erros.join(' ');
      err.hidden = false;
      $('[aria-invalid="true"]', form)?.focus();
      return;
    }
    err.hidden = true;
    btn.disabled = true;
    $('.lbl', btn).textContent = 'Enviando…';
    try {
      const r = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j.ok) throw new Error(j.erro || 'Não foi possível enviar agora.');
      $('#sucessoNum').textContent = j.numero;
      const mail = [...new Set([$('#email').value.trim(), $('#aluno_email').value.trim()].filter(Boolean))].join(' e ');
      if (mail) {
        $('#sucessoMsg').textContent = `Enviamos a confirmação com a ficha em PDF para ${mail}. Se não encontrar, confira a caixa de spam. A secretaria entrará em contato pelo WhatsApp informado.`;
      }
      const msg = `Olá! Fiz a inscrição nº ${j.numero} no Concurso de Bolsas 2027 (aluno(a): ${$('#aluno_nome').value}).`;
      $('#sucessoWhats').href = `https://wa.me/5517988470429?text=${encodeURIComponent(msg)}`;
      form.hidden = true;
      const ok = $('#sucesso');
      ok.hidden = false;
      ok.scrollIntoView({ behavior: 'smooth', block: 'start' });
      ok.focus({ preventScroll: true });
    } catch (ex) {
      err.textContent = `${ex.message} Tente novamente ou fale com a secretaria pelo WhatsApp (17) 98847-0429.`;
      err.hidden = false;
      btn.disabled = false;
      $('.lbl', btn).textContent = 'Enviar inscrição';
    }
  });
})();
