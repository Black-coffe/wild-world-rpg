<?= $this->extend('admin/layouts/aui') ?>

<?= $this->section('pageTitle') ?>Панель управления<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * Главный admin-дашборд `/dashboard` в дизайн-системе «Quiet Premium» (ADR-128).
 * Данные — App\Services\Admin\DashboardAnalyticsService (read-only). Графики — ApexCharts.
 * Презентация переписана с Hyper/градиентов на admin-ui.css; data-плумбинг сохранён.
 *
 * @var array<string,mixed> $d
 */
$arr   = static fn (mixed $v): array  => is_array($v) ? $v : [];
$num   = static fn (mixed $v): int    => is_numeric($v) ? (int) $v : 0;
$nd    = static fn (mixed $v): string => (string) (is_numeric($v) ? (int) $v : 0);
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
$days        = $num($d['trend_days'] ?? 30);
$trendStart  = $str($d['trend_start'] ?? '');
$trendEnd    = $str($d['trend_end'] ?? '');
$trendCustom = (bool) ($d['trend_custom'] ?? false);
$trendLabel  = $str($d['trend_label'] ?? ('за ' . $days . ' дн.'));
$today       = date('Y-m-d');
$winQs = $trendCustom
    ? ('from=' . rawurlencode($trendStart) . '&to=' . rawurlencode($trendEnd))
    : ('period=' . $days);

$col   = static fn (array $rows, string $key): array => array_map(static fn ($r) => is_array($r) ? ($r[$key] ?? null) : null, $rows);
$ints  = static fn (array $vals): array => array_map(static fn ($v) => is_numeric($v) ? (int) $v : 0, $vals);
$strs  = static fn (array $vals): array => array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $vals);

$charsTotal = $num($kpi['chars_total'] ?? 0);
$reachable  = $num($kpi['reachable'] ?? 0);
$reachPct   = $charsTotal > 0 ? (int) round(100 * $reachable / $charsTotal) : 0;

// KPI: [иконка, подпись, значение, под-подпись]
$kpiCards = [
    ['ri-team-line',         'Персонажей',         $money($kpi['chars_total'] ?? 0),  'всего в мире'],
    ['ri-base-station-line', 'Достижимы',          $money($reachable),                $reachPct . '% не блокировали бота'],
    ['ri-flashlight-line',   'Активны за 24ч',     $money($kpi['active_24h'] ?? 0),    'двигались по карте'],
    ['ri-walk-line',         'Активны за 7 дней',  $money($kpi['active_7d'] ?? 0),     'из них ' . $money($kpi['active_30d'] ?? 0) . ' за 30д'],
    ['ri-user-add-line',     'Новых за 7 дней',    $money($kpi['new_7d'] ?? 0),        $money($kpi['new_30d'] ?? 0) . ' за 30 дней'],
    ['ri-sword-line',        'Боёв проведено',     $money($kpi['battles_total'] ?? 0), 'PvE + PvP суммарно'],
    ['ri-coins-line',        'Золото в экономике', $money($kpi['gold_total'] ?? 0),    'на руках у игроков'],
    ['ri-home-4-line',       'Активных баз',       $money($kpi['bases_active'] ?? 0),  $money($kpi['buildings_total'] ?? 0) . ' построек'],
];

$worldStats = [
    ['ri-map-2-line',        'Исследовано клеток', $money($world['explored_cells'] ?? 0)],
    ['ri-landscape-line',    'Клеток на карте',    $money($world['map_cells'] ?? 0)],
    ['ri-hammer-line',       'Скрафчено',          $money($world['crafts_total'] ?? 0)],
    ['ri-exchange-line',     'Сделок',             $money($world['txns_total'] ?? 0)],
    ['ri-treasure-map-line', 'Объектов',           $money($world['world_objects'] ?? 0)],
    ['ri-flag-line',         'Квестов завершено',  $money($world['quests_completed'] ?? 0)],
    ['ri-fire-line',         'Активных событий',   $money($world['events_active'] ?? 0)],
    ['ri-trophy-line',       'Макс. уровень',      $money($kpi['max_level'] ?? 0)],
];
?>

