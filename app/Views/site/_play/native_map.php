<?php
/**
 * W2.N2-01 (ADR-190) — нативный экран «🌍 Мир» на `/play`: веб-рендерер модели карты
 * ({@see \App\Services\World\LiveMapService}), из которой рисует и карта бота — те же окно 12×12,
 * маркеры, туман, расстояние до базы и кнопки.
 *
 * Работает без JS: клетка — `<button>` в своей форме, роза и кнопки карты — тоже формы. Соседняя
 * клетка и роза — нативный шаг (`op=step`, `dir`, W2.N2-02); прочие клетки — `op=cell`
 * (подсказка: что на клетке, биом, координаты). Кнопки карты без своего экрана (база, добыча, хаб,
 * Поход, легенда, обзор, остров, события, дроны) — `op=bridge`. События шага (подсказки, находка,
 * рана, караван, узел, дроны…) — сообщения экрана моста под картой; их кнопки — `/play/act` с
 * `message_id` своего сообщения. Id персонажа и telegram id в разметку не попадают.
 *
 * W2.N2-03: клетка на одном из 8 лучей (дальше соседней) — превью Похода (`op=march_preview`, `dir`,
 * `n` = расстояние по Чебышёву; сервер зажимает в потолок заказа). Превью — число клеток, ETA, расход,
 * ➖/➕ и «Выступить» (`march_start`). Идущий Поход — прогресс, прибытие, «Остановиться»
 * (`march_stop`), «Продлить +5» (`march_extend`); на паузе — «Продолжить» (`march_resume`).
 * Ссылка «Весь мир» ведёт на публичную карту `/map`.
 *
 * W2.N3-03: клетки дальше 3 от игрока (по Чебышёву) несут `is-far` — на узком экране сетка
 * показывает окно 7×7, чтобы клетка оставалась удобной для пальца без горизонтального скролла.
 *
 * @var array<string, mixed>  $map
 * @var list<list<string>>    $dock
 * @var string|null           $alert
 * @var list<array<string, mixed>> $events
 * @var array<string, mixed>|null $march   идущий Поход ({@see \App\Services\World\MarchService::status()})
 * @var array<string, mixed>|null $preview превью Похода ({@see \App\Services\World\MarchService::preview()})
 */

use App\Services\Web\TelegramMarkupRenderer;
use App\Services\World\LiveMapService;
use App\Services\World\MarchService;

$m         = is_array($map ?? null) ? $map : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$actUrl    = base_url('play/act');
$stepEvents = is_array($events ?? null) ? $events : [];
$error     = is_string($m['error'] ?? null) ? $m['error'] : null;
$center    = is_array($m['center'] ?? null) ? $m['center'] : null;
$rows      = is_array($m['cells'] ?? null) ? $m['cells'] : [];
$base      = is_array($m['distance_to_base'] ?? null) ? $m['distance_to_base'] : null;
$stats     = is_array($m['stats'] ?? null) ? $m['stats'] : [];
$actions   = is_array($m['actions'] ?? null) ? $m['actions'] : [];
$legend    = is_array($m['legend'] ?? null) ? $m['legend'] : [];
$num       = static fn (mixed $v): string => is_int($v) || is_float($v) ? (string) $v : '0';
$marchNow  = is_array($march ?? null) ? $march : null;
$pv        = is_array($preview ?? null) && ($preview['ok'] ?? false) === true ? $preview : null;

// Действия по группам; направление → callback (для соседних клеток).
$byGroup = [];
$dirCb   = [];
foreach ($actions as $action) {
    if (! is_array($action) || ! is_string($action['callback'] ?? null) || ! is_string($action['label'] ?? null)) {
        continue;
    }
    $byGroup[(string) ($action['group'] ?? '')][] = $action;
    if (is_string($action['dir'] ?? null)) {
        $dirCb[$action['dir']] = $action['callback'];
    }
}
$dirAt = [];
foreach (LiveMapService::DIRECTIONS as $dir => [$dx, $dy]) {
    $dirAt["{$dx}_{$dy}"] = $dir;
}

$bridgeForm = static function (string $callback, string $label, string $class, ?string $aria = null) use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="op" value="bridge">'
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<input type="hidden" name="data" value="' . esc($callback, 'attr') . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit"' . ($aria !== null ? ' aria-label="' . esc($aria, 'attr') . '"' : '') . '>' . esc($label) . '</button></form>';
};

