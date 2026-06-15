<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Воронка игроков<?= $this->endSection() ?>
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
$e5     = $arr($d['e5'] ?? null);
$onb    = $arr($d['onboarding'] ?? null);
$fac    = $arr($d['faction'] ?? null);
$pctClr = static fn (float $p): string => $p >= 50 ? 'var(--success)' : ($p >= 10 ? 'var(--warning)' : 'var(--danger)');
?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Обзор</p>
        <h1 class="aui-display">Воронка игроков</h1>
        <p class="aui-muted aui-small" style="margin:6px 0 0">
            Активность = движение (explored_cells). Снимок: <?= esc($str($d['generated_at'] ?? '')) ?>.
            Baseline E1 — 2026-06-09 (ROADMAP-100-SESSIONS).
        </p>
    </div>
</div>

<!-- KPI cards -->
<?php
$cards = [
    ['Персонажей', $num($sum['chars_total'] ?? 0), 'ri-team-line', null],
    ['Достижимы (не blocked)', $num($sum['chars_reachable'] ?? 0), 'ri-wifi-line', 'var(--success)'],
    ['Активны 1д', $num($sum['active_1d'] ?? 0), 'ri-walk-line', null],
    ['Активны 7д', $num($sum['active_7d'] ?? 0), 'ri-walk-line', null],
    ['Активны 14д', $num($sum['active_14d'] ?? 0), 'ri-walk-line', null],
    ['Заблокировали бота', $num($sum['tg_blocked'] ?? 0), 'ri-forbid-line', 'var(--danger)'],
];
?>
<div class="aui-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); margin-bottom:var(--sp-4)">
    <?php foreach ($cards as [$label, $val, $icon, $clr]): ?>
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label"><?= esc($label) ?></span><i class="<?= esc($icon) ?> aui-kpi__icon"></i></div>
        <div class="aui-kpi__value"<?= $clr ? ' style="color:' . $clr . '"' : '' ?>><?= esc($val) ?></div>
        <div class="aui-kpi__rule"<?= $clr ? ' style="background:' . $clr . '"' : '' ?>></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Воронки -->
<div class="aui-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); margin-bottom:var(--sp-4)">
    <?php foreach ([['Воронка — всё время', $fAll], ['Воронка — когорта последних 30 дней', $f30]] as [$title, $rows]): ?>
    <div class="aui-card">
        <div class="aui-card__head"><i class="ri-filter-2-line"></i><span class="aui-card__title"><?= esc($title) ?></span></div>
        <div class="aui-tablewrap">
            <table class="aui-table">
                <thead><tr><th>Шаг</th><th class="num">Игроков</th><th class="num">% от рег.</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $s): $s = $arr($s); $p = $pf($s['pct'] ?? null); ?>
                    <tr>
                        <td><?= esc($str($s['label'] ?? '')) ?></td>
                        <td class="num"><?= esc($num($s['count'] ?? 0)) ?></td>
                        <td class="num" style="color:<?= $pctClr($p) ?>"><?= esc((string) $p) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Когорты + уровни/аномалии/квесты -->