<div class="aui-page-head">
    <div class="aui-page-head__title">
        <p class="aui-eyebrow">Обзор · живой снимок</p>
        <h1 class="aui-display">Пульс игры</h1>
        <p class="aui-muted aui-small" style="margin:6px 0 0">
            Игроки · активность · бои · экономика · мир &nbsp;·&nbsp; тренды <?= esc($trendLabel) ?>
            <?php if (($g = $str($d['generated_at'] ?? '')) !== ''): ?> &nbsp;·&nbsp; снимок <?= esc($g) ?><?php endif; ?>
        </p>
    </div>
    <div class="aui-toolbar">
        <div class="aui-pills">
            <?php foreach (\App\Services\Admin\DashboardAnalyticsService::ALLOWED_TRENDS as $p): ?>
                <a class="aui-pill<?= (! $trendCustom && (int) $p === $days) ? ' is-active' : '' ?>" href="<?= base_url('dashboard') ?>?period=<?= (int) $p ?>"><?= (int) $p ?> дн</a>
            <?php endforeach; ?>
        </div>
        <form method="get" action="<?= base_url('dashboard') ?>" class="aui-row" style="gap:6px" role="search" aria-label="Произвольный диапазон">
            <input type="date" name="from" value="<?= esc($trendStart) ?>" max="<?= esc($today) ?>" class="aui-input aui-btn--sm" style="width:auto;padding:6px 9px" aria-label="С даты">
            <span class="aui-faint">–</span>
            <input type="date" name="to" value="<?= esc($trendEnd) ?>" max="<?= esc($today) ?>" class="aui-input aui-btn--sm" style="width:auto;padding:6px 9px" aria-label="По дату">
            <button type="submit" class="aui-btn <?= $trendCustom ? 'aui-btn--primary' : 'aui-btn--ghost' ?> aui-btn--sm" title="Применить диапазон"><i class="ri-calendar-check-line"></i></button>
        </form>
        <a class="aui-btn aui-btn--ghost aui-btn--sm" href="<?= base_url('admin/funnel') ?>"><i class="ri-filter-2-line"></i> Воронка</a>
        <a class="aui-btn aui-btn--ghost aui-btn--sm" href="<?= base_url('dashboard/export') ?>?<?= esc($winQs) ?>"><i class="ri-file-excel-2-line"></i> Экспорт</a>
        <a class="aui-btn aui-btn--subtle aui-btn--sm" href="<?= base_url('dashboard') ?>?<?= esc($winQs) ?>"><i class="ri-refresh-line"></i> Обновить</a>
    </div>
</div>

<!-- KPI -->
<div class="aui-grid aui-grid--kpi" style="margin-bottom:var(--sp-4)">
    <?php foreach ($kpiCards as [$icon, $label, $value, $sub]): ?>
    <div class="aui-kpi aui-rise">
        <div class="aui-kpi__top"><span class="aui-kpi__label"><?= esc($label) ?></span><i class="<?= esc($icon) ?> aui-kpi__icon"></i></div>
        <div class="aui-kpi__value"><?= esc($value) ?></div>
        <div class="aui-kpi__rule"></div>
        <div class="aui-kpi__sub"><?= esc($sub) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- World strip -->
<div class="aui-card aui-card--flat" style="margin-bottom:var(--sp-4); overflow:hidden">
    <div class="aui-strip">
        <?php foreach ($worldStats as [$icon, $label, $value]): ?>
        <div class="aui-strip__item">
            <i class="<?= esc($icon) ?>"></i>
            <div><div class="aui-strip__v"><?= esc($value) ?></div><div class="aui-strip__l"><?= esc($label) ?></div></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Рост + уровни -->
<div class="aui-grid aui-grid--2-1" style="margin-bottom:var(--sp-4)">
    <div class="aui-card"><div class="aui-card__head"><i class="ri-line-chart-line"></i><span class="aui-card__title">Рост и активность (<?= esc((string) $days) ?> дней)</span></div><div class="aui-card__body"><div id="chartGrowth" style="min-height:300px"></div></div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-bar-chart-grouped-line"></i><span class="aui-card__title">Игроки по уровням</span></div><div class="aui-card__body"><div id="chartLevels" style="min-height:300px"></div></div></div>
