<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новое событие</h2>

<?php /* Phase D (2026-05-13): unified _event_form partial вместо 140 LOC дубля */ ?>
<?= $this->include('admin/partials/_event_form', ['mode' => 'create', 'biomes' => $biomes]) ?>

<?= $this->endSection() ?>
