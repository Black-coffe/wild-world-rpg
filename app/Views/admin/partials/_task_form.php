<?php
/**
 * Унифицированный form-partial для создания и редактирования задачи (task-шаблона).
 * Phase D, 2026-05-13. См. `inbox/2026-05-13-admin-refactor-roadmap.md` (C1).
 *
 * @param string                    $mode  'create' | 'edit'
 * @param array<string, mixed>|null $task  Запись из БД (только при mode='edit')
 */

$isEdit = ($mode ?? 'create') === 'edit';
$task   = $task ?? null;

$formAction  = $isEdit
    ? site_url('admin/tasks/update/' . ($task['id'] ?? 0))
    : site_url('admin/tasks/store');
$submitLabel = $isEdit ? 'Сохранить изменения' : 'Создать задачу';

/** Mini-helper: select-option с pre-selected value (edit) или без (create). */
$renderOption = static function (string $value, string $label, ?string $current): string {
    $selected = $current !== null && $current === $value ? ' selected' : '';
    return sprintf('<option value="%s"%s>%s</option>', esc($value), $selected, esc($label));
};

$currentType        = $isEdit ? (string) ($task['type'] ?? '') : null;
$currentParallel    = $isEdit ? (string) ((int) ($task['parallel_execution_allowed'] ?? 0)) : null;
$currentInterrupt   = $isEdit ? (string) ((int) ($task['interruptible'] ?? 0)) : null;
?>

<?php /* Phase B5 (ADR-023, 2026-05-14) — зарегистрированные task-handler'ы (динамически из HandlerRegistry). */ ?>
<?= view('admin/partials/_handler_options_panel', [
    'options' => $taskHandlers ?? [],
    'title'   => 'Зарегистрированные task-handler-классы (HandlerRegistry, ADR-023)',
    'hint'    => 'tasks.name → handler-key → handler-класс. Concrete handlers (marching/gather) обрабатывают свой task.name напрямую; generic_craft и generic_building — диспетчеризуются через CraftRecipes/Buildings config. См. Worker.php $taskHandlerKeyMap.',
    'emoji'   => '🛠️',
]) ?>

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
                <div class="mb-3 col-md-4">
                    <label for="name" class="form-label">Название задачи (английским/слитно)</label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= $isEdit ? esc(old('name', $task['name'] ?? '')) : esc(old('name')) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="name_rus" class="form-label">Название задачи (русским)</label>
                    <input type="text" class="form-control" id="name_rus" name="name_rus"
                           value="<?= $isEdit ? esc(old('name_rus', $task['name_rus'] ?? '')) : esc(old('name_rus')) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="type" class="form-label">Тип задачи</label>
                    <select class="form-select" id="type" name="type">
                        <?= $renderOption('quest',      'Квест',          $currentType) ?>
                        <?= $renderOption('building',   'Строительство',  $currentType) ?>
                        <?= $renderOption('craft',      'Крафт',          $currentType) ?>
                        <?= $renderOption('minutely',   'Ежеминутно',     $currentType) ?>
                        <?= $renderOption('daily',      'Ежедневное',     $currentType) ?>
                        <?= $renderOption('weekly',     'Еженедельное',   $currentType) ?>
                        <?= $renderOption('optionally', 'По желанию',     $currentType) ?>
                    </select>
                </div>
            </div>

            <?php
            /** Phase B6 (ADR-023) — handler_key dropdown. NULL = legacy fallback. */
            $rawEditHk = $isEdit && $task !== null ? ($task['handler_key'] ?? '') : old('handler_key', '');
            $currentHandlerKey = is_string($rawEditHk) ? $rawEditHk : '';
            ?>
            <div class="row g-2">
                <div class="mb-3 col-md-12">
                    <label for="handler_key" class="form-label">Handler (task-handler класс)</label>
                    <select class="form-select" id="handler_key" name="handler_key">
                        <option value=""<?= $currentHandlerKey === '' ? ' selected' : '' ?>>— legacy fallback (NULL) —</option>
                        <?php foreach (($taskHandlers ?? []) as $opt): ?>
                            <?php
                            $optKey = is_string($opt['key'] ?? null) ? (string) $opt['key'] : '';
                            $optDisplay = is_string($opt['displayName'] ?? null) ? (string) $opt['displayName'] : '';
                            ?>
                            <option value="<?= esc($optKey) ?>"<?= $currentHandlerKey === $optKey ? ' selected' : '' ?>>
                                <?= esc($optKey) ?> — <?= esc($optDisplay) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">B6: dispatcher предпочитает handler_key. NULL = старый путь через Worker::$taskHandlerKeyMap[tasks.name].</small>
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="min_duration" class="form-label">Минимальное время выполнения (минуты)</label>
                    <input type="number" class="form-control" id="min_duration" name="min_duration"
                           value="<?= $isEdit ? esc(old('min_duration', $task['min_duration'] ?? '')) : esc(old('min_duration')) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="max_duration" class="form-label">Максимальное время выполнения (минуты)</label>
                    <input type="number" class="form-control" id="max_duration" name="max_duration"
                           value="<?= $isEdit ? esc(old('max_duration', $task['max_duration'] ?? '')) : esc(old('max_duration')) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="difficulty_level" class="form-label">Уровень сложности</label>
                    <input type="number" class="form-control" id="difficulty_level" name="difficulty_level"
                           value="<?= $isEdit ? esc(old('difficulty_level', $task['difficulty_level'] ?? '')) : esc(old('difficulty_level')) ?>" required>
                </div>
            </div>

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="execution_limit" class="form-label">Сколько раз может выполняться задача</label>
                    <input type="number" class="form-control" id="execution_limit" name="execution_limit"
                           value="<?= $isEdit ? esc(old('execution_limit', $task['execution_limit'] ?? 0)) : esc(old('execution_limit', '0')) ?>">
                    <?php if (!$isEdit): ?><small class="text-muted">0 — без ограничений</small><?php endif; ?>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="parallel_execution_allowed" class="form-label">Параллельное выполнение с другими</label>
                    <select class="form-select" id="parallel_execution_allowed" name="parallel_execution_allowed">
                        <?= $renderOption('1', 'Да',  $currentParallel) ?>
                        <?= $renderOption('0', 'Нет', $currentParallel) ?>
                    </select>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="interruptible" class="form-label">Может ли быть прервана игроком</label>
                    <select class="form-select" id="interruptible" name="interruptible">
                        <?= $renderOption('1', 'Да',  $currentInterrupt) ?>
                        <?= $renderOption('0', 'Нет', $currentInterrupt) ?>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= $isEdit ? esc(old('description', $task['description'] ?? '')) : esc(old('description')) ?></textarea>
            </div>

            <button type="submit" class="btn btn-success"><?= esc($submitLabel) ?></button>
        </form>
    </div>
</div>
