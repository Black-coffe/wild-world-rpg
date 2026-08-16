<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class GearWeaponsAction extends BaseAction
{
    protected $buildingModel;
    protected $charBuildingModel;
    protected $charactersWeaponsModel;
    protected $weaponModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->buildingModel         = new BuildingModel();
        $this->charBuildingModel     = new CharacterBuildingModel();
        $this->charactersWeaponsModel= new CharactersWeaponsModel();
        $this->weaponModel           = new WeaponModel();
    }

    public function handle(): ServerResponse
    {
        // 1) Получаем пользователя + персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден или персонаж не определён.',
            ]);
        }

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 2) Проверяем наличие здания «Арсенал» (или другое название).
        //    Например, в buildingModel name_en = 'Arsenal'.
        $arsenal = $this->buildingModel->where('name_en', 'Arsenal')->first();
        if (!$arsenal) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Здание "Арсенал" не найдено в справочнике.',
            ]);
        }

        $charArsenal = $this->charBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $arsenal['id'])
            ->first();
        if (!$charArsenal) {
            // UX-Discoverability (CLAUDE.md): lock-state вместо глухого тупика —
            // объясняем prerequisite + даём путь к стройке. Зеркало GearArmorAction.
            $arsenalLvl = (new \App\Services\Onboarding\BuildLockService())->requiredLevel('Arsenal');
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'Markdown',
                'text'         => "🔒 *Нужен Арсенал*\n\n"
                    . "Оружие берут в руки (экипируют) в здании *«Арсенал»* — на твоей базе его пока нет. "
                    . "Скрафтленное оружие *никуда не делось*: оно ждёт тебя и экипируется, "
                    . "как только Арсенал будет построен.\n\n"
                    . "Арсенал — постройка позднего этапа: нужен *уровень {$arsenalLvl}* и несколько "
                    . "базовых зданий (Мастерская, Домна, Солнечная станция, Лаборатория).\n\n"
                    . "Жми «🏗 К стройке Арсенала» — там точный список ресурсов и чего пока не хватает.",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => '🏗 К стройке Арсенала', 'callback_data' => 'genericBuildInfo_Arsenal'],
                    ]],
                ]),
            ]);
        }

        // 3) Получаем список оружия из таблицы `characters_weapons`, принадлежащего персонажу
        $weaponRows = $this->charactersWeaponsModel
            ->where('character_id', $character['id'])
            ->findAll();

        if (empty($weaponRows)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас нет никакого оружия в «Арсенале».",
            ]);
        }

        // 4) Формируем текст + кнопки
        $lines = [];
        $keyboardButtons = [];
        $i = 1;

        foreach ($weaponRows as $row) {
            // Ищем описание оружия
            $weapon = $this->weaponModel->find($row['weapon_id']);
            if (!$weapon) {
                continue;
            }

            $name = $weapon['name'] ?? '???';
            $qty  = (int)$row['quantity'];
            // WB9 (ADR-137): метка трофея-узла «Метка пустоши» (полный провенанс — в детали).
            $sb   = !empty($row['is_soulbound']) ? ' 🔒' : '';

            // Собираем строку для списка
            $lines[] = "{$i}) *{$name}* (x{$qty}){$sb}";

            // Кнопка для подробного описания / экипировки
            $keyboardButtons[] = [
                'text'          => "{$sb}{$name}",
                'callback_data' => "gearWeaponDetail_{$row['id']}",
            ];
            $i++;
        }

        // «Шапка» текста
        $intro = "⚔️ *Раздел Оружие*\n\n"
            . "Ниже перечень всего оружия, которое сейчас есть у тебя в арсенале:\n\n";
        $listText = implode("\n", $lines);
        $finalText = $intro . $listText . "\n\n"
            . "_Нажми на нужное, чтобы посмотреть детали, взять в руки или снять._";

        // Разбиваем кнопки по 2 в ряд
        $rows = array_chunk($keyboardButtons, 2);

        // Добавляем кнопку «Назад» (ведёт в меню «GearAction» или куда угодно)
        $rows[] = [
            ['text' => '↩️ Назад', 'callback_data' => 'equipMenu']
        ];

        $keyboard = ['inline_keyboard' => $rows];

        // Картинка (например, склад оружия)
        $imagePath = base_url('uploads/telegram/craft/standard/all_weapons.jpg');

        // Убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Отправляем сообщение: фото + текст
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $finalText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
