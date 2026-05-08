<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Player\CharacterService;
use App\Services\Telegram\CallbackPrefixDispatcher;
use App\Services\Telegram\CallbackRouter;
use Config\CallbackRoutes;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Class CallbackqueryCommand
 * Обработчик всех callback_data, приходящих от inline-кнопок Telegram.
 * Служит точкой входа, чтобы определить, какой Action-Class вызывать.
 *
 * v0.51.78 (decomp Step 2) — 3 routing layers:
 *   1. character inline (TODO Step 3 — investigate redundancy з 'character' mapping)
 *   2. CallbackRouter wildcards (move_dir_*, eventPref_*)
 *   3. CallbackPrefixDispatcher (pollVote_, upgrade_building_, confirm_upgrade_building_, StartRelocationConfirm_)
 *   4. Config\CallbackRoutes::resolve($action) — exact + sellResource* fallback (Step 1)
 */
class CallbackqueryCommand extends SystemCommand
{
    protected $name = 'callbackquery';

    public function execute(): ServerResponse
    {
        $callbackQuery = $this->getCallbackQuery();
        $callbackData  = $callbackQuery->getData();

        $parts  = explode('_', $callbackData);
        $action = $parts[0];

        // 1) character inline shortcut (TODO Step 3: investigate redundancy)
        if ($action === 'character') {
            return $this->handleCharacterShortcut($callbackQuery);
        }

        // 2) CallbackRouter wildcard routing (move_dir_*, eventPref_*)
        $router = (new CallbackRouter())
            ->register('move_dir_*',  \App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction::class)
            ->register('eventPref_*', \App\Controllers\Telegram\Commands\Actions\EventPrefAction::class);
        $routedClass = $router->resolve($callbackData);
        if ($routedClass !== null && class_exists($routedClass)) {
            $handler = new $routedClass($callbackQuery);
            return $handler->handle();
        }

        // 3) Prefix dispatcher (pollVote_, upgrade_building_, confirm_upgrade_building_, StartRelocationConfirm_)
        $prefixDispatcher = new CallbackPrefixDispatcher($this->telegram);
        $prefixResponse   = $prefixDispatcher->tryDispatch($callbackQuery);
        if ($prefixResponse !== null) {
            return $prefixResponse;
        }

        // 4) Config-driven exact + prefix routing
        $handlerClass = $this->getActionHandler($action);
        if ($handlerClass && class_exists($handlerClass)) {
            $handler = new $handlerClass($callbackQuery);
            return $handler->handle();
        }

        return Request::emptyResponse();
    }

    /**
     * 'character' inline shortcut — direct CharacterService call.
     * v0.51.79: confirmed legitimate inline (НЕ redundancy з mapping):
     *   - CharacterService::showCharacterInfo показує equipment + повний stat
     *     sheet (більше ніж dead CharacterAction::handle).
     *   - Inline runs first → CharacterAction класс ніколи не reached →
     *     deleted as dead code v0.51.79.
     * Triggers: callback_data='character' (main "Перс" button) АБО
     * 'character_info' (GuessNumberAction).
     */
    private function handleCharacterShortcut(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery): ServerResponse
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
     * v0.51.77: thin wrapper around Config\CallbackRoutes::resolve().
     * Раніше містив inline-mapping з 140+ entries (lines 144-357 у v0.51.76).
     */
    protected function getActionHandler(string $action): ?string
    {
        /** @var CallbackRoutes $routes */
        $routes = config('CallbackRoutes');
        return $routes->resolve($action);
    }
}
