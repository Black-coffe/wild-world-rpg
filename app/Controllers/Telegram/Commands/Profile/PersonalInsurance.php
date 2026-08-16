<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class PersonalInsurance extends BaseAction
{
    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterModel = new CharacterModel();
    }

    /**
     * Сформировать payload «страница страховки» (текст + клавиатура).
     * Выделено в static — переиспользуется {@see ToggleInsuranceAction} для
     * edit-in-place после переключения статуса (fix-2026-05-13: раньше Toggle
     * отправлял отдельное короткое сообщение и не обновлял исходный экран →
     * игрок не видел, какая кнопка теперь активна, кликал повторно).
     *
     * @param array<int|string, mixed>|CharacterEntity $character
     * @return array{chat_id:int, text:string, parse_mode:string, reply_markup:string}
     */
    public static function buildView(array|CharacterEntity $character, int $chatId): array
    {
        $hasInsurance = (bool) ($character['insurance'] ?? false);
        $name         = (string) ($character['name'] ?? 'Выживший');

        $text = "👤 *Персонаж: {$name}*\n\n"
            . ($hasInsurance
                ? "✅ У вашего персонажа *есть* страховка от смерти.\n\n"
                : "❌ У вашего персонажа *нет* страховки от смерти.\n\n")
            . "🛡 *Информация о страховке:*\n"
            . "• Включенная страховка позволяет в случае смерти избежать полного обнуления всего, что есть у персонажа.\n"
            . "• Сохраняется прокачка, инвентарь и все остальное.\n"
            . "• При смерти персонаж просто возродится на своей базе.\n\n"
            . "💰 *Стоимость страховки:*\n"
            . "• Рассчитывается автоматически для каждого игрока.\n"
            . "• Уникальная цена определяется в момент смерти.\n"
            . "• Зависит от множества факторов и обстоятельств.\n";

        $toggleLabel = $hasInsurance ? '❌ Отключить страховку' : '🛡 Включить страховку';

        $inlineRows = [
            [
                ['text' => $toggleLabel,    'callback_data' => 'toggleInsurance'],
                ['text' => '🧮 Просчет',    'callback_data' => 'calculateInsurance'],
            ],
            // V24 (ADR-056): селективная страховка крафта — pre-paid вечный полис
            // на дорогие предметы (robots/workbench/transport). Альтернатива
            // pay-on-death страховке всего персонажа.
            [
                ['text' => '📦 Крафт-страховка', 'callback_data' => 'craftInsuranceList'],
            ],
        ];

        // ADR-150 Слайс 2: возврат на карточку «Я» (чинит тупик Страховки). Только при me_hub ON.
        if (\App\Services\Telegram\BotMenuService::meHubEnabled()) {
            $inlineRows[] = [['text' => '◀️ Я', 'callback_data' => 'character']];
        }

        $keyboard = ['inline_keyboard' => $inlineRows];

        return [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();

        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::sendMessage(self::buildView($character, $chatId));
    }
}
