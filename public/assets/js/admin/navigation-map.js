/* Navigation map (дерево навигации игры) — admin viewer. v1
 * Источник данных: window.__nmDataUrl → { generated_at, stats, entrypoints[], nodes[], edges[] }
 * Граф строится детерминированно из кода (NavigationMapService), read-only.
 */
(function () {
    'use strict';

    var DATA_URL = window.__nmDataUrl;
    var REFRESH_MS = 30000;

    var state = {
        graph: null,
        byClass: {},        // class -> node
        edgesFrom: {},      // from_class -> [edge]
        edgesTo: {},        // to_class -> [edge]
        tab: 'tree',
        query: '',
        selected: null,
        timer: null
    };

    /* ---------- utils ---------- */
    function $(sel, root) { return (root || document).querySelector(sel); }
    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html !== undefined) e.innerHTML = html;
        return e;
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function hl(s, q) {
        s = esc(s);
        if (!q) return s;
        try {
            var re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
            return s.replace(re, '<span class="nm-hl">$1</span>');
        } catch (e) { return s; }
    }
    function grpClass(g) { return 'nm-grp-' + (g || 'other'); }

    /* ---------- data load ---------- */
    function load() {
        fetch(DATA_URL, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (g) {
                state.graph = g;
                index(g);
                renderStats(g);
                render();
                $('#nm-tree').setAttribute('aria-busy', 'false');
            })
            .catch(function (e) {
                $('#nm-tree').innerHTML = '<div class="nm-empty"><i class="ri-error-warning-line"></i><div>Ошибка загрузки графа</div><small class="text-muted">' + esc(e) + '</small></div>';
            });
    }

    function index(g) {
        state.byClass = {};
        state.edgesFrom = {};
        state.edgesTo = {};
        (g.nodes || []).forEach(function (n) { state.byClass[n.class] = n; });
        (g.edges || []).forEach(function (e) {
            (state.edgesFrom[e.from_class] = state.edgesFrom[e.from_class] || []).push(e);
            if (e.to_class) (state.edgesTo[e.to_class] = state.edgesTo[e.to_class] || []).push(e);
        });
    }

    /* ---------- stats ---------- */
    function renderStats(g) {
        var s = g.stats || {};
        $('#nm-stats').textContent =
            'Экранов: ' + s.nodes + ' · Переходов: ' + s.edges + ' · Точек входа: ' + s.entrypoints + ' · Макс. глубина: ' + s.max_depth;

        var when = g.generated_at ? new Date(g.generated_at) : null;
        $('#nm-updated').textContent = when ? ('обновлено ' + when.toLocaleTimeString()) : '';

        var cards = [
            { lbl: 'Экраны', val: s.nodes, cls: '' },
            { lbl: 'Переходы (кнопки)', val: s.edges, cls: '' },
            { lbl: 'Точки входа', val: s.entrypoints, cls: 'nm-ok' },
            { lbl: 'Мёртвые кнопки', val: s.unresolved, cls: s.unresolved ? 'nm-warn' : 'nm-ok' },
            { lbl: 'Тупики (без кнопок)', val: s.dead_ends, cls: '' },
            { lbl: 'Сироты (недостижимы)', val: s.orphans, cls: s.orphans ? 'nm-warn' : 'nm-ok' }
        ];
        var bar = $('#nm-statbar');
        bar.innerHTML = '';
        cards.forEach(function (c) {
            var col = el('div', 'col-6 col-md-4 col-xl-2');
            col.appendChild(el('div', 'nm-stat ' + c.cls,
                '<div class="nm-stat-val">' + c.val + '</div><div class="nm-stat-lbl">' + c.lbl + '</div>'));
            bar.appendChild(col);
        });

        $('#nm-count-problems').textContent = (s.unresolved + s.orphans + s.dead_ends);
        $('#nm-count-screens').textContent = s.nodes;
    }

    /* ---------- render dispatcher ---------- */
    function render() {
        var tree = $('#nm-tree');
        var empty = $('#nm-empty');
        empty.classList.add('d-none');
        tree.innerHTML = '';

        if (state.query) { renderSearch(tree); return; }
        if (state.tab === 'tree') renderTree(tree);
        else if (state.tab === 'problems') renderProblems(tree);
        else renderScreens(tree);
    }

    /* ---------- TREE (lazy, from entrypoints) ---------- */
    function renderTree(root) {
        var eps = (state.graph.entrypoints || []);
        eps.forEach(function (ep) {
            var node = ep.to_class ? state.byClass[ep.to_class] : null;
            var wrap = el('div', 'nm-node nm-entry');
            var row = entryRow(ep, node);
            wrap.appendChild(row.row);
            wrap.appendChild(row.children);
            root.appendChild(wrap);
        });
    }

    function entryRow(ep, node) {
        var children = el('div', 'nm-children nm-collapsed');
        var row = el('div', 'nm-row');
        var built = false;

        var toggle = el('span', 'nm-toggle' + (node ? '' : ' nm-leaf'), '<i class="ri-arrow-right-s-line"></i>');
        row.appendChild(toggle);
        row.appendChild(el('span', 'nm-trigger', esc(ep.trigger)));
        row.appendChild(el('span', 'nm-label', '<strong>' + esc(ep.label) + '</strong>'));
        if (node) row.appendChild(el('span', 'nm-screen-name', esc(node.short)));
        if (!node) row.appendChild(el('span', 'nm-badge nm-badge-dead', '✖ не найден'));

        function expand() {
            if (!node) return;
            if (!built) {
                buildChildren(children, node, {});
                built = true;
            }
            children.classList.toggle('nm-collapsed');
            toggle.classList.toggle('nm-open');
        }
        toggle.addEventListener('click', function (e) { e.stopPropagation(); expand(); });
        row.addEventListener('click', function () { if (node) select(node); });
        row._expand = expand;
        return { row: row, children: children };
    }

    /* build children edges of a screen node, lazily */
    function buildChildren(container, node, ancestors) {
        var edges = (state.edgesFrom[node.class] || []);
        if (!edges.length) {
            container.appendChild(el('div', 'nm-row',
                '<span class="nm-toggle nm-leaf"></span><span class="text-muted small">— нет кнопок (тупик) —</span>'));
            return;
        }
        var anc = Object.assign({}, ancestors);
        anc[node.class] = true;

        edges.forEach(function (edge) {
            container.appendChild(edgeRow(edge, anc));
        });
    }

    /* a row representing a button (edge) + its target screen, expandable */
    function edgeRow(edge, ancestors) {
        var target = edge.to_class ? state.byClass[edge.to_class] : null;
        var isCycle = target && ancestors[target.class];
        var expandable = target && !isCycle;

        var node = el('div', 'nm-node');
        var row = el('div', 'nm-row');
        var children = el('div', 'nm-children nm-collapsed');
        var built = false;

        var toggle = el('span', 'nm-toggle' + (expandable ? '' : ' nm-leaf'),
            expandable ? '<i class="ri-arrow-right-s-line"></i>' : '');
        row.appendChild(toggle);

        if (target) row.appendChild(el('span', 'nm-dot ' + grpClass(target.group)));
        row.appendChild(el('span', 'nm-label', hl(edge.label, state.query)));

        if (edge.unresolved) {
            row.appendChild(el('span', 'nm-badge nm-badge-dead', '✖ мёртвая'));
        } else if (target) {
            row.appendChild(el('span', 'nm-screen-name', esc(target.short)));
            if (isCycle) row.appendChild(el('span', 'nm-badge nm-badge-loop', '↩ выше'));
            else if (target.in > 1) row.appendChild(el('span', 'nm-badge nm-badge-loop', '↗ ' + target.in));
            if (typeof target.out === 'number' && target.out > 0)
                row.appendChild(el('span', 'nm-badge nm-badge-out', '⌘ ' + target.out));
        }

        function expand() {
            if (!expandable) return;
            if (!built) { buildChildren(children, target, ancestors); built = true; }
            children.classList.toggle('nm-collapsed');
            toggle.classList.toggle('nm-open');
        }
        toggle.addEventListener('click', function (e) { e.stopPropagation(); expand(); });
        row.addEventListener('click', function () { if (target) select(target); });
        row._expand = expand;

        node.appendChild(row);
        node.appendChild(children);
        return node;
    }

    /* ---------- PROBLEMS ---------- */
    function renderProblems(root) {
        var nodes = state.graph.nodes || [];
        var edges = state.graph.edges || [];

        var dead = edges.filter(function (e) { return e.unresolved && e.callback !== '(внутр. dispatch)'; });
        var orphans = nodes.filter(function (n) { return n.in === 0 && n.depth === null && n.kind !== 'task-result'; });
        var deadends = nodes.filter(function (n) { return n.out === 0; });
        var taskOrphans = nodes.filter(function (n) { return n.in === 0 && n.depth === null && n.kind === 'task-result'; });

        root.appendChild(sectionTitle('Мёртвые / нерезолвенные кнопки (' + dead.length + ')',
            'Кнопка есть, но callback не резолвится ни в один маршрут → клик в никуда либо динамика, требующая ручной проверки.'));
        if (!dead.length) root.appendChild(emptyNote('Нет мёртвых кнопок 🎉'));
        dead.forEach(function (e) {
            var from = state.byClass[e.from_class];
            var row = el('div', 'nm-row');
            row.appendChild(el('span', 'nm-toggle nm-leaf'));
            row.appendChild(el('span', 'nm-badge nm-badge-dead', '✖'));
            row.appendChild(el('span', 'nm-label', hl(e.label, state.query)));
            row.appendChild(el('span', 'nm-screen-name', esc(from ? from.short : e.from_class)));
            row.appendChild(el('span', 'nm-d-cb ms-1', esc(e.callback)));
            row.addEventListener('click', function () { if (from) select(from); });
            root.appendChild(row);
        });

        root.appendChild(sectionTitle('Сироты — недостижимы от точек входа (' + orphans.length + ')',
            'Ни одна кнопка/маршрут не ведёт на экран. Возможно мёртвый код, либо вход через внутренний вызов.'));
        if (!orphans.length) root.appendChild(emptyNote('Сирот нет 🎉'));
        orphans.forEach(function (n) { root.appendChild(screenRow(n)); });

        root.appendChild(sectionTitle('Тупики — экран без кнопок (' + deadends.length + ')',
            'Экран не предлагает ни одной кнопки навигации. Часть легитимна (подтверждения), часть — UX-тупик без «назад».'));
        deadends.forEach(function (n) { root.appendChild(screenRow(n)); });

        root.appendChild(sectionTitle('Итоги задач без входящих (инфо) (' + taskOrphans.length + ')',
            'Это async-обработчики (завершение задач/события). Достигаются не кнопкой — это норма, перечислены для полноты.'));
        taskOrphans.forEach(function (n) { root.appendChild(screenRow(n)); });
    }

    function sectionTitle(t, sub) {
        var d = el('div', 'nm-d-section-title mt-3');
        d.innerHTML = esc(t) + (sub ? ' <span class="text-muted" style="text-transform:none;font-weight:400">— ' + esc(sub) + '</span>' : '');
        return d;
    }
    function emptyNote(t) { return el('div', 'text-muted small px-2 py-1', esc(t)); }

    /* ---------- SCREENS (flat, grouped) ---------- */
    function renderScreens(root) {
        var nodes = (state.graph.nodes || []).slice().sort(function (a, b) {
            if (a.group !== b.group) return a.group < b.group ? -1 : 1;
            return (b.out || 0) - (a.out || 0);
        });
        var curGroup = null;
        nodes.forEach(function (n) {
            if (n.group !== curGroup) {
                curGroup = n.group;
                root.appendChild(sectionTitle(curGroup + ' (' + nodes.filter(function (x) { return x.group === curGroup; }).length + ')'));
            }
            root.appendChild(screenRow(n));
        });
    }

    function screenRow(n) {
        var row = el('div', 'nm-row');
        row.appendChild(el('span', 'nm-toggle nm-leaf'));
        row.appendChild(el('span', 'nm-dot ' + grpClass(n.group)));
        row.appendChild(el('span', 'nm-label', hl(n.short, state.query)));
        if (typeof n.depth === 'number') row.appendChild(el('span', 'nm-badge nm-badge-depth', 'd' + n.depth));
        row.appendChild(el('span', 'nm-badge nm-badge-out', '⌘ ' + (n.out || 0)));
        row.appendChild(el('span', 'nm-badge nm-badge-loop', '↘ ' + (n.in || 0)));
        if (n.out === 0) row.appendChild(el('span', 'nm-badge nm-badge-dead', 'тупик'));
        row.addEventListener('click', function () { select(n); });
        return row;
    }

    /* ---------- SEARCH (flat results) ---------- */
    function renderSearch(root) {
        var q = state.query.toLowerCase();
        var nodes = state.graph.nodes || [];
        var edges = state.graph.edges || [];

        var matchNodes = nodes.filter(function (n) {
            return (n.short && n.short.toLowerCase().indexOf(q) >= 0) ||
                (n.class && n.class.toLowerCase().indexOf(q) >= 0) ||
                (n.group && n.group.toLowerCase().indexOf(q) >= 0) ||
                (n.routes && n.routes.join(' ').toLowerCase().indexOf(q) >= 0);
        });
        var matchEdges = edges.filter(function (e) {
            return (e.label && e.label.toLowerCase().indexOf(q) >= 0) ||
                (e.callback && e.callback.toLowerCase().indexOf(q) >= 0);
        });

        $('#nm-search-hint').textContent = 'Найдено экранов: ' + matchNodes.length + ', кнопок: ' + matchEdges.length;

        if (!matchNodes.length && !matchEdges.length) {
            $('#nm-empty').classList.remove('d-none');
            return;
        }

        if (matchNodes.length) {
            root.appendChild(sectionTitle('Экраны (' + matchNodes.length + ')'));
            matchNodes.forEach(function (n) { root.appendChild(screenRow(n)); });
        }
        if (matchEdges.length) {
            root.appendChild(sectionTitle('Кнопки (' + matchEdges.length + ')'));
            matchEdges.slice(0, 200).forEach(function (e) {
                var from = state.byClass[e.from_class];
                var to = e.to_class ? state.byClass[e.to_class] : null;
                var row = el('div', 'nm-row');
                row.appendChild(el('span', 'nm-toggle nm-leaf'));
                if (e.unresolved) row.appendChild(el('span', 'nm-badge nm-badge-dead', '✖'));
                else if (to) row.appendChild(el('span', 'nm-dot ' + grpClass(to.group)));
                row.appendChild(el('span', 'nm-label', hl(e.label, state.query)));
                row.appendChild(el('span', 'nm-screen-name',
                    esc(from ? from.short : '?') + ' → ' + esc(to ? to.short : (e.unresolved ? 'нет' : '?'))));
                row.addEventListener('click', function () { if (from) select(from); });
                root.appendChild(row);
            });
        }
    }

    /* ---------- DETAIL DRAWER ---------- */
    function select(node) {
        state.selected = node.class;
        document.querySelectorAll('.nm-row.nm-active').forEach(function (r) { r.classList.remove('nm-active'); });

        var d = $('#nm-detail');
        d.innerHTML = '';

        var head = el('div');
        head.appendChild(el('h5', '', '<span class="nm-dot ' + grpClass(node.group) + '"></span> ' + esc(node.short)));
        head.appendChild(el('div', 'nm-d-file', esc(node.file)));
        d.appendChild(head);

        // flags
        var flags = el('div', 'mt-2');
        var isEntry = (state.graph.entrypoints || []).some(function (ep) { return ep.to_class === node.class; });
        if (isEntry) flags.appendChild(el('span', 'nm-flag nm-flag-entry', '★ точка входа'));
        flags.appendChild(el('span', 'nm-d-pill', 'группа: ' + esc(node.group)));
        flags.appendChild(el('span', 'nm-d-pill', 'тип: ' + esc(node.kind)));
        flags.appendChild(el('span', 'nm-d-pill', 'глубина: ' + (node.depth == null ? '—' : node.depth)));
        var deadOut = (state.edgesFrom[node.class] || []).filter(function (e) { return e.unresolved; }).length;
        if (deadOut) flags.appendChild(el('span', 'nm-flag nm-flag-dead', '✖ мёртвых кнопок: ' + deadOut));
        if (node.in === 0 && node.depth === null && node.kind !== 'task-result') flags.appendChild(el('span', 'nm-flag nm-flag-orphan', 'сирота'));
        if (node.out === 0) flags.appendChild(el('span', 'nm-flag nm-flag-deadend', 'тупик'));
        d.appendChild(flags);

        // routes (how reached by callback)
        if (node.routes && node.routes.length) {
            d.appendChild(el('div', 'nm-d-section-title', 'Callback-маршруты сюда'));
            var rwrap = el('div');
            node.routes.forEach(function (r) { rwrap.appendChild(el('span', 'nm-d-pill', esc(r))); });
            d.appendChild(rwrap);
        }

        // outgoing buttons
        var outs = state.edgesFrom[node.class] || [];
        d.appendChild(el('div', 'nm-d-section-title', 'Исходящие кнопки (' + outs.length + ')'));
        if (!outs.length) d.appendChild(el('div', 'text-muted small', '— нет, это тупик —'));
        outs.forEach(function (e) {
            var to = e.to_class ? state.byClass[e.to_class] : null;
            var row = el('div', 'nm-d-edge' + (e.unresolved ? ' nm-d-dead' : ''));
            row.innerHTML = '<span>' + esc(e.label) + '</span><span class="nm-d-arrow">→</span>' +
                '<span>' + (e.unresolved ? '<span class="text-danger">мёртвая</span>' : esc(to ? to.short : '?')) + '</span>';
            var cb = el('div', 'nm-d-cb', esc(e.callback));
            var box = el('div'); box.appendChild(row); box.appendChild(cb);
            if (to && !e.unresolved) row.addEventListener('click', function () { select(to); });
            d.appendChild(box);
        });

        // incoming
        var ins = state.edgesTo[node.class] || [];
        d.appendChild(el('div', 'nm-d-section-title', 'Входящие переходы (' + ins.length + ')'));
        if (isEntry) d.appendChild(el('div', 'text-muted small', '+ прямой вход (' +
            (state.graph.entrypoints.filter(function (ep) { return ep.to_class === node.class; })
                .map(function (ep) { return ep.trigger; }).join(', ')) + ')'));
        if (!ins.length && !isEntry) d.appendChild(el('div', 'text-muted small', '— никто не ссылается (сирота) —'));
        ins.slice(0, 60).forEach(function (e) {
            var from = state.byClass[e.from_class];
            var row = el('div', 'nm-d-edge');
            row.innerHTML = '<span>' + esc(from ? from.short : '?') + '</span><span class="nm-d-arrow">·</span>' +
                '<span class="text-muted">' + esc(e.label) + '</span>';
            if (from) row.addEventListener('click', function () { select(from); });
            d.appendChild(row);
        });
    }

    /* ---------- controls ---------- */
    function bind() {
        document.querySelectorAll('[data-nm-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-nm-tab]').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                state.tab = btn.getAttribute('data-nm-tab');
                render();
            });
        });

        var search = $('#nm-search');
        var clear = $('#nm-search-clear');
        search.addEventListener('input', function () {
            state.query = search.value.trim();
            clear.classList.toggle('d-none', !state.query);
            if (!state.query) $('#nm-search-hint').textContent = '';
            render();
        });
        clear.addEventListener('click', function () {
            search.value = ''; state.query = ''; clear.classList.add('d-none');
            $('#nm-search-hint').textContent = ''; render();
        });

        $('#nm-refresh').addEventListener('click', load);
        $('#nm-expand-all').addEventListener('click', function () { setAll(true); });
        $('#nm-collapse-all').addEventListener('click', function () { setAll(false); });
    }

    function setAll(open) {
        // expand/collapse currently-rendered tree rows (lazy: triggers build)
        var rows = document.querySelectorAll('#nm-tree .nm-row');
        rows.forEach(function (r) {
            if (!r._expand) return;
            var toggle = r.querySelector('.nm-toggle');
            var isOpen = toggle && toggle.classList.contains('nm-open');
            if (open && !isOpen) r._expand();
            else if (!open && isOpen) r._expand();
        });
    }

    function startLive() {
        if (state.timer) clearInterval(state.timer);
        state.timer = setInterval(function () {
            if (!state.query) load();
        }, REFRESH_MS);
    }

    /* ---------- init ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        bind();
        load();
        startLive();
    });
})();
