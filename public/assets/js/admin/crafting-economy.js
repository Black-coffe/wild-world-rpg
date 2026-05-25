/* V21 (ADR-053) — Crafting economy dashboard frontend.
 * Fetches /admin/crafting-economy/data → renders ApexCharts. Read-only, auto-refresh 60s.
 * Период: пресеты (months) или диапазон (from/to). */
(function () {
    'use strict';

    var BASE = window.__econDataUrl;
    var REFRESH_MS = 60000;
    var charts = {};
    var state = { months: '12', from: null, to: null };

    function fmt(n) {
        n = Number(n) || 0;
        var abs = Math.abs(n);
        if (abs >= 1e9) return (n / 1e9).toFixed(1) + 'B';
        if (abs >= 1e6) return (n / 1e6).toFixed(1) + 'M';
        if (abs >= 1e3) return (n / 1e3).toFixed(1) + 'k';
        return String(n);
    }
    function full(n) { return (Number(n) || 0).toLocaleString('ru-RU'); }
    function setText(id, txt) { var el = document.getElementById(id); if (el) el.textContent = txt; }

    function query() {
        if (state.from && state.to) return '?from=' + state.from + '&to=' + state.to;
        return '?months=' + state.months;
    }

    function render(id, options) {
        if (typeof ApexCharts === 'undefined') return;
        if (charts[id]) { charts[id].destroy(); }
        var el = document.getElementById(id);
        if (!el) return;
        charts[id] = new ApexCharts(el, options);
        charts[id].render();
    }

    function baseBar(categories, data, name, horizontal, color) {
        return {
            chart: { type: 'bar', height: horizontal ? 340 : 300, toolbar: { show: false }, fontFamily: 'inherit' },
            series: [{ name: name, data: data }],
            plotOptions: { bar: { horizontal: !!horizontal, borderRadius: 3, barHeight: '70%' } },
            colors: [color || '#727cf5'],
            dataLabels: { enabled: false },
            noData: { text: 'Нет данных за период' },
            xaxis: { categories: categories, labels: { formatter: horizontal ? fmt : undefined, rotate: horizontal ? 0 : -45, style: { fontSize: '11px' } } },
            yaxis: { labels: { formatter: horizontal ? undefined : fmt } },
            tooltip: { y: { formatter: full } },
            grid: { borderColor: '#eef2f7' }
        };
    }

    function draw(d) {
        var s = d.summary || {};
        setText('kpi-players', full(s.players));
        setText('kpi-gold', fmt(s.total_gold));
        setText('kpi-avggold', fmt(s.avg_gold));
        setText('kpi-crafted', full(s.crafted_qty));
        setText('kpi-resource', fmt(s.resource_qty));
        setText('kpi-tx', full(s.transactions));
        setText('econ-stats', 'Золота в обороте: ' + full(s.total_gold) + ' · крафт-записей за период: ' + full(s.crafted_rows));
        setText('econ-updated', 'обновлено ' + (d.generated_at || ''));

        var range = d.range || {};
        if (range.from && range.to) {
            setText('econ-range', 'период: ' + range.from + ' — ' + range.to);
            var exp = document.getElementById('econ-export');
            if (exp) exp.href = exp.dataset.base + query();
        }

        var cv = d.craft_volume || [];
        render('chart-craft-volume', {
            chart: { type: 'area', height: 300, toolbar: { show: false }, fontFamily: 'inherit' },
            series: [{ name: 'Кол-во', data: cv.map(function (r) { return Number(r.qty) || 0; }) }],
            xaxis: { categories: cv.map(function (r) { return r.month; }) },
            yaxis: { labels: { formatter: fmt } },
            colors: ['#0acf97'],
            stroke: { curve: 'smooth', width: 2 },
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 } },
            dataLabels: { enabled: false },
            markers: { size: cv.length === 1 ? 5 : 0 },
            noData: { text: 'Нет данных за период' },
            tooltip: { y: { formatter: full } },
            grid: { borderColor: '#eef2f7' }
        });

        var gc = d.gold_concentration || {};
        var holders = gc.holders || [];
        setText('econ-gold-share', 'Топ-холдер: ' + (gc.top_share_pct || 0) + '% · топ-10: ' + (gc.topn_share_pct || 0) + '% всего золота (текущий снимок)');
        render('chart-gold', baseBar(holders.map(function (h) { return h.name; }), holders.map(function (h) { return Number(h.gold) || 0; }), 'Золото', true, '#ffbc00'));

        var tc = d.top_crafted || [];
        render('chart-top-crafted', baseBar(tc.map(function (r) { return r.name; }), tc.map(function (r) { return Number(r.qty) || 0; }), 'Шт.', true, '#727cf5'));

        var tr = d.top_resources || [];
        render('chart-top-resources', baseBar(tr.map(function (r) { return r.name; }), tr.map(function (r) { return Number(r.qty) || 0; }), 'Кол-во', true, '#39afd1'));

        var tx = d.transactions || [];
        render('chart-transactions', {
            chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
            series: [
                { name: 'Покупки', data: tx.map(function (r) { return Number(r.buy) || 0; }) },
                { name: 'Продажи', data: tx.map(function (r) { return Number(r.sell) || 0; }) }
            ],
            xaxis: { categories: tx.map(function (r) { return r.month; }) },
            colors: ['#0acf97', '#fa5c7c'],
            plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
            dataLabels: { enabled: false },
            legend: { position: 'top' },
            noData: { text: 'Нет транзакций за период' },
            grid: { borderColor: '#eef2f7' }
        });
    }

    function load() {
        if (!BASE) return;
        fetch(BASE + query(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(draw)
            .catch(function (e) { setText('econ-stats', 'Ошибка загрузки данных'); console.error(e); });
    }

    function setActivePreset(btn) {
        var group = document.getElementById('econ-presets');
        if (!group) return;
        Array.prototype.forEach.call(group.querySelectorAll('button'), function (b) { b.classList.remove('active'); });
        if (btn) btn.classList.add('active');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var refresh = document.getElementById('econ-refresh');
        if (refresh) refresh.addEventListener('click', load);

        var presets = document.getElementById('econ-presets');
        if (presets) {
            presets.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-months]');
                if (!btn) return;
                state.months = btn.getAttribute('data-months');
                state.from = null; state.to = null;
                var fEl = document.getElementById('econ-from'); var tEl = document.getElementById('econ-to');
                if (fEl) fEl.value = ''; if (tEl) tEl.value = '';
                setActivePreset(btn);
                load();
            });
        }

        var apply = document.getElementById('econ-apply');
        if (apply) {
            apply.addEventListener('click', function () {
                var f = (document.getElementById('econ-from') || {}).value;
                var t = (document.getElementById('econ-to') || {}).value;
                if (f && t) {
                    state.from = f; state.to = t;
                    setActivePreset(null);
                    load();
                }
            });
        }

        load();
        setInterval(load, REFRESH_MS);
    });
})();
