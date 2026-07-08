<?php
/**
 * ADR-149 (Поток E1) — публичный калькулятор крафта.
 *
 * Прогрессивное улучшение: форма работает GET'ом без JS (server-render результата),
 * JS (секция scripts, Ф3) перехватывает и обновляет панель через /kalkulyator-krafta/data.
 *
 * @var array<string,mixed> $meta
 * @var list<array{recipe:string,name:string,icon:string,zone:string}> $itemList
 * @var list<array{zone:string,zone_emoji:string,items:list<array<string,mixed>>}> $catalog
 * @var array<string,mixed>|null $result
 * @var string $selected
 * @var int $qty
 * @var string $tgLink
 */
$this->extend('site/layout');

/** Форматирование золота: пробел-разделитель тысяч, без копеек. */
$g = static fn (mixed $n): string => number_format(is_numeric($n) ? (float) $n : 0.0, 0, '.', ' ');

// Группировка выпадашки по зоне (для optgroup).
$byZone = [];
foreach ($itemList as $it) {
    $byZone[$it['zone']][] = $it;
}
ksort($byZone);
?>

<?= $this->section('head') ?>
<style>
/* ADR-062: только токены дизайн-системы (0 радиусов, 0 теней, палитра-переменные). */
.cc-lede { color: var(--text-dim); max-width: 60ch; margin: 0 0 24px; }
.cc-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 8px; }
.cc-form .field { flex: 1 1 260px; margin: 0; }
.cc-form .field.qty { flex: 0 0 130px; }
.cc-result { border: 1px solid var(--border-strong); background: var(--bg-elev-1); padding: 20px; margin: 24px 0; }
.cc-result-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 16px; }
.cc-result-head .icon { font-size: 1.6rem; }
.cc-result-head .name { font-family: var(--font-display, inherit); font-size: 1.4rem; }
.cc-result-head .qty { color: var(--accent); font-weight: 700; }
.cc-golds { display: flex; flex-wrap: wrap; gap: 20px; margin: 16px 0; }
.cc-gold { border: 1px solid var(--border); padding: 12px 16px; min-width: 150px; }
.cc-gold .lbl { display: block; font-size: .72rem; letter-spacing: .05em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px; }
.cc-gold .val { font-family: var(--font-mono, monospace); font-size: 1.3rem; color: var(--text); }
.cc-gold.total .val { color: var(--accent); }
.cc-rar { display: inline-block; min-width: 26px; text-align: center; font-family: var(--font-mono, monospace); font-size: .72rem; border: 1px solid var(--border); padding: 1px 4px; color: var(--text-dim); }
.cc-note { color: var(--warning, var(--text-dim)); font-size: .9rem; margin-top: 8px; }
.cc-cat details { border: 1px solid var(--border); margin-bottom: 8px; }
.cc-cat summary { cursor: pointer; padding: 12px 16px; background: var(--bg-elev-1); font-weight: 600; }
.cc-cat summary .cnt { color: var(--text-muted); font-weight: 400; font-size: .85rem; }
.cc-cat .cat-body { padding: 4px 16px 12px; }
.cc-cat .rec { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid var(--border-faint, var(--border)); flex-wrap: wrap; }
.cc-cat .rec:first-child { border-top: 0; }
.cc-cat .rec .ing { color: var(--text-dim); font-size: .88rem; }
.cc-cat .rec a { color: var(--text); }
.cc-empty { color: var(--text-muted); font-style: italic; }
/* Дерево крафта (E1.1) — вложенные нативные <details>, разворот по клику, без JS. */
.cc-hint { color: var(--text-muted); font-weight: 400; font-size: .82rem; }
.cc-tree { margin-top: 8px; }
.cc-node { border: 1px solid var(--border); margin: 6px 0; }
.cc-node > summary { cursor: pointer; padding: 8px 12px; background: var(--bg-elev-1); list-style: none; display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
.cc-node > summary::-webkit-details-marker { display: none; }
.cc-node > summary::before { content: '▸'; color: var(--accent); display: inline-block; width: 1em; }
.cc-node[open] > summary::before { content: '▾'; }
.cc-node > summary:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.cc-node-label { font-weight: 600; }
.cc-node-qty { color: var(--accent); font-family: var(--font-mono, monospace); }
.cc-node-body { padding: 6px 12px 10px 22px; border-top: 1px solid var(--border); }
.cc-node .cc-node, .cc-node .cc-leaf { margin-left: 6px; }
.cc-node-raw { list-style: none; padding: 0; margin: 0 0 6px; }
.cc-node-raw li { padding: 3px 0; font-size: .9rem; color: var(--text-dim); display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
.cc-leaf { border: 1px solid var(--border); margin: 6px 0; padding: 8px 12px; display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
.cc-leaf::before { content: '•'; color: var(--text-muted); display: inline-block; width: 1em; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<main id="ww-main" class="container" style="padding-top:28px;padding-bottom:48px">
    <?= view('site/_breadcrumbs', ['items' => $meta['breadcrumbs'] ?? []]) ?>

    <div class="section-head">
        <h1>🧮 Калькулятор крафта</h1>
    </div>
    <p class="cc-lede">
        Выбери предмет и количество — калькулятор развернёт всё дерево рецепта: сколько
        нужно первичного сырья, какие промежуточные детали скрафтить и во сколько золота
        обойдётся докупить всё сырьё у торговца (цены у торговца плавают от спроса — это
        ориентир). Данные берутся из живой игры.
    </p>

    <form class="cc-form" method="get" action="<?= esc(base_url('kalkulyator-krafta'), 'attr') ?>" data-cc-form>
        <div class="field">
            <label class="label" for="cc-recipe">Что крафтим</label>
            <div class="select-wrap">
                <select class="select" id="cc-recipe" name="recipe" data-cc-recipe>
                    <option value="">— выбери предмет —</option>
                    <?php foreach ($byZone as $zone => $items): ?>
                        <optgroup label="<?= esc($zone) ?>">
                            <?php foreach ($items as $it): ?>
                                <option value="<?= esc($it['recipe'], 'attr') ?>" <?= $selected === $it['recipe'] ? 'selected' : '' ?>>
                                    <?= esc(($it['icon'] !== '' ? $it['icon'] . ' ' : '') . $it['name']) ?>
                                </option>
                            <?php endforeach ?>
                        </optgroup>
                    <?php endforeach ?>
                </select>
            </div>
        </div>
        <div class="field qty">
            <label class="label" for="cc-qty">Количество</label>
            <input class="input" id="cc-qty" name="qty" type="number" min="1" max="1000" value="<?= esc((string) $qty, 'attr') ?>" data-cc-qty>
        </div>
        <button class="btn primary" type="submit">Рассчитать</button>
    </form>

    <div data-cc-result>
        <?php if (is_array($result) && ($result['found'] ?? false)): ?>
            <?= view('site/_craft_calc_result', ['result' => $result]) ?>
        <?php elseif (is_array($result)): ?>
            <p class="cc-note">Рецепт не найден. Выбери предмет из списка.</p>
        <?php endif ?>
    </div>

    <!-- Просматриваемый каталог (SEO-контент + no-JS браузинг всех 100 рецептов). -->
    <div class="section-head" style="margin-top:40px"><h2>Все рецепты</h2></div>
    <div class="cc-cat">
        <?php foreach ($catalog as $group): ?>
            <details>
                <summary>
                    <?= esc(($group['zone_emoji'] !== '' ? $group['zone_emoji'] . ' ' : '') . $group['zone']) ?>
                    <span class="cnt">— <?= count($group['items']) ?> рецепт(ов)</span>
                </summary>
                <div class="cat-body">
                    <?php foreach ($group['items'] as $item): ?>
                        <div class="rec">
                            <a href="<?= esc(base_url('kalkulyator-krafta?recipe=' . rawurlencode((string) $item['recipe'])), 'attr') ?>">
                                <?= esc((is_string($item['icon']) && $item['icon'] !== '' ? $item['icon'] . ' ' : '') . (is_string($item['name']) ? $item['name'] : '')) ?>
                            </a>
                            <span class="ing">
                                <?php
                                $parts = [];
                                foreach (($item['direct'] ?? []) as $d) {
                                    if (is_array($d)) {
                                        $parts[] = esc((is_string($d['name']) ? $d['name'] : '') . ' ×' . (is_numeric($d['qty']) ? (int) $d['qty'] : 0));
                                    }
                                }
                                echo $parts === [] ? '<span class="cc-empty">без сырья</span>' : implode(', ', $parts);
                                if (is_numeric($item['goldRequired'] ?? null) && (float) $item['goldRequired'] > 0) {
                                    echo ' · <span class="n">+' . $g($item['goldRequired']) . ' 💰</span>';
                                }
                                ?>
                            </span>
                        </div>
                    <?php endforeach ?>
                </div>
            </details>
        <?php endforeach ?>
    </div>

    <div style="margin-top:40px;text-align:center">
        <a class="btn primary" href="<?= esc($tgLink, 'attr') ?>" target="_blank" rel="noopener">▶ Играть в Wild World</a>
    </div>
</main>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
/* ADR-149 — прогрессивное улучшение калькулятора: без JS работает GET-форма,
   с JS панель обновляется мгновенно через /kalkulyator-krafta/data (fetch).
   🔴 Расчёт — только на сервере; JS лишь рендерит JSON-результат. */
(function () {
    'use strict';
    var form = document.querySelector('[data-cc-form]');
    if (!form) { return; }
    var recipeSel = form.querySelector('[data-cc-recipe]');
    var qtyInput  = form.querySelector('[data-cc-qty]');
    var resultBox = document.querySelector('[data-cc-result]');
    if (!resultBox) { return; }
    var action  = form.getAttribute('action') || '';
    var dataUrl = action.replace(/\/+$/, '') + '/data';
    var timer = null;

    function fmtGold(n) {
        n = Math.round(Number(n) || 0);
        return n.toLocaleString('ru-RU').replace(/ /g, ' ');
    }
    function fmtPrice(n) {
        n = Number(n) || 0;
        var r = Math.round(n * 100) / 100;
        return (r === Math.floor(r) ? String(r) : r.toFixed(2).replace(/0+$/, '').replace(/\.$/, ''));
    }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    /* Рекурсивный рендер узла дерева крафта (E1.1) — зеркало SSR-партиала _craft_calc_result.php. */
    function renderNode(node) {
        var icon  = node.icon ? esc(node.icon) + ' ' : '';
        var label = '<span class="cc-node-label">' + icon + esc(node.name) + '</span> <span class="cc-node-qty">×' + (node.qty | 0) + '</span>';
        var raw   = node.directRaw || [];
        var kids  = node.children || [];
        if (!raw.length && !kids.length) {
            return '<div class="cc-leaf">' + label + (node.cyclic ? ' <span class="cc-hint">(повтор выше)</span>' : '') + '</div>';
        }
        var body = '';
        if (raw.length) {
            body += '<ul class="cc-node-raw">';
            raw.forEach(function (r) {
                var ri = r.icon ? esc(r.icon) + ' ' : '';
                body += '<li><span>' + ri + esc(r.name) + '</span> <span class="cc-node-qty">×' + (r.qty | 0)
                     + '</span> <span class="cc-rar">R' + (r.rarity | 0) + '</span></li>';
            });
            body += '</ul>';
        }
        kids.forEach(function (k) { body += renderNode(k); });
        return '<details class="cc-node"><summary>' + label + '</summary><div class="cc-node-body">' + body + '</div></details>';
    }

    function renderPanel(data) {
        if (!data || !data.found) {
            resultBox.innerHTML = (data && data.recipe) ? '<p class="cc-note">Рецепт не найден. Выбери предмет из списка.</p>' : '';
            return;
        }
        var h = '<div class="cc-result">';
        h += '<div class="cc-result-head"><span class="icon">' + esc(data.icon) + '</span>'
           + '<span class="name">' + esc(data.name) + '</span>'
           + '<span class="qty">× ' + (data.qty | 0) + '</span></div>';

        h += '<div class="cc-golds">';
        h += '<div class="cc-gold"><span class="lbl">Сырьё у торговца</span><span class="val">~' + fmtGold(data.goldMarket) + ' 💰</span></div>';
        if (Number(data.goldRequired) > 0) {
            h += '<div class="cc-gold"><span class="lbl">Золото за крафт</span><span class="val">' + fmtGold(data.goldRequired) + ' 💰</span></div>';
        }
        h += '<div class="cc-gold total"><span class="lbl">Итого золота</span><span class="val">~' + fmtGold(data.goldTotal) + ' 💰</span></div>';
        h += '</div>';
        h += '<p class="cc-note">💡 «Итого» — верхняя оценка, <b>если докупать всё сырьё у торговца</b>; обычно сырьё добывают сами. Цены у торговца плавают от спроса — это текущий ориентир (~), не окончательная сумма.</p>';

        h += '<h3 style="margin:16px 0 8px">Всего нужно сырья</h3>';
        var raw = data.rawResources || [];
        if (!raw.length) {
            h += '<p class="cc-empty">Этот предмет не требует первичного сырья.</p>';
        } else {
            h += '<div class="table-wrap"><table class="table"><thead><tr><th>Ресурс</th>'
               + '<th style="text-align:right">Нужно</th><th style="text-align:center">Редкость</th>'
               + '<th style="text-align:right">Цена/шт</th><th style="text-align:right">Сумма</th></tr></thead><tbody>';
            raw.forEach(function (r) {
                h += '<tr><td>' + (r.icon ? esc(r.icon) + ' ' : '') + esc(r.name) + '</td>'
                   + '<td style="text-align:right" class="mono">' + (r.qty | 0) + '</td>'
                   + '<td style="text-align:center"><span class="cc-rar">R' + (r.rarity | 0) + '</span></td>'
                   + '<td style="text-align:right" class="mono">' + (r.priced ? fmtPrice(r.buy) : '—') + '</td>'
                   + '<td style="text-align:right" class="mono">' + (r.priced ? fmtGold(r.lineGold) : '—') + '</td></tr>';
            });
            h += '</tbody></table></div>';
        }

        var tree = data.tree;
        var treeKids = (tree && tree.children) ? tree.children : [];
        if (treeKids.length) {
            h += '<h3 style="margin:20px 0 8px">🌳 Дерево крафта <span class="cc-hint">— нажми узел, чтобы развернуть</span></h3><div class="cc-tree">';
            treeKids.forEach(function (k) { h += renderNode(k); });
            h += '</div>';
        }

        var unres = data.unresolved ? Object.keys(data.unresolved) : [];
        if (unres.length) {
            h += '<p class="cc-note">⚠️ Часть компонентов не удалось развернуть автоматически: ' + esc(unres.join(', ')) + '.</p>';
        }
        h += '</div>';
        resultBox.innerHTML = h;
    }

    function update() {
        var recipe = recipeSel ? recipeSel.value : '';
        var qty    = qtyInput ? qtyInput.value : '1';
        if (!recipe) { resultBox.innerHTML = ''; syncUrl(''); return; }
        resultBox.setAttribute('aria-busy', 'true');
        fetch(dataUrl + '?recipe=' + encodeURIComponent(recipe) + '&qty=' + encodeURIComponent(qty), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              renderPanel(data);
              resultBox.removeAttribute('aria-busy');
              syncUrl('?recipe=' + encodeURIComponent(recipe) + '&qty=' + encodeURIComponent(qty));
          })
          .catch(function () { resultBox.removeAttribute('aria-busy'); });
    }

    function syncUrl(qs) {
        try { history.replaceState(null, '', action + qs); } catch (e) { /* no-op */ }
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); update(); });
    if (recipeSel) { recipeSel.addEventListener('change', update); }
    if (qtyInput) {
        qtyInput.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(update, 300);
        });
    }
})();
</script>
<?= $this->endSection() ?>
