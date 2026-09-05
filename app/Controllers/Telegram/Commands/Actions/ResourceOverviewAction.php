<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Player\ResourceOverviewService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Slice 1 (инициатива «ресурс-грамотность») — экран «📊 Все мои ресурсы».
 *
 * Callback `resourceOverview`. Вход — кнопка на хабе «🎒 Инвентарь» (видна только
 * при killswitch inventory.overview.enabled → dormant = byte-identical). Один экран
 * со ВСЕМИ местами хранения + честный итог по стоимости (включая склад базы) +
 * строка про общий пул на всех базах. Кнопки-дриллдауны в существующие экраны.
 *
 * Read-only (ничего не мутирует). Media-off safe: чистый текст (sendMessage), весь
 * смысл в тексте. Markdown — только парные `*` (легаси parse_mode, без эскейпа).
 */
final class ResourceOverviewAction extends BaseAction
{
    private ResourceOverviewService $overview;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->overview = new ResourceOverviewService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        // Dormant-страховка: прямой callback при выключенном killswitch (кнопки нет, но
        // вдруг старое сообщение) → мягкий отказ, без рендера.
        if (! $this->overview->enabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => 'Этот экран пока недоступен.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $s      = $this->overview->summary($charId);

        $text = "📊 *Все мои ресурсы*\n\n"
            . "Это один общий запас — он у тебя единый, на скольких бы базах ты ни сидел.\n\n"
            . "🎒 *Добытые ресурсы:* " . $this->fmt($s['backpack']['units']) . " шт"
                . " (" . $this->fmt($s['backpack']['kinds']) . " видов, ~" . $this->fmt($s['backpack']['value']) . "💰)\n"
            // Вопрос игрока 18.08.2026: «какая вообще вместимость инвентаря?» Предела
            // сейчас нет вовсе, и об этом нигде не говорилось — называем и вес, и факт.
            . "⚖️ *Вес добычи:* " . $this->fmt($s['backpack']['weight']) . " кг — рюкзак не ограничен\n"
            . "🔨 *Крафтовые предметы:* " . $this->fmt($s['crafted']['units']) . " шт"
                . " (~" . $this->fmt($s['crafted']['value']) . "💰)\n"
            . "⚔️ *Оружие:* " . $this->fmt($s['weapons']) . " · 🛡 *Броня:* " . $this->fmt($s['outfits']) . "\n"
            . "📦 *Склад базы:* " . $this->fmt($s['base_storage']['units']) . " шт"
                . " (~" . $this->fmt($s['base_storage']['value']) . "💰) — кладёшь сам на базе или возит карго-дрон\n"
            . "💰 *Золото:* " . $this->fmt($s['gold']) . "\n"
            . "━━━━━━━━━━\n"
            . "🧮 *Итого по стоимости:* ~" . $this->fmt($s['net_worth']) . "💰\n\n"
            . "Открой раздел, чтобы посмотреть детали 👇";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Добытые ресурсы', 'callback_data' => 'resourcesGathered'],
                    ['text' => '🔨 Крафтовые ресурсы', 'callback_data' => 'resourcesCrafting'],
                ],
                [
                    ['text' => '📦 Склад базы', 'callback_data' => 'baseStorageList'],
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                ],
            ],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function fmt(int $n): string
    {
        return number_format($n, 0, '.', ' ');
    }
}
