<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Редактирование биома<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Настройки игры · контент · биомы</p>
        <h1 class="aui-display">Редактирование: <?= esc($biome['name'] ?? '') ?></h1>
    </div>
    <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/biomes') ?>"><i class="ri-arrow-left-line"></i> К списку</a>
</div>

<div class="aui-card">
    <div class="aui-card__body">
        <!-- Отображение флеш-сообщения об ошибках -->
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="aui-alert aui-alert--danger" style="margin-bottom:var(--sp-4)"><i class="ri-error-warning-line"></i><div>
                <?= session()->getFlashdata('errors') ?>
            </div></div>
        <?php endif; ?>

        <div class="aui-alert aui-alert--info" style="margin-bottom:var(--sp-5)"><i class="ri-information-line"></i><div>
            Соотношение слов и цифр:<br>
            <strong>Легко:</strong> 1 и 2 |
            <strong>Умеренно:</strong> 3 и 4 |
            <strong>Среднее:</strong> 5 и 6 |
            <strong>Сложно:</strong> 7 и 8 |
            <strong>Очень сложно:</strong> 9 и 10
        </div></div>

        <!-- Форма редактирования биома -->
        <form action="<?= site_url('admin/biomes/update/' . $biome['id']) ?>" method="post">
            <?= csrf_field() ?>
            <div class="aui-formgrid">
                <div class="aui-field">
                    <label for="name" class="aui-label">Название биома</label>
                    <input type="text" class="aui-input" id="name" name="name" value="<?= old('name', $biome['name']) ?>" readonly>
                </div>
                <div class="aui-field">
                    <label for="occurrence_rate" class="aui-label">Частота встречаемости</label>
                    <input type="text" class="aui-input" id="occurrence_rate" name="occurrence_rate" value="<?= old('occurrence_rate', $biome['occurrence_rate']) ?>">
                </div>
                <div class="aui-field">
                    <label for="biome_type" class="aui-label">Тип биома</label>
                    <select class="aui-select" id="biome_type" name="biome_type">
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
            <div class="aui-field">
                <label for="description" class="aui-label">Описание</label>
                <textarea class="aui-textarea" id="description" name="description" rows="5"><?= old('description', $biome['description']) ?></textarea>
            </div>
            <div class="aui-formgrid">
                <div class="aui-field">
                    <label for="danger_level_text" class="aui-label">Уровень опасности</label>
                    <select class="aui-select" id="danger_level_text" name="danger_level_text">
                        <option value="Легкий" <?= $biome['danger_level_text'] == 'Легкий' ? 'selected' : '' ?>>Легкий</option>
                        <option value="Умеренный" <?= $biome['danger_level_text'] == 'Умеренный' ? 'selected' : '' ?>>Умеренный</option>
                        <option value="Средний" <?= $biome['danger_level_text'] == 'Средний' ? 'selected' : '' ?>>Средний</option>
                        <option value="Сложный" <?= $biome['danger_level_text'] == 'Сложный' ? 'selected' : '' ?>>Сложный</option>
                        <option value="Очень сложный" <?= $biome['danger_level_text'] == 'Очень сложный' ? 'selected' : '' ?>>Очень сложный</option>
                    </select>
                </div>
                <div class="aui-field">
                    <label for="danger_level" class="aui-label">Уровень опасности</label>
                    <select class="aui-select" id="danger_level" name="danger_level">
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
                <div class="aui-field">
                    <label for="survival_difficulty_text" class="aui-label">Сложность выживания</label>
                    <select class="aui-select" id="survival_difficulty_text" name="survival_difficulty_text">
                        <option value="Легко" <?= $biome['survival_difficulty_text'] == 'Легко' ? 'selected' : '' ?>>Легко</option>
                        <option value="Умеренно" <?= $biome['survival_difficulty_text'] == 'Умеренно' ? 'selected' : '' ?>>Умеренно</option>
                        <option value="Среднее" <?= $biome['survival_difficulty_text'] == 'Среднее' ? 'selected' : '' ?>>Среднее</option>
                        <option value="Сложно" <?= $biome['survival_difficulty_text'] == 'Сложно' ? 'selected' : '' ?>>Сложно</option>
                        <option value="Очень сложно" <?= $biome['survival_difficulty_text'] == 'Очень сложно' ? 'selected' : '' ?>>Очень сложно</option>
                    </select>
                </div>
                <div class="aui-field">
                    <label for="survival_difficulty" class="aui-label">Сложность выживания</label>
                    <select class="aui-select" id="survival_difficulty" name="survival_difficulty">
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
            <div class="aui-form-actions">
                <button type="submit" class="aui-btn aui-btn--primary"><i class="ri-save-line"></i> Сохранить изменения</button>
                <a class="aui-btn aui-btn--ghost" href="<?= site_url('admin/biomes') ?>">Отмена</a>
            </div>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
