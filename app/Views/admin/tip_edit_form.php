<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Редактирование совета<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции · советы</p>
        <h1 class="aui-display">Редактирование: <?= esc($tip['title_ru']) ?></h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/tips') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?= view('admin/partials/_tip_form', ['mode' => 'edit', 'tip' => $tip]) ?>

<?= $this->endSection() ?>
