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
$subs  = is_array($result['subCrafts'] ?? null) ? $result['subCrafts'] : [];
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

    <?php if ($subs !== []): ?>
    <h3 style="margin:20px 0 4px">Промежуточные крафты</h3>
    <ul class="cc-subs">
        <?php foreach ($subs as $s): if (! is_array($s)) { continue; } ?>
            <li>
                <?= esc((is_string($s['icon'] ?? null) && $s['icon'] !== '' ? $s['icon'] . ' ' : '') . (is_string($s['name'] ?? null) ? $s['name'] : '')) ?>
                <span class="n">×<?= (int) ($s['qty'] ?? 0) ?></span>
            </li>
        <?php endforeach ?>
    </ul>
    <?php endif ?>

    <?php if ($unres !== []): ?>
        <p class="cc-note">⚠️ Часть компонентов не удалось развернуть автоматически: <?= esc(implode(', ', array_keys($unres))) ?>.</p>
    <?php endif ?>
</div>
