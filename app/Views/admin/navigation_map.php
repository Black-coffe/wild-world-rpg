<?= $this->extend('templates/dashboard') ?>

<?= $this->section('content') ?>

<link rel="stylesheet" href="<?= base_url('assets/css/admin/navigation-map.css') ?>?v=1">

<div class="content">
    <div class="container-fluid nav-map-app" id="nav-map-app">
        <!-- Header -->
        <div class="row align-items-center mb-2 nm-header">
            <div class="col-md-6">
                <div class="page-title-box mb-0">
                    <h4 class="page-title mb-1">
                        <i class="ri-route-line me-1"></i>
                        Дерево навигации игры
                    </h4>
                    <p class="text-muted small mb-0" id="nm-stats">Загрузка…</p>
                </div>
            </div>
            <div class="col-md-6 d-flex justify-content-md-end gap-2 align-items-center mt-2 mt-md-0">
                <span class="badge bg-success-subtle text-success nm-live-badge" id="nm-live-badge" title="Авто-обновление каждые 30 секунд">
                    <i class="ri-radio-button-line"></i> live
                </span>
                <span class="text-muted small" id="nm-updated"></span>
                <button class="btn btn-sm btn-soft-primary" id="nm-refresh" type="button" title="Перезагрузить">
                    <i class="ri-refresh-line"></i>
                </button>
                <button class="btn btn-sm btn-soft-secondary" id="nm-expand-all" type="button" title="Развернуть всё">
                    <i class="ri-expand-up-down-line"></i>
                </button>
                <button class="btn btn-sm btn-soft-secondary" id="nm-collapse-all" type="button" title="Свернуть всё">
                    <i class="ri-contract-up-down-line"></i>
                </button>
            </div>
        </div>

        <!-- Stats cards -->
        <div class="row g-2 mb-3 nm-statbar" id="nm-statbar"></div>

        <!-- Search + tabs -->
        <div class="row g-2 mb-3">
            <div class="col-12 col-lg-7">
                <div class="nm-search-wrap">
                    <i class="ri-search-line nm-search-icon"></i>
                    <input
                        type="search"
                        id="nm-search"
                        class="form-control nm-search"
                        placeholder="Поиск: экран, кнопка, callback, класс…"
                        autocomplete="off"
                    >
                    <button type="button" class="btn btn-link nm-search-clear d-none" id="nm-search-clear" aria-label="Очистить">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
                <div class="text-muted small mt-1 nm-search-hint" id="nm-search-hint"></div>
            </div>
            <div class="col-12 col-lg-5">
                <ul class="nav nav-pills nm-tabs justify-content-lg-end" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-nm-tab="tree" type="button">
                            <i class="ri-node-tree"></i> Дерево
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-nm-tab="problems" type="button">
                            <i class="ri-error-warning-line"></i> Проблемы <span class="badge bg-danger-subtle text-danger ms-1" id="nm-count-problems">·</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-nm-tab="screens" type="button">
                            <i class="ri-list-check-2"></i> Все экраны <span class="badge bg-light text-dark ms-1" id="nm-count-screens">·</span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Body: tree + detail drawer -->
        <div class="row g-3 nm-body">
            <div class="col-12 col-xl-8">
                <div class="nm-tree" id="nm-tree" aria-busy="true">
                    <div class="nm-skeleton">
                        <div class="nm-skeleton-row"></div>
                        <div class="nm-skeleton-row"></div>
                        <div class="nm-skeleton-row"></div>
                        <div class="nm-skeleton-row"></div>
                    </div>
                </div>
                <div class="nm-empty d-none" id="nm-empty">
                    <i class="ri-search-eye-line"></i>
                    <div>Ничего не найдено</div>
                    <small class="text-muted">Попробуй другие ключевые слова или сбрось фильтр.</small>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="nm-detail" id="nm-detail">
                    <div class="nm-detail-placeholder">
                        <i class="ri-information-line"></i>
                        <h6 class="mt-2">Выбери экран дерева</h6>
                        <p class="text-muted small mb-0">
                            Кликни на любой экран &mdash; здесь появятся детали: исходящие кнопки,
                            куда они ведут, входящие переходы (callback-маршруты), глубина от точки
                            входа, файл-обработчик и флаги (мёртвые кнопки, тупик, сирота).
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Legend -->
        <div class="row mt-3">
            <div class="col-12">
                <div class="nm-legend small text-muted">
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-onboarding"></span> онбординг</span>
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-craft"></span> крафт</span>
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-base"></span> база</span>
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-trade"></span> торговля</span>
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-quests"></span> квесты</span>
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-combat"></span> бой</span>
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-world"></span> мир</span>
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-services"></span> сервис-экран</span>
                    <span class="nm-legend-item"><span class="nm-dot nm-grp-task-results"></span> итог задачи</span>
                    <span class="nm-legend-item ms-2"><i class="ri-close-circle-line text-danger"></i> мёртвая кнопка</span>
                    <span class="nm-legend-item"><i class="ri-link text-info"></i> перекрёстная ссылка</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.__nmDataUrl = "<?= site_url('admin/navigation/data') ?>";
</script>
<script src="<?= base_url('assets/js/admin/navigation-map.js') ?>?v=1"></script>

<?= $this->endSection() ?>
