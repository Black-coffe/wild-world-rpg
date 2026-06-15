<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Сообщение всем<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции</p>
        <h1 class="aui-display">Отправка сообщения игрокам</h1>
    </div>
</div>

<?php
    $flashSuccess = session()->getFlashdata('success');
    $flashError   = session()->getFlashdata('error');
?>
<?php if (is_string($flashSuccess) && $flashSuccess !== ''): ?>
    <div class="aui-alert aui-alert--success" style="margin-bottom:var(--sp-4)"><i class="ri-checkbox-circle-line"></i><div><?= esc($flashSuccess) ?></div></div>
<?php endif; ?>
<?php if (is_string($flashError) && $flashError !== ''): ?>
    <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div><?= esc($flashError) ?></div></div>
<?php endif; ?>

<div class="aui-card">
    <div class="aui-card__body">
    <form action="<?= base_url('/admin/send-message') ?>" method="post" id="messageForm" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="aui-field">
            <label class="aui-label" for="title">Название сообщения (макс. 160 символов)</label>
            <input type="text" class="aui-input" name="title" id="title" maxlength="160" required>
            <div class="aui-hint">Покажется жирным в шапке: «ℹ️ Название ℹ️».</div>
        </div>

        <div class="aui-field">
            <label class="aui-label" for="message">Основное содержимое сообщения</label>
            <div class="msg-toolbar aui-row" style="gap:var(--sp-2); flex-wrap:wrap; margin-bottom:var(--sp-2)" role="toolbar" aria-label="Форматирование">
                <div class="aui-btngroup" role="group" aria-label="Форматирование текста">
                    <button type="button" data-wrap="*" title="Жирный (Ctrl+B)"><b>B</b></button>
                    <button type="button" data-wrap="_" title="Курсив (Ctrl+I)"><i>I</i></button>
                    <button type="button" data-wrap="`" title="Моноспейс (Ctrl+E)"><span class="aui-mono">{ }</span></button>
                </div>
                <div class="aui-btngroup" role="group" aria-label="Блоки">
                    <button type="button" data-prefix="&gt; " title="Цитата">&gt;</button>
                    <button type="button" data-prefix="• " title="Маркер списка">•</button>
                    <button type="button" data-action="link" title="Ссылка">🔗</button>
                </div>
                <div class="aui-btngroup" role="group" aria-label="Эмодзи">
                    <button type="button" data-emoji="🎉">🎉</button>
                    <button type="button" data-emoji="⚠️">⚠️</button>
                    <button type="button" data-emoji="🛠️">🛠️</button>
                    <button type="button" data-emoji="🎞️">🎞️</button>
                    <button type="button" data-emoji="🤖">🤖</button>
                    <button type="button" data-emoji="🌍">🌍</button>
                    <button type="button" data-emoji="🛡️">🛡️</button>
                    <button type="button" data-emoji="🌲">🌲</button>
                    <button type="button" data-emoji="🌾">🌾</button>
                    <button type="button" data-emoji="🔥">🔥</button>
                </div>
            </div>
            <textarea class="aui-textarea aui-input--mono" name="message" id="message" rows="9" required></textarea>
            <div class="aui-hint">
                Markdown Telegram: <span class="aui-mono">*жирный*</span>, <span class="aui-mono">_курсив_</span>,
                <span class="aui-mono">`код`</span>, <span class="aui-mono">[текст](url)</span>,
                <span class="aui-mono">&gt; цитата</span>. Спецсимволы вне разметки экранируются автоматически на стороне Telegram.
            </div>
        </div>

        <div class="aui-card aui-card--flat" style="background:var(--surface-2); margin-bottom:var(--sp-4)">
            <div class="aui-card__body">
                <label class="aui-label" style="margin-bottom:var(--sp-3)">Картинка к сообщению (опционально)</label>
                <div class="aui-stack" style="gap:var(--sp-2)">
                    <label class="aui-check aui-check--radio">
                        <input type="radio" name="image_mode" id="image_mode_none" value="none" checked>
                        <span class="aui-check__box"></span> Без картинки (только текст)
                    </label>
                    <label class="aui-check aui-check--radio">
                        <input type="radio" name="image_mode" id="image_mode_upload" value="upload">
                        <span class="aui-check__box"></span> Прикрепить файл (JPEG/PNG/WebP, до 5 МБ)
                    </label>
                    <label class="aui-check aui-check--radio">
                        <input type="radio" name="image_mode" id="image_mode_ai" value="ai_generate">
                        <span class="aui-check__box"></span> 🤖 Сгенерировать через OpenAI в стиле игры (ADR-022, «Найденная фотоплёнка»)
                    </label>
                </div>

                <div id="image_upload_block" style="display:none; margin-top:var(--sp-3)">
                    <input type="file" name="image_file" id="image_file" class="aui-input" accept="image/jpeg,image/png,image/webp">
                    <div class="aui-hint">Картинка приложится как Telegram photo с подписью (caption ≤1024 символов; длиннее — отправится двумя сообщениями: фото + текст).</div>
                </div>

                <div id="image_ai_block" style="display:none; margin-top:var(--sp-3)">
                    <label for="image_scene" class="aui-label">Уточнение сцены (опционально, по-английски лучше)</label>
                    <input type="text" name="image_scene" id="image_scene" class="aui-input" maxlength="500"
                           placeholder="напр. survivors gathered around a campfire reading a notice">
                    <div class="aui-hint">
                        Промпт автоматически склеит STYLE CORE + LEXICON «announcement.generic» + это уточнение. Если поле пусто — генерация по первым ~12 словам анонса. ⚠️ Стоит ~$0.04 за один прогон.
                    </div>
                </div>
            </div>
        </div>

        <div class="aui-field">
            <label class="aui-label" for="telegram_ids">ID Telegram пользователей (через запятую, необязательно)</label>
            <input type="text" class="aui-input" name="telegram_ids" id="telegram_ids" value="" placeholder="оставь пустым для рассылки всем">
        </div>

        <div class="aui-form-actions">
            <button type="submit" class="aui-btn aui-btn--primary" id="submitBtn">Отправить сообщение</button>
        </div>
    </form>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/admin/send-message.js') ?>"></script>
<?= $this->endSection() ?>
