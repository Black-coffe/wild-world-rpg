<?php

declare(strict_types=1);

namespace App\Services\Craft;

use App\Entities\CharacterEntity;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Точка старта Tier-2 (`shortageScreen` в семи `Start*2Action` крафта брони и
 * телепорт-маяков `WorkbenchStandard`) была скопирована в каждый класс почти
 * дословно — расходились только источник количества (`$this->quantity` у
 * брони, литерал `1` у телепортов — там нет своей "×N" ветки) и то, отвечает
 * ли класс на callback_query здесь или уже ответил раньше в `handle()`.
 * Этот помощник несёт общую часть: вызов `CraftShortageService::describe()` и
 * отправку экрана. Fallback на случай выключенного killswitch'а
 * (`craft.shortage_hint.enabled=false`) остаётся за вызывающим классом —
 * тексты и клавиатуры fallback'а у семёрки разные (у части — свои кнопки
 * навигации), унифицировать их не просили.
 *
 * По образцу `CraftCardHelper` (story craft-shortfall-buy-01): помощник
 * вызывается явно из каждого класса, а не через общего предка — у семёрки
 * нет общего базового класса под этот метод.
 */
final class CraftShortageScreenHelper
{
    private CraftShortageService $shortage;

    public function __construct(?CraftShortageService $shortage = null)
    {
        $this->shortage = $shortage ?? new CraftShortageService();
    }

    /**
     * @param array<string,mixed>|CharacterEntity                          $character
     * @param array<string,array{need:int,have:int,name:string}>           $missingResources
     * @param array<string,array{need:int,have:int,name:string}>           $missingItems
     * @param array<string,mixed>                                          $recipe
     * @param callable():ServerResponse                                    $fallback вызывается только когда
     *                                                                      `CraftShortageService::isEnabled()` === false
     */
    public function render(
        array|CharacterEntity $character,
        array $missingResources,
        array $missingItems,
        int $quantity,
        array $recipe,
        int|string $chatId,
        callable $fallback,
        ?string $callbackQueryId = null
    ): ServerResponse {
        // Часть вызывающих классов уже сняла "часики" в начале handle() и
        // передаёт null — повторный answerCallbackQuery() здесь не нужен.
        if ($callbackQueryId !== null) {
            Request::answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
        }

        if (! $this->shortage->isEnabled()) {
            return $fallback();
        }

        $screen = $this->shortage->describe($character, $missingResources, $missingItems, $quantity, $recipe);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $screen['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($screen['keyboard']),
        ]);
    }
}
