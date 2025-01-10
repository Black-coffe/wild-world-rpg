<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>
<div class="container">
    <h2>Отправка сообщения игрокам</h2>

    <!-- Всплывающее сообщение об успехе -->
    <?php if (session()->getFlashdata('success')): ?>
        <div class="alert alert-success">
            <?= session()->getFlashdata('success') ?>
        </div>
    <?php endif; ?>

    <!-- Всплывающее сообщение об ошибке -->
    <?php if (session()->getFlashdata('error')): ?>
        <div class="alert alert-danger">
            <?= session()->getFlashdata('error') ?>
        </div>
    <?php endif; ?>

    <form action="<?= base_url('/admin/send-message') ?>" method="post" id="messageForm">
        <?= csrf_field() ?> <!-- CSRF токен для защиты -->

        <div class="form-group">
            <label for="title">Название сообщения (макс. 160 символов)</label>
            <input type="text" class="form-control" name="title" id="title" maxlength="160" required>
            <small id="titleWarning" class="form-text text-danger" style="display: none;">В названии сообщения обнаружены запрещенные символы!</small>
        </div>

        <div class="form-group">
            <label for="message">Основное содержимое сообщения</label>
            <textarea class="form-control" name="message" id="message" rows="5" required></textarea>
            <small id="messageWarning" class="form-text text-danger" style="display: none;">В содержимом сообщения обнаружены запрещенные символы!</small>
        </div>

        <div class="form-group">
            <label for="telegram_ids">ID Telegram пользователей (через запятую, необязательно)</label>
            <input type="text" class="form-control" name="telegram_ids" id="telegram_ids" value="352706554,">
        </div>

        <button type="submit" class="btn btn-primary" id="submitBtn" disabled>Отправить сообщение</button>
    </form>
</div>

<script>
    // Запрещенные символы для Telegram Markdown
    const forbiddenChars = [ '*', '[', ']', '(', ')', '~', '>', '#', '+', '-', '=', '|', '{', '}'];

    // Функция для проверки наличия запрещенных символов
    function checkForbiddenSymbols(inputValue) {
        return forbiddenChars.some(char => inputValue.includes(char));
    }

    document.addEventListener('DOMContentLoaded', function() {
        const titleInput = document.getElementById('title');
        const messageInput = document.getElementById('message');
        const submitBtn = document.getElementById('submitBtn');
        const titleWarning = document.getElementById('titleWarning');
        const messageWarning = document.getElementById('messageWarning');

        function validateForm() {
            let titleHasForbiddenChars = checkForbiddenSymbols(titleInput.value);
            let messageHasForbiddenChars = checkForbiddenSymbols(messageInput.value);

            // Показываем предупреждение если в названии или сообщении найдены запрещенные символы
            titleWarning.style.display = titleHasForbiddenChars ? 'block' : 'none';
            messageWarning.style.display = messageHasForbiddenChars ? 'block' : 'none';

            // Отключаем кнопку отправки если найдены запрещенные символы
            if (titleHasForbiddenChars || messageHasForbiddenChars) {
                submitBtn.disabled = true;
            } else {
                submitBtn.disabled = false;
            }
        }

        // Проверяем заголовок и сообщение на запрещенные символы в реальном времени
        titleInput.addEventListener('input', validateForm);
        messageInput.addEventListener('input', validateForm);
    });
</script>
<?= $this->endSection() ?>
