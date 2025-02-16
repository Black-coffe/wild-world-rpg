<?php

namespace App\Controllers;

use App\Services\Player\PvEService;
use App\Services\PVE\BattleService;
use App\Services\PVE\DamageService;
use App\Services\PVE\EffectService;
use App\Services\PVE\RewardService;
use App\Services\PVE\EquipmentService;
use App\Services\PVE\BattleLogger;
use CodeIgniter\RESTful\ResourceController;
use Psr\Log\NullLogger;

class PvETestController extends ResourceController
{
    private PvEService $pveService;

    public function __construct()
    {
        $logger = new NullLogger();
        $damageService = new DamageService($logger);
        $effectService = new EffectService($logger);
        $battleLogger = new BattleLogger($logger);
        $rewardService = new RewardService($logger);
        $equipmentService = new EquipmentService($logger);
        $battleService = new BattleService($damageService, $effectService, $battleLogger, $logger);
        $this->pveService = new PvEService($battleService, $rewardService, $equipmentService, $logger);
    }

    /**
     * Запускает тестовый бой с 2 игроками и 2 NPC.
     */
    public function fight()
    {
        // Тестовые данные игроков
        $players = [
            [
                'id' => 1,
                'name' => 'TestPlayer1',
                'level' => 10,
                'health' => 100,
                'tired' => 50,
                'strength' => 20,
                'agility' => 15,
                'intellect' => 10,
                'damage_value' => 12,
                'armor' => 5,
                'damage_type' => 'Physical',
                'equipment' => [
                    'weapon' => ['name' => 'Iron Sword', 'damage_value' => 15, 'attack_speed' => 1.2],
                    'body' => ['name' => 'Leather Armor', 'armor_value' => 8, 'physical_resistance' => 10]
                ]
            ],
            [
                'id' => 2,
                'name' => 'TestPlayer2',
                'level' => 9,
                'health' => 100,
                'tired' => 55,
                'strength' => 18,
                'agility' => 17,
                'intellect' => 12,
                'damage_value' => 10,
                'armor' => 6,
                'damage_type' => 'Physical',
                'equipment' => [
                    'weapon' => ['name' => 'Steel Dagger', 'damage_value' => 13, 'attack_speed' => 1.4],
                    'body' => ['name' => 'Chainmail Armor', 'armor_value' => 10, 'physical_resistance' => 12]
                ]
            ]
        ];

        // Тестовые данные NPC
        $npcs = [
            [
                'id' => 101,
                'name' => 'TestNPC1',
                'level' => 8,
                'health' => 80,
                'tired' => 60,
                'strength' => 15,
                'agility' => 12,
                'intellect' => 5,
                'damage_value' => 10,
                'armor' => 3,
                'damage_type' => 'Physical',
                'isBoss' => false
            ],
            [
                'id' => 102,
                'name' => 'TestNPC2',
                'level' => 7,
                'health' => 85,
                'tired' => 65,
                'strength' => 16,
                'agility' => 10,
                'intellect' => 6,
                'damage_value' => 11,
                'armor' => 4,
                'damage_type' => 'Physical',
                'isBoss' => false
            ]
        ];

        // Запускаем бой
        $biome = "Grasslands"; // Можно менять биом для тестов
        $result = [];

        for ($round = 1; $round <= 4; $round++) {
            // Определяем пары для сражения
            if ($round === 1) {
                $attacker = $players[0];
                $defender = $npcs[0];
            } elseif ($round === 2) {
                $attacker = $players[1];
                $defender = $npcs[1];
            } elseif ($round === 3) {
                $attacker = $players[0];
                $defender = $npcs[1];
            } else {
                $attacker = $players[1];
                $defender = $npcs[0];
            }

            $fightResult = $this->pveService->attack($attacker, $defender, $biome);
            $result[] = [
                'round' => $round,
                'attacker' => $attacker['name'],
                'defender' => $defender['name'],
                'fightResult' => $fightResult
            ];
        }

        return $this->respond($result, 200);
    }
}
