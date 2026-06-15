<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Новый совет<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции · советы</p>
        <h1 class="aui-display">Новый совет</h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/tips') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<?= view('admin/partials/_tip_form', ['mode' => 'create']) ?>

<?= $this->endSection() ?>
