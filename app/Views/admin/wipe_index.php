<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>🔥 ВАЙП всех игроков</h2>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>
<?php if (session()->getFlashdata('errors')): ?>
    <div class="alert alert-danger">
        <?php foreach ((array) session()->getFlashdata('errors') as $error): ?>
            <?= esc($error) ?><br>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="alert alert-danger">
    <strong>⚠️ Необратимая операция.</strong> Полный вайп сбрасывает прогресс ВСЕХ игроков до нуля
    (как вайп сезона). <strong>Остаётся:</strong> игровой мир и карта, имена игроков и их id,
    контент-определения, баланс (game_settings), админ/сайт. <strong>Сбрасывается:</strong> весь
    прогресс персонажей, инвентарь, постройки, фракции, исследования, рынок, эндгейм.
</div>

<?php $cov = $preview['coverage']; ?>
<?php if ($cov['unclassified'] !== []): ?>
    <div class="alert alert-danger">
        🔴 <strong>Вайп заблокирован.</strong> Незаклассифицированные таблицы (нет в <code>Config\WipeManifest</code>):
        <code><?= esc(implode(', ', $cov['unclassified'])) ?></code>.
        Добавь их в манифест (ADR-087), иначе они будут упущены при вайпе.
    </div>
<?php endif; ?>
<?php if ($cov['stale'] !== []): ?>
    <div class="alert alert-warning">
        ⚠ Stale-записи манифеста (таблиц нет в БД): <code><?= esc(implode(', ', $cov['stale'])) ?></code>.
    </div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-md-3"><div class="card text-bg-light"><div class="card-body">
        <div class="fs-4"><?= esc((string) $preview['players']) ?></div><div>аккаунтов (telegram_users)</div>
    </div></div></div>
    <div class="col-md-3"><div class="card text-bg-light"><div class="card-body">
        <div class="fs-4"><?= esc((string) $preview['characters']) ?></div><div>персонажей (сброс)</div>
    </div></div></div>
    <div class="col-md-3"><div class="card text-bg-light"><div class="card-body">
        <div class="fs-4 text-danger"><?= esc((string) $preview['totals']['rows_deleted']) ?></div><div>строк будет удалено</div>
    </div></div></div>
    <div class="col-md-3"><div class="card text-bg-light"><div class="card-body">
        <div class="fs-4"><?= esc((string) $preview['totals']['tables_kept']) ?></div><div>таблиц сохраняется</div>
    </div></div></div>
</div>

<div class="card mb-4">
    <div class="card-header">Что произойдёт с каждой таблицей (KEEP скрыты — раскрой ниже)</div>
    <div class="card-body">
        <table class="table table-sm table-striped">
            <thead><tr><th>Таблица</th><th>Стратегия</th><th>Строк сейчас</th><th>Действие</th></tr></thead>
            <tbody>
            <?php foreach ($preview['tables'] as $t): ?>
                <?php if ($t['strategy'] === 'keep') { continue; } ?>
                <?php
                    $badge = match ($t['strategy']) {
                        'player_data', 'transient'      => 'bg-danger',
                        'character_reset'               => 'bg-primary',
                        'identity_reset', 'seed_reset'  => 'bg-warning text-dark',
                        default                         => 'bg-secondary',
                    };
                ?>
                <tr>
                    <td><code><?= esc($t['table']) ?></code><br><small class="text-muted"><?= esc($t['note']) ?></small></td>
                    <td><span class="badge <?= $badge ?>"><?= esc($t['strategy']) ?></span></td>
                    <td><?= esc((string) $t['rows']) ?></td>
                    <td><?= esc($t['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <details>
            <summary class="text-muted">🟢 Сохраняемые таблицы (KEEP) — <?= esc((string) $preview['totals']['tables_kept']) ?> шт.</summary>
            <table class="table table-sm mt-2">
                <tbody>
                <?php foreach ($preview['tables'] as $t): ?>
                    <?php if ($t['strategy'] !== 'keep') { continue; } ?>
                    <tr>
                        <td><code><?= esc($t['table']) ?></code></td>
                        <td><?= esc((string) $t['rows']) ?> строк</td>
                        <td class="text-muted"><?= esc($t['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </div>
</div>

<div class="card">
    <div class="card-header">Сообщение всем игрокам (отправится ПОСЛЕ вайпа) — можно отредактировать</div>
    <div class="card-body">
        <form action="<?= site_url('admin/wipe/arm') ?>" method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Заголовок</label>
                <input type="text" class="form-control" name="broadcast_title" value="<?= esc($broadcastTitle) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Текст (Telegram-Markdown)</label>
                <textarea class="form-control" name="broadcast_message" rows="8"><?= esc($broadcastMessage) ?></textarea>
            </div>
            <button type="submit" class="btn btn-danger"
                <?= $cov['unclassified'] !== [] ? 'disabled title="Сначала классифицируй таблицы в WipeManifest"' : '' ?>>
                🔥 Делать вайп →
            </button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
