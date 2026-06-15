<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
// ADR-052 — публичный сайт занимает корень; админ-логин переехал на /admin/login
// (старый /login сохранён для совместимости). Форма логина постит на /login/authenticate.
$routes->get('/', 'Front::home');
$routes->get('admin/login', 'Login::new');

// Asana «Расширение логирования PVP» Фаза 2 (ADR-092): ПУБЛИЧНЫЙ просмотр боёв (flat ADR-062).
// Публичный тир — общая статистика (без координат; координаты gated за Фазой 3 TG-auth).
// Admin-версия (/admin/battles) сохранена ниже для review. Намеренная публикация (пересмотр v0.51.7).
$routes->get('battles', 'BattlesController::publicIndex');
$routes->get('battles/view/(:num)', 'BattlesController::publicView/$1');

// Asana «Визуальная таблица достижений на сайте» (Steam/Монобанк-стиль, flat ADR-062).
// Публичная сетка достижений + глобальный % игроков; личный прогресс — за TG-входом (ADR-061).
$routes->get('achievements', 'AchievementsController::publicIndex');
// v0.51.6 security cleanup: removed public unprotected routes
// - /migrate -> MigrationController (CRITICAL: anyone could trigger migrations->latest() via GET)
// - /pve-test, /pve-test-view -> PvETestController (dead test scaffolding, replaced by PHPUnit)
// Use CLI `php spark migrate` for migrations (proper deploy flow).

