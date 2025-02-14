<?php

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\NpcModel;
use App\Models\NpcSpawnModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\BattleLogModel;
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;
use App\Models\TelegramUserModel;
use App\Services\Player\DeathService;

use Longman\TelegramBot\Request;

class PvEService
{
    // --- Константы (боевая механика) ------------------
    private const ROUNDS_PER_DAMAGE_INCREASE = 15;   // Каждые 15 раундов повышаем damageBoost
    private const DAMAGE_INCREASE_PER_STEP   = 0.15;
    private const MAX_ROUNDS                 = 100;  // PvE-бой короче, чем PvP

    // Смерть/штрафы
    private const DEATH_EXP_LOSS_PERCENT     = 0.05;
    private const DEATH_STAT_LOSS_PERCENT    = 0.005;

    // Награда победителю (для игрока)
    private const WINNER_EXP_BASE_BONUS      = 0.05;
    private const WINNER_EXP_MAX_ADDITIVE    = 0.1;
    private const WINNER_ATTR_BONUS_CHANCE   = 20;
    private const WINNER_ATTR_BONUS_FACTOR   = 0.001;

    // Уклонение
    private const MAX_DODGE_CHANCE_PERCENT   = 75;

    // Модификатор биома
    private const DAMAGE_BIOME_BASE = 0.1;

    // Lucky Strike
    private const LUCKY_STRIKE_DIFF_FACTOR    = 0.3;
    private const LUCKY_STRIKE_MAX_CHANCE     = 40;
    private const LUCKY_STRIKE_DAMAGE_MULT    = 1.5;
    private const LUCKY_STRIKE_DEBUFF_PERCENT = 0.10;
    private const LUCKY_STRIKE_CHANCE_PER_AGI = 0.02;

    // One-Shot
    private const ONESHOT_LEVELDIFF_THRESHOLD = 50;
    private const ONESHOT_MAX_CHANCE          = 50;

    // Бонусы за уровень и статы
    private const LEVEL_DIFF_BONUS_PER_LVL = 0.02;
    private const LEVEL_DIFF_CAP           = 5;
    private const STATS_BONUS_FACTOR       = 0.001;

