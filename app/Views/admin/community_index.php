<?php
/**
 * ADR-176 (community-chat-bot), story 12 — `/admin/community`.
 *
 * @var array<int, list<array<string, mixed>>>                        $topics
 * @var list<array<string, mixed>>                                    $openMessages
 * @var list<array<string, mixed>>                                    $drafts
 * @var list<array<string, mixed>>                                    $approved
 * @var array{bot_vs_human_share: ?float, guard_rejection_rate: ?float, stale_open_questions: int, top_repeated: list<array{answer_id:int, question_pattern:string, uses:int}>} $metrics
 * @var int                                                           $staleHours
 */

$pct = static fn (?float $v): string => $v === null ? '—' : round($v * 100) . '%';

$statusBadge = static function (string $status): string {
    $map = [
        'new'       => ['aui-badge--info', 'новый'],
        'escalated' => ['aui-badge--warning', 'эскалация'],
        'answered'  => ['aui-badge--success', 'отвечен'],
        'ignored'   => ['aui-badge', 'проигнорирован'],
    ];
    [$class, $label] = $map[$status] ?? ['aui-badge', $status];

    return '<span class="aui-badge ' . $class . '">' . esc($label) . '</span>';
};
?>
<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Чат сообщества<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции · ADR-176</p>
        <h1 class="aui-display">Очередь модерации чата сообщества</h1>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc(session()->getFlashdata('error')) ?></div></div>
<?php endif; ?>

