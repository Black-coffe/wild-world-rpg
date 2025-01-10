<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Редактировать биом</h2>

<div class="card">
    <div class="card-body">
        <!-- Отображение флеш-сообщения об ошибках -->
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger" role="alert">
                <?= session()->getFlashdata('errors') ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-warning mt-3" role="alert">
            Соотношение слов и цифр:<br>
            <strong>Легко:</strong> 1 и 2 |
            <strong>Умеренно:</strong> 3 и 4 |
            <strong>Среднее:</strong> 5 и 6 |
            <strong>Сложно:</strong> 7 и 8 |
            <strong>Очень сложно:</strong> 9 и 10
        </div>

        <!-- Форма редактирования биома -->
        <form action="<?= site_url('admin/biomes/update/' . $biome['id']) ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <div class="mb-3">
                        <label for="name" class="form-label">Название биома</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= old('name', $biome['name']) ?>" readonly>
                    </div>
                </div>
                <div class="mb-3 col-md-4">
                    <div class="mb-3">
                        <label for="occurrence_rate" class="form-label">Частота встречаемости</label>
                        <input type="text" class="form-control" id="occurrence_rate" name="occurrence_rate" value="<?= old('occurrence_rate', $biome['occurrence_rate']) ?>">
                    </div>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="biome_type" class="form-label">Тип биома</label>
                    <select class="form-select" id="biome_type" name="biome_type">
                        <option value="hot" <?= $biome['biome_type'] == 'hot' ? 'selected' : '' ?>>Жаркий</option>
                        <option value="cold" <?= $biome['biome_type'] == 'cold' ? 'selected' : '' ?>>Холодный</option>
                        <option value="wet" <?= $biome['biome_type'] == 'wet' ? 'selected' : '' ?>>Сырой</option>
                        <option value="dry" <?= $biome['biome_type'] == 'dry' ? 'selected' : '' ?>>Сухой</option>
                        <option value="volcanic" <?= $biome['biome_type'] == 'volcanic' ? 'selected' : '' ?>>Вулканический</option>
                        <option value="cave" <?= $biome['biome_type'] == 'cave' ? 'selected' : '' ?>>Пещерный</option>
                        <option value="jungle" <?= $biome['biome_type'] == 'jungle' ? 'selected' : '' ?>>Джунгли</option>
                        <option value="desert" <?= $biome['biome_type'] == 'desert' ? 'selected' : '' ?>>Пустынный</option>
                        <option value="plain" <?= $biome['biome_type'] == 'plain' ? 'selected' : '' ?>>Равнинный</option>
                    </select>
                </div>

            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="5"><?= old('description', $biome['description']) ?></textarea>
            </div>
            <div class="row g-2">
                <div class="mb-3 col-md-3">
                    <div class="mb-3">
                        <label for="danger_level_text" class="form-label">Уровень опасности</label>
                        <select class="form-select" id="danger_level_text" name="danger_level_text">
                            <option value="Легкий" <?= $biome['danger_level_text'] == 'Легкий' ? 'selected' : '' ?>>Легкий</option>
                            <option value="Умеренный" <?= $biome['danger_level_text'] == 'Умеренный' ? 'selected' : '' ?>>Умеренный</option>
                            <option value="Средний" <?= $biome['danger_level_text'] == 'Средний' ? 'selected' : '' ?>>Средний</option>
                            <option value="Сложный" <?= $biome['danger_level_text'] == 'Сложный' ? 'selected' : '' ?>>Сложный</option>
                            <option value="Очень сложный" <?= $biome['danger_level_text'] == 'Очень сложный' ? 'selected' : '' ?>>Очень сложный</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 col-md-3">
                    <div class="mb-3">
                        <label for="danger_level" class="form-label">Уровень опасности</label>
                        <select class="form-select" id="danger_level" name="danger_level">
                            <option value="1" <?= $biome['danger_level'] == '1' ? 'selected' : '' ?>>1</option>
                            <option value="2" <?= $biome['danger_level'] == '2' ? 'selected' : '' ?>>2</option>
                            <option value="3" <?= $biome['danger_level'] == '3' ? 'selected' : '' ?>>3</option>
                            <option value="4" <?= $biome['danger_level'] == '4' ? 'selected' : '' ?>>4</option>
                            <option value="5" <?= $biome['danger_level'] == '5' ? 'selected' : '' ?>>5</option>
                            <option value="6" <?= $biome['danger_level'] == '6' ? 'selected' : '' ?>>6</option>
                            <option value="7" <?= $biome['danger_level'] == '7' ? 'selected' : '' ?>>7</option>
                            <option value="8" <?= $biome['danger_level'] == '8' ? 'selected' : '' ?>>8</option>
                            <option value="9" <?= $biome['danger_level'] == '9' ? 'selected' : '' ?>>9</option>
                            <option value="10" <?= $biome['danger_level'] == '10' ? 'selected' : '' ?>>10</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 col-md-3">
                    <div class="mb-3">
                        <label for="survival_difficulty_text" class="form-label">Сложность выживания</label>
                        <select class="form-select" id="survival_difficulty_text" name="survival_difficulty_text">
                            <option value="Легко" <?= $biome['survival_difficulty_text'] == 'Легко' ? 'selected' : '' ?>>Легко</option>
                            <option value="Умеренно" <?= $biome['survival_difficulty_text'] == 'Умеренно' ? 'selected' : '' ?>>Умеренно</option>
                            <option value="Среднее" <?= $biome['survival_difficulty_text'] == 'Среднее' ? 'selected' : '' ?>>Среднее</option>
                            <option value="Сложно" <?= $biome['survival_difficulty_text'] == 'Сложно' ? 'selected' : '' ?>>Сложно</option>
                            <option value="Очень сложно" <?= $biome['survival_difficulty_text'] == 'Очень сложно' ? 'selected' : '' ?>>Очень сложно</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3 col-md-3">
                    <div class="mb-3">
                        <label for="survival_difficulty" class="form-label">Сложность выживания</label>
                        <select class="form-select" id="survival_difficulty" name="survival_difficulty">
                            <option value="1" <?= $biome['survival_difficulty'] == '1' ? 'selected' : '' ?>>1</option>
                            <option value="2" <?= $biome['survival_difficulty'] == '2' ? 'selected' : '' ?>>2</option>
                            <option value="3" <?= $biome['survival_difficulty'] == '3' ? 'selected' : '' ?>>3</option>
                            <option value="4" <?= $biome['survival_difficulty'] == '4' ? 'selected' : '' ?>>4</option>
                            <option value="5" <?= $biome['survival_difficulty'] == '5' ? 'selected' : '' ?>>5</option>
                            <option value="6" <?= $biome['survival_difficulty'] == '6' ? 'selected' : '' ?>>6</option>
                            <option value="7" <?= $biome['survival_difficulty'] == '7' ? 'selected' : '' ?>>7</option>
                            <option value="8" <?= $biome['survival_difficulty'] == '8' ? 'selected' : '' ?>>8</option>
                            <option value="9" <?= $biome['survival_difficulty'] == '9' ? 'selected' : '' ?>>9</option>
                            <option value="10" <?= $biome['survival_difficulty'] == '10' ? 'selected' : '' ?>>10</option>
                        </select>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
