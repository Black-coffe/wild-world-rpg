<?php
/**
 * W2.N1-01 (ADR-190) — постоянный HUD `/play`: здоровье, выносливость, золото, уровень и опыт,
 * клетка и биом, активная задача с живым таймером. Данные — модель персонажа
 * ({@see \App\Services\Player\CharacterSheetService::hud()}), та же, из которой рисует бот.
 *
 * Таймер без JS показывает остаток на момент отрисовки; с JS `wildworld-play.js` тикает его от
 * `data-ends-at` (unix, секунды) без запросов к серверу. HUD приходит заново в каждом ответе
 * `/play/act`, `/play/view`, `/play/inbox` и подменяет `#play-hud` целиком.
 *
 * W2.N2-03: идущий Поход ({@see \App\Services\World\MarchService::status()}) — направление,
 * пройдено/всего, таймер до прибытия (тот же `data-ends-at`) и «Остановиться» (`op=march_stop`);
 * на паузе — «Продолжить» (`op=march_resume`). Опрос входящих обновляет полосу — прогресс виден
 * без перезагрузки.
 *
 * @var array<string, mixed> $hud
 */

use App\Services\Player\CharacterSheetService;

$h       = is_array($hud ?? null) ? $hud : [];
$num     = static fn (mixed $v): string => CharacterSheetService::displayNumber(is_scalar($v) ? (string) $v : '');
$gold    = is_int($h['gold'] ?? null) ? $h['gold'] : 0;
$level   = is_int($h['level'] ?? null) ? $h['level'] : 0;
$percent = is_int($h['level_percent'] ?? null) ? $h['level_percent'] : null;
$next    = is_int($h['next_level'] ?? null) ? $h['next_level'] : null;
$cell    = is_array($h['cell'] ?? null) ? $h['cell'] : null;
$biome   = is_string($h['biome'] ?? null) ? $h['biome'] : null;
$task    = is_array($h['task'] ?? null) ? $h['task'] : null;
$more    = is_int($h['tasks_more'] ?? null) ? $h['tasks_more'] : 0;
$march   = is_array($h['march'] ?? null) ? $h['march'] : null;
$viewUrl = base_url('play/view');

$endsAt = $task !== null && is_int($task['ends_at'] ?? null) ? $task['ends_at'] : null;
$left   = static function (int $seconds): string {
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;

    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
};
?>
<div class="play-hud" id="play-hud" role="region" aria-label="Состояние персонажа" data-now="<?= time() ?>">
    <dl class="play-hud-stats">
        <div class="play-hud-stat is-health"><dt>💖 Здоровье</dt><dd><?= esc($num($h['health'] ?? '')) ?></dd></div>
        <div class="play-hud-stat is-tired"><dt>🥱 Выносливость</dt><dd><?= esc($num($h['tired'] ?? '')) ?></dd></div>
        <div class="play-hud-stat is-gold"><dt>💰 Золото</dt><dd><?= esc(number_format($gold, 0, '.', ' ')) ?></dd></div>
        <div class="play-hud-stat is-level">
            <dt>📈 Уровень</dt>
            <dd><?= $level ?><span class="play-hud-sub">опыт <?= esc($num($h['experience'] ?? '')) ?></span></dd>
            <?php if ($percent !== null && $next !== null): ?>
                <progress class="play-hud-bar" max="100" value="<?= max(0, min(100, $percent)) ?>" aria-label="До уровня <?= $next ?>: <?= $percent ?>%"><?= $percent ?>%</progress>
            <?php endif ?>
        </div>
        <div class="play-hud-stat is-cell">
            <dt>🧭 Клетка</dt>
            <dd><?= $cell !== null ? 'X=' . (int) $cell['x'] . ' Y=' . (int) $cell['y'] : '—' ?><?php if ($biome !== null): ?><span class="play-hud-sub"><?= esc($biome) ?></span><?php endif ?></dd>
        </div>
    </dl>
    <div class="play-hud-task<?= $task === null ? ' is-idle' : '' ?>">
        <?php if ($task === null): ?>
            <span class="play-hud-task-name">Свободен — активных задач нет</span>
        <?php else: ?>
            <span class="play-hud-task-name">⏳ <?= esc(is_string($task['name'] ?? null) ? $task['name'] : 'Задача') ?><?= $more > 0 ? ' <span class="play-hud-sub">и ещё ' . $more . '</span>' : '' ?></span>
            <?php if ($endsAt !== null): ?>
                <time class="play-hud-timer" data-ends-at="<?= $endsAt ?>" datetime="<?= esc(date('c', $endsAt), 'attr') ?>"><?= esc($left($endsAt - time())) ?></time>
            <?php endif ?>
        <?php endif ?>
    </div>
    <?php if ($march !== null): ?>
        <?php
        $mDone    = is_int($march['steps_done'] ?? null) ? $march['steps_done'] : 0;
        $mPlanned = is_int($march['steps_planned'] ?? null) ? max(1, $march['steps_planned']) : 1;
        $mPaused  = ($march['status'] ?? '') === 'paused';
        $mEta     = is_int($march['eta'] ?? null) ? $march['eta'] : null;
        $mForm    = static function (string $op, string $label) use ($viewUrl): string {
            return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
                . '<input type="hidden" name="view" value="map"><input type="hidden" name="op" value="' . esc($op, 'attr') . '">'
                . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
                . '<button class="play-hud-march-btn" type="submit">' . esc($label) . '</button></form>';
        };
        ?>
        <div class="play-hud-march<?= $mPaused ? ' is-paused' : '' ?>">
            <span class="play-hud-task-name"><?= $mPaused ? '⏸ Поход на паузе' : '🚜 Поход' ?>: <?= esc(is_string($march['heading_label'] ?? null) ? $march['heading_label'] : '') ?> · <?= $mDone ?>/<?= $mPlanned ?> клеток</span>
            <progress class="play-hud-bar" max="<?= $mPlanned ?>" value="<?= min($mDone, $mPlanned) ?>" aria-label="Пройдено <?= $mDone ?> из <?= $mPlanned ?> клеток"><?= $mDone ?>/<?= $mPlanned ?></progress>
            <?php if ($mEta !== null): ?>
                <span class="play-hud-sub">до прибытия</span>
                <time class="play-hud-timer" data-ends-at="<?= $mEta ?>" datetime="<?= esc(date('c', $mEta), 'attr') ?>"><?= esc($left($mEta - time())) ?></time>
            <?php endif ?>
            <?php if ($mPaused): ?>
                <?= $mForm('march_resume', '🚜 Продолжить') ?>
            <?php endif ?>
            <?= $mForm('march_stop', '❌ Остановиться') ?>
        </div>
    <?php endif ?>
</div>
