<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Новый квест<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент · квесты</p>
        <h1 class="aui-display">Новый квест</h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/quests') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?= view('admin/partials/_quest_form', ['mode' => 'create']) ?>

<?= $this->endSection() ?>
