<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать объект: <?= esc($object['name']) ?></h2>

<?= view('admin/partials/_world_object_form', ['mode' => 'edit', 'object' => $object, 'biomes' => $biomes]) ?>

<?= $this->endSection() ?>
