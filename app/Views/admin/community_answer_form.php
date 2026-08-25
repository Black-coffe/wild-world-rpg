<?php
/**
 * ADR-176 (community-chat-bot), story 12 — «Правка» черновика ответа перед одобрением.
 *
 * ADR-178 (story 68) — `$advisories`/`$pendingMessageId` заполнены, когда первое
 * нажатие «Одобрить» вернулось с непокрытыми провенансом предложениями: форма
 * переотрисовывается ЭТИМ же шаблоном (см. {@see \App\Controllers\Admin\CommunityController::approve()}),
 * называет непокрытые предложения поимённо и требует явного второго подтверждения.
 * 🔴 Отсутствие пометки НЕ означает «текст подтверждён источником» — рубеж 1 просто
 * не нашёл, к чему придраться (ADR-178: 4 фабриката из 22 прошли без пометки случайно).
 *
 * @var array<string, mixed>          $answer
 * @var list<array<string, mixed>>    $openMessages
 * @var list<string>|null             $advisories
 * @var int|null                      $pendingMessageId
 */
$answer           = $answer ?? [];
$advisories       = $advisories ?? [];
$pendingMessageId = $pendingMessageId ?? null;
?>
<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Правка ответа<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции · чат сообщества</p>
        <h1 class="aui-display">Правка ответа #<?= (int) $answer['id'] ?></h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/community') ?>"><i class="ri-arrow-left-line"></i> К очереди</a>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc(session()->getFlashdata('success')) ?></div></div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc(session()->getFlashdata('error')) ?></div></div>
<?php endif; ?>

<div class="aui-card" style="margin-bottom:var(--sp-4)"><div class="aui-card__body">
    <form action="<?= site_url('admin/community/answer/' . $answer['id'] . '/save') ?>" method="post">
        <?= csrf_field() ?>
        <div class="aui-field">
            <label for="question_pattern" class="aui-label">Паттерн вопроса <span class="req">*</span></label>
            <textarea class="aui-textarea" id="question_pattern" name="question_pattern" rows="3"><?= esc(old('question_pattern', (string) ($answer['question_pattern'] ?? ''))) ?></textarea>
        </div>
        <div class="aui-field">
            <label for="answer_text" class="aui-label">Текст ответа <span class="req">*</span></label>
            <textarea class="aui-textarea" id="answer_text" name="answer_text" rows="6"><?= esc(old('answer_text', (string) ($answer['answer_text'] ?? ''))) ?></textarea>
        </div>
        <div class="aui-field">
            <label for="requires_setting" class="aui-label">Требует килсвитч (ключ GameSettings, необязательно)</label>
            <input type="text" class="aui-input" id="requires_setting" name="requires_setting" value="<?= esc(old('requires_setting', (string) ($answer['requires_setting'] ?? ''))) ?>">
        </div>
        <p class="aui-muted">Источник (provenance): <?= esc((string) ($answer['source_ref'] ?? '')) ?></p>

        <div class="aui-form-actions">
            <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-save-line"></i> Сохранить черновик</button>
        </div>
    </form>
</div></div>

<div class="aui-card"><div class="aui-card__body">
    <h2 class="aui-h2" style="margin-bottom:var(--sp-3)">Одобрить</h2>
    <p class="aui-muted" style="margin-bottom:var(--sp-3)">CommunityGuard перепроверит текст выше на момент одобрения — сохраните правки перед тем, как одобрять.</p>

    <?php if ($advisories !== []): ?>
        <!-- ADR-178 (story 68) — компонент «пометка провенанса»: живой эталон в public/admin-redesign-preview.html §09. -->
        <div class="aui-alert aui-alert--warning" style="margin-bottom:var(--sp-4); flex-direction:column; align-items:stretch">
            <div style="display:flex; gap:var(--sp-3)">
                <i class="ri-alert-line"></i>
                <div>
                    <div class="aui-alert__title">Не подтверждено источником — проверьте сами</div>
                    Пометка приводит глаз к строке, которую стоит перепроверить. <strong>Отсутствие пометки НЕ значит,
                    что остальной текст подтверждён</strong> — рубеж провенанса просто не нашёл, к чему придраться.
                </div>
            </div>
            <ul style="margin:var(--sp-3) 0 0 calc(18px + var(--sp-3)); padding:0">
                <?php foreach ($advisories as $advisory): ?>
                    <li class="aui-small" style="margin-bottom:var(--sp-2)"><?= esc((string) $advisory) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form action="<?= site_url('admin/community/answer/' . $answer['id'] . '/approve') ?>" method="post" class="aui-actions" style="flex-wrap:wrap">
        <?= csrf_field() ?>
        <select name="message_id" class="aui-select" aria-label="Куда отправить ответ">
            <option value="">— без ответа (только в банк) —</option>
            <?php foreach ($openMessages as $msg): ?>
                <option value="<?= (int) $msg['id'] ?>" <?= $pendingMessageId === (int) $msg['id'] ? 'selected' : '' ?>>#<?= (int) $msg['id'] ?> — <?= esc(mb_substr((string) ($msg['text'] ?? ''), 0, 60)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($advisories !== []): ?>
            <label class="aui-check" style="flex-basis:100%">
                <input type="checkbox" name="confirm_advisories" value="1" required aria-label="Текст не подтверждён источником — отвечаю за него">
                <span class="aui-check__box"></span> Текст не подтверждён источником — отвечаю за него
            </label>
            <button type="submit" class="aui-btn aui-btn--primary" aria-label="Подтвердить и одобрить вопреки пометке"><i class="ri-check-double-line"></i> Подтвердить и одобрить</button>
        <?php else: ?>
            <button type="submit" class="aui-btn aui-btn--primary" aria-label="Одобрить ответ"><i class="ri-check-line"></i> Одобрить</button>
        <?php endif; ?>
    </form>
</div></div>

<?= $this->endSection() ?>
