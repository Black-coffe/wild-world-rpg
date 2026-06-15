<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Новое событие<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент · события</p>
        <h1 class="aui-display">Новое событие</h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/events') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?= view('admin/partials/_event_form', ['mode' => 'create', 'biomes' => $biomes, 'effectHandlers' => $effectHandlers ?? []]) ?>

<?= $this->endSection() ?>
