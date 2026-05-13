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

<div class="card">
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="<?= $formAction ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-5">
                    <label for="title_ru" class="form-label">Название квеста (Русский)</label>
                    <input type="text" class="form-control" id="title_ru" name="title_ru"
                           value="<?= $isEdit ? esc(old('title_ru', $quest['title_ru'] ?? '')) : esc(old('title_ru')) ?>" required>
                </div>
                <div class="mb-3 col-md-5">
                    <label for="title_en" class="form-label">Название квеста (Английский)</label>
                    <input type="text" class="form-control" id="title_en" name="title_en"
                           value="<?= $isEdit ? esc(old('title_en', $quest['title_en'] ?? '')) : esc(old('title_en')) ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="status_line" class="form-label">Статус</label>
                    <select class="form-select" id="status_line" name="status" required>
                        <?= $renderOption('active',    'Активный',    $currentStatus) ?>
                        <?= $renderOption('completed', 'Завершенный', $currentStatus) ?>
                        <?= $renderOption('available', 'Доступный',   $currentStatus) ?>
                    </select>
                </div>

                <div class="mb-3 col-md-4">
                    <label for="reward" class="form-label">Награда (количество)</label>
                    <input type="number" class="form-control" id="reward" name="reward"
                           value="<?= $isEdit ? esc(old('reward', $quest['reward'] ?? '')) : esc(old('reward')) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="reward_type" class="form-label">Тип награды</label>
                    <select class="form-select" id="reward_type" name="reward_type">
                        <?= $renderOption('gold',        'Золото',                $currentRewardType) ?>
                        <?= $renderOption('resources',   'Ресурсы',               $currentRewardType) ?>
                        <?= $renderOption('Craft tools', 'Крафтовые инструменты', $currentRewardType) ?>
                        <?= $renderOption('recipes',     'Рецепты',               $currentRewardType) ?>
                        <?= $renderOption('experience',  'Опыт',                  $currentRewardType) ?>
                        <?= $renderOption('another',     'Другое',                $currentRewardType) ?>
                    </select>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="min_level" class="form-label">Мин. Уровень персонажа</label>
                    <input type="number" class="form-control" id="min_level" name="min_level"
                           value="<?= $isEdit ? esc(old('min_level', $quest['min_level'] ?? '')) : esc(old('min_level')) ?>">
                </div>

                <div class="mb-3 col-md-12">
                    <label for="description" class="form-label">Описание</label>
                    <textarea class="form-control" id="description" name="description" rows="3" required><?= $isEdit ? esc(old('description', $quest['description'] ?? '')) : esc(old('description')) ?></textarea>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><?= esc($submitLabel) ?></button>
        </form>
    </div>
</div>
