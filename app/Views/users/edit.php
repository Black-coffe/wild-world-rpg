<?= $this->extend('templates/admin') ?>

<?= $this->section('content') ?>

<div class="container edit-driver">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <h2><?=$title;?></h2>
            <hr>
            <?php $validation = $validation ?? service('validation');

            ?>
            <form action="<?= site_url('/users/' . $user->id) ?>" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <div class="mb-3 form-group">
                    <div class="row">
                        <div class="col-md-4">
                            <label for="name" class="form-label">Имя:</label>
                            <input type="text" class="form-control" name="name" id="name" value="<?= isset($user->name) ? $user->name : '' ?>">
                            <?php if (isset($validation) && is_array($validation) && array_key_exists('name', $validation)): ?>
                                <div class="alert alert-danger mt-2"><?= $validation['name'] ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label for="is_admin" class="form-label">Администратор:</label>
                            <select class="form-control" name="is_admin" id="is_admin">
                                <option value="yes" <?= isset($user->is_admin) && $user->is_admin === '1' ? 'selected' : '' ?>>Да</option>
                                <option value="no" <?= isset($user->is_admin) && $user->is_admin === '0' ? 'selected' : '' ?>>Нет</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="photo_url" class="form-label">Фото администратора (аватар):</label>
                            <input type="file" class="form-control" name="photo_url" id="photo_url">
                            <?php if (isset($validation) && is_array($validation) && array_key_exists('photo_url', $validation)): ?>
                                <div class="alert alert-danger mt-2"><?= $validation['photo_url'] ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Отправить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
