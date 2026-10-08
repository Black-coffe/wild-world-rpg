<?php
/**
 * w2-n6-trade-storage-03 (ADR-190) — нативный экран «🛒 Магазин» на `/play`: веб-рендерер моделей
 * {@see \App\Services\Player\Trade\ResourceShopScreenService} — тех же, из которых рисует бот.
 *
 * Разделы — формы POST `/play/view` (`view=shop`, `section`, `r`, `id`, `pct`), без JS — PRG. Карточка ресурса:
 * цена за 1 шт., итоги пресетов до потолка и поле «своё число» (1…потолок) — `op=sell|buy` со своим `intent_id`;
 * цена видна до сделки. Опт: превью из ядра и подтверждение `op=bulk_sell` с отпечатком плана (`token`).
 * «Продать/Купить крафт» — мост. Покупка без золота на минимум и выключенный опт — замок с путём.
 * Всё текстом, без картинок; id персонажа в разметку не идут.
 *
 * @var array<string, mixed> $shop
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

$m         = is_array($shop ?? null) ? $shop : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$section   = is_string($m['section'] ?? null) ? $m['section'] : 'hub';
$rarity    = is_int($m['rarity'] ?? null) ? $m['rarity'] : null;
$card      = is_array($m['card'] ?? null) ? $m['card'] : null;
$bulk      = is_array($m['bulk'] ?? null) ? $m['bulk'] : null;
$bulkOn    = ($m['bulk_on'] ?? false) === true;
$arr       = static fn (string $key): ?array => is_array($m[$key] ?? null) ? $m[$key] : null;
$str       = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;
$int       = static fn (mixed $v): int => is_int($v) ? $v : (is_float($v) ? (int) $v : 0);
$num       = static fn (mixed $v): string => number_format(is_numeric($v) ? (float) $v : 0.0, 0, '.', ' ');

/** Скрытые поля навигации магазина. */
$fields = static function (array $nav): string {
    $out = '<input type="hidden" name="view" value="shop">';
    foreach (['section', 'r', 'id', 'pct'] as $f) {
        if (isset($nav[$f]) && (is_int($nav[$f]) || is_string($nav[$f]))) {
            $out .= '<input type="hidden" name="' . $f . '" value="' . esc((string) $nav[$f], 'attr') . '">';
        }
    }

    return $out;
};

/** Переход по разделам — форма `view=shop`. */
$go = static function (array $nav, string $text, string $class = 'play-kb-btn') use ($viewUrl, $fields): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field() . $fields($nav)
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