// v0.51.7 security: moved /pvp + /battles behind admin login filter.
// Public exposure leaked player data (names, stats, factions, combat history).
// Admin login flow preserved — legit testing/review continues to work.
// 0 access log hits in recent window confirmed safe to relocate.
$routes->group('admin', ['filter' => 'login'], function($routes) {
    // PvP testing form + simulator (was /pvp до v0.51.7)
    $routes->get('pvp', 'PvPController::index');
    $routes->post('pvp/startFight', 'PvPController::startFight');

    // Battle history viewer (було /battles до v0.51.7)
    $routes->get('battles', 'BattlesController::index');
    $routes->get('battles/view/(:num)', 'BattlesController::view/$1');


    // Маршруты для опросов
    $routes->get('polls', 'Admin\PollController::index'); // Список всех опросов
    $routes->get('polls/create', 'Admin\PollController::createPollForm'); // Форма создания опроса
    $routes->post('polls/store', 'Admin\PollController::storePoll'); // Сохранение опроса
    $routes->get('polls/edit/(:segment)', 'Admin\PollController::editPollForm/$1'); // Форма редактирования опроса
    $routes->post('polls/update/(:segment)', 'Admin\PollController::updatePoll/$1'); // Обновление опроса
    $routes->post('polls/delete/(:segment)', 'Admin\PollController::deletePoll/$1'); // Удаление опроса (POST для защиты от случайного клика по URL)
    $routes->get('polls/statistics/(:segment)', 'Admin\PollController::statistics/$1'); // Статистика опроса (read-only)
    $routes->post('polls/stop/(:segment)', 'Admin\PollController::stopPoll/$1');     // Остановка (POST — изменяет состояние)
    $routes->post('polls/send/(:segment)', 'Admin\PollController::sendPoll/$1');     // Запуск рассылки (POST — destructive)
    // Quest routes
    $routes->get('quests', 'Admin\QuestController::index'); // List all quests
    $routes->get('quests/create', 'Admin\QuestController::createQuestForm'); // Form for creating a quest
    $routes->post('quests/store', 'Admin\QuestController::storeQuest'); // Store a new quest
    $routes->get('quests/edit/(:segment)', 'Admin\QuestController::editQuestForm/$1'); // Form for editing a quest
    $routes->post('quests/update/(:segment)', 'Admin\QuestController::updateQuest/$1'); // Update a quest
    $routes->post('quests/delete/(:segment)', 'Admin\QuestController::deleteQuest/$1'); // Delete a quest (POST — destructive, CSRF + защита от случайного клика по URL)

    // Добавляем роутинг для объектов мира
    $routes->get('world-objects', 'Admin\WorldObjectController::index'); // Список всех объектов мира
    $routes->get('world-objects/create', 'Admin\WorldObjectController::createObjectForm'); // Форма создания объекта
    $routes->post('world-objects/store', 'Admin\WorldObjectController::storeObject'); // Сохранение нового объекта
    $routes->get('world-objects/edit/(:segment)', 'Admin\WorldObjectController::editObjectForm/$1'); // Форма редактирования объекта
    $routes->post('world-objects/update/(:segment)', 'Admin\WorldObjectController::updateObject/$1'); // Обновление объекта
    $routes->post('world-objects/delete/(:segment)', 'Admin\WorldObjectController::deleteObject/$1'); // Удаление объекта (POST — destructive, CSRF)

    // страница сброса персонажа и начальная проверка
    $routes->get('character-reset', 'Admin\CharacterResetController::index');
    $routes->post('character-reset/check', 'Admin\CharacterResetController::check'); // для проверки данных
    // подтверждение и выполнение сброса
    $routes->post('character-reset/confirm', 'Admin\CharacterResetController::confirmReset');
    $routes->post('character-reset/reset-all', 'Admin\CharacterResetController::resetAllCharacters');

    // ADR-087 — полный вайп сервера (preview → arm → пароль+подтверждение → wipe → broadcast)
    $routes->get('wipe', 'Admin\WipeController::index');
    $routes->post('wipe/arm', 'Admin\WipeController::arm');
    $routes->post('wipe/execute', 'Admin\WipeController::execute');

    // Роутинг для советов
    $routes->get('tips', 'Admin\GameTipsController::index'); // Список всех советов
    $routes->get('tips/create', 'Admin\GameTipsController::createTipForm'); // Форма создания совета
    $routes->post('tips/store', 'Admin\GameTipsController::storeTip'); // Сохранение нового совета
    $routes->get('tips/edit/(:segment)', 'Admin\GameTipsController::editTipForm/$1'); // Форма редактирования совета
    $routes->post('tips/update/(:segment)', 'Admin\GameTipsController::updateTip/$1'); // Обновление совета
    $routes->post('tips/delete/(:segment)', 'Admin\GameTipsController::deleteTip/$1'); // Удаление совета (POST — destructive, CSRF)

    // Роутинг для биомов
    $routes->get('biomes', 'Admin\BiomeController::index');
    $routes->get('biomes/edit/(:segment)', 'Admin\BiomeController::editBiomeForm/$1');
    $routes->post('biomes/update/(:segment)', 'Admin\BiomeController::updateBiome/$1');
    $routes->get('biomes/(:segment)/resources', 'Admin\BiomeController::showResources/$1'); // S7 reverse-view

    // Добавляем роутинг для ресурсов
    $routes->get('resources', 'Admin\ResourceController::index');
    $routes->get('resources/create', 'Admin\ResourceController::createResourceForm'); // Для отображения формы создания ресурса
    $routes->post('resources/store', 'Admin\ResourceController::storeResource'); // Для сохранения нового ресурса
    $routes->get('resources/edit/(:segment)', 'Admin\ResourceController::editResourceForm/$1'); // Для отображения формы редактирования ресурса
    $routes->post('resources/update/(:segment)', 'Admin\ResourceController::updateResource/$1'); // Для обновления ресурса
    $routes->post('resources/delete/(:segment)', 'Admin\ResourceController::deleteResource/$1'); // Для удаления ресурса (POST — destructive, CSRF)

    // Добавляем роутинг для задач
    $routes->get('tasks', 'Admin\TaskController::index'); // Для отображения списка задач
    $routes->get('tasks/create', 'Admin\TaskController::createTaskForm'); // Для отображения формы создания задачи
    $routes->post('tasks/store', 'Admin\TaskController::storeTask'); // Для сохранения новой задачи
    $routes->get('tasks/edit/(:segment)', 'Admin\TaskController::editTaskForm/$1'); // Для отображения формы редактирования задачи
    $routes->post('tasks/update/(:segment)', 'Admin\TaskController::updateTask/$1'); // Для обновления задачи

    // Добавляем роутинг для событий
    $routes->get('events', 'Admin\EventController::index'); // Для отображения списка событий
    $routes->get('events/create', 'Admin\EventController::createEventForm'); // Для отображения формы добавления события
    $routes->post('events/store', 'Admin\EventController::storeEvent'); // Для сохранения события
    $routes->get('events/edit/(:segment)', 'Admin\EventController::editEventForm/$1'); // Для отображения формы редактирования события
    $routes->post('events/update/(:segment)', 'Admin\EventController::updateEvent/$1'); // Для обновления события

    $routes->get('send-message', 'Admin\MessageController::index'); // Для отображения формы
    $routes->post('send-message', 'Admin\MessageController::sendMessage'); // Для обработки отправки

    // F1.9 dashboard (v0.51.17): read-only viewer для admin_audit_log table
    $routes->get('audit-log', 'Admin\AuditLogController::index');

    // v0.51.114 — Endgame scenarios dashboard
    $routes->get('endgame', 'Admin\EndgameController::index');
    $routes->post('endgame/reset/(:num)', 'Admin\EndgameController::reset/$1');
    // v0.51.120 — full season reset (B)
    $routes->post('endgame/reset-season', 'Admin\EndgameController::resetSeason');

    // Craft & Building tree visualisation (read-only)
    $routes->get('craft-tree', 'Admin\CraftTreeController::index');
    $routes->get('craft-tree/data', 'Admin\CraftTreeController::data');
    $routes->get('craft-tree/export', 'Admin\CraftTreeController::export'); // S30 — CSV-экспорт

    // V21 (ADR-053) — Crafting economy dashboard (read-only аналитика gold/turnover/inflation)
    $routes->get('crafting-economy', 'Admin\CraftingEconomyController::index');
    $routes->get('crafting-economy/data', 'Admin\CraftingEconomyController::data');
    $routes->get('crafting-economy/export', 'Admin\CraftingEconomyController::export');

    // E1 (ROADMAP-100) — воронка игроков: read-only KPI-дашборд онбординга/retention
    $routes->get('funnel', 'Admin\FunnelController::index');

    // Navigation tree visualisation (read-only) — дерево кнопок/экранов/переходов игры
    $routes->get('navigation', 'Admin\NavigationMapController::index');
    $routes->get('navigation/data', 'Admin\NavigationMapController::data');

    // S5 (v0.51.187) — GameSettings live-tunable balance framework
    $routes->get('game-settings', 'Admin\GameSettingsController::index');
    $routes->post('game-settings/update', 'Admin\GameSettingsController::update');
    $routes->post('game-settings/reset', 'Admin\GameSettingsController::reset');

    // ADR-052 — CMS публичного сайта (посты + страницы)
    $routes->get('site/posts', 'Admin\SitePostController::index');
    $routes->get('site/posts/create', 'Admin\SitePostController::createForm');
    $routes->post('site/posts/store', 'Admin\SitePostController::store');
    $routes->get('site/posts/edit/(:num)', 'Admin\SitePostController::editForm/$1');
    $routes->post('site/posts/update/(:num)', 'Admin\SitePostController::update/$1');
    $routes->post('site/posts/review/(:num)', 'Admin\SitePostController::markReviewed/$1'); // POST — изменяет состояние, CSRF
    $routes->post('site/posts/delete/(:num)', 'Admin\SitePostController::delete/$1'); // POST — destructive, CSRF
    $routes->get('site/posts/preview/(:num)', 'Front::preview/$1'); // UI/UX-превью черновика (редколлегия): рендер ЛЮБОГО статуса в site-лейауте, noindex, admin-only

    $routes->get('site/pages', 'Admin\SitePageController::index');
    $routes->get('site/pages/create', 'Admin\SitePageController::createForm');
    $routes->post('site/pages/store', 'Admin\SitePageController::store');
    $routes->get('site/pages/edit/(:num)', 'Admin\SitePageController::editForm/$1');
    $routes->post('site/pages/update/(:num)', 'Admin\SitePageController::update/$1');
    $routes->post('site/pages/delete/(:num)', 'Admin\SitePageController::delete/$1'); // POST — destructive, CSRF

});


