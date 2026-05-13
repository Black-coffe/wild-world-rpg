<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать событие: <?= esc($event['name']) ?></h2>

<?php /* F7.11: WorldEvents config status banner (read-only вид на effect_kind) */ ?>
<?php if ($worldConfig !== null): ?>
    <div class="alert alert-success" role="alert">
        <strong>✅ F7-config registered.</strong> Эта подія имеет запись в <code>app/Config/WorldEvents.php</code>.
        Эффекты выполняются через <code>EventDispatcher</code> по правилам ниже.
        <strong>name_english</strong> переименовывать БЕЗОПАСНО (это только лор-косметика);
        логика ефектів привязана к ключу config'а — изменение требует синхронной правки PHP.
        <hr>
        <table class="table table-sm mb-0">
            <tr><td><strong>effect_kind</strong></td><td><code><?= esc($worldConfig['effect_kind']) ?></code></td></tr>
            <tr><td><strong>tick_chance</strong></td><td><?= esc($worldConfig['tick_chance']) ?> (<?= round($worldConfig['tick_chance'] * 100) ?>%/тик)</td></tr>
            <tr><td><strong>duration_minutes</strong> (target)</td><td><?= esc($worldConfig['duration_minutes']) ?> мин (текущая в БД: <?= esc($event['duration']) ?> мин)</td></tr>
            <tr><td><strong>frequency_weight</strong></td><td><?= esc($worldConfig['frequency_weight']) ?> (вес для weighted random в активаторе)</td></tr>
            <tr><td><strong>protection_item</strong></td><td><?= $worldConfig['protection_item'] !== null ? '<code>' . esc((string) $worldConfig['protection_item']) . '</code> (-50% damage если в инвентаре)' : '<em>—</em>' ?></td></tr>
            <tr><td><strong>notification_kind</strong></td><td><code><?= esc($worldConfig['notification_kind']) ?></code></td></tr>
        </table>
    </div>
<?php else: ?>
    <div class="alert alert-warning" role="alert">
        <strong>⚠️ F7-config отсутствует.</strong> Эта подія не зарегистрирована в <code>app/Config/WorldEvents.php</code>
        (по ключу <code><?= esc($event['name_english']) ?></code>). При активации NotificationPolicy упадёт в legacy-broadcast,
        а EventDispatcher пропустит эффекты. Чтобы фикс — добавить запись в WorldEvents.php и задеплоить.
    </div>
<?php endif; ?>

<?php /* Phase D (2026-05-13): unified _event_form partial вместо 138 LOC дубля */ ?>
<?= $this->include('admin/partials/_event_form', ['mode' => 'edit', 'event' => $event, 'biomes' => $biomes]) ?>

<?= $this->endSection() ?>
