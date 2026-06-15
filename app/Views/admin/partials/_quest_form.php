<?php
/**
 * Унифицированный form-partial для создания и редактирования квеста.
 * Phase D, 2026-05-13. См. `inbox/2026-05-13-admin-refactor-roadmap.md` (C1).
 *
 * **Bonus fix:** в create-форме был дубль `name="reward"` (select + number-input).
 * POST'или одно значение, второе теряли. Унификация исправила это —
 * теперь `reward` (numeric, required), `reward_type` (select, optional).
 *
 * @param string                    $mode  'create' | 'edit'
 * @param array<string, mixed>|null $quest Запись из БД (только при mode='edit')
 */

$isEdit = ($mode ?? 'create') === 'edit';
$quest  = $quest ?? null;

$formAction  = $isEdit
    ? site_url('admin/quests/update/' . ($quest['id'] ?? 0))
    : site_url('admin/quests/store');
$submitLabel = $isEdit ? 'Обновить квест' : 'Добавить квест';

$renderOption = static function (string $value, string $label, ?string $current): string {
    $selected = $current !== null && $current === $value ? ' selected' : '';
    return sprintf('<option value="%s"%s>%s</option>', esc($value), $selected, esc($label));
};

$currentStatus     = $isEdit ? (string) ($quest['status'] ?? '') : null;
$currentRewardType = $isEdit ? (string) ($quest['reward_type'] ?? '') : null;
?>

<div class="aui-card"><div class="aui-card__body">
    <?php if (session()->getFlashdata('errors')): ?>
        <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
            <?php foreach (session()->getFlashdata('errors') as $error): ?><?= esc($error) ?><br><?php endforeach; ?>
        </div></div>
    <?php endif; ?>

    <form action="<?= $formAction ?>" method="post">
        <?= csrf_field() ?>
        <div class="aui-formgrid">
            <div class="aui-field">
                <label for="title_ru" class="aui-label">Название квеста (Русский) <span class="req">*</span></label>
                <input type="text" class="aui-input" id="title_ru" name="title_ru"
                       value="<?= $isEdit ? esc(old('title_ru', $quest['title_ru'] ?? '')) : esc(old('title_ru')) ?>" required>
            </div>
            <div class="aui-field">
                <label for="title_en" class="aui-label">Название квеста (Английский) <span class="req">*</span></label>
                <input type="text" class="aui-input" id="title_en" name="title_en"
                       value="<?= $isEdit ? esc(old('title_en', $quest['title_en'] ?? '')) : esc(old('title_en')) ?>" required>
            </div>
            <div class="aui-field">
                <label for="status_line" class="aui-label">Статус <span class="req">*</span></label>
                <select class="aui-select" id="status_line" name="status" required>
                    <?= $renderOption('active',    'Активный',    $currentStatus) ?>
                    <?= $renderOption('completed', 'Завершенный', $currentStatus) ?>
                    <?= $renderOption('available', 'Доступный',   $currentStatus) ?>
                </select>
            </div>

            <div class="aui-field">
                <label for="reward" class="aui-label">Награда (количество) <span class="req">*</span></label>
                <input type="number" class="aui-input" id="reward" name="reward"
                       value="<?= $isEdit ? esc(old('reward', $quest['reward'] ?? '')) : esc(old('reward')) ?>" required>
            </div>
            <div class="aui-field">
                <label for="reward_type" class="aui-label">Тип награды</label>
                <select class="aui-select" id="reward_type" name="reward_type">
                    <?= $renderOption('gold',        'Золото',                $currentRewardType) ?>
                    <?= $renderOption('resources',   'Ресурсы',               $currentRewardType) ?>
                    <?= $renderOption('Craft tools', 'Крафтовые инструменты', $currentRewardType) ?>
                    <?= $renderOption('recipes',     'Рецепты',               $currentRewardType) ?>
                    <?= $renderOption('experience',  'Опыт',                  $currentRewardType) ?>
                    <?= $renderOption('another',     'Другое',                $currentRewardType) ?>
                </select>
            </div>
            <div class="aui-field">
                <label for="min_level" class="aui-label">Мин. Уровень персонажа</label>
                <input type="number" class="aui-input" id="min_level" name="min_level"
                       value="<?= $isEdit ? esc(old('min_level', $quest['min_level'] ?? '')) : esc(old('min_level')) ?>">
            </div>
        </div>

        <?php
            $prereqRaw = $isEdit ? old('prerequisite_quest', $quest['prerequisite_quest'] ?? '') : old('prerequisite_quest');
            $prereqVal = is_string($prereqRaw) ? $prereqRaw : '';
        ?>
        <div class="aui-field">
            <label for="prerequisite_quest" class="aui-label">Предусловие — title_en квеста-предшественника (V11, цепочки)</label>
            <input type="text" class="aui-input" id="prerequisite_quest" name="prerequisite_quest"
                   placeholder="напр. StrategicCaptureBunker (пусто = доступен сразу)"
                   value="<?= esc($prereqVal) ?>">
            <div class="aui-hint">Квест станет доступен только после завершения указанного квеста. Пусто — без цепочки.</div>
        </div>

        <div class="aui-field">
            <label for="description" class="aui-label">Описание <span class="req">*</span></label>
            <textarea class="aui-textarea" id="description" name="description" rows="3" required><?= $isEdit ? esc(old('description', $quest['description'] ?? '')) : esc(old('description')) ?></textarea>
        </div>

        <div class="aui-form-actions">
            <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-save-line"></i> <?= esc($submitLabel) ?></button>
        </div>
    </form>
</div></div>