</div>

<!-- Биомы / фракции / золото -->
<div class="aui-grid aui-grid--3" style="margin-bottom:var(--sp-4)">
    <div class="aui-card"><div class="aui-card__head"><i class="ri-map-pin-line"></i><span class="aui-card__title">Игроки по биомам</span></div><div class="aui-card__body"><div id="chartBiomes" style="min-height:290px"></div></div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-shield-star-line"></i><span class="aui-card__title">Фракции</span></div><div class="aui-card__body"><div id="chartFactions" style="min-height:290px"></div></div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-coins-line"></i><span class="aui-card__title">Богатство (золото)</span></div><div class="aui-card__body"><div id="chartGold" style="min-height:290px"></div></div></div>
</div>

<!-- Бои -->
<div class="aui-grid aui-grid--2-1" style="margin-bottom:var(--sp-4)">
    <div class="aui-card"><div class="aui-card__head"><i class="ri-sword-line"></i><span class="aui-card__title">Бои по дням (<?= esc((string) $days) ?> дней)</span></div><div class="aui-card__body"><div id="chartBattles" style="min-height:280px"></div></div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-pie-chart-2-line"></i><span class="aui-card__title">Типы боёв</span></div><div class="aui-card__body"><div id="chartBattleTypes" style="min-height:280px"></div></div></div>
</div>

<!-- Экономика + крафт -->
<div class="aui-grid aui-grid--2-1" style="margin-bottom:var(--sp-4)">
    <div class="aui-card"><div class="aui-card__head"><i class="ri-exchange-dollar-line"></i><span class="aui-card__title">Рынок: оборот золота (покупки vs продажи)</span></div><div class="aui-card__body"><div id="chartEconomy" style="min-height:280px"></div></div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-hammer-line"></i><span class="aui-card__title">Топ скрафченного</span></div><div class="aui-card__body"><div id="chartCrafted" style="min-height:280px"></div></div></div>
</div>

<!-- Постройки / занятость / склад -->
<div class="aui-grid aui-grid--3" style="margin-bottom:var(--sp-4)">
    <div class="aui-card"><div class="aui-card__head"><i class="ri-building-2-line"></i><span class="aui-card__title">Постройки по типам</span></div><div class="aui-card__body"><div id="chartBuildings" style="min-height:280px"></div></div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-loader-2-line"></i><span class="aui-card__title">Чем заняты сейчас</span></div><div class="aui-card__body"><div id="chartTasks" style="min-height:280px"></div></div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-archive-2-line"></i><span class="aui-card__title">Склад рынка (топ запасов)</span></div><div class="aui-card__body"><div id="chartResources" style="min-height:280px"></div></div></div>
</div>

