<?= $this->extend('admin/layouts/default') ?>

<?= $this->section('content') ?>

<h2>Создать новый ресурс</h2>

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
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            <strong>Важно - </strong> Редкость ресурса, от 1 до 10 (10 это наиболее частый, 1 наиболее меньший).<br>
            Поле <strong>цена</strong> формируется так: 11 - редкость = цена!<br>
            Поле <strong>Для торговца</strong> формируется так: 1000 * редкость = Для торговца!<br>
            Поле <strong>Требуемый уровень</strong> формируется так: Редкость (8, 9, 10) = 1; (5, 6, 7) = 2; (4, 3) = 3; (2) = 5; (1) = 10<br>
        </div>
        <!-- Форма создания ресурса -->
        <form action="<?= site_url('admin/resources/store') ?>" method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="name" class="form-label">Название ресурса</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= old('name') ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="name_en" class="form-label">Название ресурса английское</label>
                    <input type="text" class="form-control" id="name_en" name="name_en" value="<?= old('name_en') ?>" required>
                </div>
                <div class="mb-3 col-md-4">
                    <label for="icon_text" class="form-label">Иконка (Эмоджи) символьная</label>
                    <input type="text" class="form-control" id="icon_text" name="icon_text" value="<?= old('icon_text') ?>">
                </div>
            </div>
            <div class="row g-2">
                <div class="mb-3 col-md-4">
                    <label for="keyword" class="form-label">Дополнительный кейворд</label>
                    <input type="text" class="form-control" id="keyword" name="keyword" value="<?= old('keyword') ?>">
                </div>
                <div class="mb-3 col-md-4 form-check">
                    <label class="form-label">Тип</label>
                    <div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="type_crafting" name="type[]" value="crafting" <?= in_array('crafting', old('type', [])) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="type_crafting">Крафтовые</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="type_food" name="type[]" value="food" <?= in_array('food', old('type', [])) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="type_food">Еда</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="type_water" name="type[]" value="water" <?= in_array('water', old('type', [])) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="type_water">Вода</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="type_material" name="type[]" value="material" <?= in_array('material', old('type', [])) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="type_material">Материалы</label>
                        </div>
                        <!-- Добавьте другие типы по необходимости -->
                    </div>
                </div>

                <div class="mb-3 col-md-3">
                    <label for="biome_id" class="form-label">Принадлежность к биому</label>
                    <select class="form-select" id="biome_id" name="biome_id[]" multiple>
                        <option value="">Не привязан к биому</option>
                        <!-- Допустим, у вас есть переменная $biomes, содержащая список биомов -->
                        <?php foreach ($biomes as $biome): ?>
                            <option value="<?= esc($biome['id']) ?>" <?= in_array($biome['id'], old('biome_id', [])) ? 'selected' : '' ?>><?= esc($biome['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
            <div class="row g-2">
                <div class="mb-3 col-md-3">
                    <label for="price" class="form-label">Цена</label>
                    <input type="number" class="form-control" id="price" name="price" value="<?= old('price') ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="rarity" class="form-label">Редкость</label>
                    <input type="number" class="form-control" id="rarity" name="rarity" min="0" max="10" value="<?= old('rarity') ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="initial_quantity" class="form-label">Для торговца</label>
                    <input type="number" class="form-control" id="initial_quantity" name="initial_quantity" min="1" max="100000000" value="<?= old('initial_quantity') ?>" required>
                </div>
                <div class="mb-3 col-md-2">
                    <label for="level_required" class="form-label">Требуемый уровень</label>
                    <input type="number" class="form-control" id="level_required" name="level_required" value="<?= old('level_required') ?>" required>
                </div>
                <div class="mb-3 col-md-3">
                    <label for="is_tradeable" class="form-label">Возможность торговли</label>
                    <select class="form-select" id="is_tradeable" name="is_tradeable" required>
                        <option value="1">Да</option>
                        <option value="0">Нет</option>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Описание</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?= old('description') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Создать ресурс</button>
        </form>
    </div>
</div>

<?= $this->endSection() ?>
