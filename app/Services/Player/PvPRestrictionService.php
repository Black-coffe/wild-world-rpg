<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\MapModel;
use Carbon\Carbon;

/**
 * Сервис для проверки ограничений перед началом PvP.
 */
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
     * @param array $attacker Массив данных об атакующем.
     * @param array $defender Массив данных об обороняющемся.
     *
     * @return array Вернёт ['allowed' => bool, 'reason' => string]
     *         Если allowed=false, в reason будет описан запрет.
     */
    public function checkPvPAllowed(array $attacker, array $defender): array
    {
        // 1. Уровень < 5 => ни атаковать, ни быть атакованным
        if ($attacker['level'] < 5 || $defender['level'] < 5) {
            return [
                'allowed' => false,
                'reason'  => 'Один из игроков имеет уровень ниже 5 — PvP недоступно.'
            ];
        }

        // 2. Координата Y >= 900 => зона респауна => запрет PvP
        //    Для этого нужно знать cell_number и смотреть MapModel,
        //    чтобы выяснить coordinate_y. Допустим, в $attacker и $defender уже есть cell_number.
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

        // 3. Менее 10 дней с момента регистрации => нельзя атаковать / быть атакованным
        //    Предположим, что в таблице characters есть поле 'created_at'.
        //    Если у вас поле регистрации лежит в telegram_users — подстройтесь аналогичным образом.
        //    Используем библиотеку Carbon для удобного сравнения дат.
        //    Убедитесь, что Carbon подключён в composer.json и use Carbon\Carbon.

        // Преобразуем created_at в объект Carbon
        // (Если у вас нет поля created_at или называется иначе — подкорректируйте код)
        $attackerCreationDate = Carbon::parse($attacker['created_at'] ?? '1970-01-01');
        $defenderCreationDate = Carbon::parse($defender['created_at'] ?? '1970-01-01');

        $daysSinceAttackerReg = $attackerCreationDate->diffInDays(Carbon::now());
        $daysSinceDefenderReg = $defenderCreationDate->diffInDays(Carbon::now());

        if ($daysSinceAttackerReg < 10 || $daysSinceDefenderReg < 10) {
            return [
                'allowed' => false,
                'reason'  => 'Один из игроков зарегистрирован менее 10 дней назад — PvP для новичков отключено.'
            ];
        }

        // Если все проверки пройдены — разрешаем.
        return [
            'allowed' => true,
            'reason'  => 'Ok'
        ];
    }
}
