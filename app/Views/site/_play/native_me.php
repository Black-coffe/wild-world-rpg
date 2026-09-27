<?php
/**
 * W2.N1-01 (ADR-190) — нативный экран «Я» на `/play`: веб-рендерер модели персонажа
 * ({@see \App\Services\Player\CharacterSheetService}), из которой рисуется и карточка бота.
 *
 * Отдаётся целиком в `#play-state` (как экран моста) и работает без JS. Готовые фразы чужих
 * сервисов (полярная звезда, лестница, раны, серия) — legacy-Markdown Telegram, переводятся в
 * HTML тем же `TelegramMarkupRenderer`, что и мост. Кнопки без нативного экрана — формы
 * POST `/play/view` с `op=bridge`: тот же callback уходит в мост (ADR-189). Telegram/chat id
 * сюда не передаётся и не выводится.
 *
 * @var array<string, mixed>  $sheet
 * @var list<list<string>>    $dock
 * @var string|null           $alert
 */

use App\Services\Player\CharacterSheetService;
use App\Services\Web\TelegramMarkupRenderer;

$s         = is_array($sheet ?? null) ? $sheet : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$num       = static fn (mixed $v): string => CharacterSheetService::displayNumber(is_scalar($v) ? (string) $v : '');
$md        = static fn (mixed $line): string => is_string($line) && $line !== '' ? TelegramMarkupRenderer::toHtml($line, 'Markdown') : '';
$str       = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
$cell      = is_array($s['cell'] ?? null) ? $s['cell'] : null;
$debuffs   = is_array($s['debuffs'] ?? null) ? $s['debuffs'] : [];
$drone     = is_array($s['drone'] ?? null) ? $s['drone'] : null;
$gold      = is_int($s['gold'] ?? null) ? $s['gold'] : 0;
$actions   = array_merge(
    is_array($s['personal_actions'] ?? null) ? $s['personal_actions'] : [],
    is_array($s['tail_actions'] ?? null) ? $s['tail_actions'] : []
);

