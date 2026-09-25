/* ============================================================
   WILD WORLD — /play (web-bridge-p1-06, ADR-189). Только улучшение.
   Без этого файла каждая кнопка /play работает формой через PRG.
   - перехват форм #play-state и входящих: fetch + Accept: application/json,
     замена #play-state на html, обновление CSRF-токена, всплывашка ответа;
   - JSON-ответ любого статуса (400 «кнопка недоступна», 429 лимит) — на месте (p1-13);
     PRG-откат только при сбое сети / нечитаемом теле и только со свежим токеном;
   - один запрос за раз, кнопки на это время выключены;
   - опрос GET /play/inbox раз в poll_seconds (не чаще серверного минимума) — счётчик колокола;
   - колокол открывает панель входящих и шлёт POST /play/inbox/read.
   ============================================================ */
(() => {
  'use strict';

  const root = document.getElementById('play-root');
  const stateBox = document.getElementById('play-state');
  if (!root || !stateBox || !window.fetch) return;

  const bell = document.getElementById('play-bell');
  const panel = document.getElementById('play-inbox');
  const inboxList = document.getElementById('play-inbox-list');
  const csrfName = root.dataset.csrfName || '';
  const pollMin = Math.max(1, parseInt(root.dataset.pollMin || '10', 10) || 10);
  const pollSeconds = Math.max(pollMin, parseInt(root.dataset.pollSeconds || '0', 10) || pollMin);
  let busy = false;

  const csrfValue = () => {
    const input = csrfName ? document.querySelector('input[name="' + CSS.escape(csrfName) + '"]') : null;
    return input ? input.value : '';
  };

  const setCsrf = (hash) => {
    if (!csrfName || typeof hash !== 'string' || hash === '') return;
    document.querySelectorAll('input[name="' + CSS.escape(csrfName) + '"]').forEach((el) => { el.value = hash; });
  };

  const setBusy = (on) => {
    busy = on;
    root.setAttribute('aria-busy', on ? 'true' : 'false');
    root.querySelectorAll('form button, form input').forEach((el) => {
      if (on) {
        if (!el.disabled) { el.disabled = true; el.dataset.playLocked = '1'; }
      } else if (el.dataset.playLocked === '1') {
        el.disabled = false;
        delete el.dataset.playLocked;
      }
    });
  };

  const setUnread = (n) => {
    if (!bell) return;
    const count = Math.max(0, parseInt(n, 10) || 0);
    const label = count > 99 ? '99+' : (count > 0 ? String(count) : '');
    const counter = bell.querySelector('.play-bell-count');
    if (counter) counter.textContent = label;
    bell.classList.toggle('is-unread', count > 0);
    bell.setAttribute('aria-label', 'Входящие: ' + (count > 0 ? label + ' непрочитанных' : 'нет новых'));
  };

  const showAlert = (text) => {
    if (typeof text !== 'string' || text === '' || stateBox.querySelector('.play-alert')) return;
    const screen = stateBox.querySelector('.play-screen');
    if (!screen) return;
    const box = document.createElement('div');
    box.className = 'play-alert';
    box.setAttribute('role', 'status');
    const title = document.createElement('span');
    title.className = 'play-alert-title';
    title.textContent = 'Ответ кнопки';
    box.appendChild(title);
    box.appendChild(document.createTextNode(text));
    screen.insertBefore(box, screen.firstChild);
  };

  /* Ответ с JSON-телом любого статуса → {ok, json}. Отказ промиса — только сеть или нечитаемое тело. */
  const fetchJson = (url, options) => fetch(url, Object.assign({
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  }, options || {})).then((res) => res.json().then((json) => {
    if (!json || typeof json !== 'object') throw new Error('bad body');
    return { ok: res.ok, status: res.status, json: json };
  }));

  /* Для входящих: токен берём из любого ответа, но не-2xx — ошибка (счётчик не трогаем). */
  const request = (url, options) => fetchJson(url, options).then((r) => {
    setCsrf(r.json.csrf);
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json;
  });

  /* PRG-откат: только со свежим токеном из GET /play/inbox; не вышло — перезагрузка /play,
     старый токен (уже сменённый сервером, regenerate) не отправляем никогда. */
  const fallbackToPrg = (form) => {
    fetchJson(root.dataset.inboxUrl)
      .then((r) => {
        if (!r.ok || typeof r.json.csrf !== 'string' || r.json.csrf === '') throw new Error('no token');
        setCsrf(r.json.csrf);
        setBusy(false); // поля сначала включаем, иначе не уйдут
        form.submit();
      })
      .catch(() => { window.location.reload(); });
  };

  /* Один обработчик JSON-ответа действия, какой бы ни был статус (200, 400, 429…). */
  const applyAct = (form, r) => {
    const json = r.json;
    setCsrf(json.csrf);
    if (typeof json.html === 'string') stateBox.innerHTML = json.html;
    if (json.unread !== undefined) setUnread(json.unread);
    const hasAlert = typeof json.alert === 'string' && json.alert !== '';
    if (!r.ok && !hasAlert && typeof json.html !== 'string') {
      // 401/403 без ответа кнопки (вход истёк, игра выключена) — страница сама покажет, что делать.
      window.location.reload();
      return;
    }
    showAlert(json.alert);
    if (r.ok && panel && !panel.hidden && form.closest('#play-inbox')) loadInbox().catch(() => {});
  };

  /* ---- Действия: кнопки экрана, истории, дока, ввод, кнопки входящих ---- */
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !root.contains(form)) return;
    if (form.getAttribute('action') !== root.dataset.actUrl) return;
    event.preventDefault();
    if (busy) return;
    const body = new FormData(form);
    setBusy(true);
    fetchJson(form.action, { method: 'POST', body: body })
      .then((r) => {
        try { applyAct(form, r); } finally { setBusy(false); }
      }, () => {
        // Сюда — только если fetch отвергнут (сеть) или тело не JSON.
        fallbackToPrg(form);
      });
  });

  /* ---- Входящие: опрос счётчика, панель по колоколу ---- */
  const loadInbox = () => request(root.dataset.inboxUrl).then((json) => {
    setUnread(json.unread);
    if (inboxList && typeof json.html === 'string' && panel && !panel.hidden) inboxList.innerHTML = json.html;
    return json;
  });

  const markRead = () => {
    const body = new FormData();
    if (csrfName) body.append(csrfName, csrfValue());
    return request(root.dataset.readUrl, { method: 'POST', body: body }).then((json) => {
      setCsrf(json.csrf);
      setUnread(json.unread !== undefined ? json.unread : 0);
    });
  };

  if (bell && panel) {
    bell.addEventListener('click', (event) => {
      event.preventDefault();
      panel.hidden = !panel.hidden;
      bell.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
      if (panel.hidden || busy) return;
      loadInbox().then(markRead).catch(() => {});
    });
  }

  const poll = () => {
    if (document.visibilityState === 'visible' && !busy) loadInbox().catch(() => {});
  };
  window.setInterval(poll, pollSeconds * 1000);
})();
