<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Display\GearImageResolver;
use App\Services\Player\EquipmentLoadoutService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Детали брони — рендерер модели {@see EquipmentLoadoutService} (W2.N1-03, ADR-190): предмет,
 * «на базе ли» и владение берутся из того же сервиса, что надевает и снимает.
 */
class GearArmorDetailAction extends BaseAction
{

    /**
     * Массив соответствий брони/одежды и изображений.
     * Ключ — `name_en` из `outfits`, значение — имя файла изображения.
     *
     * @return array<string, string>
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

        // Предмет персонажа из модели (чужая строка или нет записи в справочнике — не найдено)
        $service = new EquipmentLoadoutService();
        $charId  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $item    = $service->item($charId, EquipmentLoadoutService::KIND_ARMOR, $charOutfitId);
        if ($item === null) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Броня или одежда не найдены в инвентаре.',
            ]);
        }
        $outfitInfo = $item['info'];
        // Поле справочника строкой: null/нет — значение по умолчанию, как у прежнего `??`.
        $f = static fn (string $key, string $default): string => is_scalar($outfitInfo[$key] ?? null) ? (string) $outfitInfo[$key] : $default;

        // Базовые поля
        $outfitName   = $item['name'];
        $armorType    = $f('armor_type', 'Обычная');
        $slot         = $f('slot', 'Неизвестный слот');
        $armorValue   = $f('armor_value', '0');
        // Прочность — характеристика предмета, без дроби: см. GearWeaponDetailAction.
        $durabilityMax= $f('durability_max', '');
        $rarity       = $f('rarity', 'Common');
        $description  = $f('description', 'Без описания');
        $isEquipped   = $item['equipped'];

        // Получаем путь к изображению
        $armorEnName = $item['name_en'] !== '' ? $item['name_en'] : 'default_armor';
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
        $isSoulbound = $item['soulbound'] !== null;
        if ($item['soulbound'] !== null) {
            $src = $item['soulbound']['source'];
            $lvl = $item['soulbound']['level'];
            $crd = $item['soulbound']['coords'];
            $text .= "🔒 *Метка пустоши*: трофей с узла _{$src}_ (L{$lvl}" . ($crd !== '' ? ", {$crd}" : '') . ").\n";
            $text .= "_Усиливает тебя ТОЛЬКО против узлов. Не надевается, не продаётся, не теряется._\n\n";
        }

        // V15 (честный closer): показываем реальные ненулевые сопротивления/
        // модификаторы + честную сноску, что они работают в PvP-дуэлях
        // (PvE учитывает лишь armor_value). Раньше эти числа не показывались.
        $resLines = \App\Services\Display\OutfitDisplayHelper::resistanceLines($outfitInfo);
        if (! empty($resLines)) {
            $text .= "*Спец-свойства:*\n" . implode("\n", $resLines) . "\n";
            $text .= \App\Services\Display\OutfitDisplayHelper::PVP_NOTE . "\n\n";
        }

        // Определяем, находится ли игрок на базе
        $isOnBase = $service->isOnBase($charId);

        // Готовим кнопки:
        // 1) Если предмет уже надет — всегда показываем "Снять"
        // 2) Если не надет:
        //    - Если игрок на базе — кнопка "Надеть"
        //    - Если не на базе — либо скрыть кнопку, либо пометить как недоступную

        // Кнопка «Надеть/Снять» показывается только когда действие реально доступно.
        // Вне базы надеть нельзя — не рисуем мёртвую кнопку, причину поясняем в тексте.
        $rows = [];
        if ($isSoulbound) {
            // WB9: трофей-узел не надевается (raid-only бонус действует пассивно) → нет кнопки «Надеть».
        } elseif ($isEquipped) {
            $rows[] = [['text' => 'Снять', 'callback_data' => "toggleEquipArmor_{$charOutfitId}"]];
        } elseif ($isOnBase) {
            $rows[] = [['text' => 'Надеть', 'callback_data' => "toggleEquipArmor_{$charOutfitId}"]];
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
}
