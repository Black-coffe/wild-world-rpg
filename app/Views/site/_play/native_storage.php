<?php
/**
 * w2-n6-trade-storage-03 (ADR-190) — нативный экран «📦 Склад базы» на `/play`: веб-рендерер модели
 * {@see \App\Services\Web\WebNativeScreenService::storageModel()} поверх {@see \App\Services\Bases\BaseStorageService},
 * того же ядра, что у бота.
 *
 * Список склада в режиме сортировки (`mode`), «Забрать всё» и забор одного вида (`op=storage_take`, `id`), «Положить
 * всё» и сдача одного вида из рюкзака (`op=storage_put`, `id`) — формы POST `/play/view` со своим `intent_id`, без JS
 * — PRG. Вне базы кнопки не пропадают: замок объясняет, что склад на базе, и даёт путь; нажатие — отказ ядра.
 * Всё текстом, без картинок; id персонажа в разметку не идут.
 *
 * @var array<string, mixed> $storage
 * @var list<list<string>>   $dock
 * @var string|null          $alert
 */

$m         = is_array($storage ?? null) ? $storage : [];
$alertText = is_string($alert ?? null) && $alert !== '' ? $alert : null;
$viewUrl   = base_url('play/view');
$mode      = is_string($m['mode'] ?? null) ? $m['mode'] : 'recent';
$rows      = is_array($m['rows'] ?? null) ? $m['rows'] : [];
$carried   = is_array($m['carried'] ?? null) ? $m['carried'] : [];
$onBase    = ($m['on_base'] ?? false) === true;
$str       = static fn (mixed $v, string $d = ''): string => is_scalar($v) && (string) $v !== '' ? (string) $v : $d;
$int       = static fn (mixed $v): int => is_int($v) ? $v : 0;

