<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать квест: <?= esc($quest['title_ru']) ?></h2>

<?= $this->include('admin/partials/_quest_form', ['mode' => 'edit', 'quest' => $quest]) ?>

<?= $this->endSection() ?>
