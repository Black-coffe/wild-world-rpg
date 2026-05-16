<?= $this->extend('templates/admin') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <h1 class="mt-4">Редактировать опрос</h1>

    <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= session()->getFlashdata('error') ?></div>
    <?php endif; ?>

    <form action="<?= site_url('admin/polls/update/' . $poll['id']) ?>" method="post">
        <?= csrf_field() ?>

        <div class="mb-3">
            <label for="question" class="form-label">Вопрос</label>
            <textarea name="question" id="question" class="form-control" rows="4" required><?= esc($poll['question']) ?></textarea>
            <div class="form-text">Редактирование поддерживает HTML-разметку и эмодзи.</div>
        </div>

        <div class="mb-3">
            <label for="expires_at" class="form-label">Дата окончания голосования (необязательно)</label>
            <input type="datetime-local" name="expires_at" id="expires_at"
                   class="form-control"
                   value="<?= esc($poll['expires_at']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label">Варианты ответа</label>

            <?php if (!empty($answers) && is_array($answers)): ?>
                <?php foreach ($answers as $answer): ?>
                    <div class="mb-2">
                        <input type="text"
                               name="existingAnswers[<?= $answer['id'] ?>]"
                               class="form-control"
                               value="<?= esc($answer['answer_text']) ?>"
                               placeholder="Вариант ответа">
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Для добавления новых вариантов -->
            <div class="mb-2">
                <input type="text" name="newAnswers[]" class="form-control" placeholder="Новый вариант ответа">
            </div>

            <!-- Кнопка для динамического добавления полей через JS, если нужно -->
            <button type="button" class="btn btn-secondary" id="addNewAnswerBtn">+ Вариант ответа</button>
        </div>

        <button type="submit" class="btn btn-primary">Обновить опрос</button>
    </form>
</div>

<script src="<?= base_url('assets/js/admin/poll-edit.js') ?>"></script>

<?= $this->endSection() ?>
