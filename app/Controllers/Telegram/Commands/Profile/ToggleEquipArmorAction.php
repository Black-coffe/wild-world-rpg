<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

class ToggleEquipArmorAction extends BaseAction
{
    protected $buildingModel;
    protected $charBuildingModel;
    protected $charactersOutfitsModel;
    protected $outfitModel;

    // Для примера — добавим модели, чтобы проверить "на базе ли мы"
    protected $claimedCellModel;
    protected $characterModel;
    protected $mapModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->buildingModel           = new BuildingModel();
        $this->charBuildingModel       = new CharacterBuildingModel();
        $this->charactersOutfitsModel  = new CharactersOutfitsModel();
        $this->outfitModel             = new OutfitModel();

        // Если хотите пользоваться PlayerStateService, подключите его вместо ручного кода:
        $this->claimedCellModel   = new ClaimedCellModel();
        $this->characterModel     = new CharacterModel();
        $this->mapModel           = new MapModel();
    }

    public function handle(): ServerResponse
    {
        $callbackData = $this->callbackQuery->getData();

        // Проверяем формат callback_data
        if (!preg_match('/^toggleEquipArmor_(\d+)$/', $callbackData, $matches)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Некорректные данные для экипировки брони.',
            ]);
        }

        $charOutfitId = (int) $matches[1];

        // Загружаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Персонаж не найден.',
            ]);
        }

        // Проверяем наличие здания "Арсенал" у персонажа
        $arsenal = $this->buildingModel->where('name_en', 'Arsenal')->first();
        if (!$arsenal) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Ты можешь менять экипировку только в здании "Арсенал".',
            ]);
        }

        // Ищем запись о конкретном предмете в characters_outfits
        $outfitRow = $this->charactersOutfitsModel->find($charOutfitId);
        if (
            !$outfitRow
            || (int) $outfitRow['character_id'] !== (int) $character['id']
        ) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Броня не найдена в инвентаре.',
            ]);
        }

        // Получаем инфо о самой броне (OutfitModel)
        $outfitInfo = $this->outfitModel->find($outfitRow['outfit_id']);
        if (!$outfitInfo || empty($outfitInfo['name'])) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Информация об экипировке не найдена в справочнике.',
            ]);
        }

        // Основные поля
        $outfitName       = $outfitInfo['name'];
        $slot             = $outfitInfo['slot'] ?? null;
        $currentlyEquipped= (bool)$outfitRow['equipped'];

        // Защита от ошибок, если в БД у вещи не указан слот
        if (!$slot) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "Невозможно экипировать «{$outfitName}» — слот не задан.",
            ]);
        }

        // Характеристики брони/одежды
        $armorType     = $outfitInfo['armor_type']          ?? 'Обычная';
        $armorValue    = $outfitInfo['armor_value']         ?? 0;
        $physRes       = $outfitInfo['physical_resistance'] ?? 0;
        $fireRes       = $outfitInfo['fire_resistance']     ?? 0;
        $poisonRes     = $outfitInfo['poison_resistance']   ?? 0;
        $speedMod      = $outfitInfo['speed_modifier']      ?? 0;
        $stealthMod    = $outfitInfo['stealth_modifier']    ?? 0;
        $weight        = $outfitInfo['weight']              ?? 0;
        $rarity        = $outfitInfo['rarity']              ?? 'Common';
        // Прочность — характеристика предмета, без дроби: см. GearWeaponDetailAction.
        $durabilityMax = $outfitInfo['durability_max']      ?? 100;

        // Логика (снять/надеть)
        if ($currentlyEquipped) {
            // Снимаем (можно где угодно)
            $this->charactersOutfitsModel->update($charOutfitId, ['equipped' => 0]);
            $message = "Ты *снял* броню: `{$outfitName}`.\n\n";
        } else {
            // WB9 (ADR-137): soulbound-трофей «Метка пустоши» нельзя надеть — коллекционный знак,
            // не активная экипировка. Усиливает ТОЛЬКО против узлов (raid-only), PvP не трогает
            // (портрет П4). Гейт против надевания = защита от PvP-power-creep.
            if (!empty($outfitRow['is_soulbound'])) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => "🔒 «{$outfitName}» — это Метка пустоши, трофей с узла. Её нельзя надеть: она и так усиливает тебя в бою с узлами, но не носится и не продаётся.",
                ]);
            }

            // Если пытаемся надеть, то сначала проверяем, находится ли игрок физически на базе
            if (!$this->isOnBase($character['id'])) {
                // Если НЕ на базе, выдаём запрет
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => "Снаряжение хранится на базе.\nТы не на базе, не можешь надеть \"{$outfitName}\"!",
                ]);
            }

            // Перед надеванием снимаем другие вещи в том же слоте
            $this->charactersOutfitsModel
                ->where('character_id', $character['id'])
                ->where('slot', $slot)
                ->set(['equipped' => 0])
                ->update();

            // Экипируем выбранный предмет
            $this->charactersOutfitsModel->update($charOutfitId, ['equipped' => 1]);
            $message  = "Ты *надел* броню: `{$outfitName}`.\n\n";
            $message .= "Все другие предметы в слоте *{$slot}* были сняты.\n\n";
        }

        // Выводим основные характеристики
        $message .= "📜 *Характеристики экипировки:*\n"
            . "• Тип: `{$armorType}`\n"
            . "• 🛡 Защита: *{$armorValue}*\n"
            . "• 🏋️ Вес: *{$weight} кг*\n"
            . "• 🔧 Прочность: *{$durabilityMax}*\n"
            . "• 🩸 Физ. защита: *{$physRes}%*\n"
            . "• 🔥 Огонь: *{$fireRes}%*\n"
            . "• ☣️ Яд: *{$poisonRes}%*\n"
            . "• 👣 Скорость: *{$speedMod}*\n"
            . "• 🕵️ Скрытность: *{$stealthMod}*\n"
            . "• 🎖 Редкость: *{$rarity}*\n";

        // Обновляем текст кнопки (если сняли — будет "Одеть", если надели — "Снять")
        $toggleButtonText = $currentlyEquipped ? 'Одеть' : 'Снять';
        $toggleCallback   = "toggleEquipArmor_{$charOutfitId}";

        // Кнопка "назад" в меню брони
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => $toggleButtonText, 'callback_data' => $toggleCallback]
                ],
                [
                    ['text' => '👕 Броня / Одежда', 'callback_data' => 'gearArmor']
                ]
            ]
        ];

        // Убираем "часики" (spinner) на исходном сообщении
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Возвращаем новое сообщение с характеристиками
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $message,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Пример вспомогательного метода, проверяющего, что игрок физически на своей базе.
     * В бо́льших проектах лучше вынести в отдельный сервис (например, PlayerStateService).
     */
    private function isOnBase(int $characterId): bool
    {
        // 1) Ищем в claimed_cells запись по character_id со статусом 'active'
        $claimedCellRow = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        if (!$claimedCellRow) {
            // Нет активной базы
            return false;
        }

        // 2) Узнаём ячейку, в которой база
        $baseMapId = $claimedCellRow['map_cell_id'];
        $mapRowBase = $this->mapModel->find($baseMapId);
        if (!$mapRowBase) {
            return false;
        }

        // 3) Смотрим, где сам персонаж
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return false;
        }

        // 4) Сопоставляем cell_number персонажа с cell_number из map для базы
        //    (В таблице map есть 'cell_number'; claimed_cells.map_cell_id ссылается на map.id.)
        //    Если нужно, возможно, придётся сопоставлять ID, но чаще сравнивают именно cell_number.
        return isset($mapRowBase['cell_number'])
            && (int)$mapRowBase['cell_number'] === (int)$character['cell_number'];
    }
}
