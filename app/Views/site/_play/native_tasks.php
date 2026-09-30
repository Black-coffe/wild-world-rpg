<?php
/**
 * w2-n5-deeds-03 (ADR-190) — нативный экран «📋 Дела» на `/play`: веб-рендерер нейтральных моделей
 * {@see \App\Services\Tasks\TasksSurfaceService::model()} (хаб), {@see \App\Services\Quest\QuestListService}
 * (списки квестов, развилки) и {@see \App\Services\Events\EventsModelService} («События») — тех же, из которых
 * рисует бот.
 *
 * Разделы — формы POST `/play/view` (`view=tasks`, `section`, `id`), без JS — PRG. Хаб: идущие задачи с таймером
 * (`data-ends-at`, тикает `wildworld-play.js`) и «⛔️ Прервать» мостом, сводка квестов, задания дня с прогрессом
 * и наградой. Выключенный хаб или задания дня — замок «🔒 … (нужно: …)» с объяснением, как у бота раздел
 * закрыт. «Начать» и выбор ветки — `op=quest_start|quest_branch` с `intent_id`. «🌐 Квестомания» — мост.
 * Всё текстом, без картинок; id персонажа в разметку не идут.
 *
 * @var array<string, mixed> $tasks
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

$t         = is_array($tasks ?? null) ? $tasks : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$section   = is_string($t['section'] ?? null) ? $t['section'] : 'hub';
$hub       = is_array($t['hub'] ?? null) ? $t['hub'] : null;
$card      = is_array($t['card'] ?? null) ? $t['card'] : null;
$events    = is_array($t['events'] ?? null) ? $t['events'] : null;
$list      = static fn (string $key): array => is_array($t[$key] ?? null) ? $t[$key] : [];
$str       = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;
$int       = static fn (mixed $v): int => is_int($v) ? $v : 0;

$sections = [
    'hub'       => '📋 Сводка',
    'active'    => '🚀 Активные',
    'available' => '📜 Доступные',
    'completed' => '✅ Завершённые',
    'events'    => '🎉 События',
];

/** Переход по разделам — форма `view=tasks` с разделом и квестом. */
$go = static function (string $to, ?int $id, string $text, string $class) use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="tasks"><input type="hidden" name="section" value="' . esc($to, 'attr') . '">'
        . ($id !== null ? '<input type="hidden" name="id" value="' . $id . '">' : '')
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

/** Мутация «Дел» — форма с `op`, квестом и своим `intent_id`. */
$act = static function (string $op, int $id, string $text, string $class) use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="tasks"><input type="hidden" name="op" value="' . esc($op, 'attr') . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

/** Кнопка бота без нативного экрана — мост с той же `callback_data`. */
$bridge = static function (string $data, string $text) use ($viewUrl): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="op" value="bridge"><input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<input type="hidden" name="data" value="' . esc($data, 'attr') . '">'
        . '<button class="play-kb-btn" type="submit">' . esc($text) . '</button></form>';
};

$lock = static function (string $key, string $title, string $why, string $path): string {
    return '<div class="play-lock" data-tasks-lock="' . esc($key, 'attr') . '">'
        . '<span class="play-lock-title">' . esc($title) . '</span>'
        . '<span class="play-lock-why">' . esc($why) . '</span>'
        . '<span class="play-lock-path">Путь: ' . esc($path) . '</span></div>';
};

$reward = static fn (array $q): string => $int($q['reward'] ?? 0) > 0
    ? $int($q['reward']) . ($str($q['reward_type_ru'] ?? '') !== '' ? ' · ' . $str($q['reward_type_ru']) : '')
    : '';

