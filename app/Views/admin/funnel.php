<?= $this->extend('templates/dashboard') ?>

<?= $this->section('content') ?>

<?php
/** @var array<string,mixed> $d */
$arr = static fn (mixed $v): array => is_array($v) ? $v : [];
$str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
$num = static function (mixed $v): string {
    return is_numeric($v) ? (string) (int) $v : '0';
};
$pf  = static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0;

$sum    = $arr($d['summary'] ?? null);
$fAll   = $arr($d['funnel_all'] ?? null);
$f30    = $arr($d['funnel_30d'] ?? null);
$levels = $arr($d['levels'] ?? null);
$weekly = $arr($d['weekly'] ?? null);
$anom   = $arr($d['anomalies'] ?? null);
$quests = $arr($d['quests'] ?? null);
$pctCls = static fn (float $p): string => $p >= 50 ? 'text-success' : ($p >= 10 ? 'text-warning' : 'text-danger');
?>

<div class="content">
    <div class="container-fluid">

        <div class="row align-items-center mb-3">
            <div class="col">
                <div class="page-title-box mb-0">
                    <h4 class="page-title mb-1"><i class="ri-filter-2-line me-1"></i> Воронка игроков</h4>
                    <p class="text-muted small mb-0">
                        Активность = движение (explored_cells). Снимок: <?= esc($str($d['generated_at'] ?? '')) ?>.
                        Baseline E1 — 2026-06-09 (ROADMAP-100-SESSIONS).
                    </p>
                </div>
            </div>
        </div>

        <!-- KPI cards -->
        <div class="row">
            <?php
            $cards = [
                ['Персонажей', $num($sum['chars_total'] ?? 0), 'ri-team-line', 'text-primary'],
                ['Достижимы (не blocked)', $num($sum['chars_reachable'] ?? 0), 'ri-wifi-line', 'text-success'],
                ['Активны 1д', $num($sum['active_1d'] ?? 0), 'ri-walk-line', 'text-info'],
                ['Активны 7д', $num($sum['active_7d'] ?? 0), 'ri-walk-line', 'text-info'],
                ['Активны 14д', $num($sum['active_14d'] ?? 0), 'ri-walk-line', 'text-info'],
                ['Заблокировали бота', $num($sum['tg_blocked'] ?? 0), 'ri-forbid-line', 'text-danger'],
            ];
            foreach ($cards as [$label, $val, $icon, $cls]): ?>
            <div class="col-md-4 col-xl-2"><div class="card"><div class="card-body p-2 text-center">
                <i class="<?= esc($icon) ?> fs-3 <?= esc($cls) ?>"></i>
                <h4 class="mt-1 mb-0"><?= esc($val) ?></h4>
                <p class="text-muted small mb-0"><?= esc($label) ?></p>
            </div></div></div>
            <?php endforeach; ?>
        </div>

        <div class="row">
            <?php
            $funnelBlocks = [
                ['Воронка — всё время', $fAll],
                ['Воронка — когорта последних 30 дней', $f30],
            ];
            foreach ($funnelBlocks as [$title, $rows]): ?>
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header py-2"><b><?= esc($title) ?></b></div>
                    <div class="card-body p-2">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>Шаг</th><th class="text-end">Игроков</th><th class="text-end">% от рег.</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $s): $s = $arr($s); $p = $pf($s['pct'] ?? null); ?>
                                <tr>
                                    <td><?= esc($str($s['label'] ?? '')) ?></td>
                                    <td class="text-end"><?= esc($num($s['count'] ?? 0)) ?></td>
                                    <td class="text-end <?= $pctCls($p) ?>"><?= esc((string) $p) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="row">
            <!-- Weekly cohorts -->
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header py-2"><b>Недельные когорты (8 недель): пришли → шаг → вернулись</b></div>
                    <div class="card-body p-2">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>Неделя</th><th class="text-end">Рег.</th><th class="text-end">Шаг</th>
                                <th class="text-end">Верн. D1+</th><th class="text-end">Верн. D7+</th><th class="text-end">База</th></tr></thead>
                            <tbody>
                            <?php foreach ($weekly as $w): $w = $arr($w); ?>
                                <tr>
                                    <td><?= esc($str($w['week'] ?? '')) ?></td>
                                    <td class="text-end"><?= esc($num($w['regs'] ?? 0)) ?></td>
                                    <td class="text-end"><?= esc($num($w['moved'] ?? 0)) ?></td>
                                    <td class="text-end"><?= esc($num($w['back_d1'] ?? 0)) ?></td>
                                    <td class="text-end"><?= esc($num($w['back_d7'] ?? 0)) ?></td>
                                    <td class="text-end"><?= esc($num($w['with_base'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="text-muted small mb-0 mt-1">«Верн. D1+/D7+» — есть движение спустя ≥1/≥7 суток после регистрации. Свежие недели занижены по построению.</p>
                    </div>
                </div>
            </div>

            <!-- Levels + anomalies + quests -->
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header py-2"><b>Уровни</b></div>
                    <div class="card-body p-2">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>Корзина</th><th class="text-end">Игроков</th></tr></thead>
                            <tbody>
                            <?php foreach ($levels as $l): $l = $arr($l); ?>
                                <tr><td><?= esc($str($l['bucket'] ?? '')) ?></td><td class="text-end"><?= esc($num($l['chars'] ?? 0)) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header py-2"><b>Аномалии</b></div>
                    <div class="card-body p-2">
                        <ul class="mb-0 small">
                            <li>Клетка 1 (легаси-респавн-fallback): <b><?= esc($num($anom['cell1_chars'] ?? 0)) ?></b> чаров,
                                из них заблокировали бота: <b><?= esc($num($anom['cell1_blocked'] ?? 0)) ?></b>.</li>
                            <li>«Застрявшие» L1 (созданы &gt;14 дн назад, нет движения 14 дн): <b><?= esc($num($anom['stuck_l1'] ?? 0)) ?></b>.</li>
                        </ul>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header py-2"><b>Квесты ADR-088 (с активации 2026-06-02)</b></div>
                    <div class="card-body p-2">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>Бакет</th><th class="text-end">Стартов</th><th class="text-end">Завершено</th><th class="text-end">Игроков</th></tr></thead>
                            <tbody>
                            <?php foreach (['new' => 'NEW (extended)', 'old' => 'OLD (baseline)'] as $k => $label):
                                $q = $arr($quests[$k] ?? null); ?>
                                <tr>
                                    <td><?= esc($label) ?></td>
                                    <td class="text-end"><?= esc($num($q['started'] ?? 0)) ?></td>
                                    <td class="text-end"><?= esc($num($q['completed'] ?? 0)) ?></td>
                                    <td class="text-end"><?= esc($num($q['players'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?= $this->endSection() ?>
