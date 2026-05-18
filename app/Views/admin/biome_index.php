<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Список биомов</h2>

<!-- Отображение флеш-сообщения о успешном обновлении -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= session()->getFlashdata('success') ?>
    </div>
<?php endif; ?>

<table class="table table-striped table-centered mb-0">
    <thead>
    <tr>
        <th>Название биома</th>
        <th>Уровень опасности</th>
        <th>Уровень опасности (число)</th>
        <th>Сложность выживания</th>
        <th>Сложность выживания (число)</th>
        <th>Частота встречаемости</th>
        <th>Тип биома</th> <!-- Новая колонка -->
        <th>Действие</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($biomes as $biome): ?>
        <tr>
            <td><?= esc($biome['name']) ?></td>
            <td><?= esc($biome['danger_level_text']) ?></td>
            <td><?= esc($biome['danger_level']) ?></td>
            <td><?= esc($biome['survival_difficulty_text']) ?></td>
            <td><?= esc($biome['survival_difficulty']) ?></td>
            <td><?= esc($biome['occurrence_rate']) ?>%</td>
            <td><?= esc($biome['biome_type']) ?> <!-- Данные о типе биома --></td>
            <td>
                <a href="<?= site_url('admin/biomes/edit/' . $biome['id']) ?>" class="action-icon" title="Редактировать"> <i class="mdi mdi-pencil"></i></a>
                <a href="<?= site_url('admin/biomes/' . $biome['id'] . '/resources') ?>" class="action-icon" title="Ресурсы этого биома"> <i class="mdi mdi-package-variant"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?= $this->endSection() ?>
