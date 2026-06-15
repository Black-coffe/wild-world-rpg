<?= $this->extend('templates/dashboard') ?>

<?= $this->section('content') ?>

<?php
/**
 * Главный admin-дашборд `/admin` (логотип → панель управления).
 * Данные — App\Services\Admin\DashboardAnalyticsService (read-only).
 * Графики — ApexCharts (подключён в layout templates/dashboard).
 *
 * @var array<string,mixed> $d
 */
$arr   = static fn (mixed $v): array  => is_array($v) ? $v : [];
$num   = static fn (mixed $v): int    => is_numeric($v) ? (int) $v : 0;
$nd    = static fn (mixed $v): string => (string) (is_numeric($v) ? (int) $v : 0);
$fnum  = static fn (mixed $v): float  => is_numeric($v) ? (float) $v : 0.0;
$str   = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
$money = static fn (mixed $v): string => number_format(is_numeric($v) ? (float) $v : 0.0, 0, '.', ' ');
$j     = static fn (mixed $v): string => (string) json_encode(
    $v,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

$d        = $arr($d);
$kpi      = $arr($d['kpi'] ?? null);
$growth   = $arr($d['growth'] ?? null);
$levels   = $arr($d['levels'] ?? null);
$biomes   = $arr($d['biomes'] ?? null);
$factions = $arr($d['factions'] ?? null);
$goldB    = $arr($d['gold_buckets'] ?? null);
$battles  = $arr($d['battles'] ?? null);
$ladder   = $arr($d['pvp_ladder'] ?? null);
$economy  = $arr($d['economy'] ?? null);
$crafted  = $arr($d['top_crafted'] ?? null);
$resTop   = $arr($d['top_resources'] ?? null);
$builds   = $arr($d['buildings'] ?? null);
$tasks    = $arr($d['tasks'] ?? null);
$world    = $arr($d['world'] ?? null);
$topP     = $arr($d['top_players'] ?? null);
$topR     = $arr($d['top_rich'] ?? null);
$days     = $num($d['trend_days'] ?? 30);

$col = static fn (array $rows, string $key): array => array_map(
    static fn ($r) => is_array($r) ? ($r[$key] ?? null) : null,
    $rows
);
$ints  = static fn (array $vals): array => array_map(static fn ($v) => is_numeric($v) ? (int) $v : 0, $vals);
$strs  = static fn (array $vals): array => array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $vals);

$charsTotal = $num($kpi['chars_total'] ?? 0);
$reachable  = $num($kpi['reachable'] ?? 0);
$reachPct   = $charsTotal > 0 ? (int) round(100 * $reachable / $charsTotal) : 0;

// KPI hero-карточки: [иконка, подпись, значение, под-подпись, gradient-class]
$kpiCards = [
    ['ri-team-line',         'Персонажей',          $money($kpi['chars_total'] ?? 0),  'всего в мире',                                 'g1'],
    ['ri-base-station-line', 'Достижимы',           $money($reachable),                $reachPct . '% не блокировали бота',            'g2'],
    ['ri-flashlight-line',   'Активны за 24ч',      $money($kpi['active_24h'] ?? 0),   'двигались по карте',                           'g3'],
    ['ri-walk-line',         'Активны за 7 дней',   $money($kpi['active_7d'] ?? 0),    'из них ' . $money($kpi['active_30d'] ?? 0) . ' за 30д', 'g4'],
    ['ri-user-add-line',     'Новых за 7 дней',     $money($kpi['new_7d'] ?? 0),       $money($kpi['new_30d'] ?? 0) . ' за 30 дней',   'g5'],
    ['ri-sword-line',        'Боёв проведено',      $money($kpi['battles_total'] ?? 0),'PvE + PvP суммарно',                           'g6'],
    ['ri-coins-line',        'Золото в экономике',  $money($kpi['gold_total'] ?? 0),   'на руках у игроков',                           'g7'],
    ['ri-home-4-line',       'Активных баз',        $money($kpi['bases_active'] ?? 0), $money($kpi['buildings_total'] ?? 0) . ' построек', 'g8'],
];

