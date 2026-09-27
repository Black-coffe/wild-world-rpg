<?php
/**
 * W2.N1-03 (ADR-190) — нативное снаряжение на `/play`: веб-рендерер модели
 * {@see \App\Services\Player\EquipmentLoadoutService}, из которой рисуют и экраны «⚔️ Экип» бота.
 *
 * Без Арсенала — замок с объяснением и путём к стройке (кнопка моста), а не ошибка после тапа.
 * Замок — только на «Надеть»: надетую броню и без Арсенала можно снять (`op=unequip`, как в боте).
 * С Арсеналом — оружие и броня, надетое отмечено; «Надеть» / «Снять» — формы POST `/play/view`
 * (`op=equip|unequip`, `intent_id` — повтор не меняет состояние второй раз). Продажа (ADR-165) —
 * кнопка моста. Работает без JS. Telegram/chat id сюда не передаётся.
 *
 * @var array<string, mixed> $loadout
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

use App\Services\Display\OutfitDisplayHelper;
use App\Services\Player\EquipmentLoadoutService;
use App\Services\Telegram\BotMenuService;

$l          = is_array($loadout ?? null) ? $loadout : [];
$lock       = is_array($l['lock'] ?? null) ? $l['lock'] : null;
$onBase     = ($l['on_base'] ?? false) === true;
$saleOn     = ($l['sale_enabled'] ?? false) === true;
$weapons    = is_array($l['weapons'] ?? null) ? $l['weapons'] : [];
$armor      = is_array($l['armor'] ?? null) ? $l['armor'] : [];
$alertText  = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl    = base_url('play/view');
$str        = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;

/** Кнопка моста (callback бота) — форма `op=bridge`. */
$bridge = static function (string $label, string $callback) use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="op" value="bridge">'
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<input type="hidden" name="data" value="' . esc($callback, 'attr') . '">'
        . '<button class="play-kb-btn" type="submit">' . esc($label) . '</button></form>';
};

/** Надеть / снять — нативная смена. */
$change = static function (string $op, string $kind, int $rowId, string $label) use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="gear">'
        . '<input type="hidden" name="op" value="' . esc($op, 'attr') . '">'
        . '<input type="hidden" name="kind" value="' . esc($kind, 'attr') . '">'
        . '<input type="hidden" name="item" value="' . $rowId . '">'
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<button class="play-kb-btn' . ($op === 'equip' ? ' is-primary' : '') . '" type="submit">' . esc($label) . '</button></form>';
};

$equippedNames = static function (array $items): string {
    $names = [];
    foreach ($items as $it) {
        if (is_array($it) && ($it['equipped'] ?? false) === true && is_string($it['name'] ?? null)) {
            $names[] = $it['name'];
        }
    }

    return $names === [] ? '❌ Нет' : implode(', ', $names);
};

