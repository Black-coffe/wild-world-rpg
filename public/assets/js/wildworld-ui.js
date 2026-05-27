/* ============================================================
   WILD WORLD — публичный сайт. Минимальный UI JS.
   ============================================================
   Версия:  v0.1 (2026-05-27, ADR-062)
   Объём:   только то, что нужно для базовой навигации.
            - burger toggle + off-canvas drawer
            - dropdowns (top-nav «Разделы»)
            - Esc закрывает drawer + dropdown
            - click-outside для dropdown
            - active-state у nav-ссылок по текущему URI
            - аккордеоны (опционально, на страницах FAQ/wiki)
   Подключение: layout.php → <script src="/assets/js/wildworld-ui.js" defer>
   ============================================================ */
(() => {
  'use strict';

  /* ---- BURGER / DRAWER ---- */
  const burger = document.getElementById('ww-burger');
  const drawer = document.getElementById('ww-drawer');
  const setDrawer = (open) => {
    if (!drawer || !burger) return;
    drawer.classList.toggle('is-open', open);
    burger.classList.toggle('is-open', open);
    burger.setAttribute('aria-expanded', String(open));
    drawer.setAttribute('aria-hidden', String(!open));
    document.body.style.overflow = open ? 'hidden' : '';
  };
  if (burger && drawer) {
    burger.addEventListener('click', () => setDrawer(!drawer.classList.contains('is-open')));
    drawer.querySelectorAll('[data-close="drawer"]').forEach(el =>
      el.addEventListener('click', () => setDrawer(false))
    );
    drawer.querySelectorAll('.panel a').forEach(a =>
      a.addEventListener('click', () => setDrawer(false))
    );
  }

  /* ---- DROPDOWNS (top-nav «Разделы» и др.) ---- */
  document.querySelectorAll('.dropdown').forEach(dd => {
    const btn = dd.querySelector('[data-dropdown-trigger]') || dd.querySelector('button, a');
    if (!btn) return;
    btn.addEventListener('click', e => {
      e.preventDefault();
      e.stopPropagation();
      document.querySelectorAll('.dropdown.is-open').forEach(o => { if (o !== dd) o.classList.remove('is-open'); });
      dd.classList.toggle('is-open');
    });
  });
  document.addEventListener('click', e => {
    if (!e.target.closest('.dropdown')) {
      document.querySelectorAll('.dropdown.is-open').forEach(d => d.classList.remove('is-open'));
    }
  });

  /* ---- ESC CLOSES ---- */
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (drawer && drawer.classList.contains('is-open')) setDrawer(false);
    document.querySelectorAll('.dropdown.is-open').forEach(d => d.classList.remove('is-open'));
    document.querySelectorAll('.modal-root.is-open').forEach(m => m.classList.remove('is-open'));
  });

  /* ---- ACCORDION (FAQ / wiki — если есть на странице) ---- */
  document.querySelectorAll('.acc').forEach(acc => {
    // открыть первый по умолчанию, если ничего не is-open
    if (!acc.querySelector('.acc-item.is-open')) {
      const first = acc.querySelector('.acc-item');
      if (first) {
        first.classList.add('is-open');
      }
    }
    acc.querySelectorAll('.acc-item.is-open .acc-body').forEach(b => {
      b.style.maxHeight = b.scrollHeight + 'px';
    });
    acc.addEventListener('click', e => {
      const h = e.target.closest('.acc-head');
      if (!h || !acc.contains(h)) return;
      const item = h.parentElement;
      const body = item.querySelector('.acc-body');
      const wasOpen = item.classList.contains('is-open');
      acc.querySelectorAll('.acc-item').forEach(it => {
        it.classList.remove('is-open');
        const b = it.querySelector('.acc-body');
        if (b) b.style.maxHeight = '0px';
      });
      if (!wasOpen) {
        item.classList.add('is-open');
        if (body) body.style.maxHeight = body.scrollHeight + 'px';
      }
    });
  });

  /* ---- TABS (если есть на странице) ---- */
  document.querySelectorAll('.tabs').forEach(tabs => {
    tabs.addEventListener('click', e => {
      const b = e.target.closest('.tab');
      if (!b || !tabs.contains(b)) return;
      tabs.querySelectorAll('.tab').forEach(t => { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
      b.classList.add('is-active');
      b.setAttribute('aria-selected', 'true');
      const target = b.dataset.tab;
      if (!target) return;
      // переключение работает в пределах общего родителя
      const parent = tabs.parentElement || document;
      parent.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('is-active'));
      const panel = parent.querySelector('#' + CSS.escape(target));
      if (panel) panel.classList.add('is-active');
    });
  });

  /* ---- TABLE SORT ---- */
  document.querySelectorAll('.table[data-sortable]').forEach(tbl => {
    const ths = tbl.querySelectorAll('th[data-sort]');
    const tbody = tbl.querySelector('tbody');
    if (!tbody) return;
    ths.forEach((th, i) => {
      th.addEventListener('click', () => {
        const type = th.dataset.sort || 'text';
        const desc = th.classList.contains('is-sorted') && !th.classList.contains('desc');
        ths.forEach(t => t.classList.remove('is-sorted', 'desc'));
        th.classList.add('is-sorted');
        if (desc) th.classList.add('desc');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((a, b) => {
          const av = a.children[i].textContent.trim();
          const bv = b.children[i].textContent.trim();
          if (type === 'num') {
            const f = s => parseFloat(s.replace(/[^\d.,-]/g, '').replace(',', '.')) || 0;
            return desc ? f(bv) - f(av) : f(av) - f(bv);
          }
          return desc ? bv.localeCompare(av, 'ru') : av.localeCompare(bv, 'ru');
        });
        rows.forEach(r => tbody.appendChild(r));
      });
    });
  });
})();
