<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">📦 Ресурсы биома: <?= esc($biome['name']) ?> <small class="text-muted">(id=<?= esc($biome['id']) ?>)</small></h2>
    <a href="<?= site_url('admin/biomes') ?>" class="btn btn-sm btn-outline-secondary">← К списку биомов</a>
</div>

<p class="text-muted">
    Read-only превью. Резолв через <code>FIND_IN_SET(<?= esc($biome['id']) ?>, biome_id)</code> по
    <code>resources.biome_id</code> CSV (та же логика, что у runtime gather'а).
    Редактировать привязку — на странице <a href="<?= site_url('admin/resources') ?>">ресурсов</a>.
</p>

<?php if (empty($resources)): ?>
    <div class="alert alert-warning">В этом биоме не настроено ни одного добываемого ресурса.</div>
<?php else: ?>
    <p><strong>Всего ресурсов: <?= count($resources) ?></strong></p>
    <table class="table table-striped table-sm table-hover">
        <thead>
        <tr>
            <th>#</th>
            <th>Название</th>
            <th>name_en</th>
            <th>Редкость</th>
            <th>Цена</th>
            <th>Lv</th>
            <th>Тип</th>
            <th>biome_id (CSV)</th>
            <th>Действие</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($resources as $r): ?>
            <tr>
                <td><?= esc($r['id']) ?></td>
                <td><?= esc($r['name']) ?></td>
                <td><code><?= esc($r['name_en'] ?? '—') ?></code></td>
                <td><?= esc($r['rarity'] ?? '—') ?></td>
                <td><?= esc($r['price'] ?? 0) ?></td>
                <td><?= esc($r['level_required'] ?? 1) ?></td>
                <td><small class="text-muted"><?= esc($r['type'] ?? '—') ?></small></td>
                <td><code><?= esc($r['biome_id'] ?? '—') ?></code></td>
                <td>
                    <a href="<?= site_url('admin/resources/edit/' . $r['id']) ?>" class="action-icon" title="Редактировать ресурс"><i class="mdi mdi-pencil"></i></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?= $this->endSection() ?>