// Мини-полоска мирового снимка
$worldStats = [
    ['ri-map-2-line',          'Исследовано клеток',  $money($world['explored_cells'] ?? 0)],
    ['ri-landscape-line',      'Клеток на карте',     $money($world['map_cells'] ?? 0)],
    ['ri-hammer-line',         'Скрафчено предметов', $money($world['crafts_total'] ?? 0)],
    ['ri-exchange-line',       'Сделок на рынке',     $money($world['txns_total'] ?? 0)],
    ['ri-treasure-map-line',   'Объектов в мире',     $money($world['world_objects'] ?? 0)],
    ['ri-flag-line',           'Квестов завершено',   $money($world['quests_completed'] ?? 0)],
    ['ri-fire-line',           'Активных событий',    $money($world['events_active'] ?? 0)],
    ['ri-trophy-line',         'Макс. уровень',       $money($kpi['max_level'] ?? 0)],
];
?>

<style>
    .dash-wrap .page-title-box { padding: 0; }
    .kpi-card {
        border: 0; border-radius: 16px; color: #fff; overflow: hidden;
        position: relative; box-shadow: 0 8px 24px rgba(0,0,0,.12);
        transition: transform .18s ease, box-shadow .18s ease;
    }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 14px 32px rgba(0,0,0,.2); }
    .kpi-card .card-body { padding: 1.1rem 1.2rem; }
    .kpi-card .kpi-icon {
        position: absolute; right: 10px; bottom: -6px; font-size: 4.2rem;
        opacity: .18; line-height: 1;
    }
    .kpi-card .kpi-label { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; opacity: .9; }
    .kpi-card .kpi-value { font-size: 1.85rem; font-weight: 700; line-height: 1.1; margin: .15rem 0; }
    .kpi-card .kpi-sub   { font-size: .78rem; opacity: .85; }
    .kpi-card.g1 { background: linear-gradient(135deg,#6a11cb 0%,#2575fc 100%); }
    .kpi-card.g2 { background: linear-gradient(135deg,#11998e 0%,#38ef7d 100%); }
    .kpi-card.g3 { background: linear-gradient(135deg,#f7971e 0%,#ffd200 100%); color:#3b2f00; }
    .kpi-card.g4 { background: linear-gradient(135deg,#fc466b 0%,#3f5efb 100%); }
    .kpi-card.g5 { background: linear-gradient(135deg,#00b4db 0%,#0083b0 100%); }
    .kpi-card.g6 { background: linear-gradient(135deg,#cb356b 0%,#bd3f32 100%); }
    .kpi-card.g7 { background: linear-gradient(135deg,#f2994a 0%,#f2c94c 100%); color:#3b2f00; }
    .kpi-card.g8 { background: linear-gradient(135deg,#56ab2f 0%,#a8e063 100%); color:#173a00; }
    .kpi-card.g3 .kpi-label, .kpi-card.g3 .kpi-sub,
    .kpi-card.g7 .kpi-label, .kpi-card.g7 .kpi-sub,
    .kpi-card.g8 .kpi-label, .kpi-card.g8 .kpi-sub { opacity: .8; }

    .dash-card { border-radius: 14px; border: 1px solid rgba(0,0,0,.05); }
    .dash-card .card-header { background: transparent; border-bottom: 1px solid rgba(0,0,0,.06); font-weight: 600; }
    .world-strip .ws-item { display:flex; align-items:center; gap:.7rem; padding:.55rem .25rem; }
    .world-strip .ws-item i { font-size:1.5rem; color:#6a11cb; width:1.8rem; text-align:center; }
    .world-strip .ws-v { font-weight:700; font-size:1.05rem; line-height:1; }
    .world-strip .ws-l { font-size:.74rem; color:#8a909d; }
    .lb-rank { width:1.7rem;height:1.7rem;border-radius:50%;display:inline-flex;align-items:center;
               justify-content:center;font-weight:700;font-size:.78rem;background:#eef0f4;color:#5b6577; }
    .lb-rank.r1 { background:#ffd700;color:#5c4600; } .lb-rank.r2 { background:#cfd6e0;color:#3b424d; }
    .lb-rank.r3 { background:#e7b58b;color:#5c3a16; }
</style>

<div class="content dash-wrap">
    <div class="container-fluid">

        <div class="row align-items-center mb-2">
            <div class="col">
                <div class="page-title-box">
                    <h4 class="page-title mb-1"><i class="ri-dashboard-3-line me-1"></i> Пульс игры</h4>
                    <p class="text-muted small mb-0">
                        Живой обзор: игроки · активность · бои · экономика · мир.
                        Тренды — за <?= esc((string) $days) ?> дней. Снимок: <?= esc($str($d['generated_at'] ?? '')) ?>.
                    </p>
                </div>
            </div>
            <div class="col-auto d-flex align-items-center gap-2 flex-wrap">
                <div class="btn-group btn-group-sm" role="group" aria-label="Период тренда">
                    <span class="btn btn-sm btn-light disabled border-0 text-muted px-1"><i class="ri-calendar-line"></i></span>
                    <?php foreach (\App\Services\Admin\DashboardAnalyticsService::ALLOWED_TRENDS as $p): ?>
                        <a href="<?= base_url('dashboard') ?>?period=<?= (int) $p ?>"
                           class="btn btn-sm <?= $p === $days ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= (int) $p ?> дн</a>
                    <?php endforeach; ?>
                </div>
                <a href="<?= base_url('admin/funnel') ?>" class="btn btn-sm btn-outline-primary">
                    <i class="ri-filter-2-line me-1"></i> Воронка новичка</a>
                <a href="<?= base_url('dashboard') ?>?period=<?= (int) $days ?>" class="btn btn-sm btn-light">
                    <i class="ri-refresh-line me-1"></i> Обновить</a>
            </div>
        </div>

        <!-- KPI hero -->
        <div class="row g-3 mb-1">
            <?php foreach ($kpiCards as [$icon, $label, $value, $sub, $g]): ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card kpi-card <?= esc($g) ?> h-100">
                    <div class="card-body">
                        <i class="<?= esc($icon) ?> kpi-icon"></i>
                        <div class="kpi-label"><?= esc($label) ?></div>
                        <div class="kpi-value"><?= esc($value) ?></div>
                        <div class="kpi-sub"><?= esc($sub) ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- World strip -->
        <div class="row mb-1">
            <div class="col-12">
                <div class="card dash-card">
                    <div class="card-body py-2 world-strip">
                        <div class="row">
                            <?php foreach ($worldStats as [$icon, $label, $value]): ?>
                            <div class="col-6 col-md-3 col-xl">
                                <div class="ws-item">
                                    <i class="<?= esc($icon) ?>"></i>
                                    <div>
                                        <div class="ws-v"><?= esc($value) ?></div>
                                        <div class="ws-l"><?= esc($label) ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Рост и уровни -->
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-line-chart-line me-1"></i> Рост и активность (<?= esc((string) $days) ?> дней)</div>
                    <div class="card-body"><div id="chartGrowth" style="min-height:300px;"></div></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-bar-chart-grouped-line me-1"></i> Распределение по уровням</div>
                    <div class="card-body"><div id="chartLevels" style="min-height:300px;"></div></div>
                </div>
            </div>
        </div>

        <!-- Игроки: биомы / фракции / богатство -->
        <div class="row g-3 mt-0">
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-map-pin-line me-1"></i> Игроки по биомам</div>
                    <div class="card-body"><div id="chartBiomes" style="min-height:300px;"></div></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-shield-star-line me-1"></i> Фракции</div>
                    <div class="card-body"><div id="chartFactions" style="min-height:300px;"></div></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-coins-line me-1"></i> Богатство (золото)</div>
                    <div class="card-body"><div id="chartGold" style="min-height:300px;"></div></div>
                </div>
            </div>
        </div>

        <!-- Бои и PvP -->
        <div class="row g-3 mt-0">
            <div class="col-xl-8">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-sword-line me-1"></i> Бои по дням (<?= esc((string) $days) ?> дней)</div>
                    <div class="card-body"><div id="chartBattles" style="min-height:280px;"></div></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-pie-chart-2-line me-1"></i> Типы боёв</div>
                    <div class="card-body"><div id="chartBattleTypes" style="min-height:280px;"></div></div>
                </div>
            </div>
        </div>

        <!-- Экономика и крафт -->
        <div class="row g-3 mt-0">
            <div class="col-xl-8">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-exchange-dollar-line me-1"></i> Рынок: оборот золота (покупки vs продажи)</div>
                    <div class="card-body"><div id="chartEconomy" style="min-height:280px;"></div></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-hammer-line me-1"></i> Топ скрафченного</div>
                    <div class="card-body"><div id="chartCrafted" style="min-height:280px;"></div></div>
                </div>
            </div>
        </div>

        <!-- Мир: постройки / занятость / склад -->
        <div class="row g-3 mt-0">
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-building-2-line me-1"></i> Постройки по типам</div>
                    <div class="card-body"><div id="chartBuildings" style="min-height:280px;"></div></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-loader-2-line me-1"></i> Чем заняты сейчас</div>
                    <div class="card-body"><div id="chartTasks" style="min-height:280px;"></div></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-archive-2-line me-1"></i> Склад рынка (топ запасов)</div>
                    <div class="card-body"><div id="chartResources" style="min-height:280px;"></div></div>
                </div>
            </div>
        </div>

        <!-- Лидерборды -->
        <div class="row g-3 mt-0 mb-3">
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-vip-crown-2-line me-1"></i> Топ по уровню</div>
                    <div class="card-body p-2">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>#</th><th>Игрок</th><th class="text-end">Ур.</th><th class="text-end">Золото</th></tr></thead>
                            <tbody>
                            <?php foreach ($topP as $i => $p): $p = $arr($p); $r = (int) $i + 1; ?>
                                <tr>
                                    <td><span class="lb-rank <?= $r <= 3 ? 'r' . $r : '' ?>"><?= $r ?></span></td>
                                    <td class="text-truncate" style="max-width:130px;"><?= esc($str($p['name'] ?? '')) ?></td>
                                    <td class="text-end fw-bold"><?= esc($nd($p['level'] ?? 0)) ?></td>
                                    <td class="text-end text-muted"><?= esc($money($p['gold'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($topP === []): ?><tr><td colspan="4" class="text-center text-muted py-3">Нет данных</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-money-dollar-circle-line me-1"></i> Богатейшие</div>
                    <div class="card-body p-2">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>#</th><th>Игрок</th><th class="text-end">Золото</th><th class="text-end">Ур.</th></tr></thead>
                            <tbody>
                            <?php foreach ($topR as $i => $p): $p = $arr($p); $r = (int) $i + 1; ?>
                                <tr>
                                    <td><span class="lb-rank <?= $r <= 3 ? 'r' . $r : '' ?>"><?= $r ?></span></td>
                                    <td class="text-truncate" style="max-width:130px;"><?= esc($str($p['name'] ?? '')) ?></td>
                                    <td class="text-end fw-bold"><?= esc($money($p['gold'] ?? 0)) ?></td>
                                    <td class="text-end text-muted"><?= esc($nd($p['level'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($topR === []): ?><tr><td colspan="4" class="text-center text-muted py-3">Нет данных</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card dash-card h-100">
                    <div class="card-header py-2"><i class="ri-boxing-line me-1"></i> PvP-ладдер</div>
                    <div class="card-body p-2">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th>#</th><th>Боец</th><th class="text-end">Очки</th><th class="text-end">W/L</th></tr></thead>
                            <tbody>
                            <?php foreach ($ladder as $i => $p): $p = $arr($p); $r = (int) $i + 1; ?>
                                <tr>
                                    <td><span class="lb-rank <?= $r <= 3 ? 'r' . $r : '' ?>"><?= $r ?></span></td>
                                    <td class="text-truncate" style="max-width:120px;"><?= esc($str($p['name'] ?? '')) ?></td>
                                    <td class="text-end fw-bold"><?= esc($nd($p['points'] ?? 0)) ?></td>
                                    <td class="text-end text-muted"><?= esc($nd($p['wins'] ?? 0)) ?>/<?= esc($nd($p['losses'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($ladder === []): ?><tr><td colspan="4" class="text-center text-muted py-3">Ещё не было PvP-боёв</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php /* ApexCharts с CDN: локальный vendor/ в .gitignore и не деплоится (404 на проде). jsDelivr надёжен и версионно закреплён. */ ?>
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"
        integrity="sha384-KNaFJ+EK516RuHsoycvreec5pD7BkTKJEkjMrVSQWu9KGTl7En4dhIDv7t1DFJ+g"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof ApexCharts === 'undefined') { console.warn('ApexCharts не загружен'); return; }

    var PALETTE = ['#6a11cb','#2575fc','#11998e','#38ef7d','#f7971e','#fc466b','#00b4db','#cb356b','#f2994a','#8e44ad','#16a085','#e67e22'];
    var FONT = 'Nunito, "Segoe UI", sans-serif';

    // ── данные из PHP ──
    var DATA = {
        growth:   <?= $j(['labels' => $strs($arr($growth['labels'] ?? null)), 'regs' => $ints($arr($growth['regs'] ?? null)), 'active' => $ints($arr($growth['active'] ?? null))]) ?>,
        levels:   { labels: <?= $j($strs($col($levels, 'bucket'))) ?>, data: <?= $j($ints($col($levels, 'chars'))) ?> },
        biomes:   { labels: <?= $j($strs($col($biomes, 'name'))) ?>, data: <?= $j($ints($col($biomes, 'chars'))) ?> },
        factions: { labels: <?= $j($strs($col($factions, 'name'))) ?>, data: <?= $j($ints($col($factions, 'chars'))) ?> },
        gold:     { labels: <?= $j($strs($col($goldB, 'bucket'))) ?>, data: <?= $j($ints($col($goldB, 'chars'))) ?> },
        battles:  { labels: <?= $j($strs($battles['labels'] ?? [])) ?>, daily: <?= $j($ints($battles['daily'] ?? [])) ?> },
        bTypes:   { labels: <?= $j($strs($col($arr($battles['by_type'] ?? null), 'type'))) ?>, data: <?= $j($ints($col($arr($battles['by_type'] ?? null), 'count'))) ?> },
        economy:  { labels: <?= $j($strs($economy['labels'] ?? [])) ?>, buy: <?= $j($ints($economy['buy'] ?? [])) ?>, sell: <?= $j($ints($economy['sell'] ?? [])) ?> },
        crafted:  { labels: <?= $j($strs($col($crafted, 'name'))) ?>, data: <?= $j($ints($col($crafted, 'qty'))) ?> },
        resources:{ labels: <?= $j($strs($col($resTop, 'name'))) ?>, data: <?= $j($ints($col($resTop, 'qty'))) ?> },
        builds:   { labels: <?= $j($strs($col($builds, 'type'))) ?>, data: <?= $j($ints($col($builds, 'count'))) ?> },
        tasks:    { labels: <?= $j($strs($col($tasks, 'type'))) ?>, data: <?= $j($ints($col($tasks, 'count'))) ?> }
    };

    var noData = { text: 'Нет данных', style: { color: '#9aa0ac', fontSize: '14px' } };

    function mount(id, opts) {
        var el = document.querySelector(id);
        if (!el) return;
        opts.chart = Object.assign({ fontFamily: FONT, toolbar: { show: false }, animations: { enabled: true } }, opts.chart || {});
        opts.colors = opts.colors || PALETTE;
        opts.noData = noData;
        try { new ApexCharts(el, opts).render(); } catch (e) { console.error('chart ' + id, e); }
    }

    var SPARK_GRID = { borderColor: 'rgba(0,0,0,.06)', strokeDashArray: 4 };

    // 1) Рост и активность — area
    mount('#chartGrowth', {
        chart: { type: 'area', height: 300, stacked: false },
        series: [
            { name: 'Новые персонажи', data: DATA.growth.regs },
            { name: 'Активны (движение)', data: DATA.growth.active }
        ],
        colors: ['#6a11cb', '#11998e'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2.5 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.45, opacityTo: 0.05 } },
        xaxis: { categories: DATA.growth.labels, tickAmount: 10, labels: { rotate: -45, style: { fontSize: '10px' } } },
        grid: SPARK_GRID,
        legend: { position: 'top', horizontalAlign: 'right' }
    });

    // 2) Уровни — bar
    mount('#chartLevels', {
        chart: { type: 'bar', height: 300 },
        series: [{ name: 'Игроков', data: DATA.levels.data }],
        plotOptions: { bar: { borderRadius: 5, distributed: true, columnWidth: '55%' } },
        dataLabels: { enabled: true },
        legend: { show: false },
        xaxis: { categories: DATA.levels.labels },
        grid: SPARK_GRID
    });

    // 3) Биомы — donut
    mount('#chartBiomes', {
        chart: { type: 'donut', height: 300 },
        series: DATA.biomes.data,
        labels: DATA.biomes.labels,
        legend: { position: 'bottom', fontSize: '11px' },
        dataLabels: { enabled: false },
        plotOptions: { pie: { donut: { size: '62%' } } }
    });

    // 4) Фракции — donut
    mount('#chartFactions', {
        chart: { type: 'donut', height: 300 },
        series: DATA.factions.data,
        labels: DATA.factions.labels,
        colors: ['#cb356b', '#11998e', '#2575fc', '#f7971e'],
        legend: { position: 'bottom', fontSize: '11px' },
        plotOptions: { pie: { donut: { size: '62%' } } }
    });

    // 5) Богатство — bar
    mount('#chartGold', {
        chart: { type: 'bar', height: 300 },
        series: [{ name: 'Игроков', data: DATA.gold.data }],
        plotOptions: { bar: { borderRadius: 5, distributed: true, columnWidth: '55%' } },
        dataLabels: { enabled: true },
        legend: { show: false },
        colors: ['#f2994a', '#f2c94c', '#00b4db', '#0083b0', '#11998e', '#38ef7d'],
        xaxis: { categories: DATA.gold.labels, labels: { style: { fontSize: '10px' } } },
        grid: SPARK_GRID
    });

    // 6) Бои по дням — area
    mount('#chartBattles', {
        chart: { type: 'area', height: 280 },
        series: [{ name: 'Боёв', data: DATA.battles.daily }],
        colors: ['#cb356b'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2.5 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.45, opacityTo: 0.05 } },
        xaxis: { categories: DATA.battles.labels, tickAmount: 10, labels: { rotate: -45, style: { fontSize: '10px' } } },
        grid: SPARK_GRID
    });

    // 7) Типы боёв — donut
    mount('#chartBattleTypes', {
        chart: { type: 'donut', height: 280 },
        series: DATA.bTypes.data,
        labels: DATA.bTypes.labels,
        colors: ['#fc466b', '#3f5efb', '#f7971e', '#11998e'],
        legend: { position: 'bottom' },
        plotOptions: { pie: { donut: { size: '62%' } } }
    });

    // 8) Экономика — line
    mount('#chartEconomy', {
        chart: { type: 'line', height: 280 },
        series: [
            { name: 'Покупки', data: DATA.economy.buy },
            { name: 'Продажи', data: DATA.economy.sell }
        ],
        colors: ['#2575fc', '#11998e'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2.5 },
        xaxis: { categories: DATA.economy.labels, tickAmount: 10, labels: { rotate: -45, style: { fontSize: '10px' } } },
        yaxis: { labels: { formatter: function (v) { return Math.abs(v) >= 1000 ? (v / 1000).toFixed(0) + 'K' : v; } } },
        grid: SPARK_GRID,
        legend: { position: 'top', horizontalAlign: 'right' }
    });

    // 9) Топ крафта — horizontal bar
    mount('#chartCrafted', {
        chart: { type: 'bar', height: 280 },
        series: [{ name: 'Скрафчено', data: DATA.crafted.data }],
        plotOptions: { bar: { borderRadius: 4, horizontal: true, barHeight: '65%' } },
        dataLabels: { enabled: false },
        colors: ['#6a11cb'],
        xaxis: { categories: DATA.crafted.labels, labels: { style: { fontSize: '10px' } } },
        grid: SPARK_GRID
    });

    // 10) Постройки — donut
    mount('#chartBuildings', {
        chart: { type: 'donut', height: 280 },
        series: DATA.builds.data,
        labels: DATA.builds.labels,
        legend: { position: 'bottom', fontSize: '11px' },
        plotOptions: { pie: { donut: { size: '60%' } } }
    });

    // 11) Чем заняты — bar
    mount('#chartTasks', {
        chart: { type: 'bar', height: 280 },
        series: [{ name: 'Заданий в работе', data: DATA.tasks.data }],
        plotOptions: { bar: { borderRadius: 4, distributed: true, columnWidth: '55%' } },
        dataLabels: { enabled: true },
        legend: { show: false },
        xaxis: { categories: DATA.tasks.labels, labels: { style: { fontSize: '10px' } } },
        grid: SPARK_GRID
    });

    // 12) Склад рынка — horizontal bar
    mount('#chartResources', {
        chart: { type: 'bar', height: 280 },
        series: [{ name: 'На складе', data: DATA.resources.data }],
        plotOptions: { bar: { borderRadius: 4, horizontal: true, barHeight: '65%' } },
        dataLabels: { enabled: false },
        colors: ['#00b4db'],
        xaxis: { categories: DATA.resources.labels, labels: { style: { fontSize: '10px' } } },
        grid: SPARK_GRID
    });
});
</script>

<?= $this->endSection() ?>
