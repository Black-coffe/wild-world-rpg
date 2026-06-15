<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Редактирование события<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент · события</p>
        <h1 class="aui-display">Редактирование: <?= esc($event['name']) ?></h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/events') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?php /* F7.11: WorldEvents config status banner (read-only вид на effect_kind) */ ?>
<?php if ($worldConfig !== null): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div>
        <div class="aui-alert__title">F7-config зарегистрирован</div>
        Событие имеет запись в <code>app/Config/WorldEvents.php</code>; эффекты выполняются через <code>EventDispatcher</code>.
        <strong>name_english</strong> переименовывать безопасно (лор-косметика); логика эффектов привязана к ключу config'а.
        <table class="aui-table" style="margin-top:var(--sp-3)">
            <tbody>
            <tr><td>effect_kind</td><td><code><?= esc($worldConfig['effect_kind']) ?></code></td></tr>
            <tr><td>tick_chance</td><td><?= esc($worldConfig['tick_chance']) ?> (<?= round($worldConfig['tick_chance'] * 100) ?>%/тик)</td></tr>
            <tr><td>duration_minutes (target)</td><td><?= esc($worldConfig['duration_minutes']) ?> мин (в БД: <?= esc($event['duration']) ?> мин)</td></tr>
            <tr><td>frequency_weight</td><td><?= esc($worldConfig['frequency_weight']) ?></td></tr>
            <tr><td>protection_item</td><td><?= $worldConfig['protection_item'] !== null ? '<code>' . esc((string) $worldConfig['protection_item']) . '</code> (−50% damage если в инвентаре)' : '<span class="aui-faint">—</span>' ?></td></tr>
            <tr><td>notification_kind</td><td><code><?= esc($worldConfig['notification_kind']) ?></code></td></tr>
            </tbody>
        </table>
    </div></div>
<?php else: ?>
    <div class="aui-alert aui-alert--warning" style="margin-bottom:var(--sp-4)"><i class="ri-alert-line"></i><div>
        <div class="aui-alert__title">F7-config отсутствует</div>
        Событие не зарегистрировано в <code>app/Config/WorldEvents.php</code> (ключ <code><?= esc($event['name_english']) ?></code>).
        При активации NotificationPolicy упадёт в legacy-broadcast, а EventDispatcher пропустит эффекты — добавь запись в WorldEvents.php и задеплой.
    </div></div>
<?php endif; ?>

<?= view('admin/partials/_event_form', ['mode' => 'edit', 'event' => $event, 'biomes' => $biomes, 'effectHandlers' => $effectHandlers ?? []]) ?>

<?= $this->endSection() ?>
