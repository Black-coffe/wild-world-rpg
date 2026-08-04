<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Services\Display\GearImageResolver;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GearArmorDetailAction extends BaseAction
{
    /** @var CharactersOutfitsModel */
    protected $charactersOutfitsModel;
    /** @var OutfitModel */
    protected $outfitModel;

    // Добавим модели, чтобы можно было определить,
    // находится ли игрок на своей базе
    protected $claimedCellModel;
    protected $characterModel;
    protected $mapModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->outfitModel = new OutfitModel();

        // Для проверки "на базе?" (можно вынести в сервис, но для наглядности делаем прямо здесь)
        $this->claimedCellModel = new ClaimedCellModel();
        $this->characterModel   = new CharacterModel();
        $this->mapModel         = new MapModel();
    }

    /**
     * Массив соответствий брони/одежды и изображений.
     * Ключ — `name_en` из `outfits`, значение — имя файла изображения.
     */
    protected function getArmorImageMap(): array
    {
        return [
            'RaggedShirt'               => 'ragged_shirt.jpg', // Рваная рубаха
            'WandererClothes'           => 'drifter_clothes.jpg', // Одежда бродяги
            'LeatherJacket'             => 'leather_jacket.jpg', // Кожаная куртка
            'ReinforcedLeatherJacket'   => 'reinforced_leather_jacket.jpg', // Усиленная кожаная куртка
            'ScrapChestplate'           => 'scrap_chestplate.jpg',
            'OldMilitaryVest'           => 'old_military_vest.jpg',
            'HunterVest'                => 'hunter_vest.jpg',
            'RustyFullPlate'            => 'rusty_full_plate.jpg',
            'MercenaryArmor'            => 'mercenary_armor.jpg',
            'SamuraiArmorOyori'         => 'samurai_armor_oyori.jpg',
            'DamagedPowerArmor'         => 'damaged_power_armor.jpg',
            'ExoskeletonBogomol'        => 'exoskeleton_bogomol.jpg',
            'TacticalArmorSuit'         => 'tactical_armor_suit.jpg',
            'ShadowArmor'               => 'shadow_armor.jpg',
            'TitanPowerArmor'           => 'titan_power_armor.jpg',
            'ExoskeletonStrekoza'       => 'exoskeleton_strekoza.jpg',
            'JuggernautBattleArmor'     => 'juggernaut_battle_armor.jpg',
            'AdmiralChestplate'         => 'admiral_chestplate.jpg',
            'PhantomApparel'            => 'phantom_apparel.jpg',
            'TeslaShardArmor'           => 'tesla_shard_armor.jpg',
        ];
    }

    /**
     * Путь к существующему файлу картинки брони, либо null (файла нет → шлём текст).
     *
     * Прод-инцидент 2026-07-10: раньше метод возвращал `standard/<файл>` не проверяя
     * существование → `Request::encodeFile()` падал на fopen. 16 из 20 позиций карты
     * не лежат в `standard/`. Резолв и деградация — в {@see GearImageResolver}.
     */
    protected function getArmorImagePath(string $armorEnName): ?string
    {
        return GearImageResolver::armorImage($armorEnName, $this->getArmorImageMap());
    }

    public function handle(): ServerResponse
    {
        $callbackData = $this->callbackQuery->getData();
        if (!preg_match('/^gearArmorDetail_(\d+)$/', $callbackData, $matches)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Некорректные данные для просмотра брони.',
            ]);
        }

        $charOutfitId = (int) $matches[1];

        // Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Не удалось загрузить пользователя или персонажа.',
            ]);
        }

        // Проверяем, есть ли у персонажа данный предмет
        $outfitRow = $this->charactersOutfitsModel->find($charOutfitId);
        if (!$outfitRow || (int)$outfitRow['character_id'] !== (int)$character['id']) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Броня или одежда не найдены в инвентаре.',
            ]);
        }

        // Данные о предмете
        $outfitInfo = $this->outfitModel->find($outfitRow['outfit_id']);
        if (!$outfitInfo) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Информация об экипировке в базе не найдена.',
            ]);
        }

        // Базовые поля
        $outfitName   = $outfitInfo['name'];
        $armorType    = $outfitInfo['armor_type'] ?? 'Обычная';
        $slot         = $outfitInfo['slot'] ?? 'Неизвестный слот';
        $armorValue   = $outfitInfo['armor_value'] ?? 0;
        // Прочность — характеристика предмета, без дроби: см. GearWeaponDetailAction.
        $durabilityMax= $outfitInfo['durability_max'];
        $rarity       = $outfitInfo['rarity'] ?? 'Common';
        $description  = $outfitInfo['description'] ?? 'Без описания';
        $isEquipped   = (bool) $outfitRow['equipped'];

        // Получаем путь к изображению
        $armorEnName = $outfitInfo['name_en'] ?? 'default_armor';
        $imagePath = $this->getArmorImagePath($armorEnName);

        // Формируем текст с характеристиками
        $text  = "🛡 *Детали брони*\n\n";
        $text .= "Название: *{$outfitName}*\n";
        $text .= "Тип: *{$armorType}*\n";
        $text .= "Слот: *{$slot}*\n";
        $text .= "Редкость: *{$rarity}*\n";
        $text .= "Прочность: {$durabilityMax}\n";
        $text .= "Защита: *{$armorValue}*\n\n";
        $text .= "Описание: _{$description}_\n\n";

        // WB9 (ADR-137): badge soulbound-трофея «Метка пустоши» с провенансом (media-off).
        $or          = is_array($outfitRow) ? $outfitRow : [];
        $isSoulbound = ! empty($or['is_soulbound']);
        if ($isSoulbound) {
            $src    = is_scalar($or['soulbound_source'] ?? null) ? (string) $or['soulbound_source'] : 'Узел';
            $lvlRaw = $or['soulbound_level'] ?? 0;
            $lvl    = is_numeric($lvlRaw) ? (int) $lvlRaw : 0;
            $crd    = is_scalar($or['soulbound_coords'] ?? null) ? (string) $or['soulbound_coords'] : '';
            $text .= "🔒 *Метка пустоши*: трофей с узла _{$src}_ (L{$lvl}" . ($crd !== '' ? ", {$crd}" : '') . ").\n";
            $text .= "_Усиливает тебя ТОЛЬКО против узлов. Не надевается, не продаётся, не теряется._\n\n";
        }

        // V15 (честный closer): показываем реальные ненулевые сопротивления/
        // модификаторы + честную сноску, что они работают в PvP-дуэлях
        // (PvE учитывает лишь armor_value). Раньше эти числа не показывались.
        $resLines = \App\Services\Display\OutfitDisplayHelper::resistanceLines(
            is_array($outfitInfo) ? $outfitInfo : []
        );
        if (! empty($resLines)) {
            $text .= "*Спец-свойства:*\n" . implode("\n", $resLines) . "\n";
            $text .= \App\Services\Display\OutfitDisplayHelper::PVP_NOTE . "\n\n";
        }

        // Определяем, находится ли игрок на базе
        $isOnBase = $this->isOnBase($character['id']);

        // Готовим кнопки:
        // 1) Если предмет уже надет — всегда показываем "Снять"
        // 2) Если не надет:
        //    - Если игрок на базе — кнопка "Одеть"
        //    - Если не на базе — либо скрыть кнопку, либо пометить как недоступную

        // Кнопка «Одеть/Снять» показывается только когда действие реально доступно.
        // Вне базы надеть нельзя — не рисуем мёртвую кнопку, причину поясняем в тексте.
        $rows = [];
        if ($isSoulbound) {
            // WB9: трофей-узел не надевается (raid-only бонус действует пассивно) → нет кнопки «Одеть».
        } elseif ($isEquipped) {
            $rows[] = [['text' => 'Снять', 'callback_data' => "toggleEquipArmor_{$charOutfitId}"]];
        } elseif ($isOnBase) {
            $rows[] = [['text' => 'Одеть', 'callback_data' => "toggleEquipArmor_{$charOutfitId}"]];
        } else {
            $text .= "_⚠️ Надеть можно только на базе._\n\n";
        }

        // ADR-165 — вход в продажу с карточки брони (зеркало GearWeaponDetailAction).
        // Надетое и соулбаунд-трофеи не продаются: кнопки нет, причина — текстом.
        if (! $isSoulbound && (new \App\Services\Economy\GearSaleService())->isEnabled()) {
            if ($isEquipped) {
                $text .= "_💰 Продать можно только снятую броню._\n\n";
            } else {
                $rows[] = [['text' => '💰 Продать торговцу', 'callback_data' => "sellGearItem_a_{$charOutfitId}"]];
            }
        }

        $rows[] = [['text' => '↩️ Назад', 'callback_data' => 'gearArmor']];

        $keyboard = ['inline_keyboard' => $rows];

        // Убираем "часики" на кнопке
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Картинки нет на диске → картинка это enhancement (MEDIA-OFF, ADR-020):
        // caption самодостаточен, шлём его текстом. Раньше здесь падал encodeFile().
        if ($imagePath === null) {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Отправляем фото + описание
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Проверяет, что персонаж физически находится на своей активной базе.
     *
     * @param int $characterId
     * @return bool
     */
    private function isOnBase(int $characterId): bool
    {
        // 1) Ищем активную базу
        $claimedCellRow = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        if (!$claimedCellRow) {
            // Нет базы
            return false;
        }

        // 2) Узнаём карту базы
        $baseMapId = $claimedCellRow['map_cell_id'];
        $mapRowBase = $this->mapModel->find($baseMapId);
        if (!$mapRowBase) {
            return false;
        }

        // 3) Получаем персонажа и сравниваем ячейки
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return false;
        }

        return (
            isset($mapRowBase['cell_number'])
            && (int)$mapRowBase['cell_number'] === (int)$character['cell_number']
        );
    }
}
