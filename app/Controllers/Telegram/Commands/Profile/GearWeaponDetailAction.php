<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
// Дополнительно для проверки "на базе" подключим модели:
use App\Models\ClaimedCellModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Services\Display\GearImageResolver;

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
     *
     * Аудит 2026-07-10 (волна арта брони): в БД 24 оружия, а карта знала 4. Десять T3/фракционных
     * стволов имели **и картинку на диске, и запись в `ImageRegistry`** — но без строки здесь
     * резолвер их не находил и отдавал `default_weapon.jpg`. Арт был нарисован и невидим.
     * Каталог не указываем: `GearImageResolver` ищет веером `standard→professional→general`.
     */
    protected function getWeaponImageMap(): array
    {
        return [
            'MetalSpear'  => 'metal_spear.jpg',
            'PipeGun'     => 'pipe_gun.jpg',
            'EnhancedBat' => 'wired_bat.jpg',
            'CrossbowMk1' => 'crossbow_mk1.jpg',

            // Огнестрел и электро-железо (арт нарисован волной 2026-07-10) — standard/.
            'SemiAutoPistol'   => 'semi_auto_pistol.jpg',
            'ShortenedShotgun' => 'shortened_shotgun.jpg',
            'TacticalSMG'      => 'tactical_smg.jpg',
            'CombatShotgun'    => 'combat_shotgun.jpg',
            'AssaultRifle556'  => 'assault_rifle_556.jpg',
            'SniperRifle308'   => 'sniper_rifle_308.jpg',
            'FirebombLauncher' => 'firebomb_launcher.jpg',
            'ElectricBaton'    => 'electric_baton.jpg',
            'PlasmaRifle'      => 'plasma_rifle.jpg',
            'TeslaKnuckles'    => 'tesla_knuckles.jpg',

            // T3-грандмастер и фракционное (V14/ADR-046, E16 Ф2) — арт лежит в professional/.
            'GaussPistol'          => 'gauss_pistol.jpg',
            'RailCarbineVikhr'     => 'rail_carbine_vikhr.jpg',
            'IonDestabilizer'      => 'ion_destabilizer.jpg',
            'FlamethrowerAid'      => 'flamethrower_aid.jpg',
            'ExoRailgunBehemoth'   => 'exo_railgun_behemoth.jpg',
            'HydraPlasmaCannon'    => 'hydra_plasma_cannon.jpg',
            'BunkerRifle'          => 'bunker_rifle.jpg',
            'TechnoBeamShotgun'    => 'techno_beam_shotgun.jpg',
            'GhostCityKnife'       => 'ghost_city_knife.jpg',
            'FarmersHarvestScythe' => 'farmers_harvest_scythe.jpg',
        ];
    }

    /**
     * Путь к существующему файлу картинки оружия, либо null (файла нет → шлём текст).
     *
     * Близнец бага брони (прод-инцидент 2026-07-10): путь возвращался без проверки
     * существования → `Request::encodeFile()` падал бы на fopen. Сейчас все 4 файла
     * карты на месте, но добавление позиции без картинки уронило бы экран.
     */
    protected function getWeaponImagePath(string $weaponEnName): ?string
    {
        return GearImageResolver::weaponImage($weaponEnName, $this->getWeaponImageMap());
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

        // WB9 (ADR-137): badge soulbound-трофея «Метка пустоши» с провенансом (media-off: весь смысл в тексте).
        $wr          = is_array($weaponRow) ? $weaponRow : [];
        $isSoulbound = ! empty($wr['is_soulbound']);
        if ($isSoulbound) {
            $src    = is_scalar($wr['soulbound_source'] ?? null) ? (string) $wr['soulbound_source'] : 'Узел';
            $lvlRaw = $wr['soulbound_level'] ?? 0;
            $lvl    = is_numeric($lvlRaw) ? (int) $lvlRaw : 0;
            $crd    = is_scalar($wr['soulbound_coords'] ?? null) ? (string) $wr['soulbound_coords'] : '';
            $text .= "🔒 *Метка пустоши*: трофей с узла _{$src}_ (L{$lvl}" . ($crd !== '' ? ", {$crd}" : '') . ").\n";
            $text .= "_Усиливает тебя ТОЛЬКО против узлов. Не надевается, не продаётся, не теряется._\n\n";
        }

        // Определяем, находится ли игрок на базе
        $isOnBase = $this->isOnBase($character['id']);

        // Логика отображения кнопки «Одеть / Снять»
        // 1) Если оружие уже надето → "Снять" доступно всегда
        // 2) Если не надето и игрок на базе → "Одеть"
        // 3) Если не надето и игрок не на базе → "Недоступно (не на базе)"
        // Кнопка «Одеть/Снять» показывается только когда действие реально доступно.
        // Вне базы надеть нельзя — не рисуем мёртвую кнопку, причину поясняем в тексте.
        $rows = [];
        if ($isSoulbound) {
            // WB9: трофей-узел не надевается (raid-only бонус действует пассивно) → нет кнопки «Одеть».
        } elseif ($isEquipped) {
            $rows[] = [['text' => 'Снять', 'callback_data' => "toggleEquipWeapon_{$charWeaponId}"]];
        } elseif ($isOnBase) {
            $rows[] = [['text' => 'Одеть', 'callback_data' => "toggleEquipWeapon_{$charWeaponId}"]];
        } else {
            $text .= "_⚠️ Надеть можно только на базе._\n\n";
        }
        $rows[] = [['text' => '↩️ Назад', 'callback_data' => 'gearWeapons']];

        $keyboard = ['inline_keyboard' => $rows];

        // Закрываем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Определяем картинку (name_en)
        $weaponEnName = $weaponInfo['name_en'] ?? 'default_weapon';
        $imagePath    = $this->getWeaponImagePath($weaponEnName);

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // Картинки нет на диске → caption самодостаточен (MEDIA-OFF, ADR-020), шлём текст.
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
