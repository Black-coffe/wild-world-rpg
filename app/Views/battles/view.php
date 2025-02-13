<?= $this->extend('layouts/main') ?>

<?= $this->section('title') ?>
Детали боя #<?= esc($battle['id']) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<h1>Бой #<?= esc($battle['id']) ?></h1>
<p>
    <strong>Тип:</strong> <?= esc($battle['battle_type']) ?><br>
    <strong>Начало:</strong> <?= esc($battle['created_at']) ?><br>
    <strong>Окончание:</strong> <?= esc($battle['finished_at']) ?><br>
    <strong>Победитель:</strong>
    <?= ($battle['winner_id'])
        ? '<span class="text-success">ID '.$battle['winner_id'].'</span>'
        : '<span class="text-muted">Ничья</span>' ?>
</p>

<?php
$attacker = $logData['characters']['attacker'] ?? [];
$defender = $logData['characters']['defender'] ?? [];
?>
<div class="mb-4">
    <h2>Участники боя:</h2>
    <ul>
        <li><strong>Attacker:</strong> <?= esc($attacker['name'] ?? '???') ?> (ID <?= esc($attacker['id'] ?? '???') ?>)</li>
        <li><strong>Defender:</strong> <?= esc($defender['name'] ?? '???') ?> (ID <?= esc($defender['id'] ?? '???') ?>)</li>
    </ul>
</div>

<?php if (!empty($logData['rounds'])): ?>
    <h2>Раунды:</h2>
    <div class="accordion" id="roundsAccordion">
        <?php foreach ($logData['rounds'] as $idx => $round): ?>
            <?php $roundNum = $idx + 1; ?>
            <div class="accordion-item mb-2">
                <h2 class="accordion-header" id="heading<?= $roundNum ?>">
                    <button class="accordion-button collapsed" type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#collapse<?= $roundNum ?>"
                            aria-expanded="false" aria-controls="collapse<?= $roundNum ?>">
                        Раунд <?= esc($round['round'] ?? $roundNum) ?>:
                        <?= esc($round['attacker'] ?? '-') ?> → <?= esc($round['defender'] ?? '-') ?>
                    </button>
                </h2>
                <div id="collapse<?= $roundNum ?>" class="accordion-collapse collapse"
                     aria-labelledby="heading<?= $roundNum ?>"
                     data-bs-parent="#roundsAccordion">
                    <div class="accordion-body">
                        <ul>
                            <li>D_equip: <?= esc($round['D_equip'] ?? 0) ?></li>
                            <li>D_level: <?= esc($round['D_level'] ?? 0) ?></li>
                            <li>D_stats: <?= esc($round['D_stats'] ?? 0) ?></li>
                            <li>D_init: <?= esc($round['D_init'] ?? 0) ?></li>
                            <li>dodgeChance: <?= esc($round['dodgeChance'] ?? 0) ?>, dodgeRoll: <?= esc($round['dodgeRoll'] ?? 0) ?></li>
                            <li>critChance: <?= esc($round['critChance'] ?? 0) ?>, critRoll: <?= esc($round['critRoll'] ?? 0) ?></li>
                            <li>biomeCoeff: <?= esc($round['biomeCoeff'] ?? 1) ?></li>
                            <li>oneShotChance: <?= esc($round['oneShotChance'] ?? 0) ?>, oneShotRoll: <?= esc($round['oneShotRoll'] ?? 0) ?></li>
                            <li>luckyStrikeApplied: <?= ($round['luckyStrikeApplied'] ?? false) ? 'Да' : 'Нет' ?></li>
                            <li>damageBoost: <?= esc($round['damageBoost'] ?? 1) ?></li>
                            <li><strong>finalDamage:</strong> <?= esc($round['finalDamage'] ?? 0) ?></li>
                            <li>defenderHealthBefore: <?= esc($round['defenderHealthBefore'] ?? 0) ?></li>
                            <li>defenderHealthAfter: <?= esc($round['defenderHealthAfter'] ?? 0) ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <p>Нет информации о раундах.</p>
<?php endif; ?>

<?php if (!empty($logData['outcome'])): ?>
    <h2>Итог боя:</h2>
    <p>
        Тип окончания: <?= esc($logData['outcome']['type'] ?? 'unknown') ?><br>
        WinnerId: <?= esc($logData['outcome']['winnerId'] ?? '---') ?><br>
        LoserId: <?= esc($logData['outcome']['loserId'] ?? '---') ?><br>
    </p>
<?php endif; ?>
<?= $this->endSection() ?>
