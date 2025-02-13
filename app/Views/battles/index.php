<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>
Список последних боёв
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<h1 class="mb-4">Последние бои</h1>
<div class="row">
    <?php foreach ($battles as $battle): ?>
        <div class="col-md-4">
            <div class="card mb-3 shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">ID боя: <?= esc($battle['id']) ?></h5>
                    <p class="card-text">
                        Тип: <strong><?= esc($battle['battle_type']) ?></strong><br>
                        Начало: <?= esc($battle['created_at']) ?><br>
                        Окончание: <?= esc($battle['finished_at']) ?><br>
                        Победитель:
                        <?= $battle['winner_id']
                            ? '<span class="text-success">ID '.$battle['winner_id'].'</span>'
                            : '<span class="text-muted">Ничья</span>' ?>
                    </p>
                    <a href="/battles/view/<?= $battle['id'] ?>" class="btn btn-primary">
                        Подробнее
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>
