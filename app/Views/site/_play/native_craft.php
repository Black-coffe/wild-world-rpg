<?php
/**
 * W2.N3-03 (ADR-190) — нативный экран «🔨 Крафт» на `/play`: веб-рендерер индекса
 * {@see \Config\CraftCatalog} (дерево экранов бота) и ядра крафта — карточка из
 * {@see \App\Services\Craft\CraftOrderService::preview()}, очередь из
 * {@see \App\Services\Craft\CraftQueueService::forCharacter()}.
 *
 * Верстак → категория → карточка: формы POST `/play/view` (`view=craft`, `bench`, `cat`, `recipe`), без JS —
 * PRG. Запертый раздел — замок «🔒 Название (нужно: …)» с объяснением и кнопкой к карточке требования.
 * Карточка: сырьё «есть / нужно на 1 шт.» (пул рюкзак+склад), время, цена, первое условие-отказ; кнопки
 * шагов бота (`CraftCardHelper::STEPS` до `max_qty`) и поле «своё число» (1..`max_qty`) — `op=craft_start`
 * с `intent_id`. Нехватка — кнопка моста на экран нехватки бота. Очередь — рядом (на узком экране под
 * каталогом): активные с таймером (`data-ends-at`, тикает `wildworld-play.js`), ожидающие с номером,
 * оценкой «≈» и «Отменить» (`op=craft_cancel`). Числа — только из модели, id персонажа в разметку не идут.
 *
 * @var array<string, mixed> $craft
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

$c         = is_array($craft ?? null) ? $craft : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$benches   = is_array($c['benches'] ?? null) ? $c['benches'] : [];
$bench     = is_string($c['bench'] ?? null) ? $c['bench'] : null;
$cats      = is_array($c['cats'] ?? null) ? $c['cats'] : [];
$cat       = is_string($c['cat'] ?? null) ? $c['cat'] : null;
$recipes   = is_array($c['recipes'] ?? null) ? $c['recipes'] : [];
$card      = is_array($c['card'] ?? null) ? $c['card'] : null;
$queue     = is_array($c['queue'] ?? null) ? $c['queue'] : [];
$active    = is_array($queue['active'] ?? null) ? $queue['active'] : [];
$waiting   = is_array($queue['queued'] ?? null) ? $queue['queued'] : [];
$str       = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;
$int       = static fn (mixed $v): int => is_int($v) ? $v : 0;
$plain     = static fn (string $text): string => str_replace(['*', '_'], '', $text);

$label = static function (array $list, ?string $key): string {
    foreach ($list as $item) {
        if (is_array($item) && ($item['key'] ?? null) === $key) {
            return is_string($item['label'] ?? null) ? $item['label'] : '';
        }
    }

    return '';
};

$duration = static function (int $minutes): string {
    $minutes = max(0, $minutes);

    return $minutes >= 60 ? intdiv($minutes, 60) . ' ч ' . ($minutes % 60) . ' мин' : $minutes . ' мин';
};

$left = static function (int $seconds): string {
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;

    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
};

/** Переход по каталогу — форма `view=craft` c нужной ступенью. */
$go = static function (array $to, string $text, string $class, ?string $aria = null) use ($viewUrl): string {
    $hidden = '';
    foreach (['bench', 'cat', 'recipe'] as $field) {
        if (is_string($to[$field] ?? null)) {
            $hidden .= '<input type="hidden" name="' . $field . '" value="' . esc($to[$field], 'attr') . '">';
        }
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="craft">' . $hidden
        . '<button class="' . esc($class, 'attr') . '" type="submit"' . ($aria !== null ? ' aria-label="' . esc($aria, 'attr') . '"' : '') . '>' . esc($text) . '</button></form>';
};

/** Мутация крафта — форма с `op` и своим `intent_id`; каталог остаётся на той же ступени. */
$act = static function (string $op, array $fields, string $text, string $class) use ($viewUrl, $bench, $cat): string {
    $hidden = '<input type="hidden" name="view" value="craft"><input type="hidden" name="op" value="' . esc($op, 'attr') . '">';
    foreach (array_filter(['bench' => $bench, 'cat' => $cat]) + $fields as $name => $value) {
        $hidden .= '<input type="hidden" name="' . esc((string) $name, 'attr') . '" value="' . esc((string) $value, 'attr') . '">';
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field() . $hidden
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

$locks = array_values(array_filter($benches, static fn (mixed $b): bool => is_array($b) && is_array($b['lock'] ?? null)));
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ кнопки</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="craft" aria-labelledby="play-native-craft-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-craft-title">🔨 Крафт</h2>
                <p class="play-craft-crumbs">
                    <?= esc(implode(' → ', array_filter([
                        'Крафт',
                        $bench !== null ? $label($benches, $bench) : null,
                        $cat !== null ? $label($cats, $cat) : null,
                        $card !== null ? $str($card['recipe']['name'] ?? '') : null,
                    ]))) ?>
                </p>
            </header>

            <div class="play-craft">
                <div class="play-craft-main">
                    <nav class="play-kb" aria-label="Разделы крафта">
                        <div class="play-kb-grid">
                            <?php foreach ($benches as $b): ?>
                                <?php if (! is_array($b)) { continue; } ?>
                                <?php if (is_array($b['lock'] ?? null)): ?>
                                    <?= $go($b['lock']['target'], $str($b['lock']['title'] ?? ''), 'play-kb-btn is-locked') ?>
                                <?php else: ?>
                                    <?= $go(['bench' => $str($b['key'] ?? '')], $str($b['label'] ?? ''), 'play-kb-btn' . ($bench === ($b['key'] ?? null) ? ' is-primary' : '')) ?>
                                <?php endif ?>
                            <?php endforeach ?>
                        </div>
                    </nav>

                    <?php foreach ($locks as $b): ?>
                        <div class="play-lock" data-craft-lock="<?= esc($str($b['key'] ?? ''), 'attr') ?>">
                            <span class="play-lock-title"><?= esc($str($b['lock']['title'] ?? '')) ?></span>
                            <span class="play-lock-why"><?= esc($str($b['lock']['why'] ?? '')) ?></span>
                            <span class="play-lock-path">Путь: <?= esc($str($b['lock']['path'] ?? '')) ?></span>
                        </div>
                    <?php endforeach ?>

                    <?php if ($bench === null): ?>
                        <p class="play-native-hint">Выбери раздел: общий крафт работает где угодно, стандартный и профессиональный — рецепты посерьёзнее.</p>
                    <?php else: ?>
                        <section class="play-native-section" aria-label="Категории">
                            <h3 class="play-native-subtitle is-plain"><?= esc($label($benches, $bench)) ?></h3>
                            <div class="play-kb-grid">
                                <?php foreach ($cats as $k): ?>
                                    <?php if (! is_array($k)) { continue; } ?>
                                    <?= $go(['bench' => $bench, 'cat' => $str($k['key'] ?? '')], $str($k['label'] ?? '') . ' · ' . $int($k['count'] ?? 0), 'play-kb-btn' . ($cat === ($k['key'] ?? null) ? ' is-primary' : '')) ?>
                                <?php endforeach ?>
                            </div>
                        </section>
                    <?php endif ?>

                    <?php if ($cat !== null): ?>
                        <section class="play-native-section" aria-label="Рецепты">
                            <h3 class="play-native-subtitle is-plain"><?= esc($label($cats, $cat)) ?></h3>
                            <div class="play-kb-grid">
                                <?php foreach ($recipes as $r): ?>
                                    <?php if (! is_array($r)) { continue; } ?>
                                    <?php $isCard = $card !== null && ($card['recipe']['key'] ?? null) === ($r['key'] ?? null); ?>
                                    <?= $go(['bench' => $bench, 'cat' => $cat, 'recipe' => $str($r['key'] ?? '')], $str($r['icon'] ?? '') . ' ' . $str($r['name'] ?? ''), 'play-kb-btn' . ($isCard ? ' is-primary' : '')) ?>
                                <?php endforeach ?>
                            </div>
                        </section>
                    <?php endif ?>

                    <?php if ($card !== null): ?>
                        <?php
                        $recipeKey = $str($card['recipe']['key'] ?? '');
                        $maxQty    = $int($card['max_qty'] ?? 0);
                        $reqs      = array_merge(
                            is_array($card['resources'] ?? null) ? $card['resources'] : [],
                            is_array($card['items'] ?? null) ? $card['items'] : []
                        );
                        $gold      = $int($card['gold'] ?? 0);
                        $gate      = ($card['ok'] ?? false) !== true && ($card['code'] ?? '') !== 'missing_materials' ? $plain($str($card['message'] ?? '')) : '';
                        $shortage  = is_array($card['shortage'] ?? null) ? $card['shortage'] : null;
                        $steps     = is_array($card['steps'] ?? null) ? $card['steps'] : [];
                        ?>
                        <section class="play-craft-card" aria-labelledby="play-craft-card-title">
                            <h3 class="play-craft-card-title" id="play-craft-card-title"><?= esc(trim($str($card['recipe']['icon'] ?? '') . ' ' . $str($card['recipe']['name'] ?? ''))) ?></h3>
                            <dl class="play-craft-facts">
                                <div><dt>⏱ Время, 1 шт.</dt><dd><?= esc($duration($int($card['minutes_one'] ?? 0))) ?></dd></div>
                                <div><dt>💰 Цена, 1 шт.</dt><dd><?= $gold > 0 ? esc(number_format($gold, 0, '.', ' ')) . ' зол.' : 'без золота' ?></dd></div>
                                <div><dt>📦 Можно поставить</dt><dd><?= $maxQty ?> шт.</dd></div>
                                <?php if ($int($card['queue_pos'] ?? 0) > 0): ?>
                                    <div><dt>📋 Место в очереди</dt><dd>№<?= $int($card['queue_pos']) ?></dd></div>
                                <?php endif ?>
                            </dl>
                            <?php if ($reqs !== []): ?>
                                <ul class="play-craft-reqs" aria-label="Сырьё на 1 шт.: есть / нужно (рюкзак и склад)">
                                    <?php foreach ($reqs as $req): ?>
                                        <?php if (! is_array($req)) { continue; } $have = $int($req['have'] ?? 0); $need = $int($req['need'] ?? 0); ?>
                                        <li class="play-craft-req<?= $have < $need ? ' is-short' : '' ?>">
                                            <span class="play-craft-req-name"><?= $have < $need ? '❌' : '✅' ?> <?= esc($str($req['name'] ?? '')) ?></span>
                                            <span class="play-craft-req-qty"><?= $have ?> / <?= $need ?></span>
                                        </li>
                                    <?php endforeach ?>
                                </ul>
                                <p class="play-native-hint">Есть / нужно на 1 шт. — считается рюкзак и склад базы, как при старте.</p>
                            <?php endif ?>
                            <?php if ($gate !== ''): ?>
                                <div class="play-native-note" data-craft-gate><?= esc($gate) ?></div>
                            <?php endif ?>

                            <?php if ($maxQty >= 1): ?>
                                <div class="play-kb-grid">
                                    <?php foreach ($steps as $n): ?>
                                        <?= $act('craft_start', ['recipe' => $recipeKey, 'qty' => $int($n)], '🛠️ Крафт ' . $int($n) . ' шт', 'play-kb-btn' . ($n === 1 ? ' is-primary' : '')) ?>
                                    <?php endforeach ?>
                                </div>
                                <form class="play-craft-qty" action="<?= esc($viewUrl, 'attr') ?>" method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="view" value="craft"><input type="hidden" name="op" value="craft_start">
                                    <input type="hidden" name="bench" value="<?= esc((string) $bench, 'attr') ?>"><input type="hidden" name="cat" value="<?= esc((string) $cat, 'attr') ?>">
                                    <input type="hidden" name="recipe" value="<?= esc($recipeKey, 'attr') ?>">
                                    <input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>">
                                    <label class="play-craft-qty-label" for="play-craft-qty">Своё число, 1–<?= $maxQty ?></label>
                                    <input class="play-craft-qty-input" id="play-craft-qty" name="qty" type="number" inputmode="numeric" min="1" max="<?= $maxQty ?>" step="1" value="1" required>
                                    <button class="play-kb-btn" type="submit">🛠️ Крафт</button>
                                </form>
                            <?php elseif ($shortage !== null): ?>
                                <p class="play-native-hint">Сырья не хватает даже на одну штуку — экран бота покажет, чего не хватает и где это взять или купить.</p>
                                <div class="play-kb-grid">
                                    <form action="<?= esc($viewUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="op" value="bridge"><input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="data" value="<?= esc($str($shortage['callback_data'] ?? ''), 'attr') ?>"><button class="play-kb-btn" type="submit"><?= esc($str($shortage['text'] ?? '')) ?></button></form>
                                </div>
                            <?php endif ?>
                        </section>
                    <?php endif ?>
                </div>

                <aside class="play-craft-queue" aria-labelledby="play-craft-queue-title">
                    <h3 class="play-craft-queue-title" id="play-craft-queue-title">📋 Очередь крафта</h3>
                    <?php if ($active === [] && $waiting === []): ?>
                        <p class="play-native-hint">Пусто. Поставь крафт в карточке рецепта — он появится здесь с таймером.</p>
                    <?php endif ?>
                    <?php if ($active !== [] || $waiting !== []): ?>
                        <ul class="play-craft-jobs">
                            <?php foreach ($active as $job): ?>
                                <?php if (! is_array($job)) { continue; } $secs = $int($job['seconds_left'] ?? 0); $endsAt = time() + $secs; ?>
                                <li class="play-craft-job is-active">
                                    <span class="play-craft-job-name">⚙️ <?= esc($str($job['name'] ?? '', 'Крафт')) ?> ×<?= max(1, $int($job['qty'] ?? 1)) ?></span>
                                    <span class="play-craft-job-meta">идёт · осталось <time class="play-craft-timer" data-ends-at="<?= $endsAt ?>" datetime="<?= esc(date('c', $endsAt), 'attr') ?>"><?= esc($left($secs)) ?></time></span>
                                </li>
                            <?php endforeach ?>
                            <?php foreach ($waiting as $job): ?>
                                <?php if (! is_array($job)) { continue; } ?>
                                <li class="play-craft-job is-queued">
                                    <span class="play-craft-job-name">№<?= $int($job['position'] ?? 0) ?> · <?= esc($str($job['name'] ?? '', 'Крафт')) ?> ×<?= max(1, $int($job['qty'] ?? 1)) ?></span>
                                    <span class="play-craft-job-meta">≈ старт через <?= esc($duration((int) ceil($int($job['starts_in_seconds'] ?? 0) / 60))) ?> · займёт ≈ <?= esc($duration($int($job['minutes_total'] ?? 0))) ?></span>
                                    <?= $act('craft_cancel', ['task' => $int($job['charTaskId'] ?? 0)] + ($card !== null ? ['recipe' => $str($card['recipe']['key'] ?? '')] : []), '❌ Отменить', 'play-kb-btn') ?>
                                </li>
                            <?php endforeach ?>
                        </ul>
                        <p class="play-native-hint">Готовое придёт во входящие 🔔 и ляжет в инвентарь. Отменить можно только ожидающий крафт — сырьё и золото вернутся.</p>
                    <?php endif ?>
                </aside>
            </div>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
