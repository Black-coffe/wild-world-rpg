<?php
/**
 * Admin layout «Quiet Premium» (ADR-128 — docs/admin-design/ADMIN_UI_CONSTITUTION.md).
 * Самодостаточный шелл на public/assets/css/admin-ui.css — НЕ использует Hyper.
 * Страницы переводятся на этот layout по группам; не переведённые остаются на старом.
 *
 * Секции:
 *   content   — обязательная, тело страницы (внутри <main class="aui-main">)
 *   pageTitle — опц., строка в топбар (фолбэк «Админка»)
 *   head      — опц., доп. <link>/<style> в <head>
 *   scripts   — опц., доп. <script> перед </body> (напр. ApexCharts)
 *
 * @var string $title
 */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Wild World — Админка') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('favicon.svg') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= base_url('favicon-32.png') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('apple-touch-icon.png') ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,450;9..144,500;9..144,600&family=Hanken+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    <!-- Remix Icon (локально, ri-*) -->
    <link href="<?= base_url('css/icons.min.css') ?>" rel="stylesheet" type="text/css">
    <!-- Дизайн-система «Quiet Premium» -->
    <link href="<?= base_url('assets/css/admin-ui.css') ?>?v=2" rel="stylesheet" type="text/css">

    <?= $this->renderSection('head') ?>
</head>
<body>
<div class="aui-shell">

    <?= $this->include('admin/layouts/_aui_sidebar') ?>

    <?php $user = service('auth')->getCurrentUser(); ?>
    <header class="aui-topbar">
        <button class="aui-iconbtn" id="aui-burger" aria-label="Открыть меню"><i class="ri-menu-line"></i></button>
        <div class="aui-topbar__title"><?php $pt = $this->renderSection('pageTitle'); echo $pt !== '' ? $pt : 'Админка'; ?></div>
        <div class="aui-topbar__spacer"></div>
        <a class="aui-iconbtn" href="<?= base_url('/') ?>" target="_blank" rel="noopener" aria-label="Открыть сайт"><i class="ri-external-link-line"></i></a>
        <?php if ($user): ?>
        <div style="position:relative">
            <div class="aui-user" id="aui-user" role="button" tabindex="0" aria-haspopup="true">
                <div class="aui-avatar"><?= esc(mb_strtoupper(mb_substr((string) ($user->name ?? '?'), 0, 1))) ?></div>
                <div class="aui-user__meta">
                    <div class="aui-user__name"><?= esc($user->name ?? '') ?></div>
                    <div class="aui-user__role"><?= ($user->is_admin ?? false) ? 'Администратор' : 'Пользователь' ?></div>
                </div>
                <i class="ri-arrow-down-s-line" style="color:var(--ink-4)"></i>
            </div>
            <div class="aui-menu" id="aui-user-menu" hidden style="position:absolute; right:0; top:calc(100% + 6px); z-index:50">
                <a class="aui-menu__item" href="<?= base_url('logout') ?>"><i class="ri-logout-box-r-line"></i> Выйти</a>
            </div>
        </div>
        <?php endif; ?>
    </header>

    <main class="aui-main">
        <?= $this->renderSection('content') ?>
    </main>

</div>

<script>
/* Шелл «Quiet Premium» — submenu collapse + мобильный сайдбар + меню юзера. Без Bootstrap. */
(function () {
    document.querySelectorAll('.aui-navlink[data-collapse]').forEach(function (l) {
        l.addEventListener('click', function (e) {
            e.preventDefault();
            var sub = document.getElementById(l.getAttribute('data-collapse'));
            var open = l.getAttribute('aria-expanded') === 'true';
            l.setAttribute('aria-expanded', String(!open));
            if (sub) { sub.style.display = open ? 'none' : 'block'; }
        });
    });
    var b = document.getElementById('aui-burger'), sb = document.getElementById('aui-sidebar');
    if (b && sb) { b.addEventListener('click', function () { sb.classList.toggle('is-open'); }); }
    var u = document.getElementById('aui-user'), m = document.getElementById('aui-user-menu');
    if (u && m) {
        u.addEventListener('click', function (e) { e.stopPropagation(); m.hidden = !m.hidden; });
        document.addEventListener('click', function () { m.hidden = true; });
    }

    // Таблицы: живой поиск + сортировка по колонке (замена DataTables, без jQuery).
    document.querySelectorAll('[data-table-search]').forEach(function (inp) {
        var tbl = document.querySelector(inp.getAttribute('data-table-search'));
        if (!tbl) return;
        inp.addEventListener('input', function () {
            var q = inp.value.trim().toLowerCase();
            tbl.querySelectorAll('tbody tr').forEach(function (tr) {
                if (tr.children.length < 2) return;
                tr.style.display = tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
            });
        });
    });
    document.querySelectorAll('table[data-enhance] thead th[data-sort]').forEach(function (th) {
        th.addEventListener('click', function () {
            var tbl = th.closest('table'), tb = tbl.querySelector('tbody');
            var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
            var asc = th.getAttribute('data-asc') !== '1';
            tbl.querySelectorAll('thead th').forEach(function (h) { h.classList.remove('sort-asc', 'sort-desc'); h.removeAttribute('data-asc'); });
            th.setAttribute('data-asc', asc ? '1' : '0');
            th.classList.add(asc ? 'sort-asc' : 'sort-desc');
            var rows = Array.prototype.slice.call(tb.querySelectorAll('tr')).filter(function (r) { return r.children.length > 1; });
            rows.sort(function (a, b) {
                var x = a.children[idx] ? a.children[idx].textContent.trim() : '';
                var y = b.children[idx] ? b.children[idx].textContent.trim() : '';
                var nx = parseFloat(x.replace(/[^0-9.\-]/g, '')), ny = parseFloat(y.replace(/[^0-9.\-]/g, ''));
                var c = (!isNaN(nx) && !isNaN(ny) && x !== '' && y !== '') ? nx - ny : x.localeCompare(y, 'ru');
                return asc ? c : -c;
            });
            rows.forEach(function (r) { tb.appendChild(r); });
        });
    });
})();
</script>

<?= $this->renderSection('scripts') ?>
</body>
</html>