<div class="aui-grid aui-grid--kpi" style="margin-bottom:var(--sp-5)">
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label">Бот против живых</span><i class="ri-robot-2-line aui-kpi__icon"></i></div>
        <div class="aui-kpi__value"><?= esc($pct($metrics['bot_vs_human_share'])) ?></div>
        <div class="aui-kpi__rule"></div>
        <div class="aui-kpi__sub">доля ответов от бота, не от игрока, за 7 дней — рост означает, что чат перестал отвечать сам себе</div>
    </div>
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label">Отказы гварда</span><i class="ri-shield-cross-line aui-kpi__icon"></i></div>
        <div class="aui-kpi__value"><?= esc($pct($metrics['guard_rejection_rate'])) ?></div>
        <div class="aui-kpi__rule"></div>
        <div class="aui-kpi__sub">доля эскалаций среди попыток ответить за 7 дней — рост значит «пополни банк», не «гвард сломан»</div>
    </div>
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label">Просрочено &gt;<?= (int) $staleHours ?>ч</span><i class="ri-time-line aui-kpi__icon"></i></div>
        <div class="aui-kpi__value"><?= (int) $metrics['stale_open_questions'] ?></div>
        <div class="aui-kpi__rule"></div>
        <div class="aui-kpi__sub">открытые вопросы без ответа дольше <?= (int) $staleHours ?> часов — инцидент, не низкий приоритет</div>
    </div>
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label">Топ повторов</span><i class="ri-repeat-line aui-kpi__icon"></i></div>
        <div class="aui-kpi__value"><?= count($metrics['top_repeated']) ?></div>
        <div class="aui-kpi__rule"></div>
        <div class="aui-kpi__sub">
            <?php if ($metrics['top_repeated'] === []): ?>
                нет данных за 7 дней
            <?php else: ?>
                <?php foreach ($metrics['top_repeated'] as $row): ?>
                    <?= esc(mb_substr($row['question_pattern'], 0, 40)) ?> (×<?= (int) $row['uses'] ?>)<br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="aui-card" style="margin-bottom:var(--sp-5)">
    <div class="aui-card__body">
        <h2 class="aui-h2" style="margin-bottom:var(--sp-3)">Открытые вопросы</h2>
        <?php if ($topics === []): ?>
            <p class="aui-muted">Очередь пуста.</p>
        <?php endif; ?>
        <?php foreach ($topics as $threadId => $rows): ?>
            <p class="aui-eyebrow" style="margin-top:var(--sp-4)">Топик #<?= (int) $threadId ?></p>
            <div class="aui-tablewrap">
                <table class="aui-table">
                    <thead><tr>
                        <th>Автор</th><th>Текст</th><th>Возраст</th><th>Статус</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $ageHours = isset($row['sent_at']) && is_string($row['sent_at'])
                            ? (int) floor((time() - strtotime($row['sent_at'])) / 3600)
                            : null;
                        ?>
                        <tr>
                            <td class="aui-muted">@<?= esc((string) ($row['username'] ?? $row['telegram_user_id'] ?? '?')) ?></td>
                            <td><?= esc(mb_substr((string) ($row['text'] ?? ''), 0, 160)) ?></td>
                            <td>
                                <?php if ($ageHours !== null && $ageHours > $staleHours): ?>
                                    <span class="aui-badge aui-badge--danger"><?= $ageHours ?>ч</span>
                                <?php else: ?>
                                    <span class="aui-muted"><?= $ageHours !== null ? $ageHours . 'ч' : '—' ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $statusBadge((string) ($row['status'] ?? 'new')) ?></td>
                            <td>
                                <form action="<?= site_url('admin/community/erase') ?>" method="post" onsubmit="return confirm('Стереть все сообщения этого игрока из community_messages?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="telegram_user_id" value="<?= esc((string) ($row['telegram_user_id'] ?? '')) ?>">
                                    <button type="submit" class="aui-btn aui-btn--danger aui-btn--sm"><i class="ri-delete-bin-line"></i> Стереть всё от игрока</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="aui-card" style="margin-bottom:var(--sp-5)">
    <div class="aui-card__body">
        <h2 class="aui-h2" style="margin-bottom:var(--sp-3)">Черновики банка</h2>
        <div class="aui-tablewrap">
            <table class="aui-table">
                <thead><tr>
                    <th>Паттерн вопроса</th><th>Текст ответа</th><th>Источник</th><th>Ответить на</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($drafts as $draft): ?>
                    <tr>
                        <td class="aui-muted text-truncate"><?= esc(mb_substr((string) $draft['question_pattern'], 0, 90)) ?></td>
                        <td class="text-truncate"><?= esc(mb_substr((string) $draft['answer_text'], 0, 120)) ?></td>
                        <td class="aui-muted"><?= esc((string) $draft['source_ref']) ?></td>
                        <td>
                            <form action="<?= site_url('admin/community/answer/' . $draft['id'] . '/approve') ?>" method="post" class="aui-actions">
                                <?= csrf_field() ?>
                                <select name="message_id" class="aui-select">
                                    <option value="">— без ответа (только в банк) —</option>
                                    <?php foreach ($openMessages as $msg): ?>
                                        <option value="<?= (int) $msg['id'] ?>">#<?= (int) $msg['id'] ?> — <?= esc(mb_substr((string) ($msg['text'] ?? ''), 0, 60)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="aui-btn aui-btn--primary aui-btn--sm"><i class="ri-check-line"></i> Одобрить</button>
                            </form>
                        </td>
                        <td>
                            <div class="aui-actions">
                                <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/community/answer/' . $draft['id'] . '/edit') ?>" title="Правка"><i class="ri-pencil-line"></i></a>
                                <form action="<?= site_url('admin/community/answer/' . $draft['id'] . '/reject') ?>" method="post" onsubmit="return confirm('Отклонить черновик?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="aui-btn aui-btn--danger aui-btn--icon" title="Отклонить"><i class="ri-close-line"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($drafts === []): ?><tr><td colspan="5" class="aui-table__empty">Нет черновиков</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="aui-card">
    <div class="aui-card__body">
        <h2 class="aui-h2" style="margin-bottom:var(--sp-3)">Активный банк (одобрено)</h2>
        <div class="aui-tablewrap">
            <table class="aui-table">
                <thead><tr>
                    <th>Паттерн вопроса</th><th>Текст ответа</th><th>Одобрил</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($approved as $row): ?>
                    <tr>
                        <td class="aui-muted text-truncate"><?= esc(mb_substr((string) $row['question_pattern'], 0, 90)) ?></td>
                        <td class="text-truncate"><?= esc(mb_substr((string) $row['answer_text'], 0, 120)) ?></td>
                        <td class="aui-muted"><?= esc((string) ($row['approved_by'] ?? '?')) ?></td>
                        <td>
                            <form action="<?= site_url('admin/community/answer/' . $row['id'] . '/revoke') ?>" method="post" class="aui-actions" onsubmit="return confirm('Отозвать ответ? Если он уже ушёл в чат — туда же уйдёт поправка.');">
                                <?= csrf_field() ?>
                                <input type="text" class="aui-input" name="correction_text" placeholder="Текст поправки (необязательно)">
                                <button type="submit" class="aui-btn aui-btn--danger aui-btn--sm"><i class="ri-arrow-go-back-line"></i> Отозвать</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($approved === []): ?><tr><td colspan="4" class="aui-table__empty">Банк пуст</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
