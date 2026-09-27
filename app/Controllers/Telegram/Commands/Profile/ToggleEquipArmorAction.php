<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\EquipmentLoadoutService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * «Надеть / Снять» броню — тонкий рендерер {@see EquipmentLoadoutService::toggle()}
 * (W2.N1-03, ADR-190). Сервис гейтит Арсенал у персонажа так же, как оружие (раньше здесь
 * проверялся только справочник зданий — вход открывал лишь экран списка), и снимает соседей по
 * слоту одним UPDATE.
 */
class ToggleEquipArmorAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId       = $this->callbackQuery->getMessage()->getChat()->getId();
        $callbackData = $this->callbackQuery->getData();

        // Проверяем формат callback_data
        if (!preg_match('/^toggleEquipArmor_(\d+)$/', $callbackData, $matches)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Некорректные данные для экипировки брони.',
            ]);
        }

        $charOutfitId = (int) $matches[1];

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден.',
            ]);
        }

        $charId  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $outcome = (new EquipmentLoadoutService())->toggle($charId, EquipmentLoadoutService::KIND_ARMOR, $charOutfitId);
        $item    = $outcome['item'];

        if (! $outcome['ok'] || $item === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => EquipmentLoadoutService::refusal(EquipmentLoadoutService::KIND_ARMOR, $outcome['code'], $item['name'] ?? ''),
            ]);
        }

        $outfitName = $item['name'];
        $info       = $item['info'];
        $slot       = (string) $item['slot'];
        $field      = static fn (string $key, string $default): string => is_scalar($info[$key] ?? null) ? (string) $info[$key] : $default;

        if ($outcome['code'] === EquipmentLoadoutService::UNEQUIPPED) {
            $message = "Ты *снял* броню: `{$outfitName}`.\n\n";
        } else {
            $message  = "Ты *надел* броню: `{$outfitName}`.\n\n";
            $message .= "Все другие предметы в слоте *{$slot}* были сняты.\n\n";
        }

        // Прочность — характеристика предмета, без дроби: см. GearWeaponDetailAction.
        $message .= "📜 *Характеристики экипировки:*\n"
            . "• Тип: `{$field('armor_type', 'Обычная')}`\n"
            . "• 🛡 Защита: *{$field('armor_value', '0')}*\n"
            . "• 🏋️ Вес: *{$field('weight', '0')} кг*\n"
            . "• 🔧 Прочность: *{$field('durability_max', '100')}*\n"
            . "• 🩸 Физ. защита: *{$field('physical_resistance', '0')}%*\n"
            . "• 🔥 Огонь: *{$field('fire_resistance', '0')}%*\n"
            . "• ☣️ Яд: *{$field('poison_resistance', '0')}%*\n"
            . "• 👣 Скорость: *{$field('speed_modifier', '0')}*\n"
            . "• 🕵️ Скрытность: *{$field('stealth_modifier', '0')}*\n"
            . "• 🎖 Редкость: *{$field('rarity', 'Common')}*\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => $item['equipped'] ? 'Снять' : 'Надеть', 'callback_data' => "toggleEquipArmor_{$charOutfitId}"],
                    ['text' => '👕 Броня / Одежда', 'callback_data' => 'gearArmor'],
                ],
            ],
        ];

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $message,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
