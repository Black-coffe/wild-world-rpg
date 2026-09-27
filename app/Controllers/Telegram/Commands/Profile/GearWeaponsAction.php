<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\EquipmentLoadoutService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * «⚔️ Оружие» — список оружия, рендерер модели {@see EquipmentLoadoutService} (W2.N1-03, ADR-190).
 */
class GearWeaponsAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден или персонаж не определён.',
            ]);
        }

        $chatId  = $this->callbackQuery->getMessage()->getChat()->getId();
        $charId  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $loadout = (new EquipmentLoadoutService())->forCharacter($charId);

        if ($loadout['lock'] !== null) {
            // UX-Discoverability (CLAUDE.md): lock-state вместо глухого тупика —
            // объясняем prerequisite + даём путь к стройке. Зеркало GearArmorAction.
            $lock = $loadout['lock'];

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'Markdown',
                'text'         => "🔒 *{$lock['title']}*\n\n"
                    . "Оружие берут в руки (экипируют) в здании *«Арсенал»* — на твоей базе его пока нет. "
                    . "Скрафтленное оружие *никуда не делось*: оно ждёт тебя и экипируется, "
                    . "как только Арсенал будет построен.\n\n"
                    . "Арсенал — постройка позднего этапа: нужен *уровень {$lock['required_level']}* и несколько "
                    . "базовых зданий (Мастерская, Домна, Солнечная станция, Лаборатория).\n\n"
                    . "Жми «{$lock['button']}» — там точный список ресурсов и чего пока не хватает.",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => $lock['button'], 'callback_data' => $lock['callback']],
                    ]],
                ]),
            ]);
        }

        if ($loadout['weapons'] === []) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас нет никакого оружия в «Арсенале».",
            ]);
        }

        $lines = [];
        $keyboardButtons = [];
        foreach ($loadout['weapons'] as $i => $item) {
            // WB9 (ADR-137): метка трофея-узла «Метка пустоши» (полный провенанс — в детали).
            $sb = $item['soulbound'] !== null ? ' 🔒' : '';
            $lines[] = ($i + 1) . ") *{$item['name']}* (x{$item['quantity']}){$sb}";
            $keyboardButtons[] = [
                'text'          => "{$sb}{$item['name']}",
                'callback_data' => "gearWeaponDetail_{$item['row_id']}",
            ];
        }

        $finalText = "⚔️ *Раздел Оружие*\n\n"
            . "Ниже перечень всего оружия, которое сейчас есть у тебя в арсенале:\n\n"
            . implode("\n", $lines) . "\n\n"
            . "_Нажми на нужное, чтобы посмотреть детали, взять в руки или снять._";

        $rows   = array_chunk($keyboardButtons, 2);
        $rows[] = [
            ['text' => '↩️ Назад', 'callback_data' => 'equipMenu']
        ];

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile(base_url('uploads/telegram/craft/standard/all_weapons.jpg')),
            'caption'    => $finalText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }
}
