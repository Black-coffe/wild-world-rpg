<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Services\Player\EquipmentLoadoutService;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Controllers\Telegram\Commands\Actions\BaseAction;

/**
 * «⚔️ Экип» — хаб снаряжения, рендерер модели {@see EquipmentLoadoutService} (W2.N1-03, ADR-190).
 *
 * Без Арсенала хаб сразу говорит об этом: кнопки разделов несут замок «(нужно: Арсенал)», рядом —
 * путь к стройке (UX-DISCOVERABILITY: lock-state, а не ошибка после тапа). Разделы по-прежнему
 * открываются и объясняют prerequisite подробно.
 */
class GearAction extends BaseAction
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

        $charId  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $service = new EquipmentLoadoutService();
        $lock    = $service->hasArsenal($charId) ? null : EquipmentLoadoutService::arsenalLock();

        $keyboard = ['inline_keyboard' => self::keyboard($lock)];

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile(base_url('uploads/telegram/gear/equipped_hero.png')),
            'caption'    => self::caption($lock),
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Подпись хаба (media-off: весь смысл в тексте).
     *
     * @param array{title:string, required_level:int, callback:string, button:string}|null $lock
     */
    public static function caption(?array $lock): string
    {
        if ($lock !== null) {
            return "⚔️ *Раздел экипировки*\n\n"
                . "🔒 *{$lock['title']}* — надевать броню и брать в руки оружие можно только в здании "
                . "*«Арсенал»*, а на твоей базе его пока нет. Всё скрафтленное *никуда не делось*: "
                . "оно ждёт и наденется, как только Арсенал будет построен.\n\n"
                . "Арсенал — постройка позднего этапа: нужен *уровень {$lock['required_level']}* и несколько "
                . "базовых зданий. Жми «{$lock['button']}» — там точный список ресурсов.\n";
        }

        return "⚔️ *Раздел экипировки*\n\n"
            . "Ты находишься в своём *Арсенале* — здесь можно просмотреть:\n"
            . "• Что у тебя есть из брони/одежды\n"
            . "• Наличие оружия (ближнего и дальнего боя)\n"
            . "• Текущую экипировку: что уже надето на твоё тело\n\n"
            . "Выбери нужный пункт, чтобы увидеть подробности или изменить экипировку.\n\n"
            . "⚠️ *Внимание!* Убедись, что у тебя достаточно места, и помни о весе доспехов — "
            . "перегруз может негативно сказаться на выносливости.\n";
    }

    /**
     * Две кнопки в ряд, без одиночек.
     *
     * @param array{title:string, required_level:int, callback:string, button:string}|null $lock
     *
     * @return list<list<array{text:string, callback_data:string}>>
     */
    public static function keyboard(?array $lock): array
    {
        if ($lock === null) {
            return [
                [
                    ['text' => '👕 Броня / Одежда', 'callback_data' => 'gearArmor'],
                    ['text' => '⚔️ Оружие',         'callback_data' => 'gearWeapons'],
                ],
                [
                    ['text' => '⬅️ Назад', 'callback_data' => 'character'],
                ],
            ];
        }

        return [
            [
                ['text' => '🔒 👕 Броня (нужно: Арсенал)',  'callback_data' => 'gearArmor'],
                ['text' => '🔒 ⚔️ Оружие (нужно: Арсенал)', 'callback_data' => 'gearWeapons'],
            ],
            [
                ['text' => $lock['button'], 'callback_data' => $lock['callback']],
                ['text' => '⬅️ Назад',      'callback_data' => 'character'],
            ],
        ];
    }
}
