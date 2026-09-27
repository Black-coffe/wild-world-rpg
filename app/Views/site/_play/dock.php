<?php
/**
 * W2.N1 (ADR-190) — нижний док `/play`: общий для экрана моста (`site/_play/state`) и нативных
 * экранов (`site/_play/native_*`). Кнопка, у которой есть нативный экран («🧑 Я», с W2.N2 —
 * «🌍 Мир»/«Карта» → сетка карты, с W2.N3 — «🔨 Крафт» → верстаки и очередь), идёт в POST `/play/view`; остальные — прежним текстом в POST
 * `/play/act` (мост). Каждая форма несёт CSRF и свой `intent_id`; без JS — PRG.
 *
 * @var list<list<string>>|mixed $dock
 */

use App\Services\Web\WebNativeScreenService;

$rows    = is_array($dock ?? null) ? $dock : [];
$actUrl  = base_url('play/act');
$viewUrl = base_url('play/view');
$count   = 0;
?>
<nav class="play-dock" aria-label="Меню игры">
    <?php foreach ($rows as $row): ?>
        <?php foreach (is_array($row) ? $row : [] as $label): ?>
            <?php if (! is_string($label) || $label === '') { continue; } $count++; ?>
            <?php $nativeView = WebNativeScreenService::viewForDockLabel($label); ?>
            <?php if ($nativeView !== null): ?>
                <form action="<?= esc($viewUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="view" value="<?= esc($nativeView, 'attr') ?>"><button class="play-dock-btn" type="submit"><?= esc($label) ?></button></form>
            <?php else: ?>
                <form action="<?= esc($actUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="kind" value="text"><input type="hidden" name="data" value="<?= esc($label, 'attr') ?>"><button class="play-dock-btn" type="submit"><?= esc($label) ?></button></form>
            <?php endif ?>
        <?php endforeach ?>
    <?php endforeach ?>
    <?php if ($count === 0): ?>
        <form action="<?= esc($actUrl, 'attr') ?>" method="post"><?= csrf_field() ?><input type="hidden" name="intent_id" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="kind" value="command"><input type="hidden" name="data" value="/menu"><button class="play-dock-btn" type="submit">Меню</button></form>
    <?php endif ?>
</nav>
