<?= $this->extend('templates/dashboard') ?>

<?= $this->section('content') ?>

<div class="content">
    <div class="container-fluid" id="econ-app">

        <!-- Header -->
        <div class="row align-items-center mb-3">
            <div class="col-md-6">
                <div class="page-title-box mb-0">
                    <h4 class="page-title mb-1">
                        <i class="ri-line-chart-line me-1"></i>
                        Экономика крафта
                    </h4>
                    <p class="text-muted small mb-0" id="econ-stats">Загрузка…</p>
                </div>
            </div>
            <div class="col-md-6 d-flex justify-content-md-end gap-2 align-items-center mt-2 mt-md-0">
                <span class="badge bg-success-subtle text-success" id="econ-live" title="Авто-обновление каждые 60 секунд">
                    <i class="ri-radio-button-line"></i> live
                </span>
                <span class="text-muted small" id="econ-updated"></span>
                <button class="btn btn-sm btn-soft-primary" id="econ-refresh" type="button" title="Перезагрузить">
                    <i class="ri-refresh-line"></i>
                </button>
                <a class="btn btn-sm btn-soft-success" href="<?= site_url('admin/crafting-economy/export') ?>" title="Экспорт в CSV" download>
                    <i class="ri-file-excel-2-line"></i>
                </a>
            </div>
        </div>

        <!-- KPI cards -->
        <div class="row" id="econ-kpis">
            <div class="col-md-4 col-xl-2"><div class="card"><div class="card-body p-2 text-center">
                <i class="ri-team-line fs-3 text-primary"></i>
                <h4 class="mt-1 mb-0" id="kpi-players">—</h4>
                <p class="text-muted small mb-0">Игроков</p>
            </div></div></div>
            <div class="col-md-4 col-xl-2"><div class="card"><div class="card-body p-2 text-center">
                <i class="ri-coin-line fs-3 text-primary"></i>
                <h4 class="mt-1 mb-0" id="kpi-gold">—</h4>
                <p class="text-muted small mb-0">Всего золота</p>
            </div></div></div>
            <div class="col-md-4 col-xl-2"><div class="card"><div class="card-body p-2 text-center">
                <i class="ri-scales-3-line fs-3 text-primary"></i>
                <h4 class="mt-1 mb-0" id="kpi-avggold">—</h4>
                <p class="text-muted small mb-0">Среднее золото</p>
            </div></div></div>
            <div class="col-md-4 col-xl-2"><div class="card"><div class="card-body p-2 text-center">
                <i class="ri-hammer-line fs-3 text-primary"></i>
                <h4 class="mt-1 mb-0" id="kpi-crafted">—</h4>
                <p class="text-muted small mb-0">Скрафчено (шт.)</p>
            </div></div></div>
            <div class="col-md-4 col-xl-2"><div class="card"><div class="card-body p-2 text-center">
                <i class="ri-stack-line fs-3 text-primary"></i>
                <h4 class="mt-1 mb-0" id="kpi-resource">—</h4>
                <p class="text-muted small mb-0">Ресурсов на руках</p>
            </div></div></div>
            <div class="col-md-4 col-xl-2"><div class="card"><div class="card-body p-2 text-center">
                <i class="ri-exchange-line fs-3 text-primary"></i>
                <h4 class="mt-1 mb-0" id="kpi-tx">—</h4>
                <p class="text-muted small mb-0">Транзакций</p>
            </div></div></div>
        </div>

        <!-- Charts -->
        <div class="row">
            <div class="col-xl-8">
                <div class="card"><div class="card-body">
                    <h5 class="card-title">Оборот крафта по месяцам</h5>
                    <div id="chart-craft-volume" style="min-height:300px;"></div>
                </div></div>
            </div>
            <div class="col-xl-4">
                <div class="card"><div class="card-body">
                    <h5 class="card-title">Концентрация золота (топ-10)</h5>
                    <p class="text-muted small mb-1" id="econ-gold-share"></p>
                    <div id="chart-gold" style="min-height:300px;"></div>
                </div></div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-6">
                <div class="card"><div class="card-body">
                    <h5 class="card-title">Топ скрафченных предметов</h5>
                    <div id="chart-top-crafted" style="min-height:340px;"></div>
                </div></div>
            </div>
            <div class="col-xl-6">
                <div class="card"><div class="card-body">
                    <h5 class="card-title">Топ удерживаемых ресурсов</h5>
                    <div id="chart-top-resources" style="min-height:340px;"></div>
                </div></div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card"><div class="card-body">
                    <h5 class="card-title">Транзакции по месяцам (объём buy/sell)</h5>
                    <div id="chart-transactions" style="min-height:280px;"></div>
                </div></div>
            </div>
        </div>

    </div>
</div>

<script>
    window.__econDataUrl = "<?= site_url('admin/crafting-economy/data') ?>";
</script>
<script src="<?= base_url('assets/js/admin/crafting-economy.js') ?>?v=1"></script>

<?= $this->endSection() ?>
