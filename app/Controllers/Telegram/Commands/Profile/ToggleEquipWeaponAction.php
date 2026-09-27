<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\EquipmentLoadoutService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * «Надеть / Снять» оружие — тонкий рендерер {@see EquipmentLoadoutService::toggle()}
 * (W2.N1-03, ADR-190). Все проверки и атомарная смена — в сервисе, его же зовёт веб; здесь —
 * только тексты исходов, прежние по смыслу.
 */
class ToggleEquipWeaponAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId       = $this->callbackQuery->getMessage()->getChat()->getId();
        $callbackData = $this->callbackQuery->getData();
        // Ожидаем формат "toggleEquipWeapon_{id}"
        if (!preg_match('/^toggleEquipWeapon_(\d+)$/', $callbackData, $matches)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Некорректные данные для переключения экипировки.',
            ]);
        }

        $charWeaponId = (int) $matches[1];

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден или не зарегистрирован.',
            ]);
        }

        $charId  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $outcome = (new EquipmentLoadoutService())->toggle($charId, EquipmentLoadoutService::KIND_WEAPON, $charWeaponId);
        $item    = $outcome['item'];

        if (! $outcome['ok'] || $item === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => EquipmentLoadoutService::refusal(EquipmentLoadoutService::KIND_WEAPON, $outcome['code'], $item['name'] ?? 'Неизвестное оружие'),
            ]);
        }

        $weaponName = $item['name'];
        $weaponInfo = $item['info'];
        if ($outcome['code'] === EquipmentLoadoutService::UNEQUIPPED) {
            $actionText = "Ты *снял* оружие:\n`{$weaponName}`";
            $extraNote  = "Теперь оно лежит в твоём арсенале.";
        } else {
            $actionText = "Ты успешно *экипировал* оружие:\n`{$weaponName}`";
            $extraNote  = "Все остальные оружия сняты. Теперь {$weaponName} числится на тебе.";
        }

        // Прочность — характеристика предмета, без дроби: см. GearWeaponDetailAction.
        $shortStats = sprintf(
            "💥 Урон: %d\n🔎 Тип урона: %s\n⚙️ Прочность: %d",
            is_numeric($weaponInfo['damage_value'] ?? null) ? (int) $weaponInfo['damage_value'] : 0,
            is_scalar($weaponInfo['damage_type'] ?? null) ? (string) $weaponInfo['damage_type'] : 'physical',
            is_numeric($weaponInfo['durability_max'] ?? null) ? (int) $weaponInfo['durability_max'] : 100
        );

        $text = "⚔️ *Экипировка оружия*\n\n"
            . "{$actionText}\n\n"
            . "{$shortStats}\n\n"
            . "{$extraNote}\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => $item['equipped'] ? 'Снять' : 'Надеть', 'callback_data' => "toggleEquipWeapon_{$charWeaponId}"],
                    ['text' => '⚔️ Экип', 'callback_data' => 'equipMenu'],
                ],
            ],
        ];

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