    // Модели
    protected $characterModel;
    protected $npcModel;
    protected $npcSpawnModel;
    protected $mapModel;
    protected $biomeModel;
    protected $battleLogModel;
    protected $charactersWeaponsModel;
    protected $weaponsModel;
    protected $charactersOutfitsModel;
    protected $outfitsModel;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->npcModel = new NpcModel();
        $this->npcSpawnModel = new NpcSpawnModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->battleLogModel = new BattleLogModel();
        $this->charactersWeaponsModel = new CharactersWeaponsModel();
        $this->weaponsModel = new WeaponModel();
        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->outfitsModel = new OutfitModel();
    }

    /**
     * Основной метод запуска PvE-боя между игроком и NPC.
     *
     * @param int $playerId
     * @param int $npcSpawnId
     * @return array ['message' => string, 'fightLog' => array]
     */
    public function attack(int $playerId, int $npcSpawnId): array
    {
        $player = $this->characterModel->find($playerId);
        if (!$player) {
            return ['error' => "Игрок не найден"];
        }

        $npcSpawn = $this->npcSpawnModel->find($npcSpawnId);
        if (!$npcSpawn || $npcSpawn['status'] !== 'alive') {
            return ['error' => "NPC не найден или уже мертв"];
        }
        $npc = $this->npcModel->find($npcSpawn['npc_id']);
        if (!$npc) {
            return ['error' => "Запись о NPC не найдена"];
        }

        // Проставляем здоровье и cell_number
        $npc['health'] = $npcSpawn['current_health'];
        $npc['cell_number'] = $npcSpawn['cell_number'];

        // Биом
        $mapRow = $this->mapModel->where('cell_number', $player['cell_number'])->first();
        if (!$mapRow) {
            return ['error' => "Локация игрока не найдена"];
        }
        $biome = $this->biomeModel->find($mapRow['biome_id']);
        if (!$biome) {
            return ['error' => "Биом не найден"];
        }

        // Тип NPC
        $npcType = strtolower($npc['npc_type']);

        // Запуск симуляции
        $fightResult = $this->simulateFight($player, $npc, $biome, $npcType);

        // Обработка исхода
        if (isset($fightResult['winner']['id']) && $fightResult['winner']['id'] == $player['id']) {
            // Победа игрока
            $this->grantRewards($player, $npc, $fightResult);
            $this->npcSpawnModel->markAsDead($npcSpawnId);
        } elseif (isset($fightResult['winner']['id']) && $fightResult['winner']['id'] != $player['id']) {
            // Поражение игрока
            $deathService = new DeathService();
            $deathService->handlePlayerDeathAndReward($player['id'], null);
        }

        // Логируем
        $this->logBattle($player, $npc, $fightResult, 'PVE');

        // Формируем финальный текст
        // (подобно PVP, где мы писали "Ты начал бой, но оказался слабее" и т.д.)
        $finalText = $this->buildFightResultMessage($player, $npc, $fightResult);

        // Отправляем игроку уведомление
        $this->sendTelegramNotification($player, $finalText);

        return [
            'message'  => $finalText,
            'fightLog' => $fightResult['roundLogs']
        ];
    }

    /**
     * Строим текст сообщения по аналогии с PVP:
     * - Кто атаковал первым
     * - Сколько раундов
     * - Кто победил/проиграл
     * - "Потери" или "Награда"
     */
    private function buildFightResultMessage(array $player, array $npc, array $fightResult): string
    {
        // Начинаем с заголовка
        $text = "🤖 <b>PvE-бой завершён</b>\n";

        // Кто атаковал первым
        $first = $fightResult['firstAttacker'] ?? '???';
        $rounds = $fightResult['rounds'] ?? 0;
        $text .= "⚔️ <b>Первым атаковал:</b> {$first}\n";
        $text .= "🔁 <b>Раундов:</b> {$rounds}\n";

        // Если нет loser, возможно бой прерван (friendly NPC) или вышли за MAX_ROUNDS
        if (empty($fightResult['loser'])) {
            // Ничья / истощение
            if ($fightResult['type'] === 'exhausted') {
                $text .= "<b>Оба выдохлись или бой прерван.</b>";
            } else {
                $text .= "<b>Не определён победитель?</b>";
            }
            return $text;
        }

        // Есть winner/loser
        $winner = $fightResult['winner'];
        $loser  = $fightResult['loser'];

        // Определяем имена (если NPC — берём npc_name_ru, если игрок — name)
        $winnerName = $winner['name'] ?? $winner['npc_name_ru'] ?? '???';
        $loserName  = $loser['name']  ?? $loser['npc_name_ru']  ?? '???';

        $text .= "❌ <b>Проиграл:</b> {$loserName}\n"
            . "🏆 <b>Победил:</b> {$winnerName}\n\n";

        // Если победитель — сам игрок
        if (isset($winner['id']) && $winner['id'] === $player['id']) {
            // "Награда"
            $text .= "🏆 <b>Ты одержал верх над {$npc['npc_name_ru']}!</b>\n";
            // Здесь можно расписать, что именно получил (exp, gold).
            // Пример (если grantRewards что-то начисляет):
            $gainedExp = $npc['experience_reward'] ?? 0;
            $gainedGold = $npc['gold_reward'] ?? 0;
            $text .= "Награда:\n"
                . "• Опыт: +{$gainedExp}\n"
                . "• Золото: +{$gainedGold}\n"
                . "🔥 <i>Твой триумф вдохновляет!</i>";
        }
        // Если проиграл сам игрок
        elseif (isset($loser['id']) && $loser['id'] === $player['id']) {
            // "Потери"
            $text .= "❌ <b>Ты пал в бою против {$npc['npc_name_ru']}...</b>\n";
            $text .= "Потери:\n"
                . "• Часть ресурсов\n"
                . "• Уменьшился опыт/характеристики\n"
                . "😰 <i>Горький привкус поражения...</i>";
        }

        return $text;
    }

    /**
     * Отправка сообщения в Telegram (по аналогии с PvP).
     * Берём telegram_user_id из $player, находим в TelegramUserModel => telegram_id => sendMessage.
     */
    private function sendTelegramNotification(array $player, string $message): void
    {
        if (empty($player['telegram_user_id'])) {
            return; // у игрока нет связи с telegram_users
        }

        // Загружаем модель, чтобы найти telegram_id
        $tgUserModel = new TelegramUserModel();
        $tgUser = $tgUserModel->find($player['telegram_user_id']);
        if (!$tgUser || empty($tgUser['telegram_id'])) {
            return; // не знаем chat_id, не можем отправить
        }

        // Отправляем сообщение
        Request::sendMessage([
            'chat_id'    => $tgUser['telegram_id'],
            'text'       => $message,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Симуляция боя (пошаговая логика) между игроком и NPC.
     * @param array $player
     * @param array $npc
     * @param array $biome
     * @param string $npcType
     * @return array
     */
    protected function simulateFight(array $player, array $npc, array $biome, string $npcType): array
    {
        $roundLogs = [];

        // Определяем, кто атакует первым:
        if ($npcType === 'hostile' || $npcType === 'boss') {
            $attacker = $npc;
            $defender = $player;
        } else {
            // Для neutral и friendly игрок атакует первым
            $attacker = $player;
            $defender = $npc;
        }

        $round = 0;
        $damageBoost = 1.0;
        $isFirstHit = true;
        $winner = null;
        $loser = null;
        $firstAttackerName = isset($attacker['name']) ? $attacker['name'] : $attacker['npc_name_ru'];

        while ($player['health'] > 0 && $npc['health'] > 0 && $round < self::MAX_ROUNDS) {
            $round++;
            if ($round % self::ROUNDS_PER_DAMAGE_INCREASE === 0) {
                $damageBoost += self::DAMAGE_INCREASE_PER_STEP;
            }

            // Для friendly NPC: после 5 раундов прекращаем активную атаку
            if ($npcType === 'friendly' && $round >= 5) {
                $roundLogs[] = [
                    'round' => $round,
                    'event' => "Дружелюбный NPC перестал атаковать после 5 раундов."
                ];
                break;
            }

            $calc = $this->computeDamage($attacker, $defender, $biome, $isFirstHit);
            $damage = $calc['finalDamage'] * $damageBoost;

            $defenderHealthBefore = $defender['health'];
            $defender['health'] = max(0, $defender['health'] - $damage);

            $roundLogs[] = [
                'round' => $round,
                'attacker' => isset($attacker['name']) ? $attacker['name'] : $attacker['npc_name_ru'],
                'defender' => isset($defender['name']) ? $defender['name'] : $defender['npc_name_ru'],
                'damage' => round($damage, 2),
                'defenderHealthBefore' => round($defenderHealthBefore, 2),
                'defenderHealthAfter' => round($defender['health'], 2)
            ];

            if ($defender['health'] <= 0) {
                $loser = $defender;
                $winner = $attacker;
                break;
            }

            list($attacker, $defender) = [$defender, $attacker];
            $isFirstHit = false;
        }

        $resultType = 'normal';
        if ($round >= self::MAX_ROUNDS) {
            $resultType = 'exhausted';
        }

        return [
            'type' => $resultType,
            'rounds' => $round,
            'firstAttacker' => $firstAttackerName,
            'winner' => $winner,
            'loser' => $loser,
            'roundLogs' => $roundLogs
        ];
    }

    /**
     * Вычисление урона с адаптацией PvP-формулы.
     * Для NPC базовый урон берется из поля damage_value,
     * для игрока используется логика вычисления экипировки.
     *
     * @param array $attacker
     * @param array $defender
     * @param array $biome
     * @param bool  $isFirstHit
     * @return array
     */
    protected function computeDamage(array $attacker, array $defender, array $biome, bool $isFirstHit): array
    {
        if (isset($attacker['npc_name_ru'])) {
            $D_equip = (float)$attacker['damage_value'];
        } else {
            $D_equip = $this->computeEquipmentDamage($attacker, $defender);
        }

        $levelBonus = $this->computeLevelBonus($attacker, $defender);
        $statsBonus = $this->computeStatsBonus($attacker);
        $initBonus = $isFirstHit ? (0.05 * $D_equip) : 0.0;

        $baseDamage = $D_equip + ($levelBonus * $D_equip) + ($statsBonus * $D_equip) + $initBonus;
        $danger = isset($biome['danger_level']) ? $biome['danger_level'] : 1;
        $biomeCoeff = 1 + (($danger - 1) * self::DAMAGE_BIOME_BASE);
        $damageAfterBiome = $baseDamage * $biomeCoeff;

        return ['finalDamage' => max(0, $damageAfterBiome)];
    }

    /**
     * Вычисление урона от экипировки для игрока.
     */
    private function computeEquipmentDamage(array $attacker, array $defender): float
    {
        $weapon = $this->getEquippedWeapon($attacker);
        if (!$weapon) {
            $D_base = 2.0;
            $rarityCoeff = 1.0;
            $damageType = 'Physical';
            $rangeVal = 1.0;
        } else {
            $D_base = (float)$weapon['damage_value'];
            $rarity = $weapon['rarity'] ?? 'Common';
            $rarityCoeff = $this->getRarityCoefficient($rarity);
            $damageType = $weapon['damage_type'] ?? 'Physical';
            $rangeVal = (float)($weapon['range_value'] ?? 1.0);
        }

        $R_armor = $this->computeArmorResistance($defender, $damageType);
        $F_type = max(0, 1 - $R_armor);
        $distance = $this->computeDistance($attacker, $defender);
        $F_range = $this->computeRangePenalty($rangeVal, $distance);

        $D_equip = $D_base * $rarityCoeff * $F_type * $F_range;
        return max(0, $D_equip);
    }

    private function getEquippedWeapon(array $attacker): ?array
    {
        $row = $this->charactersWeaponsModel->where('character_id', $attacker['id'])
            ->where('equipped', 1)
            ->first();
        if (!$row) {
            return null;
        }
        $weapon = $this->weaponsModel->find($row['weapon_id']);
        if (!$weapon) {
            return null;
        }
        return [
            'damage_value' => (float)$weapon['damage_value'],
            'range_value'  => (float)$weapon['range_value'],
            'damage_type'  => $weapon['damage_type'],
            'rarity'       => $weapon['rarity'],
            'crit_chance'  => isset($weapon['special_effect']) ? 10.0 : 0.0
        ];
    }

    private function computeArmorResistance(array $defender, string $damageType): float
    {
        $equippedOutfits = $this->charactersOutfitsModel->where('character_id', $defender['id'])
            ->where('equipped', 1)
            ->findAll();
        $resSum = 0.0;
        foreach ($equippedOutfits as $eq) {
            $outfit = $this->outfitsModel->find($eq['outfit_id']);
            if (!$outfit) continue;
            switch (strtolower($damageType)) {
                case 'physical':
                default:
                    $resSum += ($outfit['physical_resistance'] ?? 0) / 100.0;
                    break;
                case 'fire':
                    $resSum += ($outfit['fire_resistance'] ?? 0) / 100.0;
                    break;
                case 'poison':
                    $resSum += ($outfit['poison_resistance'] ?? 0) / 100.0;
                    break;
            }
        }
        return min(0.9, $resSum);
    }

    private function computeRangePenalty(float $weaponRange, float $distance): float
    {
        if ($distance <= $weaponRange) {
            return 1.0;
        }
        $ratio = $weaponRange / $distance;
        return max(0.0, $ratio);
    }

    private function computeDistance(array $charA, array $charB): float
    {
        $cellA = isset($charA['cell_number']) ? $charA['cell_number'] : null;
        $cellB = isset($charB['cell_number']) ? $charB['cell_number'] : null;
        if ($cellA === null || $cellB === null) {
            // Если информация о ячейке отсутствует, возвращаем значение по умолчанию
            return 1.0;
        }

        $mapA = $this->mapModel->where('cell_number', $cellA)->first();
        $mapB = $this->mapModel->where('cell_number', $cellB)->first();
        if (!$mapA || !$mapB) {
            return 1.0;
        }
        $dx = abs($mapA['coordinate_x'] - $mapB['coordinate_x']);
        $dy = abs($mapA['coordinate_y'] - $mapB['coordinate_y']);
        return sqrt($dx * $dx + $dy * $dy);
    }

    protected function computeLevelBonus(array $attacker, array $defender): float
    {
        $diff = ($attacker['level'] ?? 1) - ($defender['level'] ?? 1);
        $bounded = max(-self::LEVEL_DIFF_CAP, min(self::LEVEL_DIFF_CAP, $diff));
        return $bounded * self::LEVEL_DIFF_BONUS_PER_LVL;
    }

    protected function computeStatsBonus(array $attacker): float
    {
        $statVal = $attacker['strength'] ?? 0;
        $bonus = $statVal * self::STATS_BONUS_FACTOR;
        return min(0.1, $bonus);
    }

    protected function determineInitiative(array $c1, array $c2): array
    {
        $i1 = ($c1['agility'] ?? 0) + (($c1['level'] ?? 1) + 1) * 0.5;
        $i2 = ($c2['agility'] ?? 0) + (($c2['level'] ?? 1) + 1) * 0.5;
        return ($i1 >= $i2) ? $c1 : $c2;
    }

    private function logBattle(array $player, array $npc, array $fightResult, string $battleType): void
    {
        $logDetails = [
            'player' => [
                'id' => $player['id'],
                'name' => $player['name'],
                'level' => $player['level'],
                'health' => $player['health']
            ],
            'npc' => [
                'id' => $npc['id'],
                'name' => $npc['npc_name_ru'],
                'level' => $npc['level'],
                'health' => $npc['health']
            ],
            'rounds' => $fightResult['roundLogs'] ?? [],
            'outcome' => [
                'winnerId' => isset($fightResult['winner']['id']) ? $fightResult['winner']['id'] : null,
                'loserId' => isset($fightResult['loser']['id']) ? $fightResult['loser']['id'] : null,
            ]
        ];
        $logJson = json_encode($logDetails, JSON_UNESCAPED_UNICODE);
        $battleData = [
            'battle_type' => $battleType,
            'player1_id'  => $player['id'],
            'player2_id'  => $npc['id'],
            'winner_id'   => isset($fightResult['winner']['id']) ? $fightResult['winner']['id'] : null,
            'created_at'  => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
            'log_data'    => $logJson
        ];
        $this->battleLogModel->insert($battleData);
    }

    protected function formatFightSummary(array $fightResult, array $player, array $npc): string
    {
        if (isset($fightResult['winner']['id']) && $fightResult['winner']['id'] == $player['id']) {
            return "Ты победил NPC «{$npc['npc_name_ru']}» за {$fightResult['rounds']} раундов!";
        } else {
            return "Твой бой с NPC «{$npc['npc_name_ru']}» завершился поражением.";
        }
    }

    private function grantRewards(array $player, array $npc, array $fightResult): void
    {
        $exp = $npc['experience_reward'] ?? 0;
        $gold = $npc['gold_reward'] ?? 0;
        $player['experience'] *= (1 + self::WINNER_EXP_BASE_BONUS);
        $this->characterModel->update($player['id'], $player);
    }

    private function getRarityCoefficient(string $rarity): float
    {
        switch (strtolower($rarity)) {
            case 'uncommon':
                return 1.1;
            case 'rare':
                return 1.3;
            case 'epic':
                return 1.5;
            case 'legendary':
                return 1.7;
            default:
                return 1.0;
        }
    }
}
