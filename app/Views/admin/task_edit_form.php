<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать задачу</h2>

<div class="card">
    <div class="card-body">
        <!-- Отображение флеш-сообщения об ошибках -->
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach (session()->getFlashdata('errors') as $error): ?>
                    <?= esc($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Форма редактирования задачи -->
        <form action="<?= site_url('admin/tasks/update/' . $task['id']) ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="name" class="form-label">Название задачи (английским/слитно)</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= old('name', $task['name']) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="name_rus" class="form-label">Название задачи (русским)</label>
                    <input type="text" class="form-control" id="name_rus" name="name_rus" value="<?= old('name_rus', $task['name_rus']) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="type" class="form-label">Тип задачи</label>
                    <select class="form-select" id="type" name="type">
                        <option value="building" <?= $task['type'] == 'building' ? 'selected' : '' ?>>Строительство</option>
                        <option value="quest" <?= $task['type'] == 'quest' ? 'selected' : '' ?>>Квест</option>
                        <option value="craft" <?= $task['type'] == 'craft' ? 'selected' : '' ?>>Крафт</option>
                        <option value="daily" <?= $task['type'] == 'daily' ? 'selected' : '' ?>>Ежедневное</option>
                        <option value="weekly" <?= $task['type'] == 'weekly' ? 'selected' : '' ?>>Еженедельное</option>
                        <option value="optionally" <?= $task['type'] == 'optionally' ? 'selected' : '' ?>>По желанию</option>
                        <!-- Добавьте другие типы по необходимости -->
                    </select>
                </div>
            </div>
            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="min_duration" class="form-label">Минимальное время выполнения (минуты)</label>
                    <input type="number" class="form-control" id="min_duration" name="min_duration" value="<?= old('min_duration', $task['min_duration']) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="max_duration" class="form-label">Максимальное время выполнения (минуты)</label>
                    <input type="number" class="form-control" id="max_duration" name="max_duration" value="<?= old('max_duration', $task['max_duration']) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="difficulty_level" class="form-label">Уровень сложности</label>
                    <input type="number" class="form-control" id="difficulty_level" name="difficulty_level" value="<?= old('difficulty_level', $task['difficulty_level']) ?>" required>
                </div>
            </div>

            <!-- Добавьте поля для выполнения задачи, параллельной выполнимости и прерываемости -->

            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="execution_limit" class="form-label">Сколько раз может выполняться задача</label>
                    <input type="number" class="form-control" id="execution_limit" name="execution_limit" value="<?= old('execution_limit', $task['execution_limit']) ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="parallel_execution_allowed" class="form-label">Может ли задача быть выполнена параллельно с другими</label>
                    <select class="form-select" id="parallel_execution_allowed" name="parallel_execution_allowed">
                        <option value="1" <?= $task['parallel_execution_allowed'] ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !$task['parallel_execution_allowed'] ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="interruptible" class="form-label">Может ли задача быть прервана игроком</label>
                    <select class="form-select" id="interruptible" name="interruptible">
                        <option value="1" <?= $task['interruptible'] ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !$task['interruptible'] ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= old('description', $task['description']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-success">Сохранить изменения</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
