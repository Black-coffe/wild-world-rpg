<?php
/**
 * W2.N2-01 (ADR-190) — нативный экран «🌍 Мир» на `/play`: веб-рендерер модели карты
 * ({@see \App\Services\World\LiveMapService}), из которой рисует и карта бота — те же окно 12×12,
 * маркеры, туман, расстояние до базы и кнопки.
 *
 * Работает без JS: клетка — `<button>` в своей форме, роза и кнопки карты — тоже формы. Соседняя
 * клетка и роза — кнопки шага бота через мост (`op=bridge`, `move_dir_*`); прочие клетки —
 * `op=cell` (подсказка: что на клетке, биом, координаты). Кнопки карты без своего экрана
 * (база, добыча, хаб, Поход, легенда, обзор, остров, события, дроны) — `op=bridge`.
 * Id персонажа и telegram id в разметку не попадают.
 *
 * @var array<string, mixed>  $map
 * @var list<list<string>>    $dock
 * @var string|null           $alert
 */

use App\Services\World\LiveMapService;

$m         = is_array($map ?? null) ? $map : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$error     = is_string($m['error'] ?? null) ? $m['error'] : null;
$center    = is_array($m['center'] ?? null) ? $m['center'] : null;
$rows      = is_array($m['cells'] ?? null) ? $m['cells'] : [];
$base      = is_array($m['distance_to_base'] ?? null) ? $m['distance_to_base'] : null;
$stats     = is_array($m['stats'] ?? null) ? $m['stats'] : [];
$actions   = is_array($m['actions'] ?? null) ? $m['actions'] : [];
$legend    = is_array($m['legend'] ?? null) ? $m['legend'] : [];
$num       = static fn (mixed $v): string => is_int($v) || is_float($v) ? (string) $v : '0';

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
                            $step   = $dir !== null && isset($dirCb[$dir]) && $code !== LiveMapService::CODE_OUT ? $dirCb[$dir] : null;
                            $aria   = 'X=' . $x . ' Y=' . $y;
                            ?>
                            <?php if ($code === LiveMapService::CODE_OUT): ?>
                                <span class="<?= esc($class, 'attr') ?>" aria-hidden="true"><?= esc($marker) ?></span>
                            <?php elseif ($step !== null): ?>
                                <?= $bridgeForm($step, $marker, $class . ' is-step', 'Шаг: ' . $aria) ?>
                            <?php else: ?>
                                <form action="<?= esc($viewUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="view" value="map"><input type="hidden" name="op" value="cell"><input type="hidden" name="x" value="<?= $x ?>"><input type="hidden" name="y" value="<?= $y ?>"><button class="<?= esc($class, 'attr') ?>" type="submit" aria-label="<?= esc($aria, 'attr') ?>"><?= esc($marker) ?></button></form>
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
                            <?= $bridgeForm($dirCb[$dir], LiveMapService::DIRECTIONS[$dir][2], 'play-kb-btn') ?>
                        <?php endif ?>
                    <?php endforeach ?>
                </nav>
            <?php endif ?>

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
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
