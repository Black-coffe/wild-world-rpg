<?php
/**
 * W2.N1-02 (ADR-190) — нативный инвентарь на `/play`: веб-рендерер модели инвентаря
 * ({@see \App\Services\Player\InventoryViewService}), из которой бот берёт списки «Добытые» и
 * «Крафтовые». Всё, что несёт персонаж, — одним списком по полкам.
 *
 * Без JS: полки идут подряд, вкладки — якоря к полкам (переход к разделу). С JS
 * (`wildworld-play.js`): вкладка фильтрует список на месте, поиск по имени фильтрует строки.
 * Склад базы, «Куда ушло», «Все мои ресурсы» — кнопки моста (`op=bridge`). Telegram/chat id
 * сюда не передаётся.
 *
 * @var array<string, mixed> $inventory
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

use App\Services\Player\InventoryViewService;
use App\Services\Telegram\BotMenuService;
use App\Services\Web\WebNativeScreenService;

$inv        = is_array($inventory ?? null) ? $inventory : [];
$items      = is_array($inv['items'] ?? null) ? $inv['items'] : [];
$categories = is_array($inv['categories'] ?? null) ? $inv['categories'] : [];
$total      = is_float($inv['total_value'] ?? null) || is_int($inv['total_value'] ?? null) ? (float) $inv['total_value'] : 0.0;
$alertText  = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl    = base_url('play/view');

$byCategory = [];
foreach ($items as $item) {
    if (is_array($item) && is_string($item['category'] ?? null)) {
        $byCategory[$item['category']][] = $item;
    }
}
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ кнопки</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="inventory" data-inv aria-labelledby="play-native-inv-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-inv-title">🎒 Инвентарь</h2>
                <?php if ($items !== []): ?>
                    <div class="play-native-tags">
                        <span class="badge">Позиций: <?= count($items) ?></span>
                        <span class="badge">💰 Добытое ~ <?= esc(number_format($total, 0, '.', ' ')) ?></span>
                    </div>
                <?php endif ?>
            </header>

            <?php if ($items === []): ?>
                <div class="play-lock" data-inv-state="empty">
                    <span class="play-lock-title">Рюкзак пуст</span>
                    <span class="play-lock-why">Здесь появится всё, что ты несёшь: добытые ресурсы и созданные предметы.</span>
                    <span class="play-lock-path">Ресурсы: «<?= esc(BotMenuService::menuLabel('me')) ?>» → «🧑‍🌾 Действия 🛠️» → добыча на клетке. Предметы: «<?= esc(BotMenuService::menuLabel('craft')) ?>» в нижнем меню.</span>
                </div>
            <?php else: ?>
                <div class="play-inv-search" data-inv-search-row hidden>
                    <label class="label" for="play-inv-q">Поиск по названию</label>
                    <input class="input" id="play-inv-q" type="search" data-inv-search autocomplete="off" placeholder="Например, металл">
                </div>

                <nav class="play-inv-tabs" aria-label="Полки инвентаря">
                    <a class="play-inv-tab is-active" href="#play-inv-all" data-inv-tab="all" aria-current="true">Все <span class="play-inv-count"><?= count($items) ?></span></a>
                    <?php foreach ($categories as $cat): ?>
                        <?php if (! is_array($cat) || ! is_string($cat['key'] ?? null)) { continue; } ?>
                        <a class="play-inv-tab" href="#play-inv-<?= esc($cat['key'], 'attr') ?>" data-inv-tab="<?= esc($cat['key'], 'attr') ?>"><?= esc((is_string($cat['emoji'] ?? null) ? $cat['emoji'] . ' ' : '') . (is_string($cat['title'] ?? null) ? $cat['title'] : '')) ?> <span class="play-inv-count"><?= is_int($cat['count'] ?? null) ? $cat['count'] : 0 ?></span></a>
                    <?php endforeach ?>
                </nav>

                <div id="play-inv-all" class="play-inv-shelves">
                    <?php foreach ($categories as $cat): ?>
                        <?php if (! is_array($cat) || ! is_string($cat['key'] ?? null)) { continue; } ?>
                        <?php $key = $cat['key']; $rows = $byCategory[$key] ?? []; ?>
                        <section class="play-inv-shelf" id="play-inv-<?= esc($key, 'attr') ?>" data-inv-cat="<?= esc($key, 'attr') ?>" aria-label="<?= esc(is_string($cat['title'] ?? null) ? $cat['title'] : '', 'attr') ?>">
                            <h3 class="play-native-subtitle is-plain"><?= esc((is_string($cat['emoji'] ?? null) ? $cat['emoji'] . ' ' : '') . (is_string($cat['title'] ?? null) ? $cat['title'] : '')) ?></h3>
                            <ul class="play-inv-list">
                                <?php $hasFood = false; ?>
                                <?php foreach ($rows as $item): ?>
                                    <?php $name = is_string($item['name'] ?? null) ? $item['name'] : ''; $food = ($item['food_unused'] ?? false) === true; $hasFood = $hasFood || $food; ?>
                                    <li class="play-inv-item" data-inv-name="<?= esc(mb_strtolower($name), 'attr') ?>">
                                        <span class="play-inv-name"><?= esc($name) ?></span>
                                        <span class="play-inv-qty">× <?= esc(number_format(is_int($item['quantity'] ?? null) ? $item['quantity'] : 0, 0, '.', ' ')) ?></span>
                                        <?php if (($item['kind'] ?? null) === InventoryViewService::KIND_RESOURCE && is_string($item['rarity'] ?? null) && $item['rarity'] !== ''): ?>
                                            <span class="play-inv-meta">Редкость <?= esc($item['rarity']) ?></span>
                                        <?php endif ?>
                                        <?php if ($food): ?>
                                            <span class="play-inv-meta is-warning"><?= esc(InventoryViewService::FOOD_MARKER) ?></span>
                                        <?php endif ?>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                            <?php if ($hasFood): ?>
                                <p class="play-native-hint"><?= esc(InventoryViewService::FOOD_PATH_LINE) ?></p>
                            <?php endif ?>
                        </section>
                    <?php endforeach ?>
                    <p class="play-inv-empty" data-inv-nothing hidden>Ничего не найдено на этой полке.</p>
                </div>
            <?php endif ?>

            <nav class="play-kb" aria-label="Ещё об инвентаре">
                <div class="play-kb-grid">
                    <?php foreach (WebNativeScreenService::inventoryBridgeButtons() as $label => $callback): ?>
                        <form action="<?= esc($viewUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="op" value="bridge"><input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="data" value="<?= esc($callback, 'attr') ?>"><button class="play-kb-btn" type="submit"><?= esc($label) ?></button></form>
                    <?php endforeach ?>
                </div>
            </nav>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
