<?php
/**
 * w2-n7-combat-03 (ADR-190) — «⚔️ Бои» на `/play`: журнал моих боёв, веб-рендерер модели
 * {@see \App\Services\Web\WebNativeScreenService::battlesModel()} поверх {@see \App\Services\PVE\BattleJournalService},
 * того же ядра, из которого бот рисует «📜 Мои бои».
 *
 * Разделы «⚔️ Боёв» — журнал, арена, рейтинг PvP; выключенные арена или рейтинг — кнопка с замком, за ней объяснение
 * (журнал работает всегда). Строка боя — итог, тип, соперник, время; нажатие открывает карточку с разбором по раундам
 * (`view=battle`, `id`). Всё текстом, без картинок; id персонажа в разметку не идут.
 *
 * @var array<string, mixed> $battles
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

use App\Services\Web\WebNativeScreenService;

$m         = is_array($battles ?? null) ? $battles : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$entries   = is_array($m['entries'] ?? null) ? $m['entries'] : [];
$arenaOn   = ($m['arena_on'] ?? false) === true;
$ladderOn  = ($m['ladder_on'] ?? false) === true;
$str       = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;
$when      = static function (mixed $at): string {
    $ts = is_string($at) && $at !== '' ? strtotime($at) : false;

    return $ts === false ? '' : date('d.m H:i', $ts);
};

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
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Бои</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="battles" aria-labelledby="play-native-battles-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-battles-title">⚔️ Бои</h2>
                <p class="play-craft-crumbs">📜 Мои бои — последние бои, новые сверху. Нажми бой — откроется разбор по раундам.</p>
            </header>

            <nav class="play-kb" aria-label="Разделы боёв">
                <div class="play-kb-grid">
                    <?= $go(WebNativeScreenService::VIEW_BATTLES, [], '• 📜 Мои бои', 'play-kb-btn is-primary') ?>
                    <?= $go(WebNativeScreenService::VIEW_ARENA, [], $arenaOn ? '🏟 Арена' : '🔒 Арена (закрыта)') ?>
                    <?= $go(WebNativeScreenService::VIEW_LADDER, [], $ladderOn ? '🏆 Рейтинг PvP' : '🔒 Рейтинг PvP (закрыт)') ?>
                </div>
            </nav>

            <section class="play-native-section" aria-label="Мои бои">
                <h3 class="play-native-subtitle is-plain">📜 Мои бои<?= $entries !== [] ? ' — ' . count($entries) : '' ?></h3>
                <?php if ($entries === []): ?>
                    <p class="play-native-hint">Боёв пока нет. Сюда попадает каждый твой бой: с тварями и рейдерами в Пустоши, PvP и дуэли на 🏟 Арене.</p>
                <?php else: ?>
                    <ul class="play-craft-reqs" aria-label="Бой: итог, тип, соперник и время">
                        <?php foreach ($entries as $i => $e): ?>
                            <?php if (! is_array($e)) { continue; } ?>
                            <?php
                            $type   = WebNativeScreenService::BATTLE_TYPE_LABELS[$str($e['type'] ?? '')] ?? '⚔️ Бой';
                            $result = WebNativeScreenService::BATTLE_RESULT_LABELS[$str($e['result'] ?? '')] ?? WebNativeScreenService::BATTLE_RESULT_LABELS['unknown'];
                            $at     = $when($e['at'] ?? null);
                            ?>
                            <li class="play-craft-req"><span class="play-craft-req-name"><?= ($i + 1) . '. ' . esc($result) . ' · ' . esc($type) . ' · ' . esc($str($e['opponent'] ?? '', 'соперник')) ?></span><span class="play-craft-req-qty"><?= esc($at) ?></span></li>
                        <?php endforeach ?>
                    </ul>
                    <p class="play-native-hint">Разбор боя — кнопка с его номером.</p>
                    <div class="play-kb-grid">
                        <?php foreach ($entries as $i => $e): ?>
                            <?php if (! is_array($e) || ! is_int($e['id'] ?? null)) { continue; } ?>
                            <?php $icon = match ($e['result'] ?? null) { 'win' => '✅', 'loss' => '❌', default => '❔' }; ?>
                            <?= $go(WebNativeScreenService::VIEW_BATTLE, ['id' => $e['id']], '📜 ' . ($i + 1) . '. ' . $icon . ' ' . $str($e['opponent'] ?? '', 'соперник')) ?>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </section>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
