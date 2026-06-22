<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;

// Дополнительно подключим модели, чтобы проверить факт "на базе"
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\MapModel;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class ToggleEquipWeaponAction extends BaseAction
{
    /** @var BuildingModel */
    protected $buildingModel;
    /** @var CharacterBuildingModel */
    protected $charBuildingModel;
    /** @var CharactersWeaponsModel */
    protected $charactersWeaponsModel;
    /** @var WeaponModel */
    protected $weaponModel;

    // Добавим модели для проверки, находится ли игрок на базе
    protected $claimedCellModel;
    protected $characterModel;
    protected $mapModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->buildingModel          = new BuildingModel();
        $this->charBuildingModel      = new CharacterBuildingModel();
        $this->charactersWeaponsModel = new CharactersWeaponsModel();
        $this->weaponModel            = new WeaponModel();

        // Для проверки "на базе ли мы"
        $this->claimedCellModel = new ClaimedCellModel();
        $this->characterModel   = new CharacterModel();
        $this->mapModel         = new MapModel();
    }

    public function handle(): ServerResponse
    {
        $callbackData = $this->callbackQuery->getData();
        // Ожидаем формат "toggleEquipWeapon_{id}"
        if (!preg_match('/^toggleEquipWeapon_(\d+)$/', $callbackData, $matches)) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Некорректные данные для переключения экипировки.',
            ]);
        }

        $charWeaponId = (int) $matches[1];

        // 1) Получаем пользователя + персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Персонаж не найден или не зарегистрирован.',
            ]);
        }

        // 2) Проверяем, есть ли у игрока «Арсенал»
        $arsenal = $this->buildingModel->where('name_en', 'Arsenal')->first();
        if (!$arsenal) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Здание "Арсенал" не найдено. Обратитесь к администрации.',
            ]);
        }

        $charArsenal = $this->charBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $arsenal['id'])
            ->first();

        if (!$charArsenal) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "У тебя нет здания «Арсенал» на базе, поэтому нельзя менять экипировку!",
            ]);
        }

        // 3) Ищем в characters_weapons конкретное оружие
        $weaponRow = $this->charactersWeaponsModel->find($charWeaponId);
        if (!$weaponRow || (int)$weaponRow['character_id'] !== (int)$character['id']) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Оружие не найдено или не принадлежит вашему персонажу.',
            ]);
        }

        // Проверка количества (если оно = 0, экипировать нельзя).
        if ($weaponRow['quantity'] < 1) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => "У тебя нет экземпляров этого оружия (количество = 0).",
            ]);
        }

        // 4) Информация об оружии
        $weaponInfo = $this->weaponModel->find($weaponRow['weapon_id']);
        if (!$weaponInfo) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Информация об оружии (в WeaponModel) не найдена.',
            ]);
        }

        $weaponName       = $weaponInfo['name'] ?? 'Неизвестное оружие';
        $currentlyEquipped= (bool)$weaponRow['equipped'];

        // Логика: снять/надеть
        if ($currentlyEquipped) {
            // Снятие возможно где угодно
            $this->charactersWeaponsModel->update($charWeaponId, ['equipped' => 0]);

            $actionText   = "Ты *снял* оружие:\n`{$weaponName}`";
            $extraNote    = "Теперь оно лежит в твоём арсенале.";
            $newEquippedState = 0;
        } else {
            // WB9 (ADR-137): soulbound-трофей «Метка пустоши» нельзя надеть — это коллекционный
            // знак, а не активная экипировка. Он усиливает ТОЛЬКО против узлов (raid-only) и не
            // даёт PvP-преимущества (портрет П4). Гейт против надевания = защита от PvP-power-creep.
            if (!empty($weaponRow['is_soulbound'])) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => "🔒 «{$weaponName}» — это Метка пустоши, трофей с узла. Его нельзя надеть: он и так усиливает тебя в бою с узлами, но в руки не берётся и не продаётся.",
                ]);
            }

            // Надеть — только если игрок на базе
            if (!$this->isOnBase($character['id'])) {
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => "Ты не на базе и не можешь сейчас экипировать «{$weaponName}».",
                ]);
            }

            // Снимаем все остальные оружия у этого персонажа
            $this->charactersWeaponsModel
                ->where('character_id', $character['id'])
                ->set(['equipped' => 0])
                ->update();

            // Экипируем выбранное оружие
            $this->charactersWeaponsModel->update($charWeaponId, ['equipped' => 1]);

            $actionText   = "Ты успешно *экипировал* оружие:\n`{$weaponName}`";
            $extraNote    = "Все остальные оружия сняты. Теперь {$weaponName} числится на тебе.";
            $newEquippedState = 1;
        }

        // 5) Доп. инфо (урон, прочность)
        $curDurability  = $weaponRow['current_durability'] ?? $weaponInfo['durability'];
        $durabilityMax  = $weaponInfo['durability_max']   ?? 100;
        $damageValue    = $weaponInfo['damage_value']      ?? 0;
        $damageType     = $weaponInfo['damage_type']       ?? 'physical';

        $shortStats = sprintf(
            "💥 Урон: %d\n🔎 Тип урона: %s\n⚙️ Прочность: %d / %d",
            $damageValue,
            $damageType,
            $curDurability,
            $durabilityMax
        );

        // Формируем итоговый текст
        $text = "⚔️ *Экипировка оружия*\n\n"
            . "{$actionText}\n\n"
            . "{$shortStats}\n\n"
            . "{$extraNote}\n";

        // Кнопка — переключатель
        $toggleButtonText = $newEquippedState ? "Снять" : "Одеть";
        $toggleCallback   = "toggleEquipWeapon_{$charWeaponId}";

        // Кнопка «⚔️ Экип» → возвращение к меню экипировки
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => $toggleButtonText, 'callback_data' => $toggleCallback],
                    ['text' => '⚔️ Экип', 'callback_data' => 'equipMenu']
                ]
            ]
        ];

        // Убираем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // 6) Возвращаем ответ
        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Проверяет, находится ли персонаж физически на своей базе (active claimed cell).
     * Вы можете вынести это в отдельный сервис (PlayerStateService), но для наглядности делаем здесь.
     */
    private function isOnBase(int $characterId): bool
    {
        // 1) Ищем активную базу
        $claimedCell = $this->claimedCellModel
            ->where('character_id', $characterId)
            ->where('status', 'active')
            ->first();

        if (!$claimedCell) {
            // Нет активной базы
            return false;
        }

        // 2) Получаем map-ячейку базы
        $baseMapId = $claimedCell['map_cell_id'];
        $mapRowBase = $this->mapModel->find($baseMapId);
        if (!$mapRowBase) {
            return false;
        }

        // 3) Сравниваем cell_number базы и cell_number персонажа
        $character = $this->characterModel->find($characterId);
        if (!$character) {
            return false;
        }

        return isset($mapRowBase['cell_number'])
            && (int)$mapRowBase['cell_number'] === (int)$character['cell_number'];
    }
}
