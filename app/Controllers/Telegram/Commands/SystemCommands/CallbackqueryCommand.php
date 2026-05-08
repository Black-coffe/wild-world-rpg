<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Player\CharacterService;
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
 * v0.51.77 (decomp Step 1) — giant action→class mapping (140+ entries)
 * перенесено у Config\CallbackRoutes. getActionHandler() тепер 1-line config lookup.
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

        // Если action==='character' — показываем персонажа через сервис
        if ($action === 'character') {
            $chatId = $callbackQuery->getMessage()->getChat()->getId();
            $from   = $callbackQuery->getFrom();
            $tid    = $from->getId();

            $userModel  = new TelegramUserModel();
            $userRow    = $userModel->where('telegram_id', $tid)->first();
            if (!$userRow) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'User not found!',
                ]);
            }

            $charModel    = new CharacterModel();
            $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
            if (!$characterRow) {
                return Request::sendMessage([
                    'chat_id' => $chatId,
                    'text'    => 'Character not found!',
                ]);
            }

            $charService = new CharacterService();
            return $charService->showCharacterInfo($chatId, $characterRow);
        }

        // F2.5 wire-in: wildcard routing через CallbackRouter.
        // Pattern 'move_dir_*' матчит move_dir_north, move_dir_south, move_dir_east, move_dir_west.
        // F7.5b: 'eventPref_*' матчить eventPref_mute_1h + eventPref_muteKind_<kind> +
        // eventPref_unmute. Handler сам парсить суфікс.
        $router = (new CallbackRouter())
            ->register('move_dir_*',  \App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction::class)
            ->register('eventPref_*', \App\Controllers\Telegram\Commands\Actions\EventPrefAction::class);
        $routedClass = $router->resolve($callbackData);
        if ($routedClass !== null && class_exists($routedClass)) {
            $handler = new $routedClass($callbackQuery);
            return $handler->handle();
        }

        if (strpos($callbackData, 'pollVote_') === 0) {
            // Ожидаем формат: pollVote_{pollId}_{answerId}
            $parts = explode('_', $callbackData);
            if (count($parts) >= 3) {
                $pollId   = (int) $parts[1];
                $answerId = (int) $parts[2];

                $handlerClass = \App\Controllers\Telegram\Commands\Actions\Poll\PollVoteAction::class;
                if (class_exists($handlerClass)) {
                    $handler = new $handlerClass($this->getCallbackQuery());
                    return $handler->handleVote($pollId, $answerId);
                }
            }
            return Request::emptyResponse();
        }

        if (strpos($callbackData, 'upgrade_building_') === 0) {
            // Шаг 1: показать требования и спросить подтверждение
            $handlerClass = \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction::class;
            if (class_exists($handlerClass)) {
                $handler = new $handlerClass($this->getCallbackQuery());
                return $handler->askForUpgrade();
            }
        }

        if (strpos($callbackData, 'confirm_upgrade_building_') === 0) {
            // Шаг 2: действительно списываем и повышаем уровень
            $handlerClass = \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction::class;
            if (class_exists($handlerClass)) {
                $handler = new $handlerClass($this->getCallbackQuery());
                return $handler->confirmUpgrade();
            }
        }

        if (strpos($callbackData, 'StartRelocationConfirm_') === 0) {
            Request::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
            ]);

            $cmd = new \App\Controllers\Telegram\Commands\BaseShiftingCommand(
                $this->telegram,
                null
            );
            return $cmd->handleCallback($callbackQuery);
        }

        // Иначе — прочие action через config-driven routing
        $handlerClass = $this->getActionHandler($action);
        if ($handlerClass && class_exists($handlerClass)) {
            $handler = new $handlerClass($callbackQuery);
            return $handler->handle();
        }

        return Request::emptyResponse();
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
