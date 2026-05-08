<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
// Дополнительно для проверки "на базе" подключим модели:
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\MapModel;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class GearWeaponDetailAction extends BaseAction
{
    /** @var CharactersWeaponsModel */
    protected $charactersWeaponsModel;
    /** @var WeaponModel */
    protected $weaponModel;

    // Добавим эти модели, чтобы проверить "игрок на базе?"
    protected $claimedCellModel;
    protected $characterModel;
    protected $mapModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->charactersWeaponsModel = new CharactersWeaponsModel();
        $this->weaponModel = new WeaponModel();

        // Для проверки "на базе"
        $this->claimedCellModel = new ClaimedCellModel();
        $this->characterModel   = new CharacterModel();
        $this->mapModel         = new MapModel();
    }

    /**
     * Сопоставление name_en => имя файла оружия.
     */
    protected function getWeaponImageMap(): array
    {
        return [
            'MetalSpear'   => 'metal_spear.jpg',
            'PipeGun'      => 'pipe_gun.jpg',
            'EnhancedBat'  => 'wired_bat.jpg',
            'CrossbowMk1'  => 'crossbow_mk1.jpg',
            // ... При необходимости дополните ...
        ];
    }

    /**
     * Возвращает путь к файлу изображения (или заглушке, если не найдено).
     */
    protected function getWeaponImagePath(string $weaponEnName): string
    {
        $map = $this->getWeaponImageMap();

        if (isset($map[$weaponEnName])) {
            $filename = $map[$weaponEnName];
            // Возвращаем локальный путь, откуда Request::encodeFile сможет прочитать
            return FCPATH . 'uploads/telegram/craft/standard/' . $filename;
        }

        // Заглушка
        return FCPATH . 'uploads/telegram/craft/standard/default_weapon.jpg';
    }

    public function handle(): ServerResponse
    {
        $callbackData = $this->callbackQuery->getData();
        if (!preg_match('/^gearWeaponDetail_(\d+)$/', $callbackData, $matches)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Некорректные данные для просмотра оружия!',
            ]);
        }

        $charWeaponId = (int) $matches[1];

        // Получаем пользователя и персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Не удалось загрузить пользователя или персонажа.',
            ]);
        }

        // Ищем запись в characters_weapons
        $weaponRow = $this->charactersWeaponsModel->find($charWeaponId);
        if (!$weaponRow) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Оружие не найдено в инвентаре.',
            ]);
        }

        // Проверяем владение
        if ((int)$weaponRow['character_id'] !== (int)$character['id']) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Это оружие принадлежит другому персонажу!',
            ]);
        }

        // Достаем информацию об оружии
        $weaponInfo = $this->weaponModel->find($weaponRow['weapon_id']);
        if (!$weaponInfo) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Информация об оружии в базе не найдена.',
            ]);
        }

        // Собираем текст описания
        $name         = $weaponInfo['name'];
        $weaponType   = $weaponInfo['weapon_type'];
        $damageValue  = $weaponInfo['damage_value'];
        $damageType   = $weaponInfo['damage_type'];
        $rangeValue   = $weaponInfo['range_value'];
        $attackSpeed  = $weaponInfo['attack_speed'];
        $durability   = $weaponRow['current_durability'] ?? $weaponInfo['durability'];
        $durabilityMax= $weaponInfo['durability_max'];
        $rarity       = $weaponInfo['rarity'];
        $description  = $weaponInfo['description'];

        // Требования
        $reqStr       = $weaponInfo['required_strength'];
        $reqAgi       = $weaponInfo['required_agility'];
        $reqInt       = $weaponInfo['required_intellect'];
        $reqLevel     = $weaponInfo['required_level'];

        $quantity     = (int)$weaponRow['quantity'];
        $isEquipped   = (bool)$weaponRow['equipped'];

        $text  = "🔎 *Информация об оружии*\n\n";
        $text .= "Название: *{$name}*\n";
        $text .= "Тип: *{$weaponType}*\n";
        $text .= "Редкость: *{$rarity}*\n";
        $text .= "Количество: *{$quantity}*\n";
        $text .= "Прочность: {$durability} / {$durabilityMax}\n\n";
        $text .= "Урон: *{$damageValue}* ({$damageType})\n";
        $text .= "Дальность: *{$rangeValue}*\n";
        $text .= "Скорость атаки: *{$attackSpeed}*\n\n";
        $text .= "Требуемый уровень: {$reqLevel}\n";
        $text .= "Требуется СИЛ: {$reqStr}, ЛОВ: {$reqAgi}, ИНТ: {$reqInt}\n\n";
        $text .= "Описание: _{$description}_\n\n";

        // Определяем, находится ли игрок на базе
        $isOnBase = $this->isOnBase($character['id']);

        // Логика отображения кнопки «Одеть / Снять»
        // 1) Если оружие уже надето → "Снять" доступно всегда
        // 2) Если не надето и игрок на базе → "Одеть"
        // 3) Если не надето и игрок не на базе → "Недоступно (не на базе)"
        if ($isEquipped) {
            $buttonText     = 'Снять';
            $buttonCallback = "toggleEquipWeapon_{$charWeaponId}";
        } else {
            if ($isOnBase) {
                $buttonText     = 'Одеть';
                $buttonCallback = "toggleEquipWeapon_{$charWeaponId}";
            } else {
                $buttonText     = 'Недоступно (не на базе)';
                $buttonCallback = 'noAction'; // заглушка
            }
        }

        // Кнопки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => $buttonText, 'callback_data' => $buttonCallback],
                ],
                [
                    ['text' => '↩️ Назад', 'callback_data' => 'gearWeapons']
                ]
            ]
        ];

        // Закрываем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Определяем картинку (name_en)
        $weaponEnName = $weaponInfo['name_en'] ?? 'default_weapon';
        $imagePath    = $this->getWeaponImagePath($weaponEnName);

        // Отправляем фото + описание
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Простейшая проверка: находится ли персонаж физически на своей базе
     */
    private function isOnBase(int $characterId): bool
    {
        // 1) Ищем запись с active-базой
        $claimedCell = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        if (!$claimedCell) {
            return false;
        }

        // 2) Узнаём в map ID ячейки
        $baseMapRow = $this->mapModel->find($claimedCell['map_cell_id']);
        if (!$baseMapRow) {
            return false;
        }

        // 3) Сравниваем mapRowBase['cell_number'] с character['cell_number']
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return false;
        }

        return isset($baseMapRow['cell_number'])
            && (int)$baseMapRow['cell_number'] === (int)$character['cell_number'];
    }
}
