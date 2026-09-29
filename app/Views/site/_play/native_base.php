<?php
/**
 * W2.N4-03 (ADR-190) — нативный экран «🏠 База» на `/play`: веб-рендерер ядра базы, того же, из которого
 * рисует бот — {@see \App\Services\Bases\BaseScreenService} (какая база, обзор, визит),
 * {@see \App\Services\Buildings\BuildOrderService} (что можно построить, карточка, старт) и
 * {@see \App\Services\Buildings\BuildingUpgradeService} (превью и апгрейд).
 *
 * Навигация — формы POST `/play/view` (`view=base`, `b`, `section`, `key`, `id`), без JS — PRG. Пикер: базы под
 * сигналом своей Вышки — кнопками, остальные — строкой с расстоянием. Обзор: клетка, биом, на базе или под
 * сигналом Вышки, срок, налог, постройки со стопками (×N), у каждой — «⬆️ Улучшить» и карточка в боте (мост).
 * «Что можно построить»: запертое — замок «🔒 Название (нужно: уровень N)» с путём. Карточка: есть / нужно,
 * время, уровень, описание; хватает — «Строить» (`op=build_start`), нет — экран нехватки бота (мост). Кнопки
 * экрана базы бота без нативного экрана (маяки, ангар, декор, склад, телепорт, снос, развитие) — мост с той
 * же `callback_data`. Всё читается без картинок; id персонажа в разметку не идут.
 *
 * @var array<string, mixed> $base
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

$m         = is_array($base ?? null) ? $base : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$state     = is_string($m['state'] ?? null) ? $m['state'] : '';
$baseId    = is_int($m['base_id'] ?? null) ? $m['base_id'] : 0;
$section   = is_string($m['section'] ?? null) ? $m['section'] : 'overview';
$ov        = is_array($m['overview'] ?? null) ? $m['overview'] : null;
$info      = is_array($ov['base'] ?? null) ? $ov['base'] : [];
$coverage  = is_array($ov['coverage'] ?? null) ? $ov['coverage'] : null;
$buildings = is_array($ov['buildings'] ?? null) ? $ov['buildings'] : [];
$bridge    = is_array($m['bridge'] ?? null) ? $m['bridge'] : [];
$str       = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;
$int       = static fn (mixed $v): int => is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
$plain     = static fn (string $text): string => str_replace(['*', '_'], '', $text);

/** Переход по экрану базы — форма `view=base` с нужным разделом. */
$go = static function (array $to, string $text, string $class) use ($viewUrl): string {
    $hidden = '';
    foreach (['b', 'section', 'key', 'id'] as $field) {
        if (isset($to[$field]) && is_scalar($to[$field])) {
            $hidden .= '<input type="hidden" name="' . $field . '" value="' . esc((string) $to[$field], 'attr') . '">';
        }
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="base">' . $hidden
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

/** Мутация базы — форма с `op` и своим `intent_id`. */
$act = static function (string $op, array $fields, string $text, string $class) use ($viewUrl): string {
    $hidden = '<input type="hidden" name="view" value="base"><input type="hidden" name="op" value="' . esc($op, 'attr') . '">';
    foreach ($fields as $name => $value) {
        $hidden .= '<input type="hidden" name="' . esc((string) $name, 'attr') . '" value="' . esc((string) $value, 'attr') . '">';
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field() . $hidden
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

/** Кнопка экрана бота через мост (`op=bridge`) — та же `callback_data`, что в Telegram. */
$bridgeBtn = static function (string $data, string $text, string $class = 'play-kb-btn') use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="op" value="bridge"><input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<input type="hidden" name="data" value="' . esc($data, 'attr') . '"><button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

$home = ['b' => $baseId];
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ кнопки</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="base" aria-labelledby="play-native-base-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-base-title">🏠 База<?= $str($info['decor']['name'] ?? null) !== '' ? ' · ' . esc(trim($str($info['decor']['flag'] ?? null) . ' ' . $str($info['decor']['name']))) : '' ?></h2>
            </header>

            <?php if ($state === 'picker'): ?>
                <?php $pickable = array_values(array_filter(is_array($m['bases'] ?? null) ? $m['bases'] : [], static fn (mixed $b): bool => is_array($b) && ($b['covered'] ?? false) === true)); ?>
                <p class="play-native-hint">
                    Активных баз: <?= count(is_array($m['bases'] ?? null) ? $m['bases'] : []) ?>.
                    <?= $pickable !== [] ? 'Под сигналом Вышки связи несколько баз — выбери, с какой работать.' : 'Ни одна база сейчас не под сигналом своей Вышки связи — встань на базу или подойди под сигнал.' ?>
                </p>
                <ul class="play-base-picker">
                    <?php foreach (is_array($m['bases'] ?? null) ? $m['bases'] : [] as $b): ?>
                        <?php if (! is_array($b)) { continue; } ?>
                        <li class="play-base-pick<?= ($b['covered'] ?? false) === true ? '' : ' is-far' ?>">
                            <?php if (($b['covered'] ?? false) === true): ?>
                                <?= $go(['b' => $int($b['id'] ?? 0)], '🏠 ' . $str($b['name'] ?? null, 'База') . ' (X=' . $int($b['x'] ?? 0) . ', Y=' . $int($b['y'] ?? 0) . ')', 'play-kb-btn') ?>
                                <span class="play-base-pick-meta">под сигналом Вышки</span>
                            <?php else: ?>
                                <span class="play-base-pick-name">▫️ <?= esc($str($b['name'] ?? null, 'База')) ?> (X=<?= $int($b['x'] ?? 0) ?>, Y=<?= $int($b['y'] ?? 0) ?>)</span>
                                <span class="play-base-pick-meta">расстояние: <?= $int($b['distance'] ?? -1) >= 0 ? $int($b['distance']) . ' ходов' : 'неизвестно' ?></span>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
                <div class="play-kb-grid"><?= $bridgeBtn('TeleportToCamp', '📡 Телепорт на базу') ?></div>

            <?php elseif ($state === 'no_base'): ?>
                <p class="play-native-note">У тебя нет базы. База — твой дом: на ней строятся Склад, Теплица, Мастерская и другие постройки. Выбери клетку с подходящим биомом и разбей лагерь — экран бота покажет клетку и спросит подтверждение.</p>
                <div class="play-kb-grid"><?= $bridgeBtn('Camp', '🏕 Разбить лагерь') ?></div>

            <?php elseif ($state === 'far'): ?>
                <p class="play-native-note">Твоя база в другой клетке, и сигнал Вышки связи туда не дотягивается. Чтобы строить и управлять базой, вернись: дойди по карте или телепортируйся.</p>
                <?php if ($info !== []): ?>
                    <dl class="play-craft-facts">
                        <div><dt>📍 Координаты базы</dt><dd>X=<?= $int($info['x'] ?? 0) ?> Y=<?= $int($info['y'] ?? 0) ?></dd></div>
                        <div><dt>🌍 Биом</dt><dd><?= esc($str($info['biome'] ?? null, '???')) ?></dd></div>
                    </dl>
                <?php endif ?>
                <div class="play-kb-grid"><?= $bridgeBtn('TeleportToCamp', '📡 Телепорт') ?><?= $bridgeBtn('teleportBeacon', '📡 Маяки') ?></div>

            <?php elseif ($state !== 'base' || $ov === null): ?>
                <p class="play-native-note"><?= esc($str($m['text'] ?? null, 'Эта база сейчас недоступна.')) ?></p>
                <div class="play-kb-grid"><?= $go([], '🏠 Открыть базу заново', 'play-kb-btn') ?></div>

            <?php else: ?>
                <?php $daysLeft = $info['days_left'] ?? null; ?>
                <?php if ($coverage !== null && ($coverage['covered'] ?? false) === true): ?>
                    <p class="play-native-note">Ты не на базе, но сигнал Вышки связи (ур. <?= $int($coverage['tower_level'] ?? 0) ?>) покрывает <?= $int($coverage['distance'] ?? 0) ?> из <?= $int($coverage['max'] ?? 0) ?> ходов — базой можно управлять дистанционно. Строить и улучшать можно, только стоя на базе.</p>
                <?php endif ?>
                <dl class="play-craft-facts">
                    <div><dt>📍 Координаты</dt><dd>X=<?= $int($info['x'] ?? 0) ?> Y=<?= $int($info['y'] ?? 0) ?></dd></div>
                    <div><dt>🌍 Биом</dt><dd><?= esc($str($info['biome'] ?? null, '???')) ?></dd></div>
                    <div><dt>🏘 Построек</dt><dd><?= $int($info['count'] ?? 0) ?> шт.</dd></div>
                    <div><dt>💰 Налог</dt><dd><?= esc(number_format($int($info['tax_total'] ?? 0), 0, '.', ' ')) ?> зол./сутки</dd></div>
                    <?php if (is_int($daysLeft)): ?>
                        <div><dt>⏳ Срок</dt><dd><?= $daysLeft <= 0 ? 'истёк — на грани разрушения' : $daysLeft . ' дн.' ?></dd></div>
                    <?php endif ?>
                </dl>

                <nav class="play-kb-grid" aria-label="Разделы базы">
                    <?= $go($home, '🏘 Постройки', 'play-kb-btn' . ($section === 'overview' ? ' is-primary' : '')) ?>
                    <?= $go($home + ['section' => 'catalog'], '🏗 Строить', 'play-kb-btn' . ($section !== 'overview' ? ' is-primary' : '')) ?>
                </nav>

                <?php if ($section === 'overview'): ?>
                    <section class="play-native-section" aria-label="Постройки базы">
                        <h3 class="play-native-subtitle is-plain">Постройки</h3>
                        <?php if ($buildings === []): ?>
                            <p class="play-native-hint">На базе пока пусто. Нажми «🏗 Строить», чтобы возвести первую постройку — Склад, Теплицу и др.</p>
                        <?php else: ?>
                            <ul class="play-base-buildings">
                                <?php foreach ($buildings as $row): ?>
                                    <?php if (! is_array($row)) { continue; } $amount = $int($row['amount'] ?? 1); ?>
                                    <li class="play-base-building">
                                        <span class="play-base-building-name"><?= esc($str($row['icon'] ?? null, '🏠')) ?> <?= esc($str($row['name'] ?? null, 'Постройка')) ?><?= $amount > 1 ? ' <span class="play-base-stack">×' . $amount . '</span>' : '' ?></span>
                                        <span class="play-base-building-meta">ур. <?= max(1, $int($row['level'] ?? 1)) ?> · налог <?= $int($row['tax'] ?? 0) ?></span>
                                        <span class="play-base-building-actions">
                                            <?= $go($home + ['section' => 'upgrade', 'id' => $int($row['building_id'] ?? 0)], '⬆️ Улучшить', 'play-kb-btn') ?>
                                            <?= $bridgeBtn($str($row['bridge_callback'] ?? null), '⚙️ Действия') ?>
                                        </span>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                            <p class="play-native-hint">«⚙️ Действия» — карточка постройки в боте: роботы, теплица, маяки, дрон и остальное, что умеет здание.</p>
                        <?php endif ?>
                    </section>

                    <section class="play-native-section" aria-label="Управление базой">
                        <h3 class="play-native-subtitle is-plain">Управление</h3>
                        <div class="play-kb-grid">
                            <?php foreach ($bridge as $btn): ?>
                                <?php if (! is_array($btn)) { continue; } ?>
                                <?= $bridgeBtn($str($btn['data'] ?? null), $str($btn['text'] ?? null)) ?>
                            <?php endforeach ?>
                        </div>
                    </section>

                <?php elseif ($section === 'catalog'): ?>
                    <?php $items = is_array($m['catalog'] ?? null) ? $m['catalog'] : []; $locked = array_values(array_filter($items, static fn (mixed $i): bool => is_array($i) && ($i['locked'] ?? false) === true)); ?>
                    <section class="play-native-section" aria-label="Что можно построить">
                        <h3 class="play-native-subtitle is-plain">Что можно построить · налог в сутки</h3>
                        <?php if ($str($m['refusal'] ?? null) !== ''): ?>
                            <div class="play-native-note" data-build-gate><?= esc($str($m['refusal'])) ?></div>
                        <?php endif ?>
                        <div class="play-kb-grid">
                            <?php foreach ($items as $item): ?>
                                <?php if (! is_array($item) || ($item['locked'] ?? false) === true) { continue; } $built = $int($item['built_count'] ?? 0); ?>
                                <?= $go($home + ['section' => 'building', 'key' => $str($item['key'] ?? null)], $str($item['name'] ?? null) . ' · ' . $int($item['tax'] ?? 0) . ($built > 0 ? ' · есть ' . $built : ''), 'play-kb-btn') ?>
                            <?php endforeach ?>
                        </div>
                        <?php foreach ($locked as $item): ?>
                            <div class="play-lock" data-build-lock="<?= esc($str($item['key'] ?? null), 'attr') ?>">
                                <span class="play-lock-title">🔒 <?= esc($str($item['name'] ?? null)) ?> (нужно: уровень <?= $int($item['required_level'] ?? 0) ?>)</span>
                                <span class="play-lock-why">Постройка откроется на <?= $int($item['required_level'] ?? 0) ?>-м уровне персонажа.</span>
                                <span class="play-lock-path">Путь: уровень растёт с опытом — добыча, крафт, стройка и задания.</span>
                            </div>
                        <?php endforeach ?>
                        <p class="play-native-hint">Строить можно, только стоя на своей базе. Сама стройка идёт в фоне — можно уходить.</p>
                    </section>

                <?php elseif ($section === 'building' && is_array($m['card'] ?? null)): ?>
                    <?php
                    $card   = $m['card'];
                    $recipe = is_array($card['recipe'] ?? null) ? $card['recipe'] : [];
                    $code   = $str($card['code'] ?? null);
                    $key    = $str($card['key'] ?? null);
                    $reqs   = array_merge(is_array($card['resources'] ?? null) ? $card['resources'] : [], is_array($card['items'] ?? null) ? $card['items'] : []);
                    $deps   = is_array($card['missing_deps'] ?? null) ? $card['missing_deps'] : [];
                    $reach  = is_array($card['out_of_reach'] ?? null) ? $card['out_of_reach'] : [];
                    $why    = match ($code) {
                        'not_on_base'     => 'Ты не на своей базе. Постройки возводятся, только когда стоишь на базе — телепортируйся или дойди до неё.',
                        'low_level'       => 'Уровень пока слишком низкий: нужен хотя бы ' . $int($recipe['level_required'] ?? 0) . '-й.',
                        'no_camp'         => 'У тебя нет лагеря. Разбей лагерь, чтобы строить.',
                        'leanto_gated'    => $plain(\App\Services\Buildings\BuildingCopyNotice::leanToGateExplanation($str($card['reason'] ?? null))),
                        'unknown_building' => 'Такой постройки нет.',
                        'relocating'      => $plain($str($card['reason'] ?? null)),
                        default           => '',
                    };
                    ?>
                    <section class="play-craft-card" aria-labelledby="play-base-card-title">
                        <h3 class="play-craft-card-title" id="play-base-card-title"><?= esc(trim($str($recipe['emoji'] ?? null) . ' ' . $str($recipe['name'] ?? null, $key))) ?></h3>
                        <?php if ($why !== ''): ?>
                            <div class="play-native-note" data-build-gate><?= esc($why) ?></div>
                        <?php else: ?>
                            <dl class="play-craft-facts">
                                <div><dt>⏱ Стройка</dt><dd><?= is_int($card['minutes'] ?? null) ? '~' . $card['minutes'] . ' мин' : '—' ?></dd></div>
                                <div><dt>📈 Уровень</dt><dd>с <?= $int($recipe['level_required'] ?? 0) ?></dd></div>
                            </dl>
                            <?php if ($reqs !== []): ?>
                                <ul class="play-craft-reqs" aria-label="Материалы: есть / нужно (рюкзак)">
                                    <?php foreach ($reqs as $req): ?>
                                        <?php if (! is_array($req)) { continue; } $have = $int($req['have'] ?? 0); $need = $int($req['need'] ?? 0); ?>
                                        <li class="play-craft-req<?= $have < $need ? ' is-short' : '' ?>">
                                            <span class="play-craft-req-name"><?= $have < $need ? '❌' : '✅' ?> <?= esc($str($req['name'] ?? null)) ?></span>
                                            <span class="play-craft-req-qty"><?= $have ?> / <?= $need ?></span>
                                        </li>
                                    <?php endforeach ?>
                                </ul>
                                <p class="play-native-hint">Есть / нужно — считается рюкзак: стройка берёт материалы из него, как в боте.</p>
                            <?php endif ?>
                            <?php if ($deps !== []): ?>
                                <div class="play-native-note">Сначала постройте: <?= esc(implode(', ', array_map(static fn (mixed $d): string => is_scalar($d) ? (string) $d : '', $deps))) ?>.</div>
                            <?php endif ?>
                            <?php if ($reach !== []): ?>
                                <div class="play-native-note">На своём уровне добудешь не всё: <?= esc(implode(', ', array_map(static fn (mixed $r): string => is_array($r) ? $str($r['name'] ?? null) . ' (' . $str($r['kind'] ?? null) . ' с ' . $int($r['level'] ?? 0) . ' ур.)' : '', array_slice($reach, 0, 3)))) ?>.</div>
                            <?php endif ?>
                            <?php if ($str($recipe['info_text'] ?? null) !== ''): ?>
                                <p class="play-base-effect"><?= esc($str($recipe['info_text'])) ?></p>
                            <?php endif ?>
                            <?php if (($card['duplicate']['owned'] ?? false) === true): ?>
                                <p class="play-native-hint">Такая постройка у тебя уже есть: бонус второй не складывается, налог платится за каждую.</p>
                            <?php endif ?>
                            <div class="play-kb-grid">
                                <?php if (($card['can_start'] ?? false) === true): ?>
                                    <?= $act('build_start', $home + ['key' => $key], '🛠️ Строить ' . trim($str($recipe['emoji'] ?? null) . ' ' . $str($recipe['name'] ?? null)), 'play-kb-btn is-primary') ?>
                                <?php else: ?>
                                    <?= $bridgeBtn(\App\Services\Bases\BaseCallbackSuffix::append('genericBuildInfo_' . $key, $baseId), '🛒 Чего не хватает?') ?>
                                <?php endif ?>
                            </div>
                        <?php endif ?>
                        <div class="play-kb-grid"><?= $go($home + ['section' => 'catalog'], '↩️ К списку построек', 'play-kb-btn') ?></div>
                    </section>

                <?php elseif ($section === 'upgrade' && is_array($m['upgrade'] ?? null)): ?>
                    <?php $up = $m['upgrade']; $req = is_array($up['requirements'] ?? null) ? $up['requirements'] : []; $code = $str($up['code'] ?? null); ?>
                    <section class="play-craft-card" aria-labelledby="play-base-up-title">
                        <h3 class="play-craft-card-title" id="play-base-up-title">⬆️ Улучшение<?= $str($up['name'] ?? null) !== '' ? ': ' . esc($str($up['name'])) : '' ?></h3>
                        <?php if ($code === 'preview'): ?>
                            <dl class="play-craft-facts">
                                <div><dt>📈 Уровень</dt><dd><?= $int($up['current_level'] ?? 0) ?> → <?= $int($up['level'] ?? 0) ?></dd></div>
                                <div><dt>💰 Золото</dt><dd><?= esc(number_format($int($req['gold'] ?? 0), 0, '.', ' ')) ?> (есть <?= esc(number_format($int($up['character']['gold'] ?? 0), 0, '.', ' ')) ?>)</dd></div>
                                <div><dt>🧑 Уровень персонажа</dt><dd>от <?= $int($req['level'] ?? 0) ?></dd></div>
                            </dl>
                            <?php if (is_string($up['effect_now'] ?? null) && is_string($up['effect_next'] ?? null)): ?>
                                <p class="play-base-effect" data-upgrade-effect>✨ Эффект: <?= esc($up['effect_now']) ?><?= $up['effect_now'] === $up['effect_next'] ? ' — от уровня не меняется' : ' → ' . esc($up['effect_next']) ?></p>
                            <?php endif ?>
                            <?php if (is_array($req['resources'] ?? null) && $req['resources'] !== []): ?>
                                <ul class="play-craft-reqs" aria-label="Ресурсы апгрейда">
                                    <?php foreach ($req['resources'] as $name => $qty): ?>
                                        <li class="play-craft-req"><span class="play-craft-req-name"><?= esc($str($up['resource_names'][$name] ?? null, (string) $name)) ?></span><span class="play-craft-req-qty"><?= $int($qty) ?></span></li>
                                    <?php endforeach ?>
                                </ul>
                            <?php endif ?>
                            <div class="play-kb-grid"><?= $act('upgrade', $home + ['id' => $int($up['building_id'] ?? 0), 'from' => $int($up['current_level'] ?? 0)], '✅ Улучшить', 'play-kb-btn is-primary') ?></div>
                        <?php elseif ($code === 'missing_resources'): ?>
                            <div class="play-native-note" data-upgrade-gate>Не хватает ресурсов для уровня <?= $int($up['next_level'] ?? 0) ?>:</div>
                            <ul class="play-craft-reqs">
                                <?php foreach (is_array($up['missing'] ?? null) ? $up['missing'] : [] as $line): ?>
                                    <li class="play-craft-req is-short"><span class="play-craft-req-name"><?= esc(ltrim($str($line), '- ')) ?></span></li>
                                <?php endforeach ?>
                            </ul>
                        <?php else: ?>
                            <div class="play-native-note" data-upgrade-gate><?= esc($plain($str($up['message'] ?? null, 'Улучшение сейчас недоступно.'))) ?></div>
                        <?php endif ?>
                        <div class="play-kb-grid"><?= $go($home, '↩️ К постройкам', 'play-kb-btn') ?></div>
                    </section>
                <?php endif ?>
            <?php endif ?>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
