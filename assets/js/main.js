/* Fundação Educacional de Tanabi — interações */
(() => {
  'use strict';

  /* ⚙️ Número do WhatsApp (somente dígitos, com 55 + DDD). Ajuste aqui se necessário. */
  const WHATSAPP = '551732722726';
  const EMAIL = 'secretariafet@tanabinet.com.br';

  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => [...c.querySelectorAll(s)];
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* ignore */ } }
  };
  const waLink = (msg) => `https://wa.me/${WHATSAPP}?text=${encodeURIComponent(msg)}`;

  /* Ano no rodapé */
  const ano = $('#ano'); if (ano) ano.textContent = new Date().getFullYear();

  /* Links de WhatsApp */
  $$('[data-wa]').forEach((a) => {
    a.href = waLink(a.dataset.wa);
    a.target = '_blank';
    a.rel = 'noopener';
  });

  /* Top bar com sombra + botão topo */
  const topbar = $('#topo');
  const fabTop = $('#fabTop');
  const onScroll = () => {
    const y = window.scrollY;
    topbar.classList.toggle('is-scrolled', y > 8);
    fabTop.hidden = y < 700;
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  fabTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

  /* Drawer (menu mobile) */
  const drawer = $('#drawer');
  const scrim = $('#scrim');
  const menuBtn = $('#menuBtn');
  const openDrawer = (open) => {
    drawer.classList.toggle('is-open', open);
    drawer.setAttribute('aria-hidden', String(!open));
    menuBtn.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) {
      scrim.hidden = false;
      requestAnimationFrame(() => scrim.classList.add('is-open'));
      $('a', drawer).focus();
    } else {
      scrim.classList.remove('is-open');
      setTimeout(() => { scrim.hidden = true; }, 250);
    }
  };
  menuBtn.addEventListener('click', () => openDrawer(!drawer.classList.contains('is-open')));
  scrim.addEventListener('click', () => openDrawer(false));
  $$('a', drawer).forEach((a) => a.addEventListener('click', () => openDrawer(false)));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && drawer.classList.contains('is-open')) { openDrawer(false); menuBtn.focus(); }
  });

  /* Acessibilidade: fonte e contraste */
  const a11yBtn = $('#a11yBtn');
  const a11yPanel = $('#a11yPanel');
  a11yBtn.addEventListener('click', () => {
    const open = a11yPanel.hidden;
    a11yPanel.hidden = !open;
    a11yBtn.setAttribute('aria-expanded', String(open));
  });
  let scale = parseFloat(store.get('fet-font') || '1');
  const applyScale = () => { document.documentElement.style.fontSize = `${scale * 100}%`; store.set('fet-font', scale); };
  if (scale !== 1) applyScale();
  const contrastBtn = $('[data-a11y="contrast"]');
  const setContrast = (on) => {
    document.documentElement.classList.toggle('hc', on);
    contrastBtn.setAttribute('aria-pressed', String(on));
    store.set('fet-hc', on ? '1' : '0');
  };
  if (store.get('fet-hc') === '1') setContrast(true);
  a11yPanel.addEventListener('click', (e) => {
    const b = e.target.closest('[data-a11y]'); if (!b) return;
    const act = b.dataset.a11y;
    if (act === 'font-up') { scale = Math.min(1.4, +(scale + .1).toFixed(2)); applyScale(); }
    if (act === 'font-down') { scale = Math.max(.9, +(scale - .1).toFixed(2)); applyScale(); }
    if (act === 'contrast') setContrast(!document.documentElement.classList.contains('hc'));
  });

  /* Animações de entrada */
  const reveals = $$('.reveal');
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) { en.target.classList.add('is-visible'); io.unobserve(en.target); }
      });
    }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
    reveals.forEach((el) => io.observe(el));
  } else {
    reveals.forEach((el) => el.classList.add('is-visible'));
  }

  /* Link ativo no menu */
  const navLinks = $$('.nav a');
  const sections = navLinks.map((a) => $(a.getAttribute('href'))).filter(Boolean);
  if ('IntersectionObserver' in window) {
    const so = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (en.isIntersecting) {
          navLinks.forEach((a) => a.classList.toggle('is-active', a.getAttribute('href') === `#${en.target.id}`));
        }
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sections.forEach((s) => so.observe(s));
  }

  /* Abas do mapa (mobile) */
  const mapFrame = $('#mapFrame');
  $$('.tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      $$('.tab').forEach((t) => { t.classList.toggle('is-active', t === tab); t.setAttribute('aria-selected', String(t === tab)); });
      mapFrame.src = `https://www.google.com/maps?q=${encodeURIComponent(tab.dataset.map)}&output=embed`;
    });
  });
  $$('.unit').forEach((unit, i) => {
    unit.addEventListener('mouseenter', () => {
      if (window.matchMedia('(min-width: 768px)').matches) $$('.tab')[i]?.click();
    });
  });

  /* Formulário → WhatsApp ou e-mail */
  const form = $('#contactForm');
  const err = $('#formError');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const via = e.submitter?.value || 'whats';
    const data = Object.fromEntries(new FormData(form));
    let ok = true;
    ['nome', 'telefone', 'segmento'].forEach((n) => {
      const f = form.elements[n];
      const bad = !String(data[n] || '').trim();
      f.closest('.field').classList.toggle('is-invalid', bad);
      f.setAttribute('aria-invalid', String(bad));
      if (bad) ok = false;
    });
    err.hidden = ok;
    if (!ok) { form.querySelector('[aria-invalid="true"]').focus(); return; }

    const msg = [
      'Olá! Vim pelo site da Fundação Educacional de Tanabi.',
      `Nome: ${data.nome}`,
      `Telefone: ${data.telefone}`,
      `Interesse: ${data.segmento}`,
      data.mensagem ? `Mensagem: ${data.mensagem}` : 'Gostaria de agendar uma visita.'
    ].join('\n');

    if (via === 'email') {
      window.location.href = `mailto:${EMAIL}?subject=${encodeURIComponent('Contato pelo site – ' + data.segmento)}&body=${encodeURIComponent(msg)}`;
    } else {
      window.open(waLink(msg), '_blank', 'noopener');
    }
  });
})();
