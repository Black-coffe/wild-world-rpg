<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Статистика опроса<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Операции · опросы</p>
        <h1 class="aui-display">Статистика опроса</h1>
    </div>
</div>

<div class="aui-card" style="margin-bottom:var(--sp-4)">
    <div class="aui-card__head"><i class="ri-question-line"></i><span class="aui-card__title">Вопрос</span></div>
    <div class="aui-card__body">
        <p style="margin:0 0 var(--sp-3)"><?= $poll['question'] ?></p>
        <div class="aui-row" style="gap:var(--sp-5); flex-wrap:wrap">
            <div><span class="aui-eyebrow">Дата создания</span><div class="strong"><?= esc($poll['created_at']) ?></div></div>
            <?php if ($poll['expires_at']): ?>
                <div><span class="aui-eyebrow">Окончание голосования</span><div class="strong"><?= esc($poll['expires_at']) ?></div></div>
            <?php endif; ?>
            <div><span class="aui-eyebrow">Всего голосов</span><div class="aui-num" style="font-size:18px"><?= $totalVotes ?></div></div>
        </div>
    </div>
</div>

<div class="aui-card">
    <div class="aui-card__head"><i class="ri-bar-chart-2-line"></i><span class="aui-card__title">Распределение голосов</span></div>
    <div class="aui-tablewrap">
    <table class="aui-table">
        <thead><tr><th>Вариант ответа</th><th class="num">Голосов</th><th style="min-width:200px">Процент</th></tr></thead>
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
                <td class="strong"><?= esc($answer['answer_text']) ?></td>
                <td class="num"><?= $voteCount ?></td>
                <td>
                    <div class="aui-row" style="gap:var(--sp-3)">
                        <div class="aui-progress" style="flex:1; max-width:160px"><div class="aui-progress__bar" style="width:<?= $percent ?>%"></div></div>
                        <span class="aui-num aui-small" style="min-width:48px"><?= $percent ?>%</span>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?= $this->endSection() ?>
