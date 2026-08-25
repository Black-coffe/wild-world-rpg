<?php
/**
 * Sidebar «Quiet Premium» (layout admin/layouts/aui).
 * Активный пункт определяется по uri_string() — без правок контроллеров.
 * Ссылки 1:1 с templates/sidebar.php (старый Hyper-сайдбар), перегруппированы.
 */
$uri = trim(uri_string(), '/');
$is  = static fn (string $p): bool => $uri === $p || str_starts_with($uri, $p . '/');
$act = static fn (bool $on): string => $on ? ' is-active' : '';

$contentOpen = $is('admin/biomes') || $is('admin/resources') || $is('admin/tasks')
    || $is('admin/events') || $is('admin/world-objects') || $is('admin/quests');
$pollsOpen   = $is('admin/polls');
$siteOpen    = $is('admin/site');
?>
<aside class="aui-sidebar" id="aui-sidebar">
    <a class="aui-sidebar__brand" href="<?= base_url('dashboard') ?>" style="text-decoration:none">
        <img class="aui-sidebar__logo" src="<?= base_url('assets/img/wildworld-compass.svg') ?>" alt="Wild World">
        <div class="aui-sidebar__name">Wild<b>World</b></div>
    </a>

    <nav class="aui-sidebar__nav">

        <div class="aui-navgroup">
            <div class="aui-navgroup__title">Обзор</div>
            <a class="aui-navlink<?= $act($uri === 'dashboard') ?>" href="<?= base_url('dashboard') ?>"><i class="ri-dashboard-3-line"></i> Пульс игры</a>
            <a class="aui-navlink<?= $act($is('admin/funnel')) ?>" href="<?= base_url('admin/funnel') ?>"><i class="ri-filter-2-line"></i> Воронка игроков</a>
            <a class="aui-navlink<?= $act($is('admin/navigation')) ?>" href="<?= base_url('admin/navigation') ?>"><i class="ri-route-line"></i> Дерево навигации</a>
        </div>

        <div class="aui-navgroup">
            <div class="aui-navgroup__title">Настройки игры</div>
            <a class="aui-navlink" href="#" data-collapse="aui-sub-content" aria-expanded="<?= $contentOpen ? 'true' : 'false' ?>">
                <i class="ri-store-2-line"></i> Контент <i class="ri-arrow-right-s-line aui-navlink__arrow"></i>
            </a>
            <div class="aui-navsub" id="aui-sub-content" style="display:<?= $contentOpen ? 'block' : 'none' ?>">
                <a class="<?= $act($is('admin/biomes')) ?>" href="<?= base_url('admin/biomes') ?>">Биомы</a>
                <a class="<?= $act($is('admin/resources')) ?>" href="<?= base_url('admin/resources') ?>">Ресурсы</a>
                <a class="<?= $act($is('admin/tasks')) ?>" href="<?= base_url('admin/tasks') ?>">Задачи</a>
                <a class="<?= $act($is('admin/events')) ?>" href="<?= base_url('admin/events') ?>">События</a>
                <a class="<?= $act($is('admin/world-objects')) ?>" href="<?= base_url('admin/world-objects') ?>">Объекты</a>
                <a class="<?= $act($is('admin/quests')) ?>" href="<?= base_url('admin/quests') ?>">Квесты</a>
            </div>
            <a class="aui-navlink<?= $act($is('admin/craft-tree')) ?>" href="<?= base_url('admin/craft-tree') ?>"><i class="ri-node-tree"></i> Дерево крафта</a>
            <a class="aui-navlink<?= $act($is('admin/crafting-economy')) ?>" href="<?= base_url('admin/crafting-economy') ?>"><i class="ri-line-chart-line"></i> Экономика крафта</a>
            <a class="aui-navlink<?= $act($is('admin/game-settings')) ?>" href="<?= base_url('admin/game-settings') ?>"><i class="ri-settings-3-line"></i> Параметры баланса</a>
        </div>

        <div class="aui-navgroup">
            <div class="aui-navgroup__title">Операции</div>
            <a class="aui-navlink<?= $act($is('admin/tips')) ?>" href="<?= base_url('admin/tips') ?>"><i class="ri-advertisement-line"></i> Советы в игре</a>
            <a class="aui-navlink<?= $act($is('admin/send-message')) ?>" href="<?= base_url('admin/send-message') ?>"><i class="ri-megaphone-line"></i> Сообщение всем</a>
            <a class="aui-navlink<?= $act($is('admin/community')) ?>" href="<?= base_url('admin/community') ?>"><i class="ri-chat-3-line"></i> Чат сообщества</a>
            <a class="aui-navlink" href="#" data-collapse="aui-sub-polls" aria-expanded="<?= $pollsOpen ? 'true' : 'false' ?>">
                <i class="ri-bar-chart-box-line"></i> Опросы <i class="ri-arrow-right-s-line aui-navlink__arrow"></i>
            </a>
            <div class="aui-navsub" id="aui-sub-polls" style="display:<?= $pollsOpen ? 'block' : 'none' ?>">
                <a class="<?= $act($uri === 'admin/polls') ?>" href="<?= base_url('admin/polls') ?>">Список опросов</a>
                <a class="<?= $act($is('admin/polls/create')) ?>" href="<?= base_url('admin/polls/create') ?>">Создать опрос</a>
            </div>
            <a class="aui-navlink" href="#" data-collapse="aui-sub-site" aria-expanded="<?= $siteOpen ? 'true' : 'false' ?>">
                <i class="ri-global-line"></i> Контент сайта <i class="ri-arrow-right-s-line aui-navlink__arrow"></i>
            </a>
            <div class="aui-navsub" id="aui-sub-site" style="display:<?= $siteOpen ? 'block' : 'none' ?>">
                <a class="<?= $act($is('admin/site/posts')) ?>" href="<?= base_url('admin/site/posts') ?>">Посты</a>
                <a class="<?= $act($is('admin/site/pages')) ?>" href="<?= base_url('admin/site/pages') ?>">Страницы</a>
            </div>
        </div>

        <div class="aui-navgroup">
            <div class="aui-navgroup__title">Опасная зона</div>
            <a class="aui-navlink<?= $act($is('admin/character-reset')) ?>" href="<?= base_url('admin/character-reset') ?>"><i class="ri-user-settings-line"></i> Сброс персонажа</a>
            <a class="aui-navlink<?= $act($is('admin/wipe')) ?>" href="<?= base_url('admin/wipe') ?>" style="color:var(--danger)"><i class="ri-fire-line" style="color:var(--danger)"></i> Вайп сервера</a>
        </div>

    </nav>
</aside>