$crumb = $card !== null ? '📜 ' . $str($card['title_ru'] ?? '', 'Квест') : ($sections[$section] ?? '');
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Ответ кнопки</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="tasks" aria-labelledby="play-native-tasks-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-tasks-title">📋 Дела</h2>
                <p class="play-craft-crumbs"><?= esc('Дела → ' . $crumb) ?></p>
            </header>

            <nav class="play-kb" aria-label="Разделы «Дел»">
                <div class="play-kb-grid">
                    <?php foreach ($sections as $key => $label): ?>
                        <?= $go($key, null, $label, 'play-kb-btn' . ($section === $key && $card === null ? ' is-primary' : '')) ?>
                    <?php endforeach ?>
                </div>
            </nav>

            <?php if ($section === 'hub'): ?>
                <?php if ($hub === null): ?>
                    <?= $lock(
                        'hub',
                        '🔒 Сводка «Дела» (нужно: раздел включат на сервере)',
                        'Сводка задач и квестов сейчас закрыта — и в боте, и здесь. Идущие задачи видны в строке задач сверху, квесты и события открываются кнопками выше.',
                        '📋 Дела → 🚀 Активные / 📜 Доступные / 🎉 События'
                    ) ?>
                <?php else: ?>
                    <?php
                    $running = is_array($hub['tasks'] ?? null) ? $hub['tasks'] : [];
                    $summary = is_array($hub['summary'] ?? null) ? $hub['summary'] : [];
                    $daily   = is_array($hub['daily'] ?? null) ? $hub['daily'] : [];
                    $sumDay  = is_array($summary['daily'] ?? null) ? $summary['daily'] : [];
                    ?>
                    <?php if ($str($hub['polar_star'] ?? '') !== ''): ?>
                        <div class="play-native-note" data-tasks-star><?= esc($str($hub['polar_star'])) ?></div>
                    <?php endif ?>

                    <section class="play-native-section" aria-label="Идёт сейчас">
                        <h3 class="play-native-subtitle is-plain">⏳ Идёт сейчас<?= $running !== [] ? ' (' . count($running) . ')' : '' ?></h3>
                        <?php if ($running === []): ?>
                            <p class="play-native-hint">Ничего. Ты свободен — можно идти, добывать или крафтить.</p>
                        <?php else: ?>
                            <ul class="play-craft-jobs">
                                <?php foreach ($running as $i => $task): ?>
                                    <?php if (! is_array($task)) { continue; } $endsAt = strtotime($str($task['ends_at'] ?? '')); ?>
                                    <li class="play-craft-job is-active">
                                        <span class="play-craft-job-name"><?= $int($i) + 1 ?>) <?= esc($str($task['name'] ?? '', 'Задача')) ?></span>
                                        <span class="play-craft-job-meta">осталось
                                            <?php if ($endsAt !== false && $endsAt > time()): ?>
                                                <time class="play-craft-timer" data-ends-at="<?= $endsAt ?>" datetime="<?= esc(date('c', $endsAt), 'attr') ?>"><?= esc($str($task['left'] ?? '')) ?></time>
                                            <?php else: ?>
                                                <?= esc($str($task['left'] ?? '', 'вот-вот')) ?>
                                            <?php endif ?>
                                        </span>
                                        <?= $bridge('finishAllTasks_' . $int($task['id'] ?? 0), '⛔️ Прервать') ?>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                            <p class="play-native-hint">Задача завершится сама — награда придёт во входящие 🔔. «Прервать» останавливает задачу, награда за неё пропадает.</p>
                        <?php endif ?>
                    </section>

                    <section class="play-native-section" aria-label="Квесты">
                        <h3 class="play-native-subtitle is-plain">📜 Квесты</h3>
                        <?php if ($int($summary['branches'] ?? 0) > 0): ?>
                            <div class="play-native-note" data-tasks-branch>🔀 Развилка цепочки ждёт выбор — открой «📜 Доступные». Решение необратимо.</div>
                        <?php endif ?>
                        <dl class="play-craft-facts">
                            <div><dt>🚀 Активных</dt><dd><?= $int($summary['active'] ?? 0) ?></dd></div>
                            <div><dt>📜 Доступно</dt><dd><?= $int($summary['available'] ?? 0) ?></dd></div>
                            <div><dt>🔒 По цепочке</dt><dd><?= $int($summary['locked'] ?? 0) ?></dd></div>
                            <div><dt>✅ Завершено</dt><dd><?= $int($summary['completed'] ?? 0) ?></dd></div>
                        </dl>
                        <div class="play-kb-grid">
                            <?= $bridge('questInfo', '🌐 Квестомания') ?>
                        </div>
                    </section>

                    <section class="play-native-section" aria-label="Задания дня">
                        <h3 class="play-native-subtitle is-plain">🗓 Задания дня</h3>
                        <?php if (($t['daily_enabled'] ?? false) !== true): ?>
                            <?= $lock(
                                'daily',
                                '🔒 Задания дня (нужно: раздел включат на сервере)',
                                'Задания дня сейчас выключены — и в боте, и здесь. Когда их включат, набор на сегодня появится здесь сам.',
                                '📋 Дела → 📋 Сводка → 🗓 Задания дня'
                            ) ?>
                        <?php elseif ($daily === []): ?>
                            <p class="play-native-hint">Набор на сегодня ещё собирается — обнови экран.</p>
                        <?php else: ?>
                            <?php $done = count(array_filter($daily, static fn (mixed $d): bool => is_array($d) && ($d['is_completed'] ?? false) === true)); ?>
                            <ul class="play-craft-reqs" aria-label="Задания дня: прогресс и награда">
                                <?php foreach ($daily as $d): ?>
                                    <?php if (! is_array($d)) { continue; } $ok = ($d['is_completed'] ?? false) === true; ?>
                                    <li class="play-craft-req<?= $ok ? '' : ' is-short' ?>"><span class="play-craft-req-name"><?= $ok ? '✅' : '▫️' ?> <?= esc($str($d['title'] ?? '')) ?> · +<?= $int($d['reward_gold'] ?? 0) ?> зол.</span><span class="play-craft-req-qty"><?= $int($d['progress'] ?? 0) ?> / <?= $int($d['objective_qty'] ?? 0) ?></span></li>
                                <?php endforeach ?>
                            </ul>
                            <p class="play-native-hint">Выполнено <?= $done ?> из <?= count($daily) ?><?= $done < count($daily) && $int($sumDay['bonus'] ?? 0) > 0 ? ' · за все — бонус +' . $int($sumDay['bonus']) . ' золота' : '' ?>. Прогресс считается сам; набор обновляется каждый день.</p>
                        <?php endif ?>
                    </section>
                <?php endif ?>

            <?php elseif ($card !== null): ?>
                <?php $status = $str($card['status'] ?? ''); $qid = $int($card['id'] ?? 0); ?>
                <section class="play-craft-card" data-tasks-quest="<?= $qid ?>" aria-labelledby="play-tasks-quest-title">
                    <h3 class="play-craft-card-title" id="play-tasks-quest-title">📜 <?= esc($str($card['title_ru'] ?? '', 'Квест')) ?></h3>
                    <?php if ($str($card['description'] ?? '') !== ''): ?>
                        <p class="play-base-effect"><?= esc($str($card['description'])) ?></p>
                    <?php endif ?>
                    <dl class="play-craft-facts">
                        <div><dt>🏆 Награда</dt><dd><?= $reward($card) !== '' ? esc($reward($card)) : 'без награды' ?></dd></div>
                        <div><dt>📍 Статус</dt><dd><?= esc(match ($status) {
                            'active'    => 'идёт',
                            'completed' => 'завершён',
                            'locked'    => 'заперт',
                            default     => 'можно начать',
                        }) ?></dd></div>
                    </dl>
                    <?php if ($status === 'available'): ?>
                        <div class="play-kb-grid">
                            <?= $act('quest_start', $qid, '▶️ Начать квест', 'play-kb-btn is-primary') ?>
                        </div>
                    <?php elseif ($status === 'locked'): ?>
                        <?= $lock('quest', '🔒 ' . $str($card['title_ru'] ?? '') . ' (нужно: ' . $str($card['lock_reason'] ?? '') . ')', 'Это звено цепочки — оно откроется, когда завершишь предыдущий квест.', '📋 Дела → 📜 Доступные → ' . $str($card['prereq_title_ru'] ?? '')) ?>
                    <?php elseif ($status === 'active'): ?>
                        <p class="play-native-hint">Квест идёт: прогресс засчитывается сам, награда придёт во входящие 🔔.</p>
                    <?php else: ?>
                        <p class="play-native-hint">✅ Квест завершён, награда получена.</p>
                    <?php endif ?>
                </section>

            <?php elseif ($section === 'active' || $section === 'completed'): ?>
                <?php $quests = $list($section); ?>
                <section class="play-native-section" aria-label="<?= esc($sections[$section], 'attr') ?>">
                    <h3 class="play-native-subtitle is-plain"><?= esc($sections[$section]) ?></h3>
                    <?php if ($quests === []): ?>
                        <p class="play-native-hint"><?= $section === 'active'
                            ? 'Активных квестов нет. Начни доступный — в «📜 Доступные».'
                            : 'Пока ни одного завершённого квеста.' ?></p>
                    <?php else: ?>
                        <div class="play-kb-grid">
                            <?php foreach ($quests as $q): ?>
                                <?php if (! is_array($q)) { continue; } ?>
                                <?= $go('quest', $int($q['id'] ?? 0), ($section === 'active' ? '🛡️ ' : '✅ ') . $str($q['title_ru'] ?? '') . ($reward($q) !== '' ? ' · 🏆 ' . $reward($q) : ''), 'play-kb-btn') ?>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                </section>

            <?php elseif ($section === 'available'): ?>
                <?php $avail = $list('available'); $open = array_filter($avail, static fn (mixed $q): bool => is_array($q) && ($q['locked'] ?? false) !== true); ?>
                <?php foreach ($list('branches') as $branch): ?>
                    <?php if (! is_array($branch)) { continue; } ?>
                    <section class="play-native-section is-warning" data-tasks-branches aria-label="Развилка">
                        <h3 class="play-native-subtitle">🔀 Развилка после «<?= esc($str($branch['branch_point_ru'] ?? '')) ?>»</h3>
                        <p class="play-native-hint">Выбери один путь — остальные закроются навсегда.</p>
                        <div class="play-kb-grid">
                            <?php foreach (is_array($branch['options'] ?? null) ? $branch['options'] : [] as $opt): ?>
                                <?php if (! is_array($opt)) { continue; } ?>
                                <?= $act('quest_branch', $int($opt['quest_id'] ?? 0), '🔀 ' . $str($opt['label'] ?? '', $str($opt['title_ru'] ?? '')), 'play-kb-btn') ?>
                            <?php endforeach ?>
                        </div>
                    </section>
                <?php endforeach ?>
                <section class="play-native-section" aria-label="Доступные квесты">
                    <h3 class="play-native-subtitle is-plain">📜 Доступные</h3>
                    <?php if ($open === []): ?>
                        <p class="play-native-hint">Сейчас нечего начать: новые квесты открываются с уровнем и по цепочкам.</p>
                    <?php else: ?>
                        <div class="play-kb-grid">
                            <?php foreach ($open as $q): ?>
                                <?= $go('quest', $int($q['id'] ?? 0), '📜 ' . $str($q['title_ru'] ?? '') . ($reward($q) !== '' ? ' · 🏆 ' . $reward($q) : ''), 'play-kb-btn') ?>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                    <?php foreach ($avail as $q): ?>
                        <?php if (! is_array($q) || ($q['locked'] ?? false) !== true) { continue; } ?>
                        <?= $lock('chain', '🔒 ' . $str($q['title_ru'] ?? '') . ' (нужно: ' . $str($q['lock_reason'] ?? '') . ')', 'Звено цепочки — откроется, когда завершишь предыдущий квест.', '📋 Дела → 📜 Доступные → ' . $str($q['prereq_title_ru'] ?? '')) ?>
                    <?php endforeach ?>
                </section>

            <?php elseif ($section === 'events'): ?>
                <?php $activeEv = is_array($events['active'] ?? null) ? $events['active'] : []; $pastEv = is_array($events['past'] ?? null) ? $events['past'] : []; ?>
                <section class="play-native-section" aria-label="Идут сейчас">
                    <h3 class="play-native-subtitle is-plain">🎉 Идут сейчас</h3>
                    <?php if ($activeEv === []): ?>
                        <p class="play-native-hint">Сейчас на острове спокойно — событий нет.</p>
                    <?php else: ?>
                        <ul class="play-craft-jobs">
                            <?php foreach ($activeEv as $ev): ?>
                                <?php if (! is_array($ev)) { continue; } $biomes = is_array($ev['biomes'] ?? null) ? $ev['biomes'] : []; ?>
                                <li class="play-craft-job is-active">
                                    <span class="play-craft-job-name"><?= esc($str($ev['name'] ?? '', 'Событие')) ?></span>
                                    <?php if ($str($ev['description'] ?? '') !== ''): ?>
                                        <span class="play-craft-job-meta"><?= esc($str($ev['description'])) ?></span>
                                    <?php endif ?>
                                    <span class="play-craft-job-meta">📍 <?= esc($str($ev['where_ru'] ?? '', '—')) ?><?= $biomes !== [] ? ': ' . esc(implode(', ', array_map($str, $biomes))) : '' ?> · ✨ <?= esc($str($ev['effect_ru'] ?? '', '—')) ?></span>
                                    <span class="play-craft-job-meta">⏳ осталось <?= esc($str($ev['time_left'] ?? '', '—')) ?> · <?= ($ev['touched'] ?? false) === true ? '⚡ задело тебя' : 'тебя не задело' ?></span>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                </section>
                <section class="play-native-section" aria-label="Прошедшие события">
                    <h3 class="play-native-subtitle is-plain">📜 Прошедшие</h3>
                    <?php if ($pastEv === []): ?>
                        <p class="play-native-hint">Прошедших событий пока нет.</p>
                    <?php else: ?>
                        <ul class="play-craft-jobs">
                            <?php foreach ($pastEv as $ev): ?>
                                <?php if (! is_array($ev)) { continue; } $biomes = is_array($ev['biomes'] ?? null) ? $ev['biomes'] : []; ?>
                                <li class="play-craft-job is-queued">
                                    <span class="play-craft-job-name"><?= esc($str($ev['name'] ?? '', 'Событие')) ?></span>
                                    <span class="play-craft-job-meta"><?= esc($str($ev['start_ru'] ?? '', '—')) ?> — <?= esc($str($ev['end_ru'] ?? '', '—')) ?> · длилось <?= esc($str($ev['duration'] ?? '', '—')) ?><?= $biomes !== [] ? ' · ' . esc(implode(', ', array_map($str, $biomes))) : '' ?></span>
                                    <span class="play-craft-job-meta"><?= ($ev['touched'] ?? false) === true ? '⚡ задело тебя' : 'тебя не задело' ?></span>
                                </li>
                            <?php endforeach ?>
                        </ul>
                    <?php endif ?>
                </section>
            <?php endif ?>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
