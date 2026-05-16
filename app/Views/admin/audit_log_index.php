<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>
<div class="container-fluid mt-4">
    <h1 class="h3 mb-3"><?= esc($title) ?></h1>

    <p class="text-muted small mb-4">
        F1.9 admin audit log. Records ALL destructive admin actions (poll send/delete, character reset,
        biome/event/resource/task/quest/world-object/tip/map mutations). Read-only viewer.
    </p>

    <!-- Filters -->
    <form method="get" action="<?= site_url('admin/audit-log') ?>" class="card card-body mb-4">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">— все —</option>
                    <?php foreach ($distinctActions as $a): ?>
                        <option value="<?= esc($a) ?>" <?= ($filterAction === $a) ? 'selected' : '' ?>>
                            <?= esc($a) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Target type</label>
                <select name="target_type" class="form-select form-select-sm">
                    <option value="">— все —</option>
                    <?php foreach ($distinctTargets as $t): ?>
                        <option value="<?= esc($t) ?>" <?= ($filterTarget === $t) ? 'selected' : '' ?>>
                            <?= esc($t) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Admin user_id</label>
                <input type="number" name="admin_user_id" class="form-control form-control-sm"
                       value="<?= esc($filterAdmin ?? '') ?>" placeholder="—">
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
            </div>
        </div>
    </form>

    <?php if (empty($entries)): ?>
        <div class="alert alert-info">Нет записей для текущих фильтров.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th class="admin-audit-col-id">ID</th>
                        <th class="admin-audit-col-date">Когда (UTC)</th>
                        <th class="admin-audit-col-admin">Admin</th>
                        <th class="admin-audit-col-action">Action</th>
                        <th class="admin-audit-col-target-type">Target type</th>
                        <th class="admin-audit-col-target-id">Target ID</th>
                        <th>Payload</th>
                        <th class="admin-audit-col-ip">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $row): ?>
                        <tr>
                            <td class="text-muted small"><?= (int) ($row['id'] ?? 0) ?></td>
                            <td class="small"><?= esc($row['created_at'] ?? '—') ?></td>
                            <td class="small">
                                <?php $aId = (int) ($row['admin_user_id'] ?? 0); ?>
                                <?= esc($adminNames[$aId] ?? "user-{$aId}") ?>
                                <span class="text-muted">#<?= $aId ?></span>
                            </td>
                            <td><code><?= esc($row['action'] ?? '—') ?></code></td>
                            <td class="small"><?= esc($row['target_type'] ?? '—') ?></td>
                            <td class="text-end small"><?= esc((string) ($row['target_id'] ?? '—')) ?></td>
                            <td>
                                <?php if (!empty($row['payload'])): ?>
                                    <details>
                                        <summary class="small">JSON</summary>
                                        <pre class="small mb-0 admin-audit-payload"><?= esc($row['payload']) ?></pre>
                                    </details>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif ?>
                            </td>
                            <td class="text-muted small font-monospace"><?= esc($row['ip_address'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <?php if ($pager): ?>
            <div class="mt-3"><?= $pager->links('audit', 'default_full') ?></div>
        <?php endif ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
