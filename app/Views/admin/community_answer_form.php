<?php
/**
 * ADR-176 (community-chat-bot), story 12 — «Правка» черновика ответа перед одобрением.
 *
 * @var array<string, mixed>          $answer
 * @var list<array<string, mixed>>    $openMessages
 */
$answer = $answer ?? [];
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
    <form action="<?= site_url('admin/community/answer/' . $answer['id'] . '/approve') ?>" method="post" class="aui-actions">
        <?= csrf_field() ?>
        <select name="message_id" class="aui-select">
            <option value="">— без ответа (только в банк) —</option>
            <?php foreach ($openMessages as $msg): ?>
                <option value="<?= (int) $msg['id'] ?>">#<?= (int) $msg['id'] ?> — <?= esc(mb_substr((string) ($msg['text'] ?? ''), 0, 60)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-check-line"></i> Одобрить</button>
    </form>
</div></div>

<?= $this->endSection() ?>
