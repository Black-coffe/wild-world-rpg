<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Player\CharacterService;
use App\Services\Telegram\CallbackPrefixDispatcher;
use App\Services\Telegram\CallbackRouter;
use Config\CallbackRoutes;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Class CallbackqueryCommand
 * Обработчик всех callback_data, приходящих от inline-кнопок Telegram.
 * Точка входа для определения, какой Action-Class вызывать.
 *
 * v0.51.80 (decomp 5/5 closed) — orchestrator з 4 routing layers:
 *   1. character inline shortcut → CharacterService::showCharacterInfo
 *      (повний stat sheet + equipment, см. handleCharacterShortcut docstring)
 *   2. CallbackRouter wildcards (`move_dir_*`, `eventPref_*`) — config-driven
 *   3. CallbackPrefixDispatcher (pollVote_, upgrade_building_,
 *      confirm_upgrade_building_, StartRelocationConfirm_)
 *   4. Config\CallbackRoutes::resolve($action) — exact + sellResource*
 *
 * Source of routing config: app/Config/CallbackRoutes.php
 */
class CallbackqueryCommand extends SystemCommand
{
    protected $name = 'callbackquery';

    public function execute(): ServerResponse
    {
        $callbackQuery = $this->getCallbackQuery();
        $callbackData  = $callbackQuery->getData();
        $action        = explode('_', $callbackData)[0];

        // 1) character inline shortcut
        if ($action === 'character') {
            return $this->handleCharacterShortcut($callbackQuery);
        }

        // 2) Wildcard routing (move_dir_*, eventPref_*)
        if ($response = $this->tryWildcardRoute($callbackQuery, $callbackData)) {
            return $response;
        }

        // 3) Prefix dispatcher (pollVote_, upgrade_building_, etc.)
        if ($response = (new CallbackPrefixDispatcher($this->telegram))->tryDispatch($callbackQuery)) {
            return $response;
        }

        // 4) Config-driven exact + prefix routing
        if ($response = $this->tryExactRoute($callbackQuery, $action)) {
            return $response;
        }

        return Request::emptyResponse();
    }

    /**
     * Wildcard routing шар — Config\CallbackRoutes::$wildcardRoutes через CallbackRouter.
     */
    private function tryWildcardRoute(CallbackQuery $callbackQuery, string $callbackData): ?ServerResponse
    {
        /** @var CallbackRoutes $routes */
        $routes = config('CallbackRoutes');

        $router      = (new CallbackRouter())->registerMany($routes->wildcardRoutes);
        $routedClass = $router->resolve($callbackData);

        if ($routedClass !== null && class_exists($routedClass)) {
            return (new $routedClass($callbackQuery))->handle();
        }
        return null;
    }

    /**
     * Exact-match + sellResource prefix routing шар.
     */
    private function tryExactRoute(CallbackQuery $callbackQuery, string $action): ?ServerResponse
    {
        $handlerClass = $this->getActionHandler($action);
        if ($handlerClass && class_exists($handlerClass)) {
            return (new $handlerClass($callbackQuery))->handle();
        }
        return null;
    }

    /**
     * 'character' inline shortcut — direct CharacterService call.
     * v0.51.79: confirmed legitimate inline (НЕ redundancy з mapping):
     *   - CharacterService::showCharacterInfo показує equipment + повний
     *     stat sheet (більше ніж dead CharacterAction::handle).
     *   - Inline runs first → CharacterAction класс ніколи не reached →
     *     deleted as dead code v0.51.79.
     * Triggers: callback_data='character' (main "Перс" button) АБО
     * 'character_info' (GuessNumberAction).
     */
    private function handleCharacterShortcut(CallbackQuery $callbackQuery): ServerResponse
    {
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $tid    = $callbackQuery->getFrom()->getId();

        $userRow = (new TelegramUserModel())->where('telegram_id', $tid)->first();
        if (!$userRow) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'User not found!']);
        }

        $characterRow = (new CharacterModel())->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Character not found!']);
        }

        return (new CharacterService())->showCharacterInfo($chatId, $characterRow);
    }

    /**
     * Thin wrapper around Config\CallbackRoutes::resolve().
     * v0.51.77: раніше містив inline-mapping з 140+ entries.
     */
    protected function getActionHandler(string $action): ?string
    {
        /** @var CallbackRoutes $routes */
        $routes = config('CallbackRoutes');
        return $routes->resolve($action);
    }
}