$routes->get('signup/new', 'Signup::new');
$routes->post('signup/create', 'Signup::create');
$routes->get('signup/success', 'Signup::success');
$routes->get('login/', 'Login::new');
$routes->post('login/authenticate', 'Login::authenticate');
$routes->get('logout/', 'Login::delete');

// Password
$routes->get('password/forgot', 'Password::forgot');
$routes->post('password/process-forgot', 'Password::processForgot');
$routes->get('password/resetsent', 'Password::resetSent');
$routes->get('password/reset/(:segment)', 'Password::reset/$1');
$routes->post('password/process-reset/(:segment)', 'Password::processReset/$1');
$routes->get('password/reset-success', 'Password::resetSuccess');


$routes->get('dashboard', 'AdminController::index', ['filter' => 'login']);
$routes->get('dashboard/export', 'AdminController::exportCsv', ['filter' => 'login']);

// TELEGRAM
$routes->post('telegram/webhook', 'Telegram\BotController::webhook');


// ===== ADR-052 — публичный сайт wildworld.fun в CI4 =====
// Порядок важен (Routing::$prioritize=false → первое совпадение): SEO/wiki/категории
// объявлены ДО корневого catch-all, который должен идти ПОСЛЕДНИМ GET-маршрутом.
// Карты сайта в формате Yoast/WP (имена совпадают со старыми сабмитами в GSC).
$routes->get('sitemap.xml', 'Sitemap::index');
$routes->get('sitemap_index.xml', 'Sitemap::index');
$routes->get('post-sitemap.xml', 'Sitemap::posts');
$routes->get('page-sitemap.xml', 'Sitemap::pages');
$routes->get('category-sitemap.xml', 'Sitemap::categories');
$routes->get('post_tag-sitemap.xml', 'Sitemap::tags');
$routes->get('author-sitemap.xml', 'Sitemap::authors');

