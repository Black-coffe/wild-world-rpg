<?= $this->extend('templates/dashboard') ?>

<?= $this->section('content') ?>

<link rel="stylesheet" href="<?= base_url('assets/css/admin/craft-tree.css') ?>?v=1">

<div class="content">
    <div class="container-fluid craft-tree-app" id="craft-tree-app">
        <!-- Header -->
        <div class="row align-items-center mb-3 ct-header">
            <div class="col-md-6">
                <div class="page-title-box mb-0">
                    <h4 class="page-title mb-1">
                        <i class="ri-node-tree me-1"></i>
                        Дерево крафта и строительства
                    </h4>
                    <p class="text-muted small mb-0" id="ct-stats">Загрузка…</p>
                </div>
            </div>
            <div class="col-md-6 d-flex justify-content-md-end gap-2 align-items-center mt-2 mt-md-0">
                <span class="badge bg-success-subtle text-success ct-live-badge" id="ct-live-badge" title="Авто-обновление каждые 30 секунд">
                    <i class="ri-radio-button-line"></i> live
                </span>
                <span class="text-muted small" id="ct-updated"></span>
                <button class="btn btn-sm btn-soft-primary" id="ct-refresh" type="button" title="Перезагрузить">
                    <i class="ri-refresh-line"></i>
                </button>
                <button class="btn btn-sm btn-soft-secondary" id="ct-expand-all" type="button" title="Развернуть всё">
                    <i class="ri-expand-up-down-line"></i>
                </button>
                <button class="btn btn-sm btn-soft-secondary" id="ct-collapse-all" type="button" title="Свернуть всё">
                    <i class="ri-contract-up-down-line"></i>
                </button>
                <a class="btn btn-sm btn-soft-success" href="<?= site_url('admin/craft-tree/export') ?>" title="Экспорт в CSV" download>
                    <i class="ri-file-excel-2-line"></i>
                </a>
            </div>
        </div>

        <!-- Search + tabs -->
        <div class="row g-2 mb-3">
            <div class="col-12 col-lg-7">
                <div class="ct-search-wrap">
                    <i class="ri-search-line ct-search-icon"></i>
                    <input
                        type="search"
                        id="ct-search"
                        class="form-control ct-search"
                        placeholder="Поиск: рецепт, здание, ресурс, ингредиент…"
                        autocomplete="off"
                    >
                    <button type="button" class="btn btn-link ct-search-clear d-none" id="ct-search-clear" aria-label="Очистить">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
                <div class="text-muted small mt-1 ct-search-hint" id="ct-search-hint"></div>
            </div>
            <div class="col-12 col-lg-5">
                <ul class="nav nav-pills ct-tabs justify-content-lg-end" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-ct-tab="crafts" type="button">
                            <i class="ri-tools-fill"></i> Крафт <span class="badge bg-light text-dark ms-1" id="ct-count-crafts">·</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-ct-tab="buildings" type="button">
                            <i class="ri-building-2-fill"></i> Строительство <span class="badge bg-light text-dark ms-1" id="ct-count-buildings">·</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-ct-tab="resources" type="button">
                            <i class="ri-leaf-fill"></i> Ресурсы <span class="badge bg-light text-dark ms-1" id="ct-count-resources">·</span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Body: tree + detail drawer -->
        <div class="row g-3 ct-body">
            <div class="col-12 col-xl-8">
                <div class="ct-tree" id="ct-tree" aria-busy="true">
                    <div class="ct-skeleton">
                        <div class="ct-skeleton-row"></div>
                        <div class="ct-skeleton-row"></div>
                        <div class="ct-skeleton-row"></div>
                        <div class="ct-skeleton-row"></div>
                    </div>
                </div>
                <div class="ct-empty d-none" id="ct-empty">
                    <i class="ri-search-eye-line"></i>
                    <div>Ничего не найдено</div>
                    <small class="text-muted">Попробуй другие ключевые слова или сбрось фильтр.</small>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="ct-detail" id="ct-detail">
                    <div class="ct-detail-placeholder">
                        <i class="ri-information-line"></i>
                        <h6 class="mt-2">Выбери элемент дерева</h6>
                        <p class="text-muted small mb-0">
                            Кликни на любой рецепт, здание или ингредиент &mdash; здесь появятся
                            детали: компоненты, стоимость в золоте, обратные ссылки, описание.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.__ctDataUrl = "<?= site_url('admin/craft-tree/data') ?>";
</script>
<script src="<?= base_url('assets/js/admin/craft-tree.js') ?>?v=1"></script>

<?= $this->endSection() ?>