// Шаг — нативная форма `op=step`.
$stepForm = static function (string $dir, string $label, string $class, ?string $aria = null) use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="map">'
        . '<input type="hidden" name="op" value="step">'
        . '<input type="hidden" name="dir" value="' . esc($dir, 'attr') . '">'
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit"' . ($aria !== null ? ' aria-label="' . esc($aria, 'attr') . '"' : '') . '>' . esc($label) . '</button></form>';
};

// Поход — нативная форма `op=march_*` (превью без намерения, мутации — с `intent_id`).
$marchForm = static function (string $op, array $fields, string $label, string $class, ?string $aria = null) use ($viewUrl): string {
    $hidden = '';
    foreach ($fields as $name => $value) {
        $hidden .= '<input type="hidden" name="' . esc((string) $name, 'attr') . '" value="' . esc((string) $value, 'attr') . '">';
    }
    if ($op !== 'march_preview') {
        $hidden .= '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">';
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="map"><input type="hidden" name="op" value="' . esc($op, 'attr') . '">' . $hidden
        . '<button class="' . esc($class, 'attr') . '" type="submit"' . ($aria !== null ? ' aria-label="' . esc($aria, 'attr') . '"' : '') . '>' . esc($label) . '</button></form>';
};

// Роза: ряды 3 · 3 · 3, в центре — база (как у бота).
$rose = ['northwest', 'north', 'northeast', 'west', null, 'east', 'southwest', 'south', 'southeast'];
$baseAction = null;
foreach ($byGroup[LiveMapService::GROUP_CELL] ?? [] as $action) {
    if ($action['callback'] === 'Base') {
        $baseAction = $action;
    }
}
$bottom = array_merge(
    array_values(array_filter($byGroup[LiveMapService::GROUP_CELL] ?? [], static fn (array $a): bool => $a['callback'] !== 'Base')),
    $byGroup[LiveMapService::GROUP_NAV] ?? [],
    $byGroup[LiveMapService::GROUP_WORLD] ?? []
);
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ кнопки</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="map" aria-labelledby="play-native-map-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-map-title">🌍 Мир</h2>
                <div class="play-native-tags">
                    <?php if ($center !== null): ?>
                        <?php $centerBiome = LiveMapService::biomeName(is_int($center['biome'] ?? null) ? $center['biome'] : null); ?>
                        <span class="badge">🧭 X=<?= (int) $center['x'] ?> Y=<?= (int) $center['y'] ?><?= $centerBiome !== null ? ' · ' . esc($centerBiome) : '' ?></span>
                    <?php endif ?>
                    <?php if ($base !== null): ?>
                        <span class="badge">🏕 База: <?= (int) $base['distance'] ?> ходов <?= esc(is_string($base['arrow'] ?? null) ? $base['arrow'] : '') ?></span>
                    <?php endif ?>
                </div>
            </header>

            <?php if ($marchNow !== null): ?>
                <?php
                $mDone    = (int) ($marchNow['steps_done'] ?? 0);
                $mPlanned = max(1, (int) ($marchNow['steps_planned'] ?? 1));
                $mPaused  = ($marchNow['status'] ?? '') === 'paused';
                $mEta     = is_int($marchNow['eta'] ?? null) ? $marchNow['eta'] : null;
                ?>
                <section class="play-march is-active" aria-label="Поход">
                    <p class="play-march-title"><?= $mPaused ? '⏸ Поход на паузе' : '🚜 Поход идёт' ?>: <?= esc(is_string($marchNow['heading_label'] ?? null) ? $marchNow['heading_label'] : '') ?> · <?= $mDone ?>/<?= $mPlanned ?> клеток</p>
                    <progress class="play-march-bar" max="<?= $mPlanned ?>" value="<?= min($mDone, $mPlanned) ?>"><?= $mDone ?>/<?= $mPlanned ?></progress>
                    <?php if ($mEta !== null): ?>
                        <p class="play-march-meta">Прибытие ~<?= esc(date('H:i', $mEta)) ?> (осталось <?= max(0, (int) ceil(($mEta - time()) / 60)) ?> мин). Карта обновится, когда откроешь её снова.</p>
                    <?php elseif ($mPaused): ?>
                        <p class="play-march-meta">В пути что-то требует решения — продолжи поход или остановись.</p>
                    <?php endif ?>
                    <div class="play-march-actions">
                        <?php if ($mPaused): ?>
                            <?= $marchForm('march_resume', [], '🚜 Продолжить поход', 'play-kb-btn') ?>
                        <?php else: ?>
                            <?= $marchForm('march_extend', ['n' => MarchService::EXTEND_STEP], '➕ Продлить +' . MarchService::EXTEND_STEP, 'play-kb-btn') ?>
                        <?php endif ?>
                        <?= $marchForm('march_stop', [], '❌ Остановиться', 'play-kb-btn') ?>
                    </div>
                </section>
            <?php endif ?>

            <?php if ($error !== null): ?>
                <div class="play-native-note"><?= esc($error) ?></div>
            <?php else: ?>
                <p class="play-map-meta"><span>❤️ Здоровье: <?= esc($num($stats['health'] ?? null)) ?></span><span>💤 Усталость: <?= esc($num($stats['tired'] ?? null)) ?></span></p>

                <div class="play-map" aria-label="Карта 12×12 вокруг тебя, ты в центре">
                    <?php foreach ($rows as $row): ?>
                        <?php foreach (is_array($row) ? $row : [] as $cell): ?>
                            <?php
                            if (! is_array($cell)) {
                                continue;
                            }
                            $x      = (int) ($cell['x'] ?? 0);
                            $y      = (int) ($cell['y'] ?? 0);
                            $code   = is_string($cell['code'] ?? null) ? $cell['code'] : LiveMapService::CODE_FOG;
                            $marker = is_string($cell['marker'] ?? null) ? $cell['marker'] : '';
                            $class  = 'play-map-cell is-' . str_replace('_', '-', $code);
                            $dir    = $center !== null ? ($dirAt[($x - (int) $center['x']) . '_' . ($y - (int) $center['y'])] ?? null) : null;
                            $step   = $dir !== null && isset($dirCb[$dir]) && $code !== LiveMapService::CODE_OUT ? $dir : null;
                            $ray    = $center !== null && $step === null && $code !== LiveMapService::CODE_OUT
                                ? MarchService::ray($x - (int) $center['x'], $y - (int) $center['y'])
                                : null;
                            $ray    = $ray !== null && $ray['n'] >= 2 ? $ray : null;
                            $aria   = 'X=' . $x . ' Y=' . $y;
                            $far    = $center !== null && max(abs($x - (int) $center['x']), abs($y - (int) $center['y'])) > 3 ? ' is-far' : '';
                            ?>
                            <?php if ($code === LiveMapService::CODE_OUT): ?>
                                <span class="<?= esc($class . $far, 'attr') ?>" aria-hidden="true"><?= esc($marker) ?></span>
                            <?php elseif ($step !== null): ?>
                                <?= $stepForm($step, $marker, $class . ' is-step' . $far, 'Шаг: ' . $aria) ?>
                            <?php elseif ($ray !== null): ?>
                                <?= $marchForm('march_preview', ['dir' => $ray['dir'], 'n' => $ray['n']], $marker, $class . ' is-ray' . $far, 'Поход ×' . $ray['n'] . ': ' . $aria) ?>
                            <?php else: ?>
                                <form action="<?= esc($viewUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="view" value="map"><input type="hidden" name="op" value="cell"><input type="hidden" name="x" value="<?= $x ?>"><input type="hidden" name="y" value="<?= $y ?>"><button class="<?= esc($class . $far, 'attr') ?>" type="submit" aria-label="<?= esc($aria, 'attr') ?>"><?= esc($marker) ?></button></form>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endforeach ?>
                </div>

                <nav class="play-rose" aria-label="Роза направлений">
                    <?php foreach ($rose as $dir): ?>
                        <?php if ($dir === null): ?>
                            <?php if ($baseAction !== null): ?>
                                <?= $bridgeForm($baseAction['callback'], $baseAction['label'], 'play-kb-btn') ?>
                            <?php else: ?>
                                <span></span>
                            <?php endif ?>
                        <?php elseif (isset($dirCb[$dir])): ?>
                            <?= $stepForm($dir, LiveMapService::DIRECTIONS[$dir][2], 'play-kb-btn') ?>
                        <?php endif ?>
                    <?php endforeach ?>
                </nav>

                <?php if ($pv !== null): ?>
                    <?php
                    $pvDir  = (string) $pv['dir'];
                    $pvN    = (int) $pv['n'];
                    $pvCap  = max(1, (int) $pv['cap']);
                    $pvHook = is_array($pv['hook'] ?? null) && is_string($pv['hook']['line'] ?? null) ? $pv['hook']['line'] : '';
                    $pvLine = is_string($pv['breakdown_line'] ?? null) ? $pv['breakdown_line'] : '';
                    ?>
                    <section class="play-march is-preview" aria-labelledby="play-march-preview-title">
                        <h3 class="play-march-title" id="play-march-preview-title">🚜 Поход: <?= esc((string) $pv['dir_label']) ?> ×<?= $pvN ?></h3>
                        <dl class="play-march-facts">
                            <div><dt>Клеток</dt><dd><?= $pvN ?> из <?= $pvCap ?> возможных</dd></div>
                            <div><dt>В пути</dt><dd>~<?= (int) $pv['eta_minutes'] ?> мин</dd></div>
                            <div><dt>Расход</dt><dd>❤️ <?= esc($num($pv['hp'] ?? null)) ?> · 💤 <?= esc($num($pv['tired'] ?? null)) ?></dd></div>
                            <div><dt>Впереди</dt><dd><?= esc((string) $pv['ahead']) ?>, дальше — туман</dd></div>
                        </dl>
                        <?php if ($pvLine !== ''): ?>
                            <p class="play-march-meta"><?= esc($pvLine) ?></p>
                        <?php endif ?>
                        <p class="play-march-meta">Отряд идёт сам и остановится, если что-то потребует решения: встреча с игроком, тяжёлая рана, чужой лагерь на пути, край мира, привал по усталости.</p>
                        <?php if ($pvHook !== ''): ?>
                            <p class="play-march-meta"><?= esc($pvHook) ?></p>
                        <?php endif ?>
                        <div class="play-march-actions">
                            <?= $marchForm('march_preview', ['dir' => $pvDir, 'n' => max(1, $pvN - 1)], '➖', 'play-kb-btn', 'Меньше клеток') ?>
                            <?= $marchForm('march_preview', ['dir' => $pvDir, 'n' => min($pvN + MarchService::EXTEND_STEP, $pvCap)], '➕' . MarchService::EXTEND_STEP, 'play-kb-btn', 'Больше клеток') ?>
                            <?= $marchForm('march_start', ['dir' => $pvDir, 'n' => $pvN], '🚜 Выступить', 'play-kb-btn is-primary') ?>
                        </div>
                    </section>
                <?php endif ?>
            <?php endif ?>

            <?php foreach ($stepEvents as $msg): ?>
                <?php
                if (! is_array($msg) || ! is_int($msg['message_id'] ?? null)) {
                    continue;
                }
                $msgText = is_string($msg['text'] ?? null) ? $msg['text'] : (is_string($msg['caption'] ?? null) ? $msg['caption'] : '');
                $parse   = is_string($msg['parse_mode'] ?? null) ? $msg['parse_mode'] : null;
                ?>
                <article class="play-msg is-current" data-step-event>
                    <?php if ($msgText !== ''): ?>
                        <div class="play-msg-text"><?= TelegramMarkupRenderer::toHtml($msgText, $parse) ?></div>
                    <?php endif ?>
                    <?php foreach (is_array($msg['inline_keyboard'] ?? null) ? $msg['inline_keyboard'] : [] as $row): ?>
                        <?php if (! is_array($row) || $row === []) { continue; } ?>
                        <div class="play-kb-row">
                            <?php foreach ($row as $btn): ?>
                                <?php if (is_array($btn) && is_string($btn['text'] ?? null) && is_string($btn['callback_data'] ?? null)): ?>
                                    <form action="<?= esc($actUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="kind" value="callback"><input type="hidden" name="data" value="<?= esc($btn['callback_data'], 'attr') ?>"><input type="hidden" name="message_id" value="<?= $msg['message_id'] ?>"><button class="play-kb-btn" type="submit"><?= esc($btn['text']) ?></button></form>
                                <?php endif ?>
                            <?php endforeach ?>
                        </div>
                    <?php endforeach ?>
                </article>
            <?php endforeach ?>

            <?php if ($bottom !== []): ?>
                <nav class="play-kb" aria-label="Действия на карте">
                    <div class="play-kb-grid">
                        <?php foreach ($bottom as $action): ?>
                            <?= $bridgeForm($action['callback'], $action['label'], 'play-kb-btn') ?>
                        <?php endforeach ?>
                    </div>
                </nav>
            <?php endif ?>

            <?php if ($legend !== []): ?>
                <details class="play-map-legend">
                    <summary>Легенда</summary>
                    <ul>
                        <?php foreach ($legend as $entry): ?>
                            <?php if (is_array($entry) && is_string($entry['marker'] ?? null) && is_string($entry['label'] ?? null)): ?>
                                <li><?= esc($entry['marker']) ?> — <?= esc($entry['label']) ?></li>
                            <?php endif ?>
                        <?php endforeach ?>
                    </ul>
                </details>
            <?php endif ?>

            <p class="play-map-world"><a href="<?= esc(base_url('map'), 'attr') ?>">🗺 Весь мир — большая карта острова</a></p>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