$routes->get('wiki', 'Wiki::index');
$routes->get('wiki/(:segment)', 'Wiki::entry/$1');

// Интерактивная карта мира — публичный route + JSON-snapshot публичных слоёв.
$routes->get('map', 'Map::index');
$routes->get('map/data', 'Map::data');

// ADR-061 — Telegram Login Widget для игроков на сайте (видят свою позицию).
$routes->get('login/telegram/callback', 'TelegramLogin::callback');
$routes->post('logout/telegram', 'TelegramLogin::logout');

// 7 категорий блога (явно — иначе их перехватит корневой catch-all postslug).
$routes->get('devblog', 'Front::category/devblog');
$routes->get('informacija', 'Front::category/informacija');
$routes->get('mestnost', 'Front::category/mestnost');
$routes->get('syre', 'Front::category/syre');
$routes->get('letopis-mira', 'Front::category/letopis-mira');
$routes->get('npc', 'Front::category/npc');
$routes->get('kraft', 'Front::category/kraft');

// Корневой catch-all: одиночный slug → пост или страница. ОБЯЗАТЕЛЬНО последним.
$routes->get('(:segment)', 'Front::resolve/$1');

// 404 → проверка таблицы site_redirects (301/302) перед отдачей 404.
$routes->set404Override('App\Controllers\Errors::notFound');



