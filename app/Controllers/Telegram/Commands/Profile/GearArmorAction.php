<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GearArmorAction extends BaseAction
{
    protected $buildingModel;
    protected $charBuildingModel;
    protected $charactersOutfitsModel;
    protected $outfitModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->buildingModel        = new BuildingModel();
        $this->charBuildingModel    = new CharacterBuildingModel();
        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->outfitModel          = new OutfitModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден или персонаж не определён.',
            ]);
        }

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 1) Проверяем наличие здания "Арсенал" (Arsenal) у персонажа
        //    (или как вы его называете в buildingModel->name_en)
        $arsenal = $this->buildingModel->where('name_en', 'Arsenal')->first();
        if (!$arsenal) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Здание "Арсенал" не найдено в справочнике buildingModel.',
            ]);
        }
        $charArsenal = $this->charBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $arsenal['id'])
            ->first();
        if (!$charArsenal) {
            // UX-Discoverability (CLAUDE.md): не глухой тупик, а lock-state с
            // объяснением prerequisite + путь к нему. Триггер — жалоба игрока 02.07
            // («скрафтил куртку, а надеть негде»). Уровень тянем динамически из
            // Config\Buildings (BuildLockService) — без хардкода/дрейфа.
            $arsenalLvl = (new \App\Services\Onboarding\BuildLockService())->requiredLevel('Arsenal');
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'parse_mode'   => 'Markdown',
                'text'         => "🔒 *Нужен Арсенал*\n\n"
                    . "Броню и одежду надевают в здании *«Арсенал»* — на твоей базе его пока нет. "
                    . "Скрафтленная броня *никуда не делась и не потеряется*: она ждёт тебя и наденется, "
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

        // 2) Ищем всю броню/одежду, которая есть у игрока (slot='body', 'head', 'legs' и т.д.).
        //    Можно фильтровать именно ту, которая armor_type != NULL (чтобы не показывать оружие).
        //    Но в данном примере просто выведем всё, у чего 'slot' = 'body' или вообще всё,
        //    а проверять будем по armor_type.
        $outfitRows = $this->charactersOutfitsModel
            ->where('character_id', $character['id'])
            ->findAll();

        if (empty($outfitRows)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => "У вас нет никакой брони или одежды в «Арсенале».",
            ]);
        }

        // Формируем массив для текстового списка, а также массив для кнопок
        $lines = [];
        $keyboardButtons = [];

        // Небольшой счётчик, чтобы пронумеровать предметы
        $i = 1;

        foreach ($outfitRows as $row) {
            // Ищем описание в таблице outfits
            $outfit = $this->outfitModel->find($row['outfit_id']);
            if (!$outfit) {
                continue; // если вдруг запись не найдена
            }

            // Проверяем, действительно ли это броня/одежда (например, outfit->slot='body' или armor_type != '???')
            // Если нужно вывести только броню, можно проверить:
            // if (!in_array($outfit['armor_type'], ['Light','Medium','Heavy'])) { continue; }

            // Название + количество
            $name  = $outfit['name'] ?? '???';
            $qty   = (int)$row['quantity'];
            // WB9 (ADR-137): метка трофея-узла «Метка пустоши» (полный провенанс — в детали).
            $sb    = !empty($row['is_soulbound']) ? ' 🔒' : '';
            $lines[] = "{$i}) *{$name}* (x{$qty}){$sb}";

            // Готовим кнопку (при нажатии — переход к подробному описанию?)
            // Допустим, колбэк 'gearArmorDetail_{id_in_characters_outfits}'
            $keyboardButtons[] = [
                'text'          => "{$sb}{$name}",
                'callback_data' => "gearArmorDetail_{$row['id']}"
            ];

            $i++;
        }

        // Собираем текстовое описание
        $intro = "👕 *Раздел Броня / Одежда*\n\n"
            . "Ниже список всей амуниции (брони, одежды) у тебя в наличии:\n\n";

        $listText = implode("\n", $lines);
        $finalText = $intro . $listText . "\n\n"
            . "_Выбери нужную позицию, чтобы посмотреть детали, надеть или снять._";

        // Разделяем кнопки по строкам — по 2 в ряд, например
        $rows = array_chunk($keyboardButtons, 2);

        // Добавим кнопку "Назад" (или куда хотите)
        $rows[] = [
            ['text'=>'↩️ Назад','callback_data'=>'equipMenu']
        ];

        $keyboard = ['inline_keyboard' => $rows];

        // Картинка перед текстом (например, склад брони)
        $imagePath = base_url('uploads/telegram/craft/standard/all_armor.jpg');

        // Убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Отправляем сообщение (фото + подпись)
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $finalText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
