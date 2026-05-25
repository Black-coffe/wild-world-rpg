/* V21 (ADR-053) — Crafting economy dashboard frontend.
 * Fetches /admin/crafting-economy/data → renders ApexCharts. Read-only, auto-refresh 60s. */
(function () {
    'use strict';

    var URL = window.__econDataUrl;
    var REFRESH_MS = 60000;
    var charts = {};

    function fmt(n) {
        n = Number(n) || 0;
        var abs = Math.abs(n);
        if (abs >= 1e9) return (n / 1e9).toFixed(1) + 'B';
        if (abs >= 1e6) return (n / 1e6).toFixed(1) + 'M';
        if (abs >= 1e3) return (n / 1e3).toFixed(1) + 'k';
        return String(n);
    }

    function full(n) { return (Number(n) || 0).toLocaleString('ru-RU'); }

    function setText(id, txt) {
        var el = document.getElementById(id);
        if (el) el.textContent = txt;
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
        setText('econ-stats', 'Золота в обороте: ' + full(s.total_gold) + ' · крафт-записей: ' + full(s.crafted_rows));
        setText('econ-updated', 'обновлено ' + (d.generated_at || ''));

        // craft volume by month (area)
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
            tooltip: { y: { formatter: full } },
            grid: { borderColor: '#eef2f7' }
        });

        // gold concentration (top holders, horizontal bar)
        var gc = d.gold_concentration || {};
        var holders = gc.holders || [];
        setText('econ-gold-share', 'Топ-холдер: ' + (gc.top_share_pct || 0) + '% · топ-10: ' + (gc.topn_share_pct || 0) + '% всего золота');
        render('chart-gold', baseBar(
            holders.map(function (h) { return h.name; }),
            holders.map(function (h) { return Number(h.gold) || 0; }),
            'Золото', true, '#ffbc00'
        ));

        // top crafted (horizontal bar)
        var tc = d.top_crafted || [];
        render('chart-top-crafted', baseBar(
            tc.map(function (r) { return r.name; }),
            tc.map(function (r) { return Number(r.qty) || 0; }),
            'Шт.', true, '#727cf5'
        ));

        // top resources (horizontal bar)
        var tr = d.top_resources || [];
        render('chart-top-resources', baseBar(
            tr.map(function (r) { return r.name; }),
            tr.map(function (r) { return Number(r.qty) || 0; }),
            'Кол-во', true, '#39afd1'
        ));

        // transactions by month (grouped column buy/sell)
        var tx = d.transactions || [];
        render('chart-transactions', {
            chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'inherit', stacked: false },
            series: [
                { name: 'Покупки', data: tx.map(function (r) { return Number(r.buy) || 0; }) },
                { name: 'Продажи', data: tx.map(function (r) { return Number(r.sell) || 0; }) }
            ],
            xaxis: { categories: tx.map(function (r) { return r.month; }) },
            colors: ['#0acf97', '#fa5c7c'],
            plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
            dataLabels: { enabled: false },
            legend: { position: 'top' },
            grid: { borderColor: '#eef2f7' }
        });
    }

    function load() {
        if (!URL) return;
        fetch(URL, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(draw)
            .catch(function (e) { setText('econ-stats', 'Ошибка загрузки данных'); console.error(e); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('econ-refresh');
        if (btn) btn.addEventListener('click', load);
        load();
        setInterval(load, REFRESH_MS);
    });
})();
