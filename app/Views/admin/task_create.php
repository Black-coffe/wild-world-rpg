<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новую задачу</h2>

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

        <!-- Форма создания задачи -->
        <form action="<?= site_url('admin/tasks/store') ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="name" class="form-label">Название задачи (английским/слитно)</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= old('name') ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="name_rus" class="form-label">Название задачи (русским)</label>
                    <input type="text" class="form-control" id="name_rus" name="name_rus" value="<?= old('name_rus') ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="type" class="form-label">Тип задачи</label>
                    <select class="form-select" id="type" name="type">
                        <option value="quest">Квест</option>
                        <option value="building">Строительство</option>
                        <option value="craft">Крафт</option>
                        <option value="minutely">Ежеминутно</option>
                        <option value="daily">Ежедневное</option>
                        <option value="weekly">Еженедельное</option>
                        <option value="optionally">По желанию</option>
                    </select>
                </div>
            </div>
            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="min_duration" class="form-label">Минимальное время выполнения (минуты)</label>
                    <input type="number" class="form-control" id="min_duration" name="min_duration" value="<?= old('min_duration') ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="max_duration" class="form-label">Максимальное время выполнения (минуты)</label>
                    <input type="number" class="form-control" id="max_duration" name="max_duration" value="<?= old('max_duration') ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="difficulty_level" class="form-label">Уровень сложности</label>
                    <input type="number" class="form-control" id="difficulty_level" name="difficulty_level" value="<?= old('difficulty_level') ?>" required>
                </div>
            </div>
            <div class="row g-2">
                <div class="mb-3 col-md-6">
                    <label for="execution_limit" class="form-label">Сколько раз может игрок выполнять эту задачу</label>
                    <input type="number" class="form-control" id="execution_limit" name="execution_limit" value="<?= old('execution_limit', 0) ?>">
                    <small class="text-muted">0 - без ограничений</small>
                </div>
                <div class="mb-3 col-md-6">
                    <label for="parallel_execution_allowed" class="form-label">Может ли задача быть выполнена параллельно с другими</label>
                    <select class="form-select" id="parallel_execution_allowed" name="parallel_execution_allowed">
                        <option value="1">Да</option>
                        <option value="0">Нет</option>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label for="interruptible" class="form-label">Может ли задача быть прервана игроком</label>
                <select class="form-select" id="interruptible" name="interruptible">
                    <option value="1">Да</option>
                    <option value="0">Нет</option>
                </select>
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= old('description') ?></textarea>
            </div>
            <button type="submit" class="btn btn-success">Создать задачу</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
