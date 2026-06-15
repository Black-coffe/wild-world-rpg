<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Дерево крафта<?= $this->endSection() ?>
<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin/craft-tree.css') ?>?v=2">
<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="craft-tree-app" id="craft-tree-app">
    <div class="aui-page-head">
        <div class="aui-page-head__title">
            <p class="aui-eyebrow">Настройки игры</p>
            <h1 class="aui-display">Дерево крафта и строительства</h1>
            <p class="aui-muted aui-small" style="margin:6px 0 0" id="ct-stats">Загрузка…</p>
        </div>
        <div class="aui-toolbar">
            <span class="aui-badge aui-badge--success ct-live-badge" id="ct-live-badge" title="Авто-обновление каждые 30 секунд"><span class="aui-dot aui-dot--pulse"></span> live</span>
            <span class="aui-faint aui-small" id="ct-updated"></span>
            <button class="aui-btn aui-btn--ghost aui-btn--icon" id="ct-refresh" type="button" title="Перезагрузить"><i class="ri-refresh-line"></i></button>
            <button class="aui-btn aui-btn--ghost aui-btn--icon" id="ct-expand-all" type="button" title="Развернуть всё"><i class="ri-expand-up-down-line"></i></button>
            <button class="aui-btn aui-btn--ghost aui-btn--icon" id="ct-collapse-all" type="button" title="Свернуть всё"><i class="ri-contract-up-down-line"></i></button>
            <a class="aui-btn aui-btn--ghost aui-btn--icon" href="<?= site_url('admin/craft-tree/export') ?>" title="Экспорт в CSV" download><i class="ri-file-excel-2-line"></i></a>
        </div>
    </div>

    <!-- Search + tabs -->
    <div class="ct-controls">
        <div class="ct-search-wrap">
            <i class="ri-search-line ct-search-icon"></i>
            <input type="search" id="ct-search" class="ct-search" placeholder="Поиск: рецепт, здание, ресурс, ингредиент…" autocomplete="off">
            <button type="button" class="ct-search-clear d-none" id="ct-search-clear" aria-label="Очистить"><i class="ri-close-line"></i></button>
            <div class="aui-faint aui-small ct-search-hint" id="ct-search-hint"></div>
        </div>
        <div class="ct-tabs" role="tablist">
            <button class="active" data-ct-tab="crafts" type="button"><i class="ri-tools-fill"></i> Крафт <span class="aui-badge ct-tab-count" id="ct-count-crafts">·</span></button>
            <button data-ct-tab="buildings" type="button"><i class="ri-building-2-fill"></i> Строительство <span class="aui-badge ct-tab-count" id="ct-count-buildings">·</span></button>
            <button data-ct-tab="resources" type="button"><i class="ri-leaf-fill"></i> Ресурсы <span class="aui-badge ct-tab-count" id="ct-count-resources">·</span></button>
        </div>
    </div>

    <!-- Body: tree + detail drawer -->
    <div class="ct-body">
        <div class="ct-body__main">
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

        <div class="ct-body__aside">
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

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
    window.__ctDataUrl = "<?= site_url('admin/craft-tree/data') ?>";
</script>
<script src="<?= base_url('assets/js/admin/craft-tree.js') ?>?v=1"></script>
<?= $this->endSection() ?>
