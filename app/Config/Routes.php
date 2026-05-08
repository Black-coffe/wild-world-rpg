<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Login::new');
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
    $routes->get('quests/delete/(:segment)', 'Admin\QuestController::deleteQuest/$1'); // Delete a quest

    // Добавляем роутинг для объектов мира
    $routes->get('world-objects', 'Admin\WorldObjectController::index'); // Список всех объектов мира
    $routes->get('world-objects/create', 'Admin\WorldObjectController::createObjectForm'); // Форма создания объекта
    $routes->post('world-objects/store', 'Admin\WorldObjectController::storeObject'); // Сохранение нового объекта
    $routes->get('world-objects/edit/(:segment)', 'Admin\WorldObjectController::editObjectForm/$1'); // Форма редактирования объекта
    $routes->post('world-objects/update/(:segment)', 'Admin\WorldObjectController::updateObject/$1'); // Обновление объекта
    $routes->get('world-objects/delete/(:segment)', 'Admin\WorldObjectController::deleteObject/$1'); // Удаление объекта

    // страница сброса персонажа и начальная проверка
    $routes->get('character-reset', 'Admin\CharacterResetController::index');
    $routes->post('character-reset/check', 'Admin\CharacterResetController::check'); // для проверки данных
    // подтверждение и выполнение сброса
    $routes->post('character-reset/confirm', 'Admin\CharacterResetController::confirmReset');
    $routes->post('character-reset/reset-all', 'Admin\CharacterResetController::resetAllCharacters');

    // Роутинг для советов
    $routes->get('tips', 'Admin\GameTipsController::index'); // Список всех советов
    $routes->get('tips/create', 'Admin\GameTipsController::createTipForm'); // Форма создания совета
    $routes->post('tips/store', 'Admin\GameTipsController::storeTip'); // Сохранение нового совета
    $routes->get('tips/edit/(:segment)', 'Admin\GameTipsController::editTipForm/$1'); // Форма редактирования совета
    $routes->post('tips/update/(:segment)', 'Admin\GameTipsController::updateTip/$1'); // Обновление совета
    $routes->get('tips/delete/(:segment)', 'Admin\GameTipsController::deleteTip/$1'); // Удаление совета

    // Роутинг для биомов
    $routes->get('biomes', 'Admin\BiomeController::index');
    $routes->get('biomes/edit/(:segment)', 'Admin\BiomeController::editBiomeForm/$1');
    $routes->post('biomes/update/(:segment)', 'Admin\BiomeController::updateBiome/$1');

    // Добавляем роутинг для ресурсов
    $routes->get('resources', 'Admin\ResourceController::index');
    $routes->get('resources/create', 'Admin\ResourceController::createResourceForm'); // Для отображения формы создания ресурса
    $routes->post('resources/store', 'Admin\ResourceController::storeResource'); // Для сохранения нового ресурса
    $routes->get('resources/edit/(:segment)', 'Admin\ResourceController::editResourceForm/$1'); // Для отображения формы редактирования ресурса
    $routes->post('resources/update/(:segment)', 'Admin\ResourceController::updateResource/$1'); // Для обновления ресурса
    $routes->get('resources/delete/(:segment)', 'Admin\ResourceController::deleteResource/$1'); // Для удаления ресурса

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

// TELEGRAM
$routes->post('telegram/webhook', 'Telegram\BotController::webhook');



