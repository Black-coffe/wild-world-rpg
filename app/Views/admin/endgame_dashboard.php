<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Эндгейм<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Эндгейм</p>
        <h1 class="aui-display">🏛 Endgame Scenarios — Faction Scores</h1>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc(session()->getFlashdata('error')) ?></div></div>
<?php endif; ?>

<div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-5)">
    <i class="ri-information-line"></i>
    <div>
        1:1 faction → scenario mapping. Threshold default <strong>75 000</strong>.
        Перша faction що досягне threshold triggers FINAL — broadcast до всіх chars +
        bulk update <span class="aui-mono">endgame_state</span>. Idempotent (повторні runs no-op).
    </div>
</div>

<div class="aui-card" style="margin-bottom:var(--sp-5)"><div class="aui-tablewrap">
    <table class="aui-table">
        <thead><tr>
            <th>ID</th><th>Faction</th><th>Scenario</th><th class="num">Score</th><th class="num">Threshold</th>
            <th style="min-width:160px">Progress</th><th>Status</th><th>Last event</th><th></th>
        </tr></thead>
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
                    $statusClass = $hit ? 'aui-badge--danger' : ($pct >= 80 ? 'aui-badge--warning' : 'aui-badge--success');
                    $statusText  = $hit ? "🔥 TRIGGERED " . esc($row['threshold_hit_at']) : ($pct >= 80 ? '⚠️ ' . $pct . '%' : '✅ ' . $pct . '%');
                    $barMod      = $hit ? 'aui-progress__bar--danger' : ($pct >= 80 ? '' : 'aui-progress__bar--success');
                ?>
                <tr>
                    <td class="num"><?= esc($row['id']) ?></td>
                    <td class="strong"><?= esc($factionName) ?></td>
                    <td><?= esc($scenarioRu) ?> <span class="aui-faint aui-small">(<?= esc($row['scenario_name']) ?>)</span></td>
                    <td class="num"><?= number_format($score, 0, '.', ' ') ?></td>
                    <td class="num"><?= number_format($threshold, 0, '.', ' ') ?></td>
                    <td>
                        <div class="aui-progress" title="<?= $pct ?>%">
                            <div class="aui-progress__bar <?= $barMod ?>" style="width: <?= $pct ?>%;"></div>
                        </div>
                    </td>
                    <td><span class="aui-badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                    <td class="aui-muted"><?= esc($row['last_event_at'] ?? '—') ?></td>
                    <td>
                        <form method="POST" action="<?= site_url('/admin/endgame/reset/' . $row['id']) ?>" style="display:inline" onsubmit="return confirm('Сбросить score для <?= esc($factionName) ?>?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="aui-btn aui-btn--subtle aui-btn--sm">Reset</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<div class="aui-card" style="margin-bottom:var(--sp-5)">
    <div class="aui-card__head"><i class="ri-coins-line"></i><span class="aui-card__title">Score sources (per <span class="aui-mono">Config\EndgameScoring</span>)</span></div>
    <div class="aui-card__body">
        <ul class="aui-muted" style="margin:0; padding-left:1.2em; line-height:1.9">
            <li><strong>+50</strong> per PvP kill (winner's faction)</li>
            <li><strong>+100</strong> per quest completion (character's faction)</li>
            <li><strong>+200</strong> per building upgrade (faction tied to building)</li>
            <li><strong>+500</strong> per strategic object discovery (Bunker→Mil, Technopark→Eng, GhostCity→Part)</li>
            <li><strong>+10</strong> per crafted item (deferred — not yet wired)</li>
        </ul>
    </div>
</div>

<div class="aui-card" style="border-color:color-mix(in srgb,var(--danger) 35%,var(--line))">
    <div class="aui-card__head" style="border-bottom-color:color-mix(in srgb,var(--danger) 20%,var(--line))">
        <i class="ri-alert-line" style="color:var(--danger)"></i>
        <span class="aui-card__title" style="color:var(--danger)">⚠️ Полный season reset (v0.51.120)</span>
    </div>
    <div class="aui-card__body">
        <p class="aui-muted" style="margin:0 0 var(--sp-3)">
            Сбрасывает <strong>все 4 faction scores</strong> до 0 (threshold_hit=0) AND
            возвращает <strong>всех characters</strong> у endgame_state ('won', 'lost', 'frozen', 'evacuated')
            обратно у <span class="aui-mono">'active'</span>. Используется когда стартует "new season" после finalization.
            Действие audit logged. CSRF protected.
        </p>
        <form method="POST" action="<?= site_url('/admin/endgame/reset-season') ?>" onsubmit="return confirm('ПОДТВЕРДИТЕ: полный season reset? Это сбросит ВСЕ scores + ВСЕ character endgame states.');">
            <?= csrf_field() ?>
            <button type="submit" class="aui-btn aui-btn--danger-solid">🔄 Reset full season</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
