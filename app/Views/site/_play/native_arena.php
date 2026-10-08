<?php
/**
 * w2-n7-combat-03 (ADR-190) — «🏟 Арена» и «🏆 Рейтинг PvP» на `/play`: веб-рендерер модели
 * {@see \App\Services\Web\WebNativeScreenService::arenaModel()} поверх {@see \App\Services\PVE\ArenaScreenService}, того
 * же ядра, из которого рисует бот.
 *
 * Арена: кто открыт к дуэлям и вызов (`op=duel`, `id` соперника), итог последней дуэли со входом в разбор
 * (`view=battle`), «открыться / закрыться» (`op=duels_open`, `open`). Рейтинг: таблица, моя позиция, вкладки
 * «🌍 Глобальный / 🏳️ Моя фракция» (`f`). Выключенный раздел — замок с объяснением и путём в журнал (он работает всегда).
 * Мутации — формы POST `/play/view` со своим `intent_id`, без JS — PRG. Всё текстом, без картинок; id персонажа в
 * разметку не идут (id соперников — только в кнопке вызова, как `arenaDuel_<id>` у бота).
 *
 * @var array<string, mixed> $arena
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

use App\Services\PVE\ArenaScreenService;
use App\Services\Web\WebNativeScreenService;

$m         = is_array($arena ?? null) ? $arena : [];
$isLadder  = ($m['section'] ?? '') === 'ladder';
$a         = is_array($m['arena'] ?? null) ? $m['arena'] : [];
$l         = is_array($m['ladder'] ?? null) ? $m['ladder'] : [];
$last      = is_array($m['last'] ?? null) ? $m['last'] : null;
$arenaOn   = ($m['arena_on'] ?? false) === true;
$ladderOn  = ($m['ladder_on'] ?? false) === true;
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$str       = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;
$int       = static fn (mixed $v): int => is_int($v) ? $v : 0;

/** Переход на экран «⚔️ Боёв» (`view` + навигация). */
$go = static function (string $view, array $nav, string $text, string $class = 'play-kb-btn') use ($viewUrl): string {
    $hidden = '';
    foreach ($nav as $name => $value) {
        $hidden .= '<input type="hidden" name="' . esc((string) $name, 'attr') . '" value="' . esc((string) $value, 'attr') . '">';
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="' . esc($view, 'attr') . '">' . $hidden
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

/** Мутация арены — форма с `op`, полями и своим `intent_id`. */
$act = static function (string $op, array $fields, string $text, string $class = 'play-kb-btn') use ($viewUrl): string {
    $hidden = '';
    foreach ($fields as $name => $value) {
        $hidden .= '<input type="hidden" name="' . esc((string) $name, 'attr') . '" value="' . esc((string) $value, 'attr') . '">';
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="arena"><input type="hidden" name="op" value="' . esc($op, 'attr') . '">' . $hidden
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

if ($isLadder) {
    $title = $ladderOn
        ? '🏆 Рейтинг PvP — ' . (isset($l['faction_id']) && $l['faction_id'] !== null ? $str($l['faction_name'] ?? '', 'фракция') : '🌍 Глобальный')
        : '🏆 Рейтинг PvP';
} else {
    $title = '🏟 Арена — равные дуэли';
}
$medals = ['🥇', '🥈', '🥉'];
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title"><?= $isLadder ? 'Рейтинг' : 'Арена' ?></span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="<?= $isLadder ? 'ladder' : 'arena' ?>" aria-labelledby="play-native-arena-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-arena-title"><?= esc($title) ?></h2>
                <p class="play-craft-crumbs"><?= $isLadder
                    ? 'Очки за победы: дуэль и летальное PvP. Рейтинг — престиж, без игровых наград.'
                    : 'Спортивный поединок на равных статах: ни здоровья, ни опыта не теряется. Решают билд и удача. Победы идут в 🏆 Рейтинг PvP.' ?></p>
            </header>

            <nav class="play-kb" aria-label="Разделы боёв">
                <div class="play-kb-grid">
                    <?= $go(WebNativeScreenService::VIEW_BATTLES, [], '📜 Мои бои') ?>
                    <?= $go(WebNativeScreenService::VIEW_ARENA, [], ($isLadder ? '' : '• ') . ($arenaOn ? '🏟 Арена' : '🔒 Арена (закрыта)'), 'play-kb-btn' . ($isLadder ? '' : ' is-primary')) ?>
                    <?= $go(WebNativeScreenService::VIEW_LADDER, [], ($isLadder ? '• ' : '') . ($ladderOn ? '🏆 Рейтинг PvP' : '🔒 Рейтинг PvP (закрыт)'), 'play-kb-btn' . ($isLadder ? ' is-primary' : '')) ?>
                </div>
            </nav>

            <?php if (! $isLadder): ?>
                <?php if (! $arenaOn): ?>
                    <div class="play-lock" data-arena-lock>
                        <span class="play-lock-title">🔒 Арена (нужно: дуэли открыты администрацией)</span>
                        <span class="play-lock-why"><?= esc($str($a['lock'] ?? '', ArenaScreenService::LOCK_ARENA)) ?></span>
                        <span class="play-lock-path">Путь: пока арена закрыта, все твои бои — в ⚔️ Бои → 📜 Мои бои.</span>
                    </div>
                <?php else: ?>
                    <?php if ($last !== null): ?>
                        <section class="play-native-section" aria-label="Итог дуэли" data-duel-result>
                            <h3 class="play-native-subtitle is-plain">🤺 Итог дуэли</h3>
                            <dl class="play-craft-facts">
                                <div><dt>Итог</dt><dd><?= esc(WebNativeScreenService::BATTLE_RESULT_LABELS[$str($last['result'] ?? '')] ?? WebNativeScreenService::BATTLE_RESULT_LABELS['unknown']) ?></dd></div>
                                <div><dt>Соперник</dt><dd><?= esc($str($last['opponent'] ?? '', 'соперник')) ?></dd></div>
                                <div><dt>Раундов</dt><dd><?= $int($last['rounds_total'] ?? 0) ?></dd></div>
                            </dl>
                            <p class="play-native-note">🤺 Дуэль — без потерь: здоровье, опыт и ресурсы не меняются. Бой записан в журнал обоим.</p>
                            <div class="play-kb-grid">
                                <?= $go(WebNativeScreenService::VIEW_BATTLE, ['id' => $int($last['id'] ?? 0)], '📜 Разбор боя', 'play-kb-btn is-primary') ?>
                            </div>
                        </section>
                    <?php endif ?>

                    <?php $roster = is_array($a['roster'] ?? null) ? $a['roster'] : []; ?>
                    <section class="play-native-section" aria-label="Открытые бойцы">
                        <h3 class="play-native-subtitle is-plain">⚔️ Открытые бойцы</h3>
                        <?php if ($roster === []): ?>
                            <p class="play-native-hint">Пока никто не открыт для дуэлей. Откройся сам — тогда и тебя смогут вызвать.</p>
                        <?php else: ?>
                            <ul class="play-craft-reqs" aria-label="Боец, уровень и очки рейтинга">
                                <?php foreach ($roster as $r): ?>
                                    <?php if (! is_array($r)) { continue; } ?>
                                    <li class="play-craft-req"><span class="play-craft-req-name"><?= esc($str($r['name'] ?? '', 'боец')) ?></span><span class="play-craft-req-qty">ур. <?= $int($r['level'] ?? 0) ?><?= $int($r['pts'] ?? 0) > 0 ? ' · ' . $int($r['pts'] ?? 0) . ' очк.' : '' ?></span></li>
                                <?php endforeach ?>
                            </ul>
                            <p class="play-native-hint">Вызов — бой сразу, на равных статах. Повторный вызов — после короткой паузы.</p>
                            <div class="play-kb-grid">
                                <?php foreach ($roster as $r): ?>
                                    <?php if (! is_array($r) || $int($r['id'] ?? 0) <= 0) { continue; } ?>
                                    <?= $act(WebNativeScreenService::OP_DUEL, ['id' => $int($r['id'] ?? 0)], '⚔️ Вызвать: ' . $str($r['name'] ?? '', 'боец')) ?>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>
                    </section>

                    <section class="play-native-section" aria-label="Моя открытость к дуэлям">
                        <?php if (($a['self_open'] ?? false) === true): ?>
                            <p class="play-native-hint">✅ Ты открыт для дуэлей — тебя могут вызвать с арены.</p>
                            <div class="play-kb-grid">
                                <?= $act(WebNativeScreenService::OP_DUELS_OPEN, ['open' => '0'], '🛡 Закрыться от дуэлей') ?>
                            </div>
                        <?php else: ?>
                            <p class="play-native-hint">🔒 Ты закрыт для дуэлей. Откройся — тогда и тебя смогут вызвать на арену.</p>
                            <div class="play-kb-grid">
                                <?= $act(WebNativeScreenService::OP_DUELS_OPEN, ['open' => '1'], '⚔️ Открыться к дуэлям', 'play-kb-btn is-primary') ?>
                            </div>
                        <?php endif ?>
                    </section>
                <?php endif ?>
            <?php else: ?>
                <?php if (! $ladderOn): ?>
                    <div class="play-lock" data-ladder-lock>
                        <span class="play-lock-title">🔒 Рейтинг PvP (нужно: раздел включён администрацией)</span>
                        <span class="play-lock-why"><?= esc($str($l['lock'] ?? '', ArenaScreenService::LOCK_LADDER)) ?></span>
                        <span class="play-lock-path">Путь: пока рейтинг закрыт, все твои бои — в ⚔️ Бои → 📜 Мои бои.</span>
                    </div>
                <?php else: ?>
                    <?php
                    $factionId = isset($l['faction_id']) && is_int($l['faction_id']) ? $l['faction_id'] : null;
                    $myFaction = $int($l['my_faction'] ?? 0);
                    $rows      = is_array($l['rows'] ?? null) ? $l['rows'] : [];
                    $my        = is_array($l['my'] ?? null) ? $l['my'] : null;
                    ?>
                    <?php if ($factionId !== null || $myFaction > 0): ?>
                        <nav class="play-kb" aria-label="Вкладки рейтинга">
                            <div class="play-kb-grid">
                                <?= $go(WebNativeScreenService::VIEW_LADDER, [], ($factionId === null ? '• ' : '') . '🌍 Глобальный', 'play-kb-btn' . ($factionId === null ? ' is-primary' : '')) ?>
                                <?php if ($myFaction > 0): ?>
                                    <?= $go(WebNativeScreenService::VIEW_LADDER, ['f' => $myFaction], ($factionId === $myFaction ? '• ' : '') . '🏳️ Моя фракция', 'play-kb-btn' . ($factionId === $myFaction ? ' is-primary' : '')) ?>
                                <?php endif ?>
                            </div>
                        </nav>
                    <?php endif ?>

                    <section class="play-native-section" aria-label="Таблица рейтинга">
                        <h3 class="play-native-subtitle is-plain">🏆 Таблица</h3>
                        <?php if ($rows === []): ?>
                            <p class="play-native-hint">Пока никто не набрал очков. Стань первым — выиграй дуэль на 🏟 Арене.</p>
                        <?php else: ?>
                            <ul class="play-craft-reqs" aria-label="Место, боец, очки и победы">
                                <?php foreach ($rows as $i => $r): ?>
                                    <?php if (! is_array($r)) { continue; } ?>
                                    <li class="play-craft-req"><span class="play-craft-req-name"><?= esc(($medals[$i] ?? ($i + 1) . '.') . ' ' . $str($r['name'] ?? '', 'боец')) ?></span><span class="play-craft-req-qty"><?= $int($r['points'] ?? 0) ?> очк. · дуэли <?= $int($r['duel_wins'] ?? 0) ?> · PvP <?= $int($r['pvp_wins'] ?? 0) ?></span></li>
                                <?php endforeach ?>
                            </ul>
                        <?php endif ?>
                    </section>

                    <section class="play-native-section" aria-label="Моя позиция">
                        <h3 class="play-native-subtitle is-plain">👤 Ты</h3>
                        <?php if ($my !== null): ?>
                            <dl class="play-craft-facts">
                                <div><dt>Место</dt><dd><?= $int($my['rank'] ?? 0) > 0 ? '#' . $int($my['rank'] ?? 0) : '—' ?></dd></div>
                                <div><dt>Очки</dt><dd><?= $int($my['points'] ?? 0) ?></dd></div>
                                <div><dt>Дуэли</dt><dd><?= $int($my['duel_wins'] ?? 0) ?></dd></div>
                                <div><dt>PvP</dt><dd><?= $int($my['pvp_wins'] ?? 0) ?></dd></div>
                            </dl>
                        <?php else: ?>
                            <p class="play-native-hint">Ты ещё не в рейтинге — выиграй дуэль на 🏟 Арене, чтобы попасть в таблицу.</p>
                        <?php endif ?>
                    </section>
                <?php endif ?>
            <?php endif ?>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
