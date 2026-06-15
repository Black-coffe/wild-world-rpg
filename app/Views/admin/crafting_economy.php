<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Экономика крафта<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div id="econ-app">
    <div class="aui-page-head">
        <div class="aui-page-head__title">
            <p class="aui-eyebrow">Настройки игры</p>
            <h1 class="aui-display">Экономика крафта</h1>
            <p class="aui-muted aui-small" style="margin:6px 0 0" id="econ-stats">Загрузка…</p>
        </div>
        <div class="aui-toolbar">
            <span class="aui-badge aui-badge--success" id="econ-live" title="Авто-обновление каждые 60 секунд"><span class="aui-dot aui-dot--pulse"></span> live</span>
            <span class="aui-faint aui-small" id="econ-updated"></span>
            <button class="aui-btn aui-btn--ghost aui-btn--icon" id="econ-refresh" type="button" title="Перезагрузить"><i class="ri-refresh-line"></i></button>
            <a class="aui-btn aui-btn--ghost aui-btn--icon" id="econ-export" data-base="<?= site_url('admin/crafting-economy/export') ?>" href="<?= site_url('admin/crafting-economy/export') ?>" title="Экспорт в CSV (за выбранный период)" download><i class="ri-file-excel-2-line"></i></a>
        </div>
    </div>

    <!-- Period filter -->
    <div class="aui-card" style="margin-bottom:var(--sp-4)"><div class="aui-card__body" style="padding:var(--sp-3) var(--sp-4)">
        <div class="aui-row" style="flex-wrap:wrap; gap:var(--sp-2)">
            <span class="aui-faint aui-small"><i class="ri-calendar-line"></i> Период:</span>
            <div class="aui-btngroup" id="econ-presets">
                <button type="button" data-months="3">3 мес</button>
                <button type="button" class="is-active" data-months="12">12 мес</button>
                <button type="button" data-months="24">24 мес</button>
                <button type="button" data-months="all">Всё время</button>
            </div>
            <span class="aui-faint aui-small" style="margin-left:var(--sp-2)">или диапазон:</span>
            <input type="date" class="aui-input" id="econ-from" style="width:auto; padding:7px 10px">
            <span class="aui-faint">—</span>
            <input type="date" class="aui-input" id="econ-to" style="width:auto; padding:7px 10px">
            <button type="button" class="aui-btn aui-btn--primary aui-btn--sm" id="econ-apply">Применить</button>
            <span class="aui-faint aui-small" style="margin-left:auto" id="econ-range"></span>
        </div>
        <p class="aui-faint aui-small" style="margin:var(--sp-2) 0 0">Период применяется к графикам оборота/крафтов/транзакций и крафт-счётчикам. <b>Золото и ресурсы</b> — текущий снимок склада.</p>
    </div></div>

    <!-- KPI cards -->
    <?php
    $econKpis = [
        ['kpi-players',  'Игроков',          'ri-team-line'],
        ['kpi-gold',     'Всего золота',     'ri-coin-line'],
        ['kpi-avggold',  'Среднее золото',   'ri-scales-3-line'],
        ['kpi-crafted',  'Скрафчено (шт.)',  'ri-hammer-line'],
        ['kpi-resource', 'Ресурсов на руках','ri-stack-line'],
        ['kpi-tx',       'Транзакций',       'ri-exchange-line'],
    ];
    ?>
    <div class="aui-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); margin-bottom:var(--sp-4)">
        <?php foreach ($econKpis as [$id, $label, $icon]): ?>
        <div class="aui-kpi aui-rise">
            <div class="aui-kpi__top"><span class="aui-kpi__label"><?= esc($label) ?></span><i class="<?= esc($icon) ?> aui-kpi__icon"></i></div>
            <div class="aui-kpi__value" id="<?= esc($id) ?>">—</div>
            <div class="aui-kpi__rule"></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <div class="aui-grid aui-grid--2-1" style="margin-bottom:var(--sp-4)">
        <div class="aui-card"><div class="aui-card__head"><i class="ri-area-chart-line"></i><span class="aui-card__title">Оборот крафта по месяцам</span></div>
            <div class="aui-card__body"><div id="chart-craft-volume" style="min-height:300px"></div></div></div>
        <div class="aui-card"><div class="aui-card__head"><i class="ri-coins-line"></i><span class="aui-card__title">Концентрация золота (топ-10)</span></div>
            <div class="aui-card__body"><p class="aui-faint aui-small" style="margin:0 0 6px" id="econ-gold-share"></p><div id="chart-gold" style="min-height:300px"></div></div></div>
    </div>

    <div class="aui-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); margin-bottom:var(--sp-4)">
        <div class="aui-card"><div class="aui-card__head"><i class="ri-hammer-line"></i><span class="aui-card__title">Топ скрафченных предметов</span></div>
            <div class="aui-card__body"><div id="chart-top-crafted" style="min-height:340px"></div></div></div>
        <div class="aui-card"><div class="aui-card__head"><i class="ri-stack-line"></i><span class="aui-card__title">Топ удерживаемых ресурсов</span></div>
            <div class="aui-card__body"><div id="chart-top-resources" style="min-height:340px"></div></div></div>
    </div>

    <div class="aui-card">
        <div class="aui-card__head"><i class="ri-exchange-dollar-line"></i><span class="aui-card__title">Транзакции по месяцам (объём buy/sell)</span></div>
        <div class="aui-card__body"><div id="chart-transactions" style="min-height:280px"></div></div>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<!-- V21: self-hosted ApexCharts (theme vendor/ не задеплоен → 404; кладём в tracked assets/js/admin). -->
<script src="<?= base_url('assets/js/admin/apexcharts.min.js') ?>?v=1"></script>
<script>
    window.__econDataUrl = "<?= site_url('admin/crafting-economy/data') ?>";
</script>
<script src="<?= base_url('assets/js/admin/crafting-economy.js') ?>?v=3"></script>
<?= $this->endSection() ?>
