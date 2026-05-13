<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать совет: <?= esc($tip['title_ru']) ?></h2>

<?= view('admin/partials/_tip_form', ['mode' => 'edit', 'tip' => $tip]) ?>

<?= $this->endSection() ?>
