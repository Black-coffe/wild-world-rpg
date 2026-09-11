<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\I18n\Time;

class PvPRestrictionService
{
    /** @var CharacterModel */
    protected $characterModel;
    /** @var MapModel */
    protected $mapModel;
    /** @var GameSettingsService */
    protected $settings;

    public function __construct(?GameSettingsService $settings = null)
    {
        $this->characterModel = new CharacterModel();
        $this->mapModel       = new MapModel();
        $this->settings       = $settings ?? new GameSettingsService();
    }

    /**
     * Проверка, можно ли инициировать PvP между двумя персонажами.
     *
     * pvp-detection-clarity-02: три порога (уровень / южная граница безопасной зоны /
     * возраст аккаунта) переехали в `GameSettings` (категория `combat`,
     * `pvp.restriction.*`); миграция `Adr186SeedPvpRestrictionSettings` сеет их идемпотентно
     * вместе с деплоем, и ручка **заморожена** до снятия замера окна противостояния (ADR-186).
     *
     * 🔴 Числа 5/900/10 в `get(...)` ниже — не правило (гейт им не сравнивает напрямую), а
     * safety net третьим аргументом `GameSettingsService::get()` на случай непримененной
     * миграции/недоступной таблицы (тот же приём, что `world.move.*` в
     * `MoveCharacterToDirectionAction`). Без него отсутствие строки молча даёт `(int) null = 0`:
     * `min_level=0` снимает защиту новичков, `safe_zone_min_y=0` делает `coordinate_y >= 0`
     * истинным всегда, `min_account_age_days=0` снимает возрастной ценз — правила боя тихо
     * меняются без единой ошибки в логе. Safety net равен сегодняшнему хардкоду байт-в-байт,
     * поэтому даже без миграции поведение прежнее; сама формула гейта (`< $minLevel`,
     * `>= $safeZoneMinY`, `< $minAccountAgeDays`) нигде не содержит литерала.
     *
     * @param array|\App\Entities\CharacterEntity $attacker Массив данных об атакующем.
     * @param array|\App\Entities\CharacterEntity $defender Массив данных об обороняющемся.
     *
     * @return array Вернёт ['allowed' => bool, 'reason' => string, 'reason_code' => string, 'message' => string].
     *         `reason` сохранён для нынешних вызывающих (`AttackPlayerAction`), `reason_code`/`message`
     *         — контракт для будущей story `-07` (машиночитаемый замок до тапа). Коды причин отказа —
     *         `level` / `safe_zone` / `account_age`; при `allowed=true` — пустая строка.
     */
    public function checkPvPAllowed(array|\App\Entities\CharacterEntity $attacker, array|\App\Entities\CharacterEntity $defender): array
    {
        $minLevel          = (int) $this->settings->get('pvp.restriction.min_level', 5);
        $safeZoneMinY      = (int) $this->settings->get('pvp.restriction.safe_zone_min_y', 900);
        $minAccountAgeDays = (int) $this->settings->get('pvp.restriction.min_account_age_days', 10);

        // 1. Уровень ниже порога => ни атаковать, ни быть атакованным
        if ($attacker['level'] < $minLevel || $defender['level'] < $minLevel) {
            return [
                'allowed'     => false,
                'reason'      => "Один из игроков имеет уровень ниже {$minLevel} — PvP недоступно.",
                'reason_code' => 'level',
                'message'     => "Один из игроков имеет уровень ниже {$minLevel} — PvP недоступно.",
            ];
        }

        // 2. Проверка зоны респауна (южная граница безопасной зоны)
        $mapRowA = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        $mapRowD = $this->mapModel->where('cell_number', $defender['cell_number'])->first();

        if (!$mapRowA || !$mapRowD) {
            return [
                'allowed'     => false,
                'reason'      => 'Не удалось найти локацию (map) для одного из игроков.',
                'reason_code' => 'map_missing',
                'message'     => 'Не удалось найти локацию (map) для одного из игроков.',
            ];
        }

        if ($mapRowA['coordinate_y'] >= $safeZoneMinY || $mapRowD['coordinate_y'] >= $safeZoneMinY) {
            return [
                'allowed'     => false,
                'reason'      => "Игрок (или оба) находится в зоне респауна (Y >= {$safeZoneMinY}), PvP запрещено.",
                'reason_code' => 'safe_zone',
                'message'     => "Игрок (или оба) находится в зоне респауна (Y >= {$safeZoneMinY}), PvP запрещено.",
            ];
        }

        // 3. Аккаунт моложе порога дней => нельзя участвовать в PvP
        //    (Предполагается наличие поля 'created_at' у персонажа.)
        //    Если у вас хранится дата иначе — подкорректируйте логику.
        $attackerCreatedAt = new Time($attacker['created_at'] ?? '1970-01-01');
        $defenderCreatedAt = new Time($defender['created_at'] ?? '1970-01-01');

        $now = Time::now();
        $daysSinceAttackerReg = $attackerCreatedAt->difference($now)->getDays();
        $daysSinceDefenderReg = $defenderCreatedAt->difference($now)->getDays();

        if ($daysSinceAttackerReg < $minAccountAgeDays || $daysSinceDefenderReg < $minAccountAgeDays) {
            return [
                'allowed'     => false,
                'reason'      => "Один из игроков зарегистрирован менее {$minAccountAgeDays} дней назад — PvP для новичков отключено.",
                'reason_code' => 'account_age',
                'message'     => "Один из игроков зарегистрирован менее {$minAccountAgeDays} дней назад — PvP для новичков отключено.",
            ];
        }

        // Если все проверки пройдены — разрешаем
        return [
            'allowed'     => true,
            'reason'      => 'Ok',
            'reason_code' => '',
            'message'     => 'Ok',
        ];
    }
}
