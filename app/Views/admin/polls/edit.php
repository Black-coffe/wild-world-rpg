<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Редактировать опрос<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции · опросы</p>
        <h1 class="aui-display">Редактировать опрос</h1>
    </div>
</div>

<?php if (session()->getFlashdata('error')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc(session()->getFlashdata('error')) ?></div></div>
<?php endif; ?>

<div class="aui-card">
    <div class="aui-card__body">
    <form action="<?= site_url('admin/polls/update/' . $poll['id']) ?>" method="post">
        <?= csrf_field() ?>

        <div class="aui-field">
            <label for="question" class="aui-label">Вопрос</label>
            <textarea name="question" id="question" class="aui-textarea" rows="4" required><?= esc($poll['question']) ?></textarea>
            <div class="aui-hint">Редактирование поддерживает HTML-разметку и эмодзи.</div>
        </div>

        <div class="aui-field">
            <label for="expires_at" class="aui-label">Дата окончания голосования (необязательно)</label>
            <input type="datetime-local" name="expires_at" id="expires_at" class="aui-input" style="max-width:280px" value="<?= esc($poll['expires_at']) ?>">
        </div>

        <div class="aui-field" id="answers-container">
            <label class="aui-label">Варианты ответа</label>

            <?php if (!empty($answers) && is_array($answers)): ?>
                <?php foreach ($answers as $answer): ?>
                    <input type="text" name="existingAnswers[<?= $answer['id'] ?>]" class="aui-input"
                           style="margin-bottom:var(--sp-2)" value="<?= esc($answer['answer_text']) ?>" placeholder="Вариант ответа">
                <?php endforeach; ?>
            <?php endif; ?>

            <input type="text" name="newAnswers[]" class="aui-input" style="margin-bottom:var(--sp-2)" placeholder="Новый вариант ответа">

            <button type="button" class="aui-btn aui-btn--ghost aui-btn--sm" id="addNewAnswerBtn"><i class="ri-add-line"></i> Вариант ответа</button>
        </div>

        <div class="aui-form-actions">
            <button type="submit" class="aui-btn aui-btn--primary">Обновить опрос</button>
        </div>
    </form>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin/poll-edit.js') ?>"></script>
<?= $this->endSection() ?>