/** Сделка — форма с `op`, навигацией, своими полями и своим `intent_id`. */
$act = static function (string $op, array $nav, array $extra, string $text, string $class = 'play-kb-btn') use ($viewUrl, $fields): string {
    $hidden = '';
    foreach ($extra as $name => $value) {
        $hidden .= '<input type="hidden" name="' . esc((string) $name, 'attr') . '" value="' . esc((string) $value, 'attr') . '">';
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field() . $fields($nav)
        . '<input type="hidden" name="op" value="' . esc($op, 'attr') . '">' . $hidden
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

/** Кнопка бота без нативного экрана — мост с той же `callback_data`. */
$bridge = static function (string $data, string $text) use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="op" value="bridge"><input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<input type="hidden" name="data" value="' . esc($data, 'attr') . '">'
        . '<button class="play-kb-btn" type="submit">' . esc($text) . '</button></form>';
};

$lock = static function (string $key, string $title, string $why, string $path): string {
    return '<div class="play-lock" data-shop-lock="' . esc($key, 'attr') . '">'
        . '<span class="play-lock-title">' . esc($title) . '</span>'
        . '<span class="play-lock-why">' . esc($why) . '</span>'
        . '<span class="play-lock-path">Путь: ' . esc($path) . '</span></div>';
};

/** Доли опта — кнопки превью (сделки ещё нет). */
$bulkRow = static function (array $percents, ?int $r) use ($go, $int): string {
    $out = '';
    foreach ($percents as $p) {
        $p    = $int($p);
        $nav  = ['section' => 'bulk', 'pct' => $p] + ($r !== null ? ['r' => $r] : []);
        $out .= $go($nav, $p >= 100 ? '🧺 Всё' : "💰 {$p}%");
    }

    return $out;
};

$crumbs = [
    'hub'         => 'Магазин',
    'sell'        => 'Магазин → Продать ресы',
    'sell_rarity' => 'Магазин → Продать ресы → редкость ' . ($rarity ?? ''),
    'sell_card'   => 'Магазин → Продать ресы → ' . $str($card['name'] ?? ''),
    'buy'         => 'Магазин → Купить ресы',
    'buy_rarity'  => 'Магазин → Купить ресы → редкость ' . ($rarity ?? ''),
    'buy_card'    => 'Магазин → Купить ресы → ' . $str($card['name'] ?? ''),
    'bulk'        => 'Магазин → Продать ресы → опт',
];
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ торговца</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="shop" aria-labelledby="play-native-shop-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-shop-title">🛒 Магазин</h2>
                <p class="play-craft-crumbs"><?= esc($crumbs[$section] ?? 'Магазин') ?></p>
            </header>

            <nav class="play-kb" aria-label="Разделы магазина">
                <div class="play-kb-grid">
                    <?php foreach (is_array($m['hub'] ?? null) ? $m['hub'] : [] as $entry): ?>
                        <?php if (! is_array($entry)) { continue; } $key = $str($entry['key'] ?? ''); ?>
                        <?php if (($entry['native'] ?? false) === true): ?>
                            <?= $go(['section' => $key], $str($entry['label'] ?? ''), 'play-kb-btn' . (str_starts_with($section, $key) ? ' is-primary' : '')) ?>
                        <?php else: ?>
                            <?= $bridge($key, $str($entry['label'] ?? '')) ?>
                        <?php endif ?>
                    <?php endforeach ?>
                </div>
            </nav>

            <?php if ($section === 'hub'): ?>
                <p class="play-native-hint">Торговец берёт и продаёт сырьё по редкости; цена за штуку видна до сделки. Крафт и снаряжение у торговца открываются через экран бота.</p>

            <?php elseif ($section === 'sell' && ($hub = $arr('sell_hub')) !== null): ?>
                <section class="play-native-section" aria-label="Продать ресурсы">
                    <h3 class="play-native-subtitle is-plain">💰 Продать ресы</h3>
                    <dl class="play-craft-facts">
                        <div><dt>📦 Видов в рюкзаке</dt><dd><?= $int($hub['types'] ?? 0) ?></dd></div>
                        <div><dt>💰 Стоят всего</dt><dd>~<?= $num($hub['total_value'] ?? 0) ?> 💰</dd></div>
                    </dl>
                    <p class="play-native-hint">Выбери редкость — увидишь ресурсы, их количество и цену.</p>
                    <div class="play-kb-grid">
                        <?php foreach (is_array($hub['rarities'] ?? null) ? $hub['rarities'] : [] as $r): ?>
                            <?= $go(['section' => 'sell_rarity', 'r' => $int($r)], 'Редкость ' . $int($r)) ?>
                        <?php endforeach ?>
                    </div>
                </section>
                <section class="play-native-section" aria-label="Оптовая продажа">
                    <h3 class="play-native-subtitle is-plain">🧺 Оптом — доля всех ресурсов</h3>
                    <?php $percents = is_array($hub['bulk'] ?? null) ? $hub['bulk'] : []; ?>
                    <?php if (! $bulkOn): ?>
                        <?= $lock('bulk', '🔒 Оптовая продажа (нужно: раздел включат на сервере)', 'Опт сейчас выключен — и в боте, и здесь. Продавать можно по одному ресурсу через редкость.', 'Магазин → Продать ресы → редкость → ресурс') ?>
                    <?php elseif ($percents === []): ?>
                        <p class="play-native-hint">Продавать оптом нечего: в рюкзаке нет ресурсов с ценой больше 0.</p>
                    <?php else: ?>
                        <p class="play-native-hint">Доля берётся от каждого запаса. Сначала покажем, что и за сколько уйдёт, — сделка только после подтверждения.</p>
                        <div class="play-kb-grid"><?= $bulkRow($percents, null) ?></div>
                    <?php endif ?>
                </section>

            <?php elseif ($section === 'sell_rarity' && ($list = $arr('sell_rarity')) !== null): ?>
                <?php $rows = is_array($list['rows'] ?? null) ? $list['rows'] : []; ?>
                <section class="play-native-section" aria-label="Ресурсы редкости <?= (int) $rarity ?>">
                    <h3 class="play-native-subtitle is-plain">💰 Редкость <?= (int) $rarity ?></h3>
                    <?php if (($list['known'] ?? false) !== true): ?>
                        <p class="play-native-hint">Ресурсов редкости <?= (int) $rarity ?> в игре нет.</p>
                    <?php elseif ($rows === []): ?>
                        <p class="play-native-hint">У тебя нет ресурсов редкости <?= (int) $rarity ?>. Добыча — на клетках мира: «🌍 Мир» → клетка → действие.</p>
                    <?php else: ?>
                        <ul class="play-craft-reqs" aria-label="Ресурс: сколько есть и сколько стоит всё">
                            <?php foreach ($rows as $row): ?>
                                <?php if (! is_array($row)) { continue; } ?>
                                <li class="play-craft-req"><span class="play-craft-req-name"><?= esc($str($row['name'] ?? '')) ?> — <?= $int($row['quantity'] ?? 0) ?> шт.</span><span class="play-craft-req-qty">~<?= $num($row['total'] ?? 0) ?> 💰</span></li>
                            <?php endforeach ?>
                        </ul>
                        <div class="play-kb-grid">
                            <?php foreach ($rows as $row): ?>
                                <?php if (! is_array($row)) { continue; } ?>
                                <?= $go(['section' => 'sell_card', 'id' => $int($row['resource_id'] ?? 0)], '💰 ' . $str($row['name'] ?? '')) ?>
                            <?php endforeach ?>
                        </div>
                        <?php $percents = is_array($list['bulk'] ?? null) ? $list['bulk'] : []; ?>
                        <?php if ($percents !== []): ?>
                            <h3 class="play-native-subtitle is-plain">🧺 Оптом — редкость <?= (int) $rarity ?></h3>
                            <div class="play-kb-grid"><?= $bulkRow($percents, $rarity) ?></div>
                        <?php endif ?>
                    <?php endif ?>
                    <div class="play-kb-grid"><?= $go(['section' => 'sell'], '⬅️ К редкостям') ?></div>
                </section>

            <?php elseif (($section === 'sell_card' || $section === 'buy_card') && $card !== null): ?>
                <?php
                $selling = $section === 'sell_card';
                $op      = $selling ? 'sell' : 'buy';
                $maxQty  = $int($card['max_qty'] ?? 0);
                $resId   = $int($card['resource_id'] ?? 0);
                $nav     = ['section' => $section, 'id' => $resId];
                $presets = array_values(array_filter(
                    is_array($card['presets'] ?? null) ? $card['presets'] : [],
                    static fn (mixed $p): bool => is_array($p) && $int($p['qty'] ?? 0) <= $maxQty
                ));
                $presetQty = array_map(static fn (array $p): int => $int($p['qty'] ?? 0), $presets);
                $need      = is_array($card['need'] ?? null) ? $card['need'] : null;
                ?>
                <section class="play-craft-card" data-shop-card="<?= $op ?>" aria-labelledby="play-shop-card-title">
                    <h3 class="play-craft-card-title" id="play-shop-card-title"><?= $selling ? '💰' : '🛍️' ?> <?= esc($str($card['name'] ?? '', 'Ресурс')) ?></h3>
                    <dl class="play-craft-facts">
                        <div><dt><?= $selling ? '💰 Торговец платит за 1 шт.' : '💰 Цена за 1 шт.' ?></dt><dd><?= esc($str($card['unit_text'] ?? '', '0')) ?> 💰</dd></div>
                        <div><dt>⭐ Редкость</dt><dd><?= $int($card['rarity'] ?? 0) ?></dd></div>
                        <?php if ($selling): ?>
                            <div><dt>🎒 У тебя</dt><dd><?= $maxQty ?> шт.</dd></div>
                        <?php else: ?>
                            <div><dt>💰 Золото</dt><dd><?= $num($card['gold'] ?? 0) ?></dd></div>
                            <div><dt>📦 Хватит на</dt><dd><?= $maxQty ?> шт.</dd></div>
                        <?php endif ?>
                    </dl>
                    <?php if ($maxQty < 1): ?>
                        <p class="play-native-hint"><?= $selling
                            ? 'Этого ресурса у тебя нет — продавать нечего.'
                            : 'Золота не хватает даже на 1 шт. Продай что-нибудь и возвращайся.' ?></p>
                    <?php else: ?>
                        <?php if ($need !== null && $int($need['qty'] ?? 0) <= $maxQty): ?>
                            <div class="play-kb-grid">
                                <?= $act($op, $nav, ['qty' => $int($need['qty'] ?? 0)], '🛍️ Сколько не хватает: ' . $int($need['qty'] ?? 0) . ' шт · ' . $num($need['total'] ?? 0) . ' 💰', 'play-kb-btn is-primary') ?>
                            </div>
                        <?php endif ?>
                        <p class="play-native-hint">Кнопка — сколько штук и сколько это <?= $selling ? 'принесёт' : 'стоит' ?>. Итог считает торговец в момент сделки.</p>
                        <div class="play-kb-grid">
                            <?php foreach ($presets as $p): ?>
                                <?= $act($op, $nav, ['qty' => $int($p['qty'] ?? 0)], $int($p['qty'] ?? 0) . ' шт · ' . $num($p['total'] ?? 0) . ' 💰') ?>
                            <?php endforeach ?>
                            <?php if ($selling && ! in_array($maxQty, $presetQty, true)): ?>
                                <?= $act($op, $nav, ['qty' => $maxQty], '🧺 Всё — ' . $maxQty . ' шт', 'play-kb-btn is-primary') ?>
                            <?php endif ?>
                        </div>
                        <form class="play-craft-qty" action="<?= esc($viewUrl, 'attr') ?>" method="post">
                            <?= csrf_field() ?><?= $fields($nav) ?>
                            <input type="hidden" name="op" value="<?= $op ?>">
                            <input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>">
                            <label class="play-craft-qty-label" for="play-shop-qty">Своё число, 1–<?= $maxQty ?></label>
                            <input class="play-craft-qty-input" id="play-shop-qty" name="qty" type="number" inputmode="numeric" min="1" max="<?= $maxQty ?>" step="1" value="1" required>
                            <button class="play-kb-btn" type="submit"><?= $selling ? '💰 Продать' : '🛍️ Купить' ?></button>
                        </form>
                    <?php endif ?>
                    <div class="play-kb-grid">
                        <?= $go(['section' => $selling ? 'sell_rarity' : 'buy_rarity', 'r' => $int($card['rarity'] ?? 1)], '⬅️ К редкости ' . $int($card['rarity'] ?? 1)) ?>
                    </div>
                </section>

            <?php elseif ($section === 'buy' && ($hub = $arr('buy_hub')) !== null): ?>
                <section class="play-native-section" aria-label="Купить ресурсы">
                    <h3 class="play-native-subtitle is-plain">🛍️ Купить ресы</h3>
                    <dl class="play-craft-facts">
                        <div><dt>💰 Золото</dt><dd><?= $num($hub['gold'] ?? 0) ?></dd></div>
                    </dl>
                    <?php if (($hub['allowed'] ?? false) !== true): ?>
                        <?= $lock('buy', '🔒 Покупка у торговца (нужно: от ' . $int($hub['min_gold'] ?? 0) . ' золота)', 'Торговец не возится с мелочью: с меньшим запасом золота он не продаёт. Золото дают продажа сырья, задания и квесты.', 'Магазин → 💰 Продать ресы') ?>
                    <?php else: ?>
                        <p class="play-native-hint">Выбери редкость — увидишь, что продаёт торговец и почём.</p>
                        <div class="play-kb-grid">
                            <?php foreach (is_array($hub['rarities'] ?? null) ? $hub['rarities'] : [] as $r): ?>
                                <?= $go(['section' => 'buy_rarity', 'r' => $int($r)], 'Редкость ' . $int($r)) ?>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                </section>

            <?php elseif ($section === 'buy_rarity' && ($list = $arr('buy_rarity')) !== null): ?>
                <?php $rows = is_array($list['rows'] ?? null) ? $list['rows'] : []; ?>
                <section class="play-native-section" aria-label="Витрина редкости <?= (int) $rarity ?>">
                    <h3 class="play-native-subtitle is-plain">🛍️ Редкость <?= (int) $rarity ?></h3>
                    <?php if ($rows === []): ?>
                        <p class="play-native-hint">Ресурсов редкости <?= (int) $rarity ?> торговец не продаёт.</p>
                    <?php else: ?>
                        <div class="play-kb-grid">
                            <?php foreach ($rows as $row): ?>
                                <?php if (! is_array($row)) { continue; } ?>
                                <?= $go(['section' => 'buy_card', 'id' => $int($row['resource_id'] ?? 0)], $str($row['name'] ?? '') . ' — ' . $str($row['price_text'] ?? '0') . ' 💰/шт') ?>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                    <div class="play-kb-grid"><?= $go(['section' => 'buy'], '⬅️ К редкостям') ?></div>
                </section>

            <?php elseif ($section === 'bulk' && $bulk !== null): ?>
                <?php
                $pct   = $int($bulk['percent'] ?? 0);
                $bR    = is_int($bulk['rarity'] ?? null) ? $bulk['rarity'] : null;
                $scope = $bR === null ? 'всех ресурсов' : "ресурсов редкости {$bR}";
                $back  = $bR === null ? ['section' => 'sell'] : ['section' => 'sell_rarity', 'r' => $bR];
                $code  = $str($bulk['code'] ?? '');
                ?>
                <section class="play-craft-card" data-shop-bulk aria-labelledby="play-shop-bulk-title">
                    <h3 class="play-craft-card-title" id="play-shop-bulk-title">🧺 Оптом — <?= esc($pct >= 100 ? "ВСЁ, {$scope}" : "{$pct}% {$scope}") ?></h3>
                    <?php if ($code === 'ok'): ?>
                        <dl class="play-craft-facts">
                            <div><dt>📦 Будет продано</dt><dd><?= $int($bulk['types'] ?? 0) ?> вид(ов), <?= $num($bulk['qty'] ?? 0) ?> ед.</dd></div>
                            <div><dt>💰 Примерная выручка</dt><dd>~<?= $num($bulk['gold'] ?? 0) ?> 💰</dd></div>
                        </dl>
                        <div class="play-native-note"><?= $pct >= 100
                            ? 'Будут проданы все ходовые ресурсы в этом объёме. Реальная цена может отличаться от спроса. Действие необратимо.'
                            : 'Доля берётся от каждого запаса. Реальная цена может отличаться от спроса. Действие необратимо.' ?></div>
                        <div class="play-kb-grid">
                            <?= $act('bulk_sell', ['section' => 'bulk', 'pct' => $pct] + ($bR !== null ? ['r' => $bR] : []), ['token' => $str($bulk['token'] ?? '')], $pct >= 100 ? '✅ Да, продать всё' : "✅ Да, продать {$pct}%", 'play-kb-btn is-primary') ?>
                            <?= $go($back, '⬅️ Назад') ?>
                        </div>
                    <?php else: ?>
                        <p class="play-native-hint"><?= $code === 'disabled'
                            ? 'Оптовая продажа временно недоступна.'
                            : "Продавать по {$pct}% {$scope} сейчас нечего — нет ходовых ресурсов в достаточном объёме. Оптом продаются только ресурсы с ценой больше 0; при малой доле мелкие стопки могут давать 0 единиц." ?></p>
                        <div class="play-kb-grid"><?= $go($back, '⬅️ Назад') ?></div>
                    <?php endif ?>
                </section>
            <?php endif ?>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
