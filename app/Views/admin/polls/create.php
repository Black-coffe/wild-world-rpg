<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Создать опрос<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции · опросы</p>
        <h1 class="aui-display">Создать новый опрос</h1>
    </div>
</div>

<?php if (session()->getFlashdata('error')): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc(session()->getFlashdata('error')) ?></div></div>
<?php endif; ?>

<div class="aui-card" style="margin-bottom:var(--sp-4)">
    <div class="aui-card__head"><i class="ri-code-s-slash-line"></i><span class="aui-card__title">Примеры форматирования (parse_mode = HTML)</span></div>
    <div class="aui-card__body">
        <p style="margin:0 0 var(--sp-2)">
            <b>Жирный текст:</b> <span class="aui-mono">&lt;b&gt;Текст&lt;/b&gt;</span><br>
            <i>Курсив:</i> <span class="aui-mono">&lt;i&gt;Текст&lt;/i&gt;</span><br>
            <u>Подчёркнутый:</u> <span class="aui-mono">&lt;u&gt;Текст&lt;/u&gt;</span><br>
            Перенос строки: <span class="aui-mono">&lt;br&gt;</span><br>
            Ссылка: <span class="aui-mono">&lt;a href="https://example.com"&gt;Пример ссылки&lt;/a&gt;</span><br>
            Эмодзи можно вставлять напрямую (например, 😊, 🚀, etc.)
        </p>
        <p class="aui-muted aui-small" style="margin:0">
            Разрешённые теги: b, i, u, s, a (с href), code, pre, и т.п.
            Другие теги Telegram может не отобразить или вызвать ошибку.
        </p>
    </div>
</div>

<div class="aui-card">
    <div class="aui-card__body">
    <form id="pollForm" action="<?= site_url('admin/polls/store') ?>" method="post">
        <?= csrf_field() ?>

        <div class="aui-field">
            <label for="question" class="aui-label">Вопрос</label>
            <textarea name="question" id="question" class="aui-textarea" rows="4"
                      placeholder="Введите вопрос с HTML-разметкой и эмодзи" required></textarea>
            <div class="aui-hint">Поддерживаются некоторые HTML-теги (см. примеры выше) и эмодзи.</div>
        </div>

        <div class="aui-field">
            <label for="expires_at" class="aui-label">Дата окончания голосования (необязательно)</label>
            <input type="datetime-local" name="expires_at" id="expires_at" class="aui-input" style="max-width:280px">
        </div>

        <div class="aui-field" id="answers-container">
            <label class="aui-label">Варианты ответа</label>
            <input type="text" name="answers[]" class="aui-input" style="margin-bottom:var(--sp-2)" placeholder="Вариант ответа 1" required>
            <input type="text" name="answers[]" class="aui-input" style="margin-bottom:var(--sp-2)" placeholder="Вариант ответа 2" required>
        </div>
        <button type="button" class="aui-btn aui-btn--ghost aui-btn--sm" id="add-answer-btn" style="margin-bottom:var(--sp-4)"><i class="ri-add-line"></i> Вариант ответа</button>

        <div class="aui-form-actions">
            <button type="submit" class="aui-btn aui-btn--primary">Создать опрос</button>
        </div>
    </form>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin/poll-create.js') ?>"></script>
<?= $this->endSection() ?>
