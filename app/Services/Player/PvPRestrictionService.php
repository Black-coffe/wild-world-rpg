<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\MapModel;
use CodeIgniter\I18n\Time;

class PvPRestrictionService
{
    /** @var CharacterModel */
    protected $characterModel;
    /** @var MapModel */
    protected $mapModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->mapModel       = new MapModel();
    }

    /**
     * Проверка, можно ли инициировать PvP между двумя персонажами.
     * @param array|\App\Entities\CharacterEntity $attacker Массив данных об атакующем.
     * @param array|\App\Entities\CharacterEntity $defender Массив данных об обороняющемся.
     *
     * @return array Вернёт ['allowed' => bool, 'reason' => string]
     *         Если allowed=false, в reason будет описан запрет.
     */
    public function checkPvPAllowed(array|\App\Entities\CharacterEntity $attacker, array|\App\Entities\CharacterEntity $defender): array
    {
        // 1. Уровень < 5 => ни атаковать, ни быть атакованным
        if ($attacker['level'] < 5 || $defender['level'] < 5) {
            return [
                'allowed' => false,
                'reason'  => 'Один из игроков имеет уровень ниже 5 — PvP недоступно.'
            ];
        }

        // 2. Проверка зоны респауна (Y >= 900)
        $mapRowA = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        $mapRowD = $this->mapModel->where('cell_number', $defender['cell_number'])->first();

        if (!$mapRowA || !$mapRowD) {
            return [
                'allowed' => false,
                'reason'  => 'Не удалось найти локацию (map) для одного из игроков.'
            ];
        }

        if ($mapRowA['coordinate_y'] >= 900 || $mapRowD['coordinate_y'] >= 900) {
            return [
                'allowed' => false,
                'reason'  => 'Игрок (или оба) находится в зоне респауна (Y >= 900), PvP запрещено.'
            ];
        }

        // 3. Менее 10 дней с момента регистрации => нельзя участвовать в PvP
        //    (Предполагается наличие поля 'created_at' у персонажа.)
        //    Если у вас хранится дата иначе — подкорректируйте логику.
        $attackerCreatedAt = new Time($attacker['created_at'] ?? '1970-01-01');
        $defenderCreatedAt = new Time($defender['created_at'] ?? '1970-01-01');

        $now = Time::now();
        $daysSinceAttackerReg = $attackerCreatedAt->difference($now)->getDays();
        $daysSinceDefenderReg = $defenderCreatedAt->difference($now)->getDays();

        if ($daysSinceAttackerReg < 10 || $daysSinceDefenderReg < 10) {
            return [
                'allowed' => false,
                'reason'  => 'Один из игроков зарегистрирован менее 10 дней назад — PvP для новичков отключено.'
            ];
        }

        // Если все проверки пройдены — разрешаем
        return [
            'allowed' => true,
            'reason'  => 'Ok'
        ];
    }
}
