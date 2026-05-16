<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>
<div class="container">
    <h2>Отправка сообщения игрокам</h2>

    <?php
        $flashSuccess = session()->getFlashdata('success');
        $flashError   = session()->getFlashdata('error');
    ?>
    <?php if (is_string($flashSuccess) && $flashSuccess !== ''): ?>
        <div class="alert alert-success"><?= esc($flashSuccess) ?></div>
    <?php endif; ?>

    <?php if (is_string($flashError) && $flashError !== ''): ?>
        <div class="alert alert-danger"><?= esc($flashError) ?></div>
    <?php endif; ?>

    <form action="<?= base_url('/admin/send-message') ?>" method="post" id="messageForm" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="form-group mb-3">
            <label for="title">Название сообщения (макс. 160 символов)</label>
            <input type="text" class="form-control" name="title" id="title" maxlength="160" required>
            <small class="form-text text-muted">Покажется жирным в шапке: «ℹ️ Название ℹ️».</small>
        </div>

        <div class="form-group mb-2">
            <label for="message">Основное содержимое сообщения</label>
            <div class="msg-toolbar btn-toolbar mb-2" role="toolbar" aria-label="Форматирование">
                <div class="btn-group btn-group-sm me-2" role="group" aria-label="Форматирование текста">
                    <button type="button" class="btn btn-outline-secondary" data-wrap="*" title="Жирный (Ctrl+B)"><b>B</b></button>
                    <button type="button" class="btn btn-outline-secondary" data-wrap="_" title="Курсив (Ctrl+I)"><i>I</i></button>
                    <button type="button" class="btn btn-outline-secondary" data-wrap="`" title="Моноспейс (Ctrl+E)"><code>{ }</code></button>
                </div>
                <div class="btn-group btn-group-sm me-2" role="group" aria-label="Блоки">
                    <button type="button" class="btn btn-outline-secondary" data-prefix="&gt; " title="Цитата">&gt;</button>
                    <button type="button" class="btn btn-outline-secondary" data-prefix="• " title="Маркер списка">•</button>
                    <button type="button" class="btn btn-outline-secondary" data-action="link" title="Ссылка">🔗</button>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Эмодзи">
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🎉">🎉</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="⚠️">⚠️</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🛠️">🛠️</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🎞️">🎞️</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🤖">🤖</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🌍">🌍</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🛡️">🛡️</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🌲">🌲</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🌾">🌾</button>
                    <button type="button" class="btn btn-outline-secondary" data-emoji="🔥">🔥</button>
                </div>
            </div>
            <textarea class="form-control" name="message" id="message" rows="9" required style="font-family:Menlo,Consolas,monospace;font-size:14px;line-height:1.45;"></textarea>
            <small class="form-text text-muted">
                Markdown Telegram: <code>*жирный*</code>, <code>_курсив_</code>, <code>`код`</code>,
                <code>[текст](url)</code>, <code>&gt; цитата</code>. Спецсимволы вне разметки экранируются автоматически на стороне Telegram.
            </small>
        </div>

        <div class="form-group mb-3 border rounded p-3 bg-light">
            <label class="form-label fw-bold mb-2">Картинка к сообщению (опционально)</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="image_mode" id="image_mode_none" value="none" checked>
                <label class="form-check-label" for="image_mode_none">Без картинки (только текст)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="image_mode" id="image_mode_upload" value="upload">
                <label class="form-check-label" for="image_mode_upload">Прикрепить файл (JPEG/PNG/WebP, до 5 МБ)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="image_mode" id="image_mode_ai" value="ai_generate">
                <label class="form-check-label" for="image_mode_ai">
                    🤖 Сгенерировать через OpenAI в стиле игры (ADR-022, «Найденная фотоплёнка»)
                </label>
            </div>

            <div id="image_upload_block" class="mt-2" style="display:none;">
                <input type="file" name="image_file" id="image_file" class="form-control" accept="image/jpeg,image/png,image/webp">
                <small class="form-text text-muted">Картинка приложится как Telegram photo с подписью (caption ≤1024 символов; длиннее — отправится двумя сообщениями: фото + текст).</small>
            </div>

            <div id="image_ai_block" class="mt-2" style="display:none;">
                <label for="image_scene" class="form-label">Уточнение сцены (опционально, по-английски лучше)</label>
                <input type="text" name="image_scene" id="image_scene" class="form-control" maxlength="500"
                       placeholder="напр. survivors gathered around a campfire reading a notice">
                <small class="form-text text-muted">
                    Промпт автоматически склеит STYLE CORE + LEXICON «announcement.generic» + это уточнение. Если поле пусто — генерация по первым ~12 словам анонса. ⚠️ Стоит ~$0.04 за один прогон.
                </small>
            </div>
        </div>

        <div class="form-group mb-3">
            <label for="telegram_ids">ID Telegram пользователей (через запятую, необязательно)</label>
            <input type="text" class="form-control" name="telegram_ids" id="telegram_ids" value="" placeholder="оставь пустым для рассылки всем">
        </div>

        <button type="submit" class="btn btn-primary" id="submitBtn">Отправить сообщение</button>
    </form>
</div>

<script src="<?= base_url('assets/js/admin/send-message.js') ?>"></script>
<?= $this->endSection() ?>
