<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\EquipmentLoadoutService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * «👕 Броня / Одежда» — список брони, рендерер модели {@see EquipmentLoadoutService}
 * (W2.N1-03, ADR-190).
 */
class GearArmorAction extends BaseAction
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
            // UX-Discoverability (CLAUDE.md): не глухой тупик, а lock-state с
            // объяснением prerequisite + путь к нему. Триггер — жалоба игрока 02.07
            // («скрафтил куртку, а надеть негде»). Уровень — из Config\Buildings (BuildLockService).
            $lock = $loadout['lock'];

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'Markdown',
                'text'         => "🔒 *{$lock['title']}*\n\n"
                    . "Броню и одежду надевают в здании *«Арсенал»* — на твоей базе его пока нет. "
                    . "Скрафтленная броня *никуда не делась и не потеряется*: она ждёт тебя и наденется, "
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

        if ($loadout['armor'] === []) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас нет никакой брони или одежды в «Арсенале».",
            ]);
        }

        $lines = [];
        $keyboardButtons = [];
        foreach ($loadout['armor'] as $i => $item) {
            // WB9 (ADR-137): метка трофея-узла «Метка пустоши» (полный провенанс — в детали).
            $sb = $item['soulbound'] !== null ? ' 🔒' : '';
            $lines[] = ($i + 1) . ") *{$item['name']}* (x{$item['quantity']}){$sb}";
            $keyboardButtons[] = [
                'text'          => "{$sb}{$item['name']}",
                'callback_data' => "gearArmorDetail_{$item['row_id']}",
            ];
        }

        $finalText = "👕 *Раздел Броня / Одежда*\n\n"
            . "Ниже список всей амуниции (брони, одежды) у тебя в наличии:\n\n"
            . implode("\n", $lines) . "\n\n"
            . "_Выбери нужную позицию, чтобы посмотреть детали, надеть или снять._";

        $rows   = array_chunk($keyboardButtons, 2);
        $rows[] = [
            ['text' => '↩️ Назад', 'callback_data' => 'equipMenu']
        ];

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile(base_url('uploads/telegram/craft/standard/all_armor.jpg')),
            'caption'    => $finalText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }
}
