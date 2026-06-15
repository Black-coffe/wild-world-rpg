<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?><?= esc($title) ?><?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции · аудит</p>
        <h1 class="aui-display"><?= esc($title) ?></h1>
    </div>
</div>

<div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-4)">
    <i class="ri-information-line"></i>
    <div>
        F1.9 admin audit log. Records ALL destructive admin actions (poll send/delete, character reset,
        biome/event/resource/task/quest/world-object/tip/map mutations). Read-only viewer.
    </div>
</div>

<form method="get" action="<?= site_url('admin/audit-log') ?>" class="aui-card" style="margin-bottom:var(--sp-4)">
    <div class="aui-card__body">
        <div class="aui-formgrid" style="margin-bottom:0">
            <div class="aui-field">
                <label class="aui-label">Action</label>
                <select name="action" class="aui-select">
                    <option value="">— все —</option>
                    <?php foreach ($distinctActions as $a): ?>
                        <option value="<?= esc($a) ?>" <?= ($filterAction === $a) ? 'selected' : '' ?>><?= esc($a) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="aui-field">
                <label class="aui-label">Target type</label>
                <select name="target_type" class="aui-select">
                    <option value="">— все —</option>
                    <?php foreach ($distinctTargets as $t): ?>
                        <option value="<?= esc($t) ?>" <?= ($filterTarget === $t) ? 'selected' : '' ?>><?= esc($t) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="aui-field">
                <label class="aui-label">Admin user_id</label>
                <input type="number" name="admin_user_id" class="aui-input" value="<?= esc($filterAdmin ?? '') ?>" placeholder="—">
            </div>
            <div class="aui-field" style="display:flex; flex-direction:column; justify-content:flex-end">
                <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-filter-3-line"></i> Filter</button>
            </div>
        </div>
    </div>
</form>

<?php if (empty($entries)): ?>
    <div class="aui-card"><div class="aui-empty">
        <i class="ri-inbox-line"></i>
        <p>Нет записей для текущих фильтров.</p>
    </div></div>
<?php else: ?>
    <div class="aui-card"><div class="aui-tablewrap">
        <table class="aui-table">
            <thead><tr>
                <th>ID</th><th>Когда (UTC)</th><th>Admin</th><th>Action</th>
                <th>Target type</th><th class="num">Target ID</th><th>Payload</th><th>IP</th>
            </tr></thead>
            <tbody>
                <?php foreach ($entries as $row): ?>
                    <tr>
                        <td class="aui-faint"><?= (int) ($row['id'] ?? 0) ?></td>
                        <td class="aui-small"><?= esc($row['created_at'] ?? '—') ?></td>
                        <td class="aui-small">
                            <?php $aId = (int) ($row['admin_user_id'] ?? 0); ?>
                            <?= esc($adminNames[$aId] ?? "user-{$aId}") ?>
                            <span class="aui-faint">#<?= $aId ?></span>
                        </td>
                        <td><span class="aui-mono"><?= esc($row['action'] ?? '—') ?></span></td>
                        <td class="aui-small"><?= esc($row['target_type'] ?? '—') ?></td>
                        <td class="num"><?= esc((string) ($row['target_id'] ?? '—')) ?></td>
                        <td>
                            <?php if (!empty($row['payload'])): ?>
                                <details>
                                    <summary class="aui-small" style="cursor:pointer">JSON</summary>
                                    <pre class="aui-code" style="margin-top:6px; max-width:420px"><?= esc($row['payload']) ?></pre>
                                </details>
                            <?php else: ?>
                                <span class="aui-faint aui-small">—</span>
                            <?php endif ?>
                        </td>
                        <td class="aui-faint aui-mono aui-small"><?= esc($row['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div></div>

    <?php if ($pager): ?>
        <div class="aui-pager" style="margin-top:var(--sp-4)"><?= $pager->links('audit', 'default_full') ?></div>
    <?php endif ?>
<?php endif ?>

<?= $this->endSection() ?>
