<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать ресурс: <?= esc($resource['name']) ?></h2>

<?= $this->include('admin/partials/_resource_form', ['mode' => 'edit', 'resource' => $resource, 'biomes' => $biomes]) ?>

<?= $this->endSection() ?>
