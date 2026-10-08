<?php
/**
 * w2-n7-combat-03 (ADR-190) — карточка боя на `/play` (`view=battle`, `id`): веб-рендерер модели
 * {@see \App\Services\Web\WebNativeScreenService::battleModel()} поверх {@see \App\Services\PVE\BattleJournalService}.
 *
 * Итог, тип, соперник, время и разбор — все раунды (в боте длинный бой режется в лимит сообщения, здесь — нет).
 * Дуэль помечена «без потерь». Чужой или несуществующий бой ядро не отдаёт — карточка отвечает одним и тем же
 * отказом, без имён. Всё текстом, без картинок; id персонажа в разметку не идут.
 *
 * @var array<string, mixed> $battle
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

use App\Services\Web\WebNativeScreenService;

$m         = is_array($battle ?? null) ? $battle : [];
$card      = is_array($m['card'] ?? null) ? $m['card'] : null;
$arenaOn   = ($m['arena_on'] ?? false) === true;
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$str       = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;
// Формат как у бота (`BattleJournalAction::num`): меньше 1 — двумя знаками, чтобы 0.01 не читалось как «−0».
$num       = static function (mixed $v): string {
    $f = is_int($v) || is_float($v) ? (float) $v : 0.0;
    if ($f >= 10.0) {
        return (string) (int) round($f);
    }
    if ($f > 0.0 && $f < 1.0) {
        return rtrim(rtrim(number_format(max($f, 0.01), 2, '.', ''), '0'), '.');
    }

    return rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
};

/** Переход на экран «⚔️ Боёв». */
$go = static function (string $view, string $text, string $class = 'play-kb-btn') use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="' . esc($view, 'attr') . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

$rounds = $card !== null && is_array($card['rounds'] ?? null) ? $card['rounds'] : [];
$at     = $card !== null && is_string($card['at'] ?? null) && $card['at'] !== '' ? strtotime($card['at']) : false;
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Бои</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="battle" aria-labelledby="play-native-battle-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-battle-title">📜 Разбор боя</h2>
                <?php if ($card !== null): ?>
                    <p class="play-craft-crumbs"><?= esc($str($card['me'] ?? '', 'Ты')) ?> против <?= esc($str($card['opponent'] ?? '', 'соперник')) ?></p>
                <?php endif ?>
            </header>

            <?php if ($card === null): ?>
                <p class="play-native-hint" data-battle-missing>Этот бой не найден в твоём журнале. Здесь открываются только твои бои — список в 📜 Мои бои.</p>
            <?php else: ?>
                <dl class="play-craft-facts">
                    <div><dt>Итог</dt><dd><?= esc(WebNativeScreenService::BATTLE_RESULT_LABELS[$str($card['result'] ?? '')] ?? WebNativeScreenService::BATTLE_RESULT_LABELS['unknown']) ?></dd></div>
                    <div><dt>Бой</dt><dd><?= esc(WebNativeScreenService::BATTLE_TYPE_LABELS[$str($card['type'] ?? '')] ?? '⚔️ Бой') ?></dd></div>
                    <div><dt>Раундов</dt><dd><?= is_int($card['rounds_total'] ?? null) ? $card['rounds_total'] : 0 ?></dd></div>
                    <div><dt>Когда</dt><dd><?= $at === false ? '—' : esc(date('d.m H:i', $at)) ?></dd></div>
                </dl>
                <?php if (($card['duel'] ?? false) === true): ?>
                    <p class="play-native-note">🤺 Дуэль — без потерь: здоровье, опыт и ресурсы не меняются.</p>
                <?php endif ?>

                <section class="play-native-section" aria-label="Разбор по раундам">
                    <h3 class="play-native-subtitle is-plain">⚔️ По раундам</h3>
                    <?php if ($rounds === []): ?>
                        <p class="play-native-hint">Подробности раундов для этого боя не сохранились — остался только итог.</p>
                    <?php else: ?>
                        <ul class="play-craft-reqs" aria-label="Раунд: кто бил, урон и остаток здоровья">
                            <?php foreach ($rounds as $r): ?>
                                <?php if (! is_array($r)) { continue; } ?>
                                <?php
                                $damage = is_int($r['damage'] ?? null) || is_float($r['damage'] ?? null) ? (float) $r['damage'] : 0.0;
                                $hit    = $damage > 0.0 ? '−' . $num($damage) . (($r['lucky'] ?? false) === true ? ' ⚡' : '') : 'промах';
                                $left   = is_int($r['hp_after'] ?? null) || is_float($r['hp_after'] ?? null) ? ' · осталось ' . $num($r['hp_after']) . ' HP' : '';
                                ?>
                                <li class="play-craft-req"><span class="play-craft-req-name"><?= (is_int($r['n'] ?? null) ? $r['n'] : 0) . '. ' . esc($str($r['attacker'] ?? '', '?')) . ' → ' . esc($str($r['defender'] ?? '', '?')) ?></span><span class="play-craft-req-qty"><?= esc($hit . $left) ?></span></li>
                            <?php endforeach ?>
                        </ul>
                        <p class="play-native-hint">⚡ — удачный удар.</p>
                    <?php endif ?>
                </section>
            <?php endif ?>

            <nav class="play-kb" aria-label="Куда дальше">
                <div class="play-kb-grid">
                    <?= $go(WebNativeScreenService::VIEW_BATTLES, '📜 Мои бои', 'play-kb-btn is-primary') ?>
                    <?= $go(WebNativeScreenService::VIEW_ARENA, $arenaOn ? '🏟 Арена' : '🔒 Арена (закрыта)') ?>
                    <?= $go(WebNativeScreenService::VIEW_ME, '◀️ Я') ?>
                </div>
            </nav>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