$stats = [
    '🌟 Опыт'           => $num($s['experience'] ?? ''),
    '🤸‍♂️ Ловкость'       => $num($s['agility'] ?? ''),
    '🧠 Интеллект'      => $num($s['intellect'] ?? ''),
    '💪 Сила'           => $num($s['strength'] ?? ''),
    '💖 Здоровье'       => $num($s['health'] ?? ''),
    '🥱 Выносливость'   => $num($s['tired'] ?? ''),
    '💹 Карма торговли' => $num($s['trading_karma'] ?? ''),
    '💰 Золото'         => $gold > 0 ? number_format($gold, 0, '.', ' ') : 'нет',
    '🎢 Изучено ячеек'  => $str($s['explored'] ?? 0),
    '💼 Видов ресурсов' => $str($s['resource_kinds'] ?? 0),
    '⏳ В игре'         => $str($s['time_in_game'] ?? ''),
];
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ кнопки</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="me" aria-labelledby="play-native-me-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-me-title">🤖 <?= esc($str($s['name'] ?? '')) ?></h2>
                <div class="play-native-tags">
                    <span class="badge">📈 Уровень <?= esc($str($s['level'] ?? '')) ?></span>
                    <?php if (is_string($s['faction'] ?? null) && $s['faction'] !== ''): ?>
                        <span class="badge">🏳️ <?= esc($s['faction']) ?></span>
                    <?php endif ?>
                    <?php if (is_string($s['title'] ?? null)): ?>
                        <span class="badge">🎖 <?= esc($s['title']) ?></span>
                    <?php endif ?>
                    <?php if ($cell !== null): ?>
                        <span class="badge">🧭 X=<?= (int) $cell['x'] ?> Y=<?= (int) $cell['y'] ?> · 🌄 <?= esc($str($s['biome'] ?? '')) ?></span>
                    <?php endif ?>
                </div>
            </header>

            <?php if (($s['polar_line'] ?? null) !== null): ?>
                <div class="play-native-note"><?= $md($s['polar_line']) ?></div>
            <?php endif ?>

            <?php if (($s['ladder_line'] ?? null) !== null): ?>
                <section class="play-native-section" aria-label="Прогресс уровня">
                    <div class="play-msg-text"><?= $md($s['ladder_line']) ?></div>
                    <?php if (($s['unlock_line'] ?? null) !== null): ?>
                        <div class="play-msg-text"><?= $md($s['unlock_line']) ?></div>
                    <?php endif ?>
                </section>
            <?php endif ?>

            <section class="play-native-section" aria-label="Характеристики">
                <dl class="play-sheet">
                    <?php foreach ($stats as $label => $value): ?>
                        <div class="play-sheet-row"><dt><?= esc($label) ?></dt><dd><?= esc($value) ?></dd></div>
                    <?php endforeach ?>
                </dl>
            </section>

            <?php if ($debuffs !== []): ?>
                <section class="play-native-section is-warning" aria-label="Раны">
                    <h3 class="play-native-subtitle">🩺 Раны</h3>
                    <?php foreach ($debuffs as $line): ?>
                        <div class="play-msg-text"><?= $md($line) ?></div>
                    <?php endforeach ?>
                    <p class="play-native-hint">Еда их не снимает — нужен предмет из «💊 Аптечки».</p>
                </section>
            <?php endif ?>

            <section class="play-native-section" aria-label="Снаряжение">
                <dl class="play-sheet">
                    <div class="play-sheet-row"><dt>🛡 Броня</dt><dd><?= esc(is_string($s['armor'] ?? null) && $s['armor'] !== '' ? $s['armor'] : '❌ Нет') ?></dd></div>
                    <div class="play-sheet-row"><dt>⚔️ Оружие</dt><dd><?= esc(is_string($s['weapon'] ?? null) && $s['weapon'] !== '' ? $s['weapon'] : '❌ Нет') ?></dd></div>
                    <?php if (is_string($s['specialization'] ?? null)): ?>
                        <div class="play-sheet-row"><dt>🎓 Специализация</dt><dd><?= esc($s['specialization']) ?></dd></div>
                    <?php endif ?>
                    <?php if ($drone !== null): ?>
                        <div class="play-sheet-row"><dt>🛡 Боевой дрон</dt><dd>активен <?= (int) $drone['minutes'] ?> мин (+<?= (int) $drone['bonus'] ?>% инициативы)</dd></div>
                    <?php endif ?>
                </dl>
            </section>

            <?php if (($s['streak_line'] ?? null) !== null || ($s['milestone_line'] ?? null) !== null): ?>
                <section class="play-native-section" aria-label="Серия входов">
                    <?php foreach (['streak_line', 'milestone_line'] as $key): ?>
                        <?php if (($s[$key] ?? null) !== null): ?>
                            <div class="play-msg-text"><?= $md($s[$key]) ?></div>
                        <?php endif ?>
                    <?php endforeach ?>
                </section>
            <?php endif ?>

            <nav class="play-kb" aria-label="Действия персонажа">
                <div class="play-kb-grid">
                    <?php foreach ($actions as $action): ?>
                        <?php if (! is_array($action) || ! is_string($action['label'] ?? null)) { continue; } ?>
                        <?php if (is_string($action['url'] ?? null) && preg_match('~^https?://~i', $action['url']) === 1): ?>
                            <a class="play-kb-btn is-url" href="<?= esc($action['url'], 'attr') ?>" target="_blank" rel="noopener"><?= esc($action['label']) ?></a>
                        <?php elseif (is_string($action['callback'] ?? null)): ?>
                            <form action="<?= esc($viewUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="op" value="bridge"><input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="data" value="<?= esc($action['callback'], 'attr') ?>"><button class="play-kb-btn" type="submit"><?= esc($action['label']) ?></button></form>
                        <?php endif ?>
                    <?php endforeach ?>
                </div>
            </nav>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
