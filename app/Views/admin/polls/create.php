<?= $this->extend('templates/admin') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <h1 class="mt-4">Создать новый опрос</h1>

    <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger"><?= session()->getFlashdata('error') ?></div>
    <?php endif; ?>

    <!-- Блок примеров форматирования -->
    <div class="card mb-4">
        <div class="card-body">
            <h5>Примеры форматирования (parse_mode = HTML)</h5>
            <p>
                <b>Жирный текст:</b> &lt;b&gt;Текст&lt;/b&gt;<br>
                <i>Курсив:</i> &lt;i&gt;Текст&lt;/i&gt;<br>
                <u>Подчёркнутый:</u> &lt;u&gt;Текст&lt;/u&gt;<br>
                Перенос строки: &lt;br&gt;<br>
                Ссылка: &lt;a href="https://example.com"&gt;Пример ссылки&lt;/a&gt;<br>
                Эмодзи можно вставлять напрямую (например, 😊, 🚀, etc.)<br>
            </p>
            <p class="text-muted">
                Разрешённые теги: b, i, u, s, a (с href), code, pre, и т.п.
                Другие теги Telegram может не отобразить или вызвать ошибку.
            </p>
        </div>
    </div>
    <!-- Конец блока примеров форматирования -->

    <form id="pollForm" action="<?= site_url('admin/polls/store') ?>" method="post">
        <?= csrf_field() ?>

        <div class="mb-3">
            <label for="question" class="form-label">Вопрос</label>
            <textarea name="question" id="question" class="form-control" rows="4"
                      placeholder="Введите вопрос с HTML-разметкой и эмодзи" required></textarea>
            <div class="form-text">Поддерживаются некоторые HTML-теги (см. примеры выше) и эмодзи.</div>
        </div>

        <div class="mb-3">
            <label for="expires_at" class="form-label">Дата окончания голосования (необязательно)</label>
            <input type="datetime-local" name="expires_at" id="expires_at" class="form-control">
        </div>

        <!-- Поля для вариантов ответа -->
        <div class="mb-3" id="answers-container">
            <label class="form-label">Варианты ответа</label>
            <input type="text" name="answers[]" class="form-control mb-2" placeholder="Вариант ответа 1" required>
            <input type="text" name="answers[]" class="form-control mb-2" placeholder="Вариант ответа 2" required>
        </div>
        <button type="button" class="btn btn-secondary mb-3" id="add-answer-btn">+ Вариант ответа</button>

        <button type="submit" class="btn btn-primary">Создать опрос</button>
    </form>
</div>

<script>
    // Разрешённые теги для Telegram parse_mode=HTML
    // Разрешённые теги для Telegram parse_mode=HTML
    const ALLOWED_TAGS = [
        'b', 'i', 'u', 's', 'strike', 'del', 'strong', 'em',
        'code', 'pre', 'a', 'tg-spoiler', 'br'
    ];

    document.addEventListener('DOMContentLoaded', function() {
        // 1) Логика добавления новых полей для вариантов ответа
        const answersContainer = document.getElementById('answers-container');
        const addAnswerBtn = document.getElementById('add-answer-btn');

        addAnswerBtn.addEventListener('click', function() {
            const newField = document.createElement('input');
            newField.type = 'text';
            newField.name = 'answers[]';
            newField.classList.add('form-control', 'mb-2');
            newField.placeholder = 'Новый вариант ответа';
            answersContainer.appendChild(newField);
        });

        // 2) Валидация HTML-тегов в вопросе перед отправкой формы
        const pollForm = document.getElementById('pollForm');
        pollForm.addEventListener('submit', function(e) {
            const questionField = document.getElementById('question');
            const questionValue = questionField.value.trim();

            if (!validateTelegramHtml(questionValue)) {
                e.preventDefault();
                alert('В тексте вопроса обнаружены HTML-теги, не поддерживаемые Telegram parse_mode=HTML.\nДопустимые теги: ' + ALLOWED_TAGS.join(', '));
            }
        });
    });

    /**
     * Простейшая проверка HTML-тегов: парсим контент через DOMParser,
     * проверяем, что все встречающиеся теги входят в список ALLOWED_TAGS.
     */
    function validateTelegramHtml(htmlString) {
        // Создаём "виртуальный" документ
        const parser = new DOMParser();
        const doc = parser.parseFromString(htmlString, 'text/html');

        // Если есть parsererror, значит HTML некорректный
        if (doc.querySelector('parsererror')) {
            return false;
        }

        // Проходим по всем тегам
        const allElements = doc.body.querySelectorAll('*');
        for (let el of allElements) {
            const tagName = el.tagName.toLowerCase();
            if (!ALLOWED_TAGS.includes(tagName)) {
                // Если встретили <a>, то проверим наличие href
                if (tagName === 'a') {
                    if (!el.hasAttribute('href')) {
                        return false;
                    }
                } else {
                    // Неразрешённый тег
                    return false;
                }
            }
        }
        return true;
    }
</script>
<?= $this->endSection() ?>
