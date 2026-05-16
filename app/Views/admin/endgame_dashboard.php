<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>🏛 Endgame Scenarios — Faction Scores</h2>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert">
        <?= esc(session()->getFlashdata('success')) ?>
    </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger" role="alert">
        <?= esc(session()->getFlashdata('error')) ?>
    </div>
<?php endif; ?>

<p class="text-muted">
    1:1 faction → scenario mapping. Threshold default <strong>75 000</strong>.
    Перша faction що досягне threshold triggers FINAL — broadcast до всіх chars +
    bulk update <code>endgame_state</code>. Idempotent (повторні runs no-op).
</p>

<table class="table table-striped table-centered mb-0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Faction</th>
            <th>Scenario</th>
            <th class="text-end">Score</th>
            <th class="text-end">Threshold</th>
            <th>Progress</th>
            <th>Status</th>
            <th>Last event</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
                $factionId   = (int) $row['faction_id'];
                $factionName = $factionNames[$factionId] ?? "Faction #{$factionId}";
                $scenarioRu  = $scenarioRu[$row['scenario_name']] ?? $row['scenario_name'];
                $score       = (int) $row['score'];
                $threshold   = (int) $row['threshold'];
                $pct         = $threshold > 0 ? min(100, (int) round(100 * $score / $threshold)) : 0;
                $hit         = (int) $row['threshold_hit'] === 1;
                $statusClass = $hit ? 'badge bg-danger' : ($pct >= 80 ? 'badge bg-warning text-dark' : 'badge bg-success');
                $statusText  = $hit ? "🔥 TRIGGERED " . esc($row['threshold_hit_at']) : ($pct >= 80 ? '⚠️ ' . $pct . '%' : '✅ ' . $pct . '%');
            ?>
            <tr>
                <td><?= esc($row['id']) ?></td>
                <td><strong><?= esc($factionName) ?></strong></td>
                <td><?= esc($scenarioRu) ?> <span class="text-muted small">(<?= esc($row['scenario_name']) ?>)</span></td>
                <td class="text-end"><?= number_format($score, 0, '.', ' ') ?></td>
                <td class="text-end"><?= number_format($threshold, 0, '.', ' ') ?></td>
                <td>
                    <div class="progress admin-progress-h-20">
                        <div class="progress-bar <?= $hit ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success') ?>"
                             role="progressbar"
                             style="width: <?= $pct ?>%;">
                            <?= $pct ?>%
                        </div>
                    </div>
                </td>
                <td><span class="<?= $statusClass ?>"><?= $statusText ?></span></td>
                <td><?= esc($row['last_event_at'] ?? '—') ?></td>
                <td>
                    <form method="POST" action="<?= site_url('/admin/endgame/reset/' . $row['id']) ?>" class="d-inline" onsubmit="return confirm('Сбросить score для <?= esc($factionName) ?>?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Reset</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="mt-4">
    <h5>Score sources (per <code>Config\EndgameScoring</code>)</h5>
    <ul class="text-muted">
        <li><strong>+50</strong> per PvP kill (winner's faction)</li>
        <li><strong>+100</strong> per quest completion (character's faction)</li>
        <li><strong>+200</strong> per building upgrade (faction tied to building)</li>
        <li><strong>+500</strong> per strategic object discovery (Bunker→Mil, Technopark→Eng, GhostCity→Part)</li>
        <li><strong>+10</strong> per crafted item (deferred — not yet wired)</li>
    </ul>
</div>

<div class="mt-4 p-3 border border-danger rounded">
    <h5 class="text-danger">⚠️ Полный season reset (v0.51.120)</h5>
    <p class="text-muted mb-2">
        Сбрасывает <strong>все 4 faction scores</strong> до 0 (threshold_hit=0) AND
        возвращает <strong>всех characters</strong> у endgame_state ('won', 'lost', 'frozen', 'evacuated')
        обратно у <code>'active'</code>. Используется когда стартует "new season" после finalization.
        Действие audit logged. CSRF protected.
    </p>
    <form method="POST" action="<?= site_url('/admin/endgame/reset-season') ?>" onsubmit="return confirm('ПОДТВЕРДИТЕ: полный season reset? Это сбросит ВСЕ scores + ВСЕ character endgame states.');">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-danger">🔄 Reset full season</button>
    </form>
</div>

<?= $this->endSection() ?>
