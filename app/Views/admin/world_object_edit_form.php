<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Редактирование объекта<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент · объекты</p>
        <h1 class="aui-display">Редактирование: <?= esc($object['name']) ?></h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/world-objects') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?= view('admin/partials/_world_object_form', ['mode' => 'edit', 'object' => $object, 'biomes' => $biomes, 'objectHandlers' => $objectHandlers ?? []]) ?>

<?= $this->endSection() ?>
