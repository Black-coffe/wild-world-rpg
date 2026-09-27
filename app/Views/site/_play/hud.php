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
</div>
