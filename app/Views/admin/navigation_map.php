<?= $this->extend('admin/layouts/aui') ?>
<?= $this->section('pageTitle') ?>Дерево навигации<?= $this->endSection() ?>
<?= $this->section('head') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin/navigation-map.css') ?>?v=2">
<?= $this->endSection() ?>
<?= $this->section('content') ?>

<div class="nav-map-app" id="nav-map-app">
    <div class="aui-page-head">
        <div class="aui-page-head__title">
            <p class="aui-eyebrow">Обзор</p>
            <h1 class="aui-display">Дерево навигации игры</h1>
            <p class="aui-muted aui-small" style="margin:6px 0 0" id="nm-stats">Загрузка…</p>
        </div>
        <div class="aui-toolbar">
            <span class="aui-badge aui-badge--success" id="nm-live-badge" title="Авто-обновление каждые 30 секунд"><span class="aui-dot aui-dot--pulse"></span> live</span>
            <span class="aui-faint aui-small" id="nm-updated"></span>
            <button class="aui-btn aui-btn--ghost aui-btn--icon" id="nm-refresh" type="button" title="Перезагрузить"><i class="ri-refresh-line"></i></button>
            <button class="aui-btn aui-btn--ghost aui-btn--icon" id="nm-expand-all" type="button" title="Развернуть всё"><i class="ri-expand-up-down-line"></i></button>
            <button class="aui-btn aui-btn--ghost aui-btn--icon" id="nm-collapse-all" type="button" title="Свернуть всё"><i class="ri-contract-up-down-line"></i></button>
        </div>
    </div>

    <!-- Stats cards (JS fills) -->
    <div class="nm-statbar" id="nm-statbar"></div>

    <!-- Search + tabs -->
    <div class="nm-controls">
        <div class="nm-search-wrap">
            <i class="ri-search-line nm-search-icon"></i>
            <input type="search" id="nm-search" class="nm-search" placeholder="Поиск: экран, кнопка, callback, класс…" autocomplete="off">
            <button type="button" class="nm-search-clear d-none" id="nm-search-clear" aria-label="Очистить"><i class="ri-close-line"></i></button>
            <div class="aui-faint aui-small nm-search-hint" id="nm-search-hint"></div>
        </div>
        <div class="nm-tabs" role="tablist">
            <button class="active" data-nm-tab="tree" type="button"><i class="ri-node-tree"></i> Дерево</button>
            <button data-nm-tab="problems" type="button"><i class="ri-error-warning-line"></i> Проблемы <span class="aui-badge aui-badge--danger" id="nm-count-problems">·</span></button>
            <button data-nm-tab="screens" type="button"><i class="ri-list-check-2"></i> Все экраны <span class="aui-badge" id="nm-count-screens">·</span></button>
        </div>
    </div>

    <!-- Body: tree + detail drawer -->
    <div class="nm-body">
        <div class="nm-body__main">
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
                <small>Попробуй другие ключевые слова или сбрось фильтр.</small>
            </div>
        </div>

        <div class="nm-body__aside">
            <div class="nm-detail" id="nm-detail">
                <div class="nm-detail-placeholder">
                    <i class="ri-information-line"></i>
                    <h6>Выбери экран дерева</h6>
                    <p class="aui-faint aui-small" style="margin:0">
                        Кликни на любой экран &mdash; здесь появятся детали: исходящие кнопки,
                        куда они ведут, входящие переходы (callback-маршруты), глубина от точки
                        входа, файл-обработчик и флаги (мёртвые кнопки, тупик, сирота).
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Legend -->
    <div class="nm-legend aui-small">
        <span class="nm-legend-item"><span class="nm-dot nm-grp-onboarding"></span> онбординг</span>
        <span class="nm-legend-item"><span class="nm-dot nm-grp-craft"></span> крафт</span>
        <span class="nm-legend-item"><span class="nm-dot nm-grp-base"></span> база</span>
        <span class="nm-legend-item"><span class="nm-dot nm-grp-trade"></span> торговля</span>
        <span class="nm-legend-item"><span class="nm-dot nm-grp-quests"></span> квесты</span>
        <span class="nm-legend-item"><span class="nm-dot nm-grp-combat"></span> бой</span>
        <span class="nm-legend-item"><span class="nm-dot nm-grp-world"></span> мир</span>
        <span class="nm-legend-item"><span class="nm-dot nm-grp-services"></span> сервис-экран</span>
        <span class="nm-legend-item"><span class="nm-dot nm-grp-task-results"></span> итог задачи</span>
        <span class="nm-legend-item" style="margin-left:var(--sp-2)"><i class="ri-close-circle-line" style="color:var(--danger)"></i> мёртвая кнопка</span>
        <span class="nm-legend-item"><i class="ri-link" style="color:var(--info)"></i> перекрёстная ссылка</span>
    </div>
</div>

<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
    window.__nmDataUrl = "<?= site_url('admin/navigation/data') ?>";
</script>
<script src="<?= base_url('assets/js/admin/navigation-map.js') ?>?v=1"></script>
<?= $this->endSection() ?>
