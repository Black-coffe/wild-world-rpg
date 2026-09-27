<?php

namespace App\Controllers\Telegram\Commands\Profile;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Display\GearImageResolver;
use App\Services\Player\EquipmentLoadoutService;

use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Детали оружия — рендерер модели {@see EquipmentLoadoutService} (W2.N1-03, ADR-190): предмет,
 * «на базе ли» и владение берутся из того же сервиса, что надевает и снимает.
 */
class GearWeaponDetailAction extends BaseAction
{

    /**
     * Сопоставление name_en => имя файла оружия.
     *
     * Аудит 2026-07-10 (волна арта брони): в БД 24 оружия, а карта знала 4. Десять T3/фракционных
     * стволов имели **и картинку на диске, и запись в `ImageRegistry`** — но без строки здесь
     * резолвер их не находил и отдавал `default_weapon.jpg`. Арт был нарисован и невидим.
     * Каталог не указываем: `GearImageResolver` ищет веером `standard→professional→general`.
     *
     * @return array<string, string>
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

        // Предмет персонажа из модели (чужая строка или нет записи в справочнике — не найдено)
        $service = new EquipmentLoadoutService();
        $charId  = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $item    = $service->item($charId, EquipmentLoadoutService::KIND_WEAPON, $charWeaponId);
        if ($item === null) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Оружие не найдено в инвентаре.',
            ]);
        }
        $weaponInfo = $item['info'];
        // Поле справочника строкой — так, как его печатала интерполяция (null → '').
        $f = static fn (string $key): string => is_scalar($weaponInfo[$key] ?? null) ? (string) $weaponInfo[$key] : '';

        // Собираем текст описания
        $name         = $item['name'];
        $weaponType   = $f('weapon_type');
        $damageValue  = $f('damage_value');
        $damageType   = $f('damage_type');
        $rangeValue   = $f('range_value');
        $attackSpeed  = $f('attack_speed');
        // Прочность показываем как характеристику предмета, без дроби «текущая /
        // максимум»: износа у оружия и брони в игре нет (ничего не уменьшает
        // current_durability), а в БД у всех строк лежит константа 100 при максимуме
        // 15..120 — экран показывал то «100 / 25», то «100 / 200», то есть врал в
        // обе стороны. Вернуть дробь — когда появится реальный износ.
        $durabilityMax= $f('durability_max');
        $rarity       = $f('rarity');
        $description  = $f('description');

        // Требования
        $reqStr       = $f('required_strength');
        $reqAgi       = $f('required_agility');
        $reqInt       = $f('required_intellect');
        $reqLevel     = $f('required_level');

        $quantity     = $item['quantity'];
        $isEquipped   = $item['equipped'];

        $text  = "🔎 *Информация об оружии*\n\n";
        $text .= "Название: *{$name}*\n";
        $text .= "Тип: *{$weaponType}*\n";
        $text .= "Редкость: *{$rarity}*\n";
        $text .= "Количество: *{$quantity}*\n";
        $text .= "Прочность: {$durabilityMax}\n\n";
        $text .= "Урон: *{$damageValue}* ({$damageType})\n";
        $text .= "Дальность: *{$rangeValue}*\n";
        $text .= "Скорость атаки: *{$attackSpeed}*\n\n";
        $text .= "Требуемый уровень: {$reqLevel}\n";
        $text .= "Требуется СИЛ: {$reqStr}, ЛОВ: {$reqAgi}, ИНТ: {$reqInt}\n\n";
        $text .= "Описание: _{$description}_\n\n";

        // WB9 (ADR-137): badge soulbound-трофея «Метка пустоши» с провенансом (media-off: весь смысл в тексте).
        $isSoulbound = $item['soulbound'] !== null;
        if ($item['soulbound'] !== null) {
            $src = $item['soulbound']['source'];
            $lvl = $item['soulbound']['level'];
            $crd = $item['soulbound']['coords'];
            $text .= "🔒 *Метка пустоши*: трофей с узла _{$src}_ (L{$lvl}" . ($crd !== '' ? ", {$crd}" : '') . ").\n";
            $text .= "_Усиливает тебя ТОЛЬКО против узлов. Не надевается, не продаётся, не теряется._\n\n";
        }

        // Определяем, находится ли игрок на базе
        $isOnBase = $service->isOnBase($charId);

        // Логика отображения кнопки «Надеть / Снять»
        // 1) Если оружие уже надето → "Снять" доступно всегда
        // 2) Если не надето и игрок на базе → "Надеть"
        // 3) Если не надето и игрок не на базе → "Недоступно (не на базе)"
        // Кнопка «Надеть/Снять» показывается только когда действие реально доступно.
        // Вне базы надеть нельзя — не рисуем мёртвую кнопку, причину поясняем в тексте.
        $rows = [];
        if ($isSoulbound) {
            // WB9: трофей-узел не надевается (raid-only бонус действует пассивно) → нет кнопки «Надеть».
        } elseif ($isEquipped) {
            $rows[] = [['text' => 'Снять', 'callback_data' => "toggleEquipWeapon_{$charWeaponId}"]];
        } elseif ($isOnBase) {
            $rows[] = [['text' => 'Надеть', 'callback_data' => "toggleEquipWeapon_{$charWeaponId}"]];
        } else {
            $text .= "_⚠️ Надеть можно только на базе._\n\n";
        }

        // ADR-165 — вход в продажу прямо оттуда, где игрок смотрит на ненужный ствол.
        // Лавка доступна с любой клетки, поэтому гейта по базе тут нет. Надетое и
        // соулбаунд-трофеи не продаются — кнопки для них не рисуем (мёртвый тап хуже
        // отсутствия кнопки), причину поясняем текстом.
        if (! $isSoulbound && (new \App\Services\Economy\GearSaleService())->isEnabled()) {
            if ($isEquipped) {
                $text .= "_💰 Продать можно только снятое оружие._\n\n";
            } else {
                $rows[] = [['text' => '💰 Продать торговцу', 'callback_data' => "sellGearItem_w_{$charWeaponId}"]];
            }
        }

        $rows[] = [['text' => '↩️ Назад', 'callback_data' => 'gearWeapons']];

        $keyboard = ['inline_keyboard' => $rows];

        // Закрываем "часики"
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Определяем картинку (name_en)
        $weaponEnName = $item['name_en'] !== '' ? $item['name_en'] : 'default_weapon';
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
}
