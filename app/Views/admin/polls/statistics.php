<?= $this->extend('templates/admin') ?>

<?= $this->section('content') ?>
<div class="container-fluid">
    <h1 class="mt-4">Статистика опроса</h1>
    <div class="card mb-4">
        <div class="card-body">
            <h4>Вопрос:</h4>
            <p><?= $poll['question'] ?></p>
            <p><strong>Дата создания:</strong> <?= esc($poll['created_at']) ?></p>
            <?php if ($poll['expires_at']): ?>
                <p><strong>Окончание голосования:</strong> <?= esc($poll['expires_at']) ?></p>
            <?php endif; ?>
            <p><strong>Всего голосов:</strong> <?= $totalVotes ?></p>
        </div>
    </div>

    <h3>Распределение голосов</h3>
    <table class="table table-bordered">
        <thead>
        <tr>
            <th>Вариант ответа</th>
            <th>Голосов</th>
            <th>Процент</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($answers as $answer):
            // Находим статистику для данного ответа
            $voteCount = 0;
            foreach ($stats as $stat) {
                if ($stat['answer_text'] === $answer['answer_text']) {
                    $voteCount = (int)$stat['votes'];
                    break;
                }
            }
            $percent = $totalVotes > 0 ? round(($voteCount / $totalVotes) * 100, 2) : 0;
            ?>
            <tr>
                <td><?= esc($answer['answer_text']) ?></td>
                <td><?= $voteCount ?></td>
                <td><?= $percent ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
