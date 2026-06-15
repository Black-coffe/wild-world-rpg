<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Опросы<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции</p>
        <h1 class="aui-display">Опросы</h1>
    </div>
    <div class="aui-toolbar">
        <input class="aui-input" type="search" placeholder="Поиск опроса…" data-table-search="#tbl-polls">
        <a class="aui-btn aui-btn--primary" href="<?= site_url('admin/polls/create') ?>"><i class="ri-add-line"></i> Создать</a>
    </div>
</div>

<?php if (session()->getFlashdata('message')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('message')) ?></div></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc(session()->getFlashdata('error')) ?></div></div>
<?php endif; ?>

<div class="aui-card"><div class="aui-tablewrap">
    <table class="aui-table" id="tbl-polls" data-enhance>
        <thead><tr>
            <th class="num" data-sort>ID</th><th data-sort>Вопрос</th><th data-sort>Создан</th><th data-sort>Статус</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (! empty($polls) && is_array($polls)): ?>
            <?php foreach ($polls as $poll): ?>
                <tr>
                    <td class="num aui-mono"><?= esc($poll['id']) ?></td>
                    <td class="strong text-truncate"><?= esc(character_limiter(strip_tags($poll['question']), 80)) ?></td>
                    <td class="aui-muted"><?= esc($poll['created_at']) ?></td>
                    <td>
                        <?php if ($poll['active'] == 1): ?>
                            <span class="aui-badge aui-badge--success"><span class="aui-dot"></span> отправлен</span>
                        <?php else: ?>
                            <span class="aui-badge aui-badge--warning">черновик</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="aui-actions">
                            <?php if ($poll['active'] == 0): ?>
                                <form action="<?= site_url('admin/polls/send/' . $poll['id']) ?>" method="post" onsubmit="return confirm('Отправить опрос всем игрокам?');"><?= csrf_field() ?><button class="aui-btn aui-btn--primary aui-btn--sm"><i class="ri-send-plane-line"></i> Запустить</button></form>
                                <a class="aui-btn aui-btn--ghost aui-btn--sm" href="<?= site_url('admin/polls/edit/' . $poll['id']) ?>"><i class="ri-pencil-line"></i> Изменить</a>
                                <form action="<?= site_url('admin/polls/delete/' . $poll['id']) ?>" method="post" onsubmit="return confirm('Удалить опрос?');"><?= csrf_field() ?><button class="aui-btn aui-btn--danger aui-btn--sm"><i class="ri-delete-bin-line"></i></button></form>
                            <?php else: ?>
                                <form action="<?= site_url('admin/polls/stop/' . $poll['id']) ?>" method="post" onsubmit="return confirm('Остановить опрос?');"><?= csrf_field() ?><button class="aui-btn aui-btn--ghost aui-btn--sm" style="color:var(--warning)"><i class="ri-stop-circle-line"></i> Остановить</button></form>
                            <?php endif; ?>
                            <a class="aui-btn aui-btn--subtle aui-btn--sm" href="<?= site_url('admin/polls/statistics/' . $poll['id']) ?>"><i class="ri-bar-chart-2-line"></i> Статистика</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="5" class="aui-table__empty">Опросы не найдены</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div></div>
<?= $this->endSection() ?>
