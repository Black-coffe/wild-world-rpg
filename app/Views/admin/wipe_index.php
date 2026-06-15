<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Вайп сервера<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Опасная зона</p>
        <h1 class="aui-display">🔥 Вайп всех игроков</h1>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>
<?php if (session()->getFlashdata('errors')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
        <?php foreach ((array) session()->getFlashdata('errors') as $error): ?><?= esc($error) ?><br><?php endforeach; ?>
    </div></div>
<?php endif; ?>

<div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-5)">
    <i class="ri-alert-line"></i>
    <div>
        <div class="aui-alert__title">⚠️ Необратимая операция</div>
        Полный вайп сбрасывает прогресс ВСЕХ игроков до нуля (как вайп сезона).
        <strong>Остаётся:</strong> игровой мир и карта, имена игроков и их id, контент-определения,
        баланс (game_settings), админ/сайт. <strong>Сбрасывается:</strong> весь прогресс персонажей,
        инвентарь, постройки, фракции, исследования, рынок, эндгейм.
    </div>
</div>

<?php $cov = $preview['coverage']; ?>
<?php if ($cov['unclassified'] !== []): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)">
        <i class="ri-shield-cross-line"></i>
        <div>
            <div class="aui-alert__title">🔴 Вайп заблокирован</div>
            Незаклассифицированные таблицы (нет в <span class="aui-mono">Config\WipeManifest</span>):
            <span class="aui-mono"><?= esc(implode(', ', $cov['unclassified'])) ?></span>.
            Добавь их в манифест (ADR-087), иначе они будут упущены при вайпе.
        </div>
    </div>
<?php endif; ?>
<?php if ($cov['stale'] !== []): ?>
    <div class="aui-alert aui-alert--warning" style="margin-bottom:var(--sp-4)">
        <i class="ri-information-line"></i>
        <div>Stale-записи манифеста (таблиц нет в БД): <span class="aui-mono"><?= esc(implode(', ', $cov['stale'])) ?></span>.</div>
    </div>
<?php endif; ?>

<div class="aui-grid aui-grid--kpi" style="margin-bottom:var(--sp-5)">
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label">Аккаунтов</span><i class="aui-kpi__icon ri-telegram-line"></i></div>
        <div class="aui-kpi__value"><?= esc((string) $preview['players']) ?></div>
        <div class="aui-kpi__rule"></div><div class="aui-kpi__sub">telegram_users</div>
    </div>
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label">Персонажей</span><i class="aui-kpi__icon ri-user-line"></i></div>
        <div class="aui-kpi__value"><?= esc((string) $preview['characters']) ?></div>
        <div class="aui-kpi__rule"></div><div class="aui-kpi__sub">будут сброшены</div>
    </div>
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label">Строк к удалению</span><i class="aui-kpi__icon ri-delete-bin-line" style="color:var(--danger)"></i></div>
        <div class="aui-kpi__value" style="color:var(--danger)"><?= esc((string) $preview['totals']['rows_deleted']) ?></div>
        <div class="aui-kpi__rule" style="background:var(--danger)"></div><div class="aui-kpi__sub">необратимо</div>
    </div>
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label">Таблиц сохранится</span><i class="aui-kpi__icon ri-shield-check-line" style="color:var(--success)"></i></div>
        <div class="aui-kpi__value"><?= esc((string) $preview['totals']['tables_kept']) ?></div>
        <div class="aui-kpi__rule" style="background:var(--success)"></div><div class="aui-kpi__sub">KEEP</div>
    </div>
</div>

<div class="aui-card" style="margin-bottom:var(--sp-5)">
    <div class="aui-card__head"><i class="ri-table-line"></i><span class="aui-card__title">Что произойдёт с каждой таблицей</span></div>
    <div class="aui-card__body">
        <div class="aui-tablewrap">
        <table class="aui-table">
            <thead><tr><th>Таблица</th><th>Стратегия</th><th class="num">Строк сейчас</th><th>Действие</th></tr></thead>
            <tbody>
            <?php foreach ($preview['tables'] as $t): ?>
                <?php if ($t['strategy'] === 'keep') { continue; } ?>
                <?php
                    $badge = match ($t['strategy']) {
                        'player_data', 'transient'      => 'aui-badge--danger',
                        'character_reset'               => 'aui-badge--info',
                        'identity_reset', 'seed_reset'  => 'aui-badge--warning',
                        default                         => '',
                    };
                ?>
                <tr>
                    <td class="strong"><span class="aui-mono"><?= esc($t['table']) ?></span><br><small><?= esc($t['note']) ?></small></td>
                    <td><span class="aui-badge <?= $badge ?>"><?= esc($t['strategy']) ?></span></td>
                    <td class="num"><?= esc((string) $t['rows']) ?></td>
                    <td><?= esc($t['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <details style="margin-top:var(--sp-4)">
            <summary class="aui-muted" style="cursor:pointer">🟢 Сохраняемые таблицы (KEEP) — <?= esc((string) $preview['totals']['tables_kept']) ?> шт.</summary>
            <div class="aui-tablewrap" style="margin-top:var(--sp-3)">
            <table class="aui-table">
                <tbody>
                <?php foreach ($preview['tables'] as $t): ?>
                    <?php if ($t['strategy'] !== 'keep') { continue; } ?>
                    <tr>
                        <td><span class="aui-mono"><?= esc($t['table']) ?></span></td>
                        <td class="num"><?= esc((string) $t['rows']) ?> строк</td>
                        <td class="aui-muted"><?= esc($t['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
    </div>
</div>

<div class="aui-card">
    <div class="aui-card__head"><i class="ri-megaphone-line"></i><span class="aui-card__title">Сообщение всем игрокам (отправится ПОСЛЕ вайпа)</span></div>
    <div class="aui-card__body">
        <form action="<?= site_url('admin/wipe/arm') ?>" method="post">
            <?= csrf_field() ?>
            <div class="aui-field">
                <label class="aui-label">Заголовок</label>
                <input type="text" class="aui-input" name="broadcast_title" value="<?= esc($broadcastTitle) ?>">
            </div>
            <div class="aui-field">
                <label class="aui-label">Текст (Telegram-Markdown)</label>
                <textarea class="aui-textarea" name="broadcast_message" rows="8"><?= esc($broadcastMessage) ?></textarea>
            </div>
            <button type="submit" class="aui-btn aui-btn--danger-solid aui-btn--lg<?= $cov['unclassified'] !== [] ? ' is-disabled' : '' ?>"
                <?= $cov['unclassified'] !== [] ? 'disabled title="Сначала классифицируй таблицы в WipeManifest"' : '' ?>>
                🔥 Делать вайп →
            </button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
