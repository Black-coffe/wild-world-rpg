<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Новый ресурс<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент · ресурсы</p>
        <h1 class="aui-display">Новый ресурс</h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/resources') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?= view('admin/partials/_resource_form', ['mode' => 'create', 'biomes' => $biomes]) ?>

<?= $this->endSection() ?>