<!-- Лидерборды -->
<div class="aui-grid aui-grid--3">
    <div class="aui-card"><div class="aui-card__head"><i class="ri-vip-crown-2-line"></i><span class="aui-card__title">Топ по уровню</span></div><div class="aui-card__body aui-card__body--tight">
        <table class="aui-table"><thead><tr><th>#</th><th>Игрок</th><th class="num">Ур.</th><th class="num">Золото</th></tr></thead><tbody>
        <?php foreach ($topP as $i => $p): $p = $arr($p); $r = (int) $i + 1; ?>
            <tr><td><span class="aui-rank <?= $r <= 3 ? 'r' . $r : '' ?>"><?= $r ?></span></td><td class="strong text-truncate"><?= esc($str($p['name'] ?? '')) ?></td><td class="num strong"><?= esc($nd($p['level'] ?? 0)) ?></td><td class="num"><?= esc($money($p['gold'] ?? 0)) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($topP === []): ?><tr><td colspan="4" class="aui-table__empty">Нет данных</td></tr><?php endif; ?>
        </tbody></table>
    </div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-money-dollar-circle-line"></i><span class="aui-card__title">Богатейшие</span></div><div class="aui-card__body aui-card__body--tight">
        <table class="aui-table"><thead><tr><th>#</th><th>Игрок</th><th class="num">Золото</th><th class="num">Ур.</th></tr></thead><tbody>
        <?php foreach ($topR as $i => $p): $p = $arr($p); $r = (int) $i + 1; ?>
            <tr><td><span class="aui-rank <?= $r <= 3 ? 'r' . $r : '' ?>"><?= $r ?></span></td><td class="strong text-truncate"><?= esc($str($p['name'] ?? '')) ?></td><td class="num strong"><?= esc($money($p['gold'] ?? 0)) ?></td><td class="num"><?= esc($nd($p['level'] ?? 0)) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($topR === []): ?><tr><td colspan="4" class="aui-table__empty">Нет данных</td></tr><?php endif; ?>
        </tbody></table>
    </div></div>
    <div class="aui-card"><div class="aui-card__head"><i class="ri-boxing-line"></i><span class="aui-card__title">PvP-ладдер</span></div><div class="aui-card__body aui-card__body--tight">
        <table class="aui-table"><thead><tr><th>#</th><th>Боец</th><th class="num">Очки</th><th class="num">W/L</th></tr></thead><tbody>
        <?php foreach ($ladder as $i => $p): $p = $arr($p); $r = (int) $i + 1; ?>
            <tr><td><span class="aui-rank <?= $r <= 3 ? 'r' . $r : '' ?>"><?= $r ?></span></td><td class="strong text-truncate"><?= esc($str($p['name'] ?? '')) ?></td><td class="num strong"><?= esc($nd($p['points'] ?? 0)) ?></td><td class="num"><?= esc($nd($p['wins'] ?? 0)) ?>/<?= esc($nd($p['losses'] ?? 0)) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($ladder === []): ?><tr><td colspan="4" class="aui-table__empty">Ещё не было PvP-боёв</td></tr><?php endif; ?>
        </tbody></table>
    </div></div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"
        integrity="sha384-KNaFJ+EK516RuHsoycvreec5pD7BkTKJEkjMrVSQWu9KGTl7En4dhIDv7t1DFJ+g"
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof ApexCharts === 'undefined') { console.warn('ApexCharts не загружен'); return; }
    var root = document.documentElement;
    function cssvar(n){ return getComputedStyle(root).getPropertyValue(n).trim(); }
    var V = [1,2,3,4,5,6,7,8].map(function(i){ return cssvar('--viz-'+i); });
    var ACCENT = cssvar('--accent'), INK = cssvar('--viz-2'), GRID = cssvar('--viz-grid'),
        INK3 = cssvar('--ink-3'), INK4 = cssvar('--ink-4'), SURFACE = cssvar('--surface');

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

    function base(extra){
        extra = extra || {};
        var baseChart = { fontFamily:'Hanken Grotesk, sans-serif', foreColor: INK3, toolbar:{show:false}, zoom:{enabled:false}, animations:{enabled:true, speed:500}, parentHeightOffset:0 };
        var merged = Object.assign({
            grid:{ borderColor: GRID, strokeDashArray:3, padding:{left:4,right:4} },
            dataLabels:{ enabled:false },
            tooltip:{ theme:'light' },
            legend:{ fontSize:'12px', markers:{width:8,height:8,radius:8}, itemMargin:{horizontal:8, vertical:0}, offsetY:2 },
            noData:{ text:'Нет данных', style:{ color: INK4, fontSize:'13px' } }
        }, extra);
        merged.chart = Object.assign(baseChart, extra.chart || {});
        return merged;
    }
    function mount(id, opts){ var el=document.querySelector(id); if(!el) return; try { new ApexCharts(el, base(opts)).render(); } catch(e){ console.error('chart '+id, e); } }
    var areaFill = { type:'gradient', gradient:{ shadeIntensity:1, opacityFrom:0.22, opacityTo:0.02, stops:[0,95] } };
    var noax = { axisBorder:{show:false}, axisTicks:{show:false} };

    mount('#chartGrowth', { chart:{type:'area',height:300}, colors:[ACCENT, INK],
        series:[{name:'Новые персонажи',data:DATA.growth.regs},{name:'Активны (движение)',data:DATA.growth.active}],
        stroke:{curve:'smooth',width:2}, fill:areaFill,
        xaxis:Object.assign({categories:DATA.growth.labels, tickAmount:10, labels:{rotate:-45,style:{fontSize:'10px'}}}, noax),
        legend:{position:'top',horizontalAlign:'right'} });

    mount('#chartLevels', { chart:{type:'bar',height:300}, colors:[ACCENT],
        series:[{name:'Игроков',data:DATA.levels.data}],
        plotOptions:{bar:{borderRadius:4, columnWidth:'52%', borderRadiusApplication:'end'}},
        xaxis:Object.assign({categories:DATA.levels.labels}, noax), legend:{show:false} });

    function donut(sz){ return { plotOptions:{pie:{donut:{size:sz||'68%'}}}, stroke:{width:2, colors:[SURFACE]}, legend:{position:'bottom', fontSize:'11px'} }; }
    mount('#chartBiomes',   Object.assign({chart:{type:'donut',height:290}, colors:V, series:DATA.biomes.data,   labels:DATA.biomes.labels}, donut()));
    mount('#chartFactions', Object.assign({chart:{type:'donut',height:290}, colors:V, series:DATA.factions.data, labels:DATA.factions.labels}, donut()));

    mount('#chartGold', { chart:{type:'bar',height:290}, colors:[ACCENT],
        series:[{name:'Игроков',data:DATA.gold.data}],
        plotOptions:{bar:{borderRadius:4, columnWidth:'52%', borderRadiusApplication:'end'}},
        xaxis:Object.assign({categories:DATA.gold.labels, labels:{style:{fontSize:'10px'}}}, noax), legend:{show:false} });

    mount('#chartBattles', { chart:{type:'area',height:280}, colors:[ACCENT],
        series:[{name:'Боёв',data:DATA.battles.daily}], stroke:{curve:'smooth',width:2}, fill:areaFill,
        xaxis:Object.assign({categories:DATA.battles.labels, tickAmount:10, labels:{rotate:-45,style:{fontSize:'10px'}}}, noax) });

    mount('#chartBattleTypes', Object.assign({chart:{type:'donut',height:280}, colors:V, series:DATA.bTypes.data, labels:DATA.bTypes.labels}, donut('70%')));

    mount('#chartEconomy', { chart:{type:'line',height:280}, colors:[ACCENT, V[3]],
        series:[{name:'Покупки',data:DATA.economy.buy},{name:'Продажи',data:DATA.economy.sell}],
        stroke:{curve:'smooth',width:2},
        xaxis:Object.assign({categories:DATA.economy.labels, tickAmount:10, labels:{rotate:-45,style:{fontSize:'10px'}}}, noax),
        yaxis:{labels:{formatter:function(v){ return Math.abs(v)>=1000 ? (v/1000).toFixed(0)+'K' : v; }}},
        legend:{position:'top',horizontalAlign:'right'} });

    function hbar(color, s, labels){ return { chart:{type:'bar',height:280}, colors:[color], series:s,
        plotOptions:{bar:{horizontal:true, borderRadius:4, barHeight:'62%', borderRadiusApplication:'end'}},
        xaxis:Object.assign({categories:labels, labels:{style:{fontSize:'10px'}}}, noax) }; }
    mount('#chartCrafted',   hbar(INK,    [{name:'Скрафчено',data:DATA.crafted.data}],   DATA.crafted.labels));
    mount('#chartResources', hbar(ACCENT, [{name:'На складе',data:DATA.resources.data}], DATA.resources.labels));

    mount('#chartBuildings', Object.assign({chart:{type:'donut',height:280}, colors:V, series:DATA.builds.data, labels:DATA.builds.labels}, donut('66%')));
    mount('#chartTasks', { chart:{type:'bar',height:280}, colors:[ACCENT],
        series:[{name:'Заданий в работе',data:DATA.tasks.data}],
        plotOptions:{bar:{borderRadius:4, columnWidth:'52%', borderRadiusApplication:'end'}},
        xaxis:Object.assign({categories:DATA.tasks.labels, labels:{style:{fontSize:'10px'}}}, noax), legend:{show:false} });
});
</script>
<?= $this->endSection() ?>