<div class="aui-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); margin-bottom:var(--sp-4); align-items:start">
    <div class="aui-card">
        <div class="aui-card__head"><i class="ri-calendar-2-line"></i><span class="aui-card__title">Недельные когорты (8 недель): пришли → шаг → вернулись</span></div>
        <div class="aui-tablewrap">
            <table class="aui-table">
                <thead><tr><th>Неделя</th><th class="num">Рег.</th><th class="num">Шаг</th><th class="num">Верн. D1+</th><th class="num">Верн. D7+</th><th class="num">База</th></tr></thead>
                <tbody>
                <?php foreach ($weekly as $w): $w = $arr($w); ?>
                    <tr>
                        <td><?= esc($str($w['week'] ?? '')) ?></td>
                        <td class="num"><?= esc($num($w['regs'] ?? 0)) ?></td>
                        <td class="num"><?= esc($num($w['moved'] ?? 0)) ?></td>
                        <td class="num"><?= esc($num($w['back_d1'] ?? 0)) ?></td>
                        <td class="num"><?= esc($num($w['back_d7'] ?? 0)) ?></td>
                        <td class="num"><?= esc($num($w['with_base'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="aui-card__body" style="padding-top:0"><p class="aui-faint aui-small" style="margin:0">«Верн. D1+/D7+» — есть движение спустя ≥1/≥7 суток после регистрации. Свежие недели занижены по построению.</p></div>
    </div>

    <div class="aui-stack">
        <div class="aui-card">
            <div class="aui-card__head"><i class="ri-bar-chart-horizontal-line"></i><span class="aui-card__title">Уровни</span></div>
            <div class="aui-tablewrap">
                <table class="aui-table">
                    <thead><tr><th>Корзина</th><th class="num">Игроков</th></tr></thead>
                    <tbody>
                    <?php foreach ($levels as $l): $l = $arr($l); ?>
                        <tr><td><?= esc($str($l['bucket'] ?? '')) ?></td><td class="num"><?= esc($num($l['chars'] ?? 0)) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="aui-card">
            <div class="aui-card__head"><i class="ri-error-warning-line"></i><span class="aui-card__title">Аномалии</span></div>
            <div class="aui-card__body">
                <ul class="aui-small" style="margin:0; padding-left:1.1em; line-height:1.7">
                    <li>Клетка 1 (легаси-респавн-fallback): <b><?= esc($num($anom['cell1_chars'] ?? 0)) ?></b> чаров, из них заблокировали бота: <b><?= esc($num($anom['cell1_blocked'] ?? 0)) ?></b>.</li>
                    <li>«Застрявшие» L1 (созданы &gt;14 дн назад, нет движения 14 дн): <b><?= esc($num($anom['stuck_l1'] ?? 0)) ?></b>.</li>
                </ul>
            </div>
        </div>

        <div class="aui-card">
            <div class="aui-card__head"><i class="ri-flag-line"></i><span class="aui-card__title">Квесты ADR-088 (с активации 2026-06-02)</span></div>
            <div class="aui-tablewrap">
                <table class="aui-table">
                    <thead><tr><th>Бакет</th><th class="num">Стартов</th><th class="num">Завершено</th><th class="num">Игроков</th></tr></thead>
                    <tbody>
                    <?php foreach (['new' => 'NEW (extended)', 'old' => 'OLD (baseline)'] as $k => $label): $q = $arr($quests[$k] ?? null); ?>
                        <tr>
                            <td><?= esc($label) ?></td>
                            <td class="num"><?= esc($num($q['started'] ?? 0)) ?></td>
                            <td class="num"><?= esc($num($q['completed'] ?? 0)) ?></td>
                            <td class="num"><?= esc($num($q['players'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- E4 онбординг -->
<div class="aui-grid aui-grid--2-1" style="margin-bottom:var(--sp-4); align-items:start">
    <div class="aui-card">
        <div class="aui-card__head">
            <i class="ri-footprint-line"></i>
            <span class="aui-card__title">E4 — Онбординг-цепочка «Первые шаги выжившего» (ADR-103)</span>
            <?php $onbOn = (bool) ($onb['enabled'] ?? false); ?>
            <span class="aui-badge <?= $onbOn ? 'aui-badge--success' : '' ?>" style="margin-left:auto">killswitch: <?= $onbOn ? 'ON' : 'OFF (dormant)' ?></span>
        </div>
        <div class="aui-card__body" style="padding-bottom:0">
            <p class="aui-faint aui-small" style="margin:0 0 var(--sp-2)">
                Начали цепочку: <b><?= esc($num($onb['started'] ?? 0)) ?></b> · выпустились (L5): <b><?= esc($num($onb['graduated'] ?? 0)) ?></b>.
                «Дошёл» = шаг назначен; «прошёл» = выполнен. Следующий шаг назначается только после завершения текущего, поэтому «дошёл» убывает каскадно — обрыв виден между «дошёл» и «прошёл».
            </p>
        </div>
        <div class="aui-tablewrap">
            <table class="aui-table">
                <thead><tr><th>#</th><th>Шаг</th><th class="num">Дошёл</th><th class="num">Прошёл</th><th class="num">% прохождения</th></tr></thead>
                <tbody>
                <?php foreach ($arr($onb['steps'] ?? null) as $i => $st): $st = $arr($st); $p = $pf($st['pct'] ?? null); ?>
                    <tr>
                        <td class="aui-faint"><?= (int) $i + 1 ?></td>
                        <td><?= esc($str($st['label'] ?? '')) ?></td>
                        <td class="num"><?= esc($num($st['reached'] ?? 0)) ?></td>
                        <td class="num"><?= esc($num($st['completed'] ?? 0)) ?></td>
                        <td class="num" style="color:<?= $pctClr($p) ?>"><?= esc((string) $p) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="aui-card">
        <div class="aui-card__head"><i class="ri-lightbulb-line"></i><span class="aui-card__title">Слой 1 — контекстные подсказки (adoption)</span></div>
        <?php $hints = $arr($onb['hints'] ?? null); ?>
        <?php if ($hints === []): ?>
            <div class="aui-card__body"><p class="aui-faint aui-small" style="margin:0">Подсказок ещё не показано.</p></div>
        <?php else: ?>
        <div class="aui-tablewrap">
            <table class="aui-table">
                <thead><tr><th>Подсказка / событие</th><th class="num">Чаров</th></tr></thead>
                <tbody>
                <?php foreach ($hints as $h): $h = $arr($h); ?>
                    <tr><td><span class="aui-mono aui-small"><?= esc($str($h['action_name'] ?? '')) ?></span></td><td class="num"><?= esc($num($h['chars'] ?? 0)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- E7 фракция -->
<div class="aui-grid aui-grid--2-1" style="margin-bottom:var(--sp-4); align-items:start">
    <div class="aui-card">
        <div class="aui-card__head"><i class="ri-shield-star-line"></i><span class="aui-card__title">E7 — Конверсия в фракцию (срез ADR-097, цель 3.4%→30%)</span></div>
        <div class="aui-card__body">
            <?php $facAllPct = $pf($fac['conversion_all_pct'] ?? null); $facEligPct = $pf($fac['conversion_eligible_pct'] ?? null); ?>
            <div class="aui-grid" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); text-align:center; margin-bottom:var(--sp-3)">
                <div><div class="aui-num" style="font-size:24px"><?= esc($num($fac['chosen'] ?? 0)) ?></div><small>Выбрали фракцию</small></div>
                <div><div class="aui-num" style="font-size:24px; color:<?= $pctClr($facEligPct) ?>"><?= esc((string) $facEligPct) ?>%</div><small>от достигших L10 (<?= esc($num($fac['l10_eligible'] ?? 0)) ?>) — здоровье диалога</small></div>
                <div><div class="aui-num" style="font-size:24px; color:<?= $pctClr($facAllPct) ?>"><?= esc((string) $facAllPct) ?>%</div><small>от всех чаров (<?= esc($num($fac['total'] ?? 0)) ?>) — цель роадмапа</small></div>
                <div><div class="aui-num" style="font-size:24px"><?= esc($num($fac['unchosen_at_l10'] ?? 0)) ?></div><small>L10+ без фракции</small></div>
            </div>
            <p class="aui-faint aui-small" style="margin:0">
                Выбрали с активации ADR-097 (<?= esc($str($fac['nudge_activation'] ?? '')) ?>): <b><?= esc($num($fac['chosen_since_nudge'] ?? 0)) ?></b>.
                Узкое место — НЕ диалог выбора (eligible-конверсия высокая), а доведение до L10 + ранняя мотивация.
            </p>
        </div>
    </div>
    <div class="aui-card">
        <div class="aui-card__head"><i class="ri-pie-chart-line"></i><span class="aui-card__title">Распределение по фракциям</span></div>
        <?php $byFac = $arr($fac['by_faction'] ?? null); ?>
        <?php if ($byFac === []): ?>
            <div class="aui-card__body"><p class="aui-faint aui-small" style="margin:0">Никто ещё не выбрал фракцию.</p></div>
        <?php else: ?>
        <div class="aui-tablewrap">
            <table class="aui-table">
                <thead><tr><th>Фракция</th><th class="num">Игроков</th></tr></thead>
                <tbody>
                <?php foreach ($byFac as $bf): $bf = $arr($bf); ?>
                    <tr><td><?= esc($str($bf['name'] ?? '')) ?></td><td class="num"><?= esc($num($bf['chars'] ?? 0)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- E5 первые 30 минут -->
<div class="aui-grid aui-grid--2-1" style="align-items:start">
    <div class="aui-card">
        <div class="aui-card__head"><i class="ri-timer-flash-line"></i><span class="aui-card__title">E5 — Первые 30 минут (ADR-104): до vs после активации <?= esc($str($e5['activation'] ?? '')) ?></span></div>
        <?php
        $before = $arr($e5['before'] ?? null);
        $after  = $arr($e5['after'] ?? null);
        $mFmt   = static fn (array $c, string $k): string => is_numeric($c[$k] ?? null) ? (string) $c[$k] : '—';
        $e5Rows = [
            ['Регистраций', 'regs', null],
            ['Сделали хотя бы один шаг', 'moved', 'moved_pct'],
            ['Шаг в первые 30 минут', 'moved_30m', 'moved_30m_pct'],
            ['Медиана минут до первого шага', 'median_first_move_min', null],
            ['Стартовый набор выдан (Ф1)', 'kit', null],
            ['Момент удачи выдан (Ф3b)', 'lucky', null],
            ['Продавали ресурсы (форензик-лог)', 'sellers', null],
            ['Достигли L2+', 'l2plus', null],
        ];
        ?>
        <div class="aui-tablewrap">
            <table class="aui-table">
                <thead><tr><th>Метрика</th><th class="num">До (14 дн)</th><th class="num">После</th></tr></thead>
                <tbody>
                <?php foreach ($e5Rows as [$label, $key, $pctKey]): ?>
                    <tr>
                        <td><?= esc($label) ?></td>
                        <td class="num"><?= esc($mFmt($before, $key)) ?><?php if ($pctKey !== null): ?> <span class="aui-faint">(<?= esc($mFmt($before, $pctKey)) ?>%)</span><?php endif; ?></td>
                        <td class="num strong"><?= esc($mFmt($after, $key)) ?><?php if ($pctKey !== null): ?> <span class="aui-faint">(<?= esc($mFmt($after, $pctKey)) ?>%)</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr>
                        <td>Вернулись D1+ (среди созревших)</td>
                        <td class="num"><?= esc($mFmt($before, 'back_d1')) ?> / <?= esc($mFmt($before, 'd1_eligible')) ?></td>
                        <td class="num strong"><?= esc($mFmt($after, 'back_d1')) ?> / <?= esc($mFmt($after, 'd1_eligible')) ?></td>
                    </tr>
                    <tr>
                        <td>Вернулись D7+ (среди созревших)</td>
                        <td class="num"><?= esc($mFmt($before, 'back_d7')) ?> / <?= esc($mFmt($before, 'd7_eligible')) ?></td>
                        <td class="num strong"><?= esc($mFmt($after, 'back_d7')) ?> / <?= esc($mFmt($after, 'd7_eligible')) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="aui-card__body" style="padding-top:0"><p class="aui-faint aui-small" style="margin:0">
            «До» — когорта 14 дней перед активацией E5. Набор там 0 по построению (грант только новым чарам на /start);
            удача может капнуть и «до»-чарам — она one-shot на первый ход, в т.ч. сделанный после активации.
            D1/D7 — только среди чаров, созревших до порога (созданы ≥1/≥7 дн назад); свежая когорта дозревает.
        </p></div>
    </div>
</div>

<?= $this->endSection() ?>