/** Мутация склада — форма с `op`, видом (без него — всё), режимом и своим `intent_id`. */
$act = static function (string $op, ?int $id, string $text, string $class = 'play-kb-btn') use ($viewUrl, $mode): string {
    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="storage"><input type="hidden" name="op" value="' . esc($op, 'attr') . '">'
        . '<input type="hidden" name="mode" value="' . esc($mode, 'attr') . '">'
        . ($id !== null ? '<input type="hidden" name="id" value="' . $id . '">' : '')
        . '<input type="hidden" name="intent_id" value="' . bin2hex(random_bytes(16)) . '">'
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

/** Переход на другой нативный экран (`view`) или режим сортировки склада. */
$go = static function (string $view, array $nav, string $text, string $class = 'play-kb-btn') use ($viewUrl): string {
    $hidden = '';
    foreach ($nav as $name => $value) {
        $hidden .= '<input type="hidden" name="' . esc((string) $name, 'attr') . '" value="' . esc((string) $value, 'attr') . '">';
    }

    return '<form action="' . esc($viewUrl, 'attr') . '" method="post">' . csrf_field()
        . '<input type="hidden" name="view" value="' . esc($view, 'attr') . '">' . $hidden
        . '<button class="' . esc($class, 'attr') . '" type="submit">' . esc($text) . '</button></form>';
};

$modes = ['recent' => '🕒 Недавние', 'name' => '🔤 Название', 'qty' => '🔢 Кол-во'];
?>
<div class="play-shell is-native">
    <div class="play-screen">
        <?php if ($alertText !== null): ?>
            <div class="play-alert" role="status"><span class="play-alert-title">Склад</span><?= esc($alertText) ?></div>
        <?php endif ?>

        <article class="play-native" data-native="storage" aria-labelledby="play-native-storage-title">
            <header class="play-native-head">
                <h2 class="play-native-title" id="play-native-storage-title">📦 Склад базы</h2>
                <p class="play-craft-crumbs"><?= $onBase ? 'Ты на базе — можно забрать и положить.' : 'Ты не на базе — склад можно только посмотреть.' ?></p>
            </header>

            <?php if (! $onBase): ?>
                <div class="play-lock" data-storage-lock>
                    <span class="play-lock-title">🔒 Положить и забрать (нужно: стоять на своей базе)</span>
                    <span class="play-lock-why">Положить и забрать можно только на базе: склад физически стоит на твоей клейм-клетке. Из поля груз домой носит карго-дрон.</span>
                    <span class="play-lock-path">Путь: 🌍 Мир → клетка твоей базы (🏠 на карте) → 📦 Склад базы</span>
                </div>
                <div class="play-kb-grid">
                    <?= $go('map', [], '🌍 Мир') ?>
                    <?= $go('base', [], '🏠 База') ?>
                </div>
            <?php endif ?>

            <section class="play-native-section" aria-label="На складе">
                <h3 class="play-native-subtitle is-plain">📦 На складе<?= $rows !== [] ? ' — ' . number_format($int($m['total_units'] ?? 0), 0, '.', ' ') . ' шт.' : '' ?></h3>
                <?php if ($rows === []): ?>
                    <p class="play-native-hint">Склад пуст. Сюда кладут добычу двумя путями: руками, стоя на базе, или карго-дроном с любой клетки.</p>
                <?php else: ?>
                    <nav class="play-kb" aria-label="Сортировка склада">
                        <div class="play-kb-grid">
                            <?php foreach ($modes as $key => $label): ?>
                                <?= $go('storage', ['mode' => $key], ($key === $mode ? '• ' : '') . $label, 'play-kb-btn' . ($key === $mode ? ' is-primary' : '')) ?>
                            <?php endforeach ?>
                        </div>
                    </nav>
                    <ul class="play-craft-reqs" aria-label="Ресурс на складе и его количество">
                        <?php foreach ($rows as $row): ?>
                            <?php if (! is_array($row)) { continue; } ?>
                            <li class="play-craft-req"><span class="play-craft-req-name"><?= esc($str($row['name'] ?? '')) ?></span><span class="play-craft-req-qty"><?= number_format($int($row['quantity'] ?? 0), 0, '.', ' ') ?> шт.</span></li>
                        <?php endforeach ?>
                    </ul>
                    <p class="play-native-hint">Забрать один вид — кнопка с его именем: он уйдёт в рюкзак целиком.</p>
                    <div class="play-kb-grid">
                        <?= $act('storage_take', null, '🎒 Забрать всё', 'play-kb-btn is-primary') ?>
                        <?php foreach ($rows as $row): ?>
                            <?php if (! is_array($row)) { continue; } ?>
                            <?= $act('storage_take', $int($row['resource_id'] ?? 0), '🎒 ' . $str($row['name'] ?? '')) ?>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </section>

            <section class="play-native-section" aria-label="Положить из рюкзака">
                <h3 class="play-native-subtitle is-plain">📥 Положить из рюкзака</h3>
                <?php if ($carried === []): ?>
                    <p class="play-native-hint">В рюкзаке нет добытого сырья — класть нечего.</p>
                <?php else: ?>
                    <ul class="play-craft-reqs" aria-label="Ресурс в рюкзаке и его количество">
                        <?php foreach ($carried as $row): ?>
                            <?php if (! is_array($row)) { continue; } ?>
                            <li class="play-craft-req"><span class="play-craft-req-name"><?= esc($str($row['name'] ?? '')) ?></span><span class="play-craft-req-qty"><?= number_format($int($row['quantity'] ?? 0), 0, '.', ' ') ?> шт.</span></li>
                        <?php endforeach ?>
                    </ul>
                    <p class="play-native-hint">Вид кладётся целиком — всё, что его есть в рюкзаке.</p>
                    <div class="play-kb-grid">
                        <?= $act('storage_put', null, '📥 Положить всё', 'play-kb-btn is-primary') ?>
                        <?php foreach ($carried as $row): ?>
                            <?php if (! is_array($row)) { continue; } ?>
                            <?= $act('storage_put', $int($row['resource_id'] ?? 0), '📥 ' . $str($row['name'] ?? '')) ?>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </section>
        </article>

        <?= view('site/_play/dock', ['dock' => $dock ?? []]) ?>
    </div>
</div>
