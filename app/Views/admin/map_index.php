<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Генерация мира<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Мир</p>
        <h1 class="aui-display">Генерация игрового мира</h1>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" data-enhance>
        <thead><tr>
            <th class="num" data-sort>#</th><th data-sort>Название генерации</th><th data-sort>Статус</th><th></th><th data-sort>Дата действия</th>
        </tr></thead>
        <tbody>
        <?php foreach ($statusLog as $status): ?>
            <tr>
                <td class="num"><?= esc((string) $status['id']) ?></td>
                <td class="strong"><?= esc($status['action_name']) ?></td>
                <td>
                    <?php $st = (string) $status['action_status']; ?>
                    <span class="aui-badge <?= $st === 'None' ? '' : 'aui-badge--success' ?>"><?= esc($st) ?></span>
                </td>
                <td>
                    <?php if ($status['action_status'] === 'None'): ?>
                        <a href="<?= base_url('admin/generate/') . $status['action_name'] ?>" class="aui-btn aui-btn--ghost aui-btn--sm">Генерировать</a>
                    <?php endif; ?>
                </td>
                <td class="aui-muted"><?= esc((string) $status['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<?= $this->endSection() ?>
