<?php

declare(strict_types=1);

namespace App\Services\PVE;

use App\Models\BattleLogModel;

class PveBattleLogService
{
    /**
     * @var BattleLogModel
     */
    protected BattleLogModel $battleLogModel;

    /**
     * Массив для сбора данных лога боя.
     *
     * Структура:
     * [
     *   'characters' => [
     *       'player' => [...],
     *       'npc'    => [...],
     *   ],
     *   'rounds' => [
     *       // подробные данные каждого раунда
     *   ],
     *   'outcome' => [
     *       'type'     => 'normal',
     *       'winnerId' => <id победителя>,
     *       'loserId'  => <id проигравшего или null>,
     *   ]
     * ]
     *
     * @var array
     */
    protected array $logData = [];

    public function __construct()
    {
        $this->battleLogModel = new BattleLogModel();
    }

    /**
     * Инициализирует лог с базовыми данными участников боя.
     *
     * @param array $playerData Данные игрока.
     * @param array $npcData    Данные NPC.
     */
    public function init(array $playerData, array $npcData): void
    {
        $this->logData['characters'] = [
            'player' => $playerData,
            'npc'    => $npcData,
        ];
        $this->logData['rounds'] = [];
    }

    /**
     * Добавляет подробную информацию о раунде боя.
     *
     * @param int    $round                Номер раунда.
     * @param string $attacker             Имя атакующего.
     * @param string $defender             Имя защищающегося.
     * @param float  $baseDamage           Исходный базовый урон атакующего.
     * @param float  $levelDifference      Разница уровней (влияет на урон).
     * @param float  $strengthBonus        Бонус от силы.
     * @param float  $agilityBonus         Бонус от ловкости.
     * @param float  $totalArmor           Суммарная броня защитника (база + бонус).
     * @param float  $armorEffect          Эффект брони.
     * @param float  $finalDamage          Итоговый урон, нанесённый в раунде.
     * @param float  $defenderHealthAfter  Оставшееся здоровье защитника после удара.
     * @param bool   $luckyStrike          Флаг "счастливого удара" (если применимо).
     */
    public function addRoundLog(
        int $round,
        string $attacker,
        string $defender,
        float $baseDamage,
        float $levelDifference,
        float $strengthBonus,
        float $agilityBonus,
        float $totalArmor,
        float $armorEffect,
        float $finalDamage,
        float $defenderHealthAfter,
        bool $luckyStrike = false
    ): void {
        $this->logData['rounds'][] = [
            'round'                => $round,
            'attacker'             => $attacker,
            'defender'             => $defender,
            'base_damage'          => $baseDamage,
            'level_difference'     => $levelDifference,
            'strength_bonus'       => $strengthBonus,
            'agility_bonus'        => $agilityBonus,
            'total_armor'          => $totalArmor,
            'armor_effect'         => $armorEffect,
            'final_damage'         => $finalDamage,
            'defender_health_after'=> $defenderHealthAfter,
            'luckyStrike'          => $luckyStrike,
        ];
    }

    /**
     * Устанавливает итог боя.
     *
     * @param array $outcome Ассоциативный массив с итоговыми данными.
     */
    public function setOutcome(array $outcome): void
    {
        $this->logData['outcome'] = $outcome;
    }

    /**
     * Сохраняет лог боя в базу данных через модель BattleLogModel.
     *
     * @param string   $battleType Тип боя (например, 'PVE').
     * @param int|null $playerId   ID игрока.
     * @param int|null $npcId      ID NPC (если применимо).
     * @param int      $winnerId  ID победителя.
     *
     * @return bool|int Возвращает ID вставленной записи или false при ошибке.
     */
    public function saveLog(string $battleType, ?int $playerId, ?int $npcId, int $winnerId)
    {
        $data = [
            'battle_type' => $battleType,
            'player1_id'  => $playerId,
            'player2_id'  => $npcId,
            'winner_id'   => $winnerId,
            'created_at'  => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
            'log_data'    => json_encode($this->logData, JSON_UNESCAPED_UNICODE),
        ];

        return $this->battleLogModel->insert($data);
    }
}