$sections = [
    [EquipmentLoadoutService::KIND_WEAPON, '⚔️ Оружие', $weapons, 'Оружия пока нет — его создают крафтом: «' . BotMenuService::menuLabel('craft') . '» в нижнем меню.'],
    [EquipmentLoadoutService::KIND_ARMOR, '👕 Броня / Одежда', $armor, 'Брони пока нет — её создают крафтом: «' . BotMenuService::menuLabel('craft') . '» в нижнем меню.'],
];
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ кнопки</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="gear" aria-labelledby="play-native-gear-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-gear-title">⚔️ Снаряжение</h2>
                <dl class="play-sheet">
                    <div class="play-sheet-row"><dt>⚔️ Оружие</dt><dd><?= esc($equippedNames($weapons)) ?></dd></div>
                    <div class="play-sheet-row"><dt>🛡 Броня</dt><dd><?= esc($equippedNames($armor)) ?></dd></div>
                </dl>
            </header>

            <?php if ($lock !== null): ?>
                <div class="play-lock" data-gear-state="locked">
                    <span class="play-lock-title">🔒 Экипировка (нужно: Арсенал)</span>
                    <span class="play-lock-why">Надевать броню и брать в руки оружие можно только в здании «Арсенал», а на твоей базе его пока нет. Всё скрафтленное никуда не делось: оно ждёт и наденется, как только Арсенал будет построен<?= ($weapons !== [] || $armor !== []) ? ' (ждут: ' . (count($weapons) + count($armor)) . ')' : '' ?>.</span>
                    <span class="play-lock-path">Нужен уровень <?= (int) $lock['required_level'] ?> и базовые здания. Путь: «<?= esc($str($lock['button'] ?? '')) ?>» — там список ресурсов.</span>
                </div>
                <nav class="play-kb" aria-label="Путь к Арсеналу">
                    <div class="play-kb-grid"><?= $bridge($str($lock['button'] ?? ''), $str($lock['callback'] ?? '')) ?></div>
                </nav>
                <?php $wornArmor = array_values(array_filter($armor, static fn (mixed $it): bool => is_array($it) && ($it['equipped'] ?? false) === true && ! is_array($it['soulbound'] ?? null))); ?>
                <?php if ($wornArmor !== []): ?>
                    <section class="play-native-section" aria-label="Надетая броня">
                        <h3 class="play-native-subtitle is-plain">👕 Надето сейчас</h3>
                        <p class="play-native-hint">Снять можно и без Арсенала — надеть обратно получится, когда он будет построен.</p>
                        <ul class="play-gear-list">
                            <?php foreach ($wornArmor as $it): ?>
                                <li class="play-gear-item is-equipped">
                                    <div class="play-gear-head">
                                        <span class="play-gear-name"><?= esc($str($it['name'] ?? '', '???')) ?></span>
                                        <?php if (is_string($it['slot'] ?? null)): ?><span class="badge">Слот: <?= esc($it['slot']) ?></span><?php endif ?>
                                    </div>
                                    <div class="play-kb-grid"><?= $change('unequip', EquipmentLoadoutService::KIND_ARMOR, is_int($it['row_id'] ?? null) ? $it['row_id'] : 0, 'Снять') ?></div>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    </section>
                <?php endif ?>
            <?php else: ?>
                <?php if (! $onBase): ?>
                    <p class="play-native-hint">⚠️ Надеть можно только на базе. Снять — где угодно.</p>
                <?php endif ?>

                <?php foreach ($sections as [$kind, $title, $items, $emptyHint]): ?>
                    <section class="play-native-section" aria-label="<?= esc($title, 'attr') ?>">
                        <h3 class="play-native-subtitle is-plain"><?= esc($title) ?></h3>
                        <?php if ($items === []): ?>
                            <p class="play-inv-empty"><?= esc($emptyHint) ?></p>
                        <?php else: ?>
                            <ul class="play-gear-list">
                                <?php foreach ($items as $it): ?>
                                    <?php
                                    if (! is_array($it)) { continue; }
                                    $info      = is_array($it['info'] ?? null) ? $it['info'] : [];
                                    $rowId     = is_int($it['row_id'] ?? null) ? $it['row_id'] : 0;
                                    $equipped  = ($it['equipped'] ?? false) === true;
                                    $soulbound = is_array($it['soulbound'] ?? null) ? $it['soulbound'] : null;
                                    ?>
                                    <li class="play-gear-item<?= $equipped ? ' is-equipped' : '' ?>">
                                        <div class="play-gear-head">
                                            <span class="play-gear-name"><?= esc($str($it['name'] ?? '', '???')) ?></span>
                                            <span class="play-inv-qty">× <?= is_int($it['quantity'] ?? null) ? $it['quantity'] : 0 ?></span>
                                        </div>
                                        <div class="play-native-tags">
                                            <?php if ($equipped): ?><span class="badge is-on">✅ Надето</span><?php endif ?>
                                            <?php if ($soulbound !== null): ?><span class="badge">🔒 Метка пустоши</span><?php endif ?>
                                            <?php if ($kind === EquipmentLoadoutService::KIND_ARMOR && is_string($it['slot'] ?? null)): ?><span class="badge">Слот: <?= esc($it['slot']) ?></span><?php endif ?>
                                            <span class="badge">Редкость: <?= esc($str($info['rarity'] ?? '', 'Common')) ?></span>
                                        </div>
                                        <p class="play-gear-stats">
                                            <?php if ($kind === EquipmentLoadoutService::KIND_WEAPON): ?>
                                                💥 Урон <?= esc($str($info['damage_value'] ?? '', '0')) ?> (<?= esc($str($info['damage_type'] ?? '', 'physical')) ?>) · 🎯 Дальность <?= esc($str($info['range_value'] ?? '', '—')) ?> · ⚡ Скорость атаки <?= esc($str($info['attack_speed'] ?? '', '—')) ?> · ⚙️ Прочность <?= esc($str($info['durability_max'] ?? '', '100')) ?>
                                            <?php else: ?>
                                                🛡 Защита <?= esc($str($info['armor_value'] ?? '', '0')) ?> · Тип <?= esc($str($info['armor_type'] ?? '', 'Обычная')) ?> · 🏋️ Вес <?= esc($str($info['weight'] ?? '', '0')) ?> кг · 🔧 Прочность <?= esc($str($info['durability_max'] ?? '', '100')) ?>
                                                <?php foreach (OutfitDisplayHelper::resistanceLines($info) as $res): ?><br><?= esc($res) ?><?php endforeach ?>
                                            <?php endif ?>
                                        </p>
                                        <?php if ($soulbound !== null): ?>
                                            <p class="play-native-hint">Трофей с узла <?= esc($soulbound['source'] ?? '') ?> (L<?= (int) ($soulbound['level'] ?? 0) ?>): усиливает только против узлов, не надевается и не продаётся.</p>
                                        <?php endif ?>
                                        <div class="play-kb-grid">
                                            <?php if ($soulbound === null && $equipped): ?>
                                                <?= $change('unequip', $kind, $rowId, 'Снять') ?>
                                            <?php elseif ($soulbound === null && $onBase): ?>
                                                <?= $change('equip', $kind, $rowId, 'Надеть') ?>
                                            <?php endif ?>
                                            <?php if ($soulbound === null && $saleOn && ! $equipped): ?>
                                                <?= $bridge('💰 Продать торговцу', ($kind === EquipmentLoadoutService::KIND_WEAPON ? 'sellGearItem_w_' : 'sellGearItem_a_') . $rowId) ?>
                                            <?php endif ?>
                                        </div>
                                        <?php if ($soulbound === null && $saleOn && $equipped): ?>
                                            <p class="play-native-hint">💰 Продать можно только снятое.</p>
                                        <?php endif ?>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        <?php endif ?>
                    </section>
                <?php endforeach ?>
            <?php endif ?>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
