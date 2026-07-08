<?php
/**
 * ADR-149 — панель результата калькулятора крафта (спецификация сырья).
 * Рендерится сервером (no-JS) и как эталон для JS-регенерации (Ф3).
 *
 * @var array<string,mixed> $result
 */
/** Форматтер золота (локально — партиал самодостаточен). Итоги — целыми. */
$g = static fn (mixed $n): string => number_format(is_numeric($n) ? (float) $n : 0.0, 0, '.', ' ');
/** Цена за штуку: до 2 десятичных без хвостовых нулей (2.2 не должно стать «2»). */
$gp = static function (mixed $n): string {
    $v = is_numeric($n) ? (float) $n : 0.0;
    return $v === floor($v) ? number_format($v, 0, '.', ' ') : rtrim(rtrim(number_format($v, 2, '.', ' '), '0'), '.');
};

$raw   = is_array($result['rawResources'] ?? null) ? $result['rawResources'] : [];
$unres = is_array($result['unresolved'] ?? null) ? $result['unresolved'] : [];
?>
<div class="cc-result">
    <div class="cc-result-head">
        <span class="icon"><?= esc(is_string($result['icon'] ?? null) ? $result['icon'] : '') ?></span>
        <span class="name"><?= esc(is_string($result['name'] ?? null) ? $result['name'] : '') ?></span>
        <span class="qty">× <?= (int) ($result['qty'] ?? 1) ?></span>
    </div>

    <div class="cc-golds">
        <div class="cc-gold">
            <span class="lbl">Сырьё у торговца</span>
            <span class="val">~<?= $g($result['goldMarket'] ?? 0) ?> 💰</span>
        </div>
        <?php if (is_numeric($result['goldRequired'] ?? null) && (float) $result['goldRequired'] > 0): ?>
        <div class="cc-gold">
            <span class="lbl">Золото за крафт</span>
            <span class="val"><?= $g($result['goldRequired']) ?> 💰</span>
        </div>
        <?php endif ?>
        <div class="cc-gold total">
            <span class="lbl">Итого золота</span>
            <span class="val">~<?= $g($result['goldTotal'] ?? 0) ?> 💰</span>
        </div>
    </div>
    <p class="cc-note">💡 «Итого» — верхняя оценка, <b>если докупать всё сырьё у торговца</b>; обычно сырьё добывают сами. Цены у торговца плавают от спроса — это текущий ориентир (~), не окончательная сумма.</p>

    <h3 style="margin:16px 0 8px">Всего нужно сырья</h3>
    <?php if ($raw === []): ?>
        <p class="cc-empty">Этот предмет не требует первичного сырья.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Ресурс</th>
                    <th style="text-align:right">Нужно</th>
                    <th style="text-align:center">Редкость</th>
                    <th style="text-align:right">Цена/шт</th>
                    <th style="text-align:right">Сумма</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($raw as $r): if (! is_array($r)) { continue; } ?>
                <tr>
                    <td><?= esc((is_string($r['icon'] ?? null) && $r['icon'] !== '' ? $r['icon'] . ' ' : '') . (is_string($r['name'] ?? null) ? $r['name'] : '')) ?></td>
                    <td style="text-align:right" class="mono"><?= (int) ($r['qty'] ?? 0) ?></td>
                    <td style="text-align:center"><span class="cc-rar">R<?= (int) ($r['rarity'] ?? 0) ?></span></td>
                    <td style="text-align:right" class="mono"><?= ($r['priced'] ?? false) ? $gp($r['buy'] ?? 0) : '—' ?></td>
                    <td style="text-align:right" class="mono"><?= ($r['priced'] ?? false) ? $g($r['lineGold'] ?? 0) : '—' ?></td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endif ?>

    <?php
    $tree     = is_array($result['tree'] ?? null) ? $result['tree'] : null;
    $treeKids = ($tree !== null && is_array($tree['children'] ?? null)) ? $tree['children'] : [];
    /**
     * Рекурсивный рендер узла дерева крафта (E1.1): суб-крафт = разворачиваемый <details>
     * (своё сырьё + дочерние суб-крафты), лист/цикл = плоская строка. Без JS работает нативно.
     *
     * @var callable(array<string,mixed>): string $renderNode
     */
    $renderNode = function (array $node) use (&$renderNode): string {
        $icon  = (is_string($node['icon'] ?? null) && $node['icon'] !== '') ? $node['icon'] . ' ' : '';
        $label = '<span class="cc-node-label">' . esc($icon . (is_string($node['name'] ?? null) ? $node['name'] : ''))
               . '</span> <span class="cc-node-qty">×' . (int) ($node['qty'] ?? 0) . '</span>';
        $raw   = is_array($node['directRaw'] ?? null) ? $node['directRaw'] : [];
        $kids  = is_array($node['children'] ?? null) ? $node['children'] : [];

        if ($raw === [] && $kids === []) {
            $tag = (bool) ($node['cyclic'] ?? false) ? ' <span class="cc-hint">(повтор выше)</span>' : '';
            return '<div class="cc-leaf">' . $label . $tag . '</div>';
        }

        $body = '';
        if ($raw !== []) {
            $body .= '<ul class="cc-node-raw">';
            foreach ($raw as $r) {
                if (! is_array($r)) { continue; }
                $ri = (is_string($r['icon'] ?? null) && $r['icon'] !== '') ? $r['icon'] . ' ' : '';
                $body .= '<li><span>' . esc($ri . (is_string($r['name'] ?? null) ? $r['name'] : ''))
                       . '</span> <span class="cc-node-qty">×' . (int) ($r['qty'] ?? 0) . '</span>'
                       . ' <span class="cc-rar">R' . (int) ($r['rarity'] ?? 0) . '</span></li>';
            }
            $body .= '</ul>';
        }
        foreach ($kids as $kid) {
            if (is_array($kid)) { $body .= $renderNode($kid); }
        }

        return '<details class="cc-node"><summary>' . $label . '</summary>'
             . '<div class="cc-node-body">' . $body . '</div></details>';
    };
    ?>
    <?php if ($treeKids !== []): ?>
    <h3 style="margin:20px 0 8px">🌳 Дерево крафта <span class="cc-hint">— нажми узел, чтобы развернуть</span></h3>
    <div class="cc-tree">
        <?php foreach ($treeKids as $child): if (! is_array($child)) { continue; } ?>
            <?= $renderNode($child) ?>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <?php if ($unres !== []): ?>
        <p class="cc-note">⚠️ Часть компонентов не удалось развернуть автоматически: <?= esc(implode(', ', array_keys($unres))) ?>.</p>
    <?php endif ?>
</div>
