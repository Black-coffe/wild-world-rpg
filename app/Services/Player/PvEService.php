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
    // --- Константы (старые + новые) ------------------

    /**
     * Каждые 15 раундов боя повышаем damageBoost на 0.15 (то есть +15%)
     * Это «усталость» защиты или «ярость» атаки в затяжном бою.
     */
    private const ROUNDS_PER_DAMAGE_INCREASE = 10;
    private const DAMAGE_INCREASE_PER_STEP   = 0.15;

    /**
     * Лимит раундов боя (в PvE короче, чем в PvP)
     */
    private const MAX_ROUNDS                 = 60;

    /**
     * Штрафы при смерти (непосредственно не используем тут,
     * но оставляем для наглядности, если захотим углубить механику).
     */
    private const DEATH_EXP_LOSS_PERCENT     = 0.05;
    private const DEATH_STAT_LOSS_PERCENT    = 0.005;

    /**
     * Награда за победу (пример: игрок получает +5% к текущему опыту).
     */
    private const WINNER_EXP_BASE_BONUS      = 0.05;

    /**
     * Используемая в базовом коде «граничная» логика:
     * разница уровней зажимается до -5…+5, а на каждый уровень даёт 0.02 (2%).
     * Ниже мы для демонстрации оставим её, но можем ослабить/убрать.
     */
    private const LEVEL_DIFF_BONUS_PER_LVL   = 0.02;
    private const LEVEL_DIFF_CAP             = 5;

    /**
     * Аналогично для силы (Strength) есть фактор 0.001,
     * но жёсткий кап min(0.1, $bonus) => максимум +10% к урону.
     * В новом подходе мы частично ослабим эти ограничения,
     * но оставим для совместимости.
     */
    private const STATS_BONUS_FACTOR         = 0.001;

    /**
     * Модификатор урона от «опасного» биома:
     * если danger_level=6 => 1 + ((6-1) * 0.1) = 1.5 (то есть +50%).
     */
    private const DAMAGE_BIOME_BASE = 0.1;

    /**
     * --------------------------------------------------------------------------------
     *  Ниже — новые для нас поля/константы, связанные с «Боевой силой» (Combat Power).
     * --------------------------------------------------------------------------------
     */

    /**
     * Если разница CombatPower > 100, мы хотим дать ~99% шанс победы игроку
     * (или сильно повысить урон).
     * Но в данном примере мы будем использовать ratio-логику (см. computeDamageRatio).
     * Вдобавок приведён «шкальный» пример для лога.
     */
    private const POWER_DIFF_SCALE = [
        10   => 0.20,  // при разнице >10 => 50% базовый ориентир
        20   => 0.30,
        30   => 0.50,
        40   => 0.60,
        50   => 0.80,
        100  => 0.99,  // 100+ => 99%
    ];

    /**
     * Минимум и максимум ratio, который мы будем применять при раундовом бое.
     * Например, если difference слишком большой, ratio = 2.0 =>
     * игрок бьёт в 2 раза больнее, а получает урон в 2 раза меньше.
     */
    private const MIN_DAMAGE_RATIO = 0.3;  // игрок на порядок слабее => NPC бьёт в ~2.5 раза сильнее
    private const MAX_DAMAGE_RATIO = 2.1;  // игрок на порядок сильнее => бьёт в 1.6 раза сильнее

    /**
     * Внутренние модели
     */
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

    /**
     * Вспомогательное поле: мы будем хранить отдельно подсчитанную ratio,
     * чтобы использовать её в методе computeDamage(...).
     */
    private $damageRatioForThisFight = 1.0;

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
     * Основной метод PvE-атаки.
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

        // Проставляем здоровье и координаты NPC
        $npc['health']      = $npcSpawn['current_health'];
        $npc['cell_number'] = $npcSpawn['cell_number'];

        // Находим биом
        $mapRow = $this->mapModel->where('cell_number', $player['cell_number'])->first();
        if (!$mapRow) {
            return ['error' => "Локация игрока не найдена"];
        }
        $biome = $this->biomeModel->find($mapRow['biome_id']);
        if (!$biome) {
            return ['error' => "Биом не найден"];
        }

        // Считаем combatPower (player / npc), определяем ratio
        $playerPower  = $this->computeCombatPower($player, true);
        $npcPower     = $this->computeCombatPower($npc, false);
        $difference   = $playerPower - $npcPower;
        $this->damageRatioForThisFight = $this->computeDamageRatio($difference);

        // Тип NPC
        $npcType = strtolower($npc['npc_type']);

        // Запускаем бой
        $fightResult = $this->simulateFight($player, $npc, $biome, $npcType);

        // Проверяем исход
        if (!empty($fightResult['winner']) && $fightResult['winner']['id'] === $player['id']) {
            // Победа игрока
            $this->grantRewards($player, $npc, $fightResult);
            $this->npcSpawnModel->markAsDead($npcSpawnId);

            // --- ВАЖНО: Игрок выжил => сохраним итоговое здоровье и случайную выносливость ---
            // Предполагаем, что после боя в $player['health'] лежит остаток HP игрока
            $finalHealth = max(1, (int) floor($player['health']));
            // Выносливость = рандом от 1 до finalHealth
            $randomTired = rand(1, $finalHealth);

            $player['health'] = $finalHealth;
            $player['tired']  = $randomTired;

            // Записываем в БД
            $this->characterModel->update($player['id'], [
                'health' => $player['health'],
                'tired'  => $player['tired'],
            ]);

        } elseif (!empty($fightResult['winner']) && $fightResult['winner']['id'] !== $player['id']) {
            // Поражение игрока
            $deathService = new DeathService();
            $deathService->handlePlayerDeathAndReward($player['id'], null);
        }

        // Логируем бой
        $this->logBattle($player, $npc, $fightResult, 'PVE');

        // Формируем текст
        $finalText = $this->buildFightResultMessage($player, $npc, $fightResult);
        // Отправляем уведомление
        $this->sendTelegramNotification($player, $finalText);

        return [
            'message'  => $finalText,
            'fightLog' => $fightResult['roundLogs'],
        ];
    }

    /**
     * === Новый метод ===
     * Считаем «боевую силу» персонажа (или NPC), чтобы понять разницу.
     * В реальном проекте формулу можно усложнить/упростить.
     *
     * $isPlayer — флажок, чтобы использовать методы (getEquippedWeapon и т.д.)
     */
    private function computeCombatPower(array $charData, bool $isPlayer): float
    {
        $power = 0.0;

        // 1) Уровень:
        $lvl = $charData['level'] ?? 1;
        $power += $lvl;  // базовая добавка

        // 2) Сумма статов
        // (strength + agility + intellect) * 0.5
        // Или любая формула на ваш вкус
        $str = $charData['strength']  ?? 0;
        $agi = $charData['agility']   ?? 0;
        $int = $charData['intellect'] ?? 0;

        $statsSum = $str + $agi + $int;
        $power += ($statsSum * 0.5);

        // 3) Если это игрок, учитываем оружие/броню
        if ($isPlayer) {
            // Оружие
            $weaponRow = $this->getEquippedWeapon($charData);
            if ($weaponRow) {
                $wDmg  = $weaponRow['damage_value'];
                $wRare = $weaponRow['rarity'] ?? 'Common';
                $wMult = $this->getRarityCoefficient($wRare);
                // Пусть weaponPower = урон * редкость * 2
                $weaponPower = $wDmg * $wMult * 2;
                $power += $weaponPower;
            }

            // Броня: суммируем все виды резиста
            $armorRes = $this->getTotalArmorResistance($charData);
            // пусть 1% резиста = +0.5 к силе
            $power += (0.5 * $armorRes);
        } else {
            // Если NPC, тоже можно учесть его damage_value и уровень.
            // Но у нас уже добавили lvl и можно добавить weaponPower = damage_value * 2 (например)
            if (!empty($charData['damage_value'])) {
                $power += ($charData['damage_value'] * 2);
            }
        }

        return max(1.0, $power); // чтобы не было нуля
    }

    /**
     * === Новый метод ===
     * Получаем суммарный physical_resistance + fire + poison (в %) у игрока,
     * но не более 90 (т.к. computeArmorResistance даёт min(0.9).
     */
    private function getTotalArmorResistance(array $charData): float
    {
        $equippedOutfits = $this->charactersOutfitsModel
            ->where('character_id', $charData['id'])
            ->where('equipped', 1)
            ->findAll();

        $resSum = 0.0;
        foreach ($equippedOutfits as $eq) {
            $outfit = $this->outfitsModel->find($eq['outfit_id']);
            if (!$outfit) {
                continue;
            }
            // сложим все 3 типа резиста
            $resSum += ($outfit['physical_resistance'] ?? 0);
            $resSum += ($outfit['fire_resistance'] ?? 0);
            $resSum += ($outfit['poison_resistance'] ?? 0);
        }
        // чтобы не зашкаливало, допустим до 90
        return min(90, $resSum);
    }

    /**
     * === Новый метод ===
     * На основе разницы (playerPower - npcPower) вычисляем ratio.
     * Если difference > 100, ratio = 2.0 (игрок бьёт в 2 раза больнее и получает в 2 раза меньше урона).
     * Если difference < -100, ratio = 0.4 (или 0.2?), то NPC намного сильнее.
     */
    private function computeDamageRatio(float $difference): float
    {
        // Можно сделать логику, что за каждые +/- X difference
        // мы меняем ratio на +/-. Или взять «классический» подход:
        if ($difference > 100) {
            return self::MAX_DAMAGE_RATIO; // 2.0
        } elseif ($difference < -100) {
            return self::MIN_DAMAGE_RATIO; // 0.4
        }

        // Для промежуточных значений — пропорция. Пример (линейная):
        // difference=0 => ratio=1.0
        // difference=100 => ratio=2.0
        // difference=-100 => ratio=0.4
        // Можно придумать формулу:
        $rangeHigh = 100;  // при +100 => ratio=2
        $rangeLow  = -100; // при -100 => ratio=0.4
        $span = $rangeHigh - $rangeLow; // 200
        $ratioSpan = self::MAX_DAMAGE_RATIO - self::MIN_DAMAGE_RATIO; // 2.0 - 0.4=1.6

        $position = ($difference - $rangeLow) / $span; // (diff +100)/200 => от 0..1
        $rawRatio = self::MIN_DAMAGE_RATIO + $ratioSpan * $position;
        // Гарантируем диапазон
        $finalRatio = max(self::MIN_DAMAGE_RATIO, min(self::MAX_DAMAGE_RATIO, $rawRatio));

        return $finalRatio;
    }

    /**
     * Пошаговая логика боя.
     * При каждом ударе, если игрок является защитником, обновляем $player['health'].
     * После боя сохраняем обновлённые показатели в БД и возвращаем результаты.
     */
    public function simulateFight(array $player, array $enemy, array $biome, string $npcType)
    {
        // Логируем начальные параметры
        log_message('info', sprintf(
            "simulateFight() START: Player#%d HP=%s, NPC#%d HP=%s, Biome=%s, NPC-Type=%s",
            $player['id'] ?? 0, $player['health'] ?? '??',
            $enemy['id'] ?? 0, $enemy['health'] ?? '??',
            $biome['name'] ?? 'unknown',
            $npcType
        ));

        $roundLogs = [];
        // Определяем, кто бьёт первым
        if ($npcType === 'hostile' || $npcType === 'boss') {
            $attacker = $enemy;
            $defender = $player;
        } else {
            $attacker = $player;
            $defender = $enemy;
        }

        $round = 0;
        $damageBoost = 1.0; // растёт каждые N раундов
        $isFirstHit = true;
        $winner = null;
        $loser = null;
        $firstAttackerName = $attacker['name'] ?? $attacker['npc_name_ru'] ?? '???';

        // Основной цикл боя
        while ($player['health'] > 0 && $enemy['health'] > 0 && $round < self::MAX_ROUNDS) {
            $round++;
            if ($round % self::ROUNDS_PER_DAMAGE_INCREASE === 0) {
                $damageBoost += self::DAMAGE_INCREASE_PER_STEP;
                log_message('debug', "Round={$round}: damageBoost вырос до {$damageBoost}");
            }

            if ($npcType === 'friendly' && $round >= 5) {
                $roundLogs[] = [
                    'round' => $round,
                    'event' => "Дружелюбный NPC прекратил бой после 5 раундов."
                ];
                log_message('debug', "Round={$round}: Friendly NPC вышел из боя");
                break;
            }

            // Вычисляем базовый урон
            $calc = $this->computeDamage($attacker, $defender, $biome, $isFirstHit);
            $baseDamage = $calc['finalDamage'];

            if (isset($attacker['npc_name_ru'])) {
                $damage = $baseDamage / $this->damageRatioForThisFight;
            } else {
                $damage = $baseDamage * $this->damageRatioForThisFight;
            }
            $damage *= $damageBoost;

            $defenderHealthBefore = $defender['health'];
            $defender['health'] = max(0, $defender['health'] - $damage);

            // Если защитник — это игрок, то:
            if ((int)$defender['id'] === (int)$player['id']) {
                $player['health'] = $defender['health'];
            } elseif ((int)$attacker['id'] === (int)$player['id']) {
                $player['health'] = $attacker['health'];
            }

            $roundInfo = [
                'round'                => $round,
                'attacker'             => $attacker['name'] ?? $attacker['npc_name_ru'],
                'defender'             => $defender['name'] ?? $defender['npc_name_ru'],
                'damage'               => round($damage, 2),
                'ratioApplied'         => round($this->damageRatioForThisFight, 2),
                'damageBoost'          => round($damageBoost, 2),
                'defenderHealthBefore' => round($defenderHealthBefore, 2),
                'defenderHealthAfter'  => round($defender['health'], 2),
            ];
            $roundLogs[] = $roundInfo;
            log_message('debug', sprintf(
                "Round=%d Attacker=%s => Defender=%s: Damage=%.2f, ratio=%.2f, boost=%.2f, HPbefore=%.2f, HPafter=%.2f",
                $round,
                $roundInfo['attacker'],
                $roundInfo['defender'],
                $roundInfo['damage'],
                $roundInfo['ratioApplied'],
                $roundInfo['damageBoost'],
                $roundInfo['defenderHealthBefore'],
                $roundInfo['defenderHealthAfter']
            ));

            if ($defender['health'] <= 0) {
                $loser = $defender;
                $winner = $attacker;
                log_message('info', "simulateFight() - Бой завершён на round={$round}, победитель={$winner['id']} проигравший={$loser['id']}");
                break;
            }

            list($attacker, $defender) = [$defender, $attacker];
            $isFirstHit = false;
        }

        $resultType = 'normal';
        if ($round >= self::MAX_ROUNDS) {
            $resultType = 'exhausted';
            log_message('info', "simulateFight() - Бой достиг лимита раундов (MAX_ROUNDS={$round})");
        }

        log_message('debug', "simulateFight() BEFORE clamp: player[#{$player['id']}] HP={$player['health']}");
        $finalHealth = max(1, (int) floor($player['health']));
        $randomTired = rand(1, $finalHealth);
        $player['health'] = $finalHealth;
        $player['tired']  = $randomTired;
        log_message('info', "simulateFight() AFTER clamp => Player[#{$player['id']}] finalHP=$finalHealth, tired=$randomTired");

        $this->characterModel->update($player['id'], [
            'health' => $player['health'],
            'tired'  => $player['tired'],
        ]);
        log_message('info', sprintf(
            "simulateFight() DB-update: Player[#%d] -> health=%d, tired=%d (СОХРАНЕНО В characters)",
            $player['id'],
            $player['health'],
            $player['tired']
        ));

        return [
            'type'          => $resultType,
            'rounds'        => $round,
            'firstAttacker' => $firstAttackerName,
            'winner'        => $winner,
            'loser'         => $loser,
            'roundLogs'     => $roundLogs,
        ];
    }

    /**
     * Стандартный метод расчёта базового урона (ещё до учёта ratio).
     * Сюда мы встроили старые механики: levelBonus, statsBonus, biome.
     */
    protected function computeDamage(array $attacker, array $defender, array $biome, bool $isFirstHit): array
    {
        // Определяем базу (если NPC, берём damage_value, если игрок — считаем оружие).
        if (isset($attacker['npc_name_ru'])) {
            $D_equip = (float)$attacker['damage_value'];
        } else {
            $D_equip = $this->computeEquipmentDamage($attacker, $defender);
        }

        // Старый levelBonus (но кап до ±5)
        $levelBonus = $this->computeLevelBonus($attacker, $defender);
        // Старый statsBonus (но макс +10%)
        $statsBonus = $this->computeStatsBonus($attacker);

        // initBonus 5% от $D_equip (только в первый удар данного атакующего)
        $initBonus = $isFirstHit ? (0.05 * $D_equip) : 0.0;

        // Складываем
        $baseDamage = $D_equip
            + ($levelBonus * $D_equip)
            + ($statsBonus * $D_equip)
            + $initBonus;

        // Учитываем biome
        $danger = $biome['danger_level'] ?? 1;
        $biomeCoeff = 1 + (($danger - 1) * self::DAMAGE_BIOME_BASE);
        $damageAfterBiome = $baseDamage * $biomeCoeff;

        return [
            'finalDamage' => max(0, $damageAfterBiome),
        ];
    }

    /**
     * Считаем урон игрока от оружия (старая логика).
     */
    private function computeEquipmentDamage(array $attacker, array $defender): float
    {
        $weapon = $this->getEquippedWeapon($attacker);
        if (!$weapon) {
            // Если нет оружия, берём что-то условное
            $D_base = 2.0;
            $rarityCoeff = 1.0;
            $damageType = 'Physical';
            $rangeVal   = 1.0;
        } else {
            $D_base     = (float)$weapon['damage_value'];
            $rarity     = $weapon['rarity'] ?? 'Common';
            $rarityCoeff= $this->getRarityCoefficient($rarity);
            $damageType = $weapon['damage_type'] ?? 'Physical';
            $rangeVal   = (float)($weapon['range_value'] ?? 1.0);
        }

        // Armor (defender)
        $R_armor = $this->computeArmorResistance($defender, $damageType);
        $F_type  = max(0, 1 - $R_armor);

        // Distance
        $distance = $this->computeDistance($attacker, $defender);
        $F_range  = $this->computeRangePenalty($rangeVal, $distance);

        $D_equip = $D_base * $rarityCoeff * $F_type * $F_range;
        return max(0, $D_equip);
    }

    private function getEquippedWeapon(array $attacker): ?array
    {
        $row = $this->charactersWeaponsModel
            ->where('character_id', $attacker['id'])
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
            'crit_chance'  => isset($weapon['special_effect']) ? 10.0 : 0.0,
        ];
    }

    /**
     * Подсчитываем броню (резист) защитника (если у него есть экипированная броня).
     * Возвращаем от 0 до 0.9 (то есть 90%).
     */
    private function computeArmorResistance(array $defender, string $damageType): float
    {
        $equippedOutfits = $this->charactersOutfitsModel
            ->where('character_id', $defender['id'] ?? 0)
            ->where('equipped', 1)
            ->findAll();

        $resSum = 0.0;
        foreach ($equippedOutfits as $eq) {
            $outfit = $this->outfitsModel->find($eq['outfit_id']);
            if (!$outfit) {
                continue;
            }
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

    /**
     * Штраф за слишком большую дистанцию (если distance > weaponRange).
     */
    private function computeRangePenalty(float $weaponRange, float $distance): float
    {
        if ($distance <= $weaponRange) {
            return 1.0;
        }
        $ratio = $weaponRange / $distance;
        return max(0.0, $ratio);
    }

    /**
     * Вычисление дистанции через mapModel (координаты).
     */
    private function computeDistance(array $charA, array $charB): float
    {
        $cellA = $charA['cell_number'] ?? null;
        $cellB = $charB['cell_number'] ?? null;
        if ($cellA === null || $cellB === null) {
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

    /**
     * Старая формула разницы уровней (bounded: ±5).
     */
    protected function computeLevelBonus(array $attacker, array $defender): float
    {
        $diff = ($attacker['level'] ?? 1) - ($defender['level'] ?? 1);
        $bounded = max(-self::LEVEL_DIFF_CAP, min(self::LEVEL_DIFF_CAP, $diff));
        return $bounded * self::LEVEL_DIFF_BONUS_PER_LVL;
    }

    /**
     * Старая формула силы: (strength * 0.001), max=0.1
     */
    protected function computeStatsBonus(array $attacker): float
    {
        $statVal = $attacker['strength'] ?? 0;
        $bonus = $statVal * self::STATS_BONUS_FACTOR;
        return min(0.1, $bonus);
    }

    /**
     * Логирование боя в таблицу battle_logs.
     */
    private function logBattle(array $player, array $npc, array $fightResult, string $battleType): void
    {
        $logDetails = [
            'player' => [
                'id'     => $player['id'],
                'name'   => $player['name'],
                'level'  => $player['level'],
                'health' => $player['health']
            ],
            'npc' => [
                'id'     => $npc['id'],
                'name'   => $npc['npc_name_ru'],
                'level'  => $npc['level'],
                'health' => $npc['health']
            ],
            'rounds'  => $fightResult['roundLogs'] ?? [],
            'outcome' => [
                'winnerId' => $fightResult['winner']['id'] ?? null,
                'loserId'  => $fightResult['loser']['id']  ?? null,
            ]
        ];
        $logJson = json_encode($logDetails, JSON_UNESCAPED_UNICODE);

        $battleData = [
            'battle_type' => $battleType,
            'player1_id'  => $player['id'],
            'player2_id'  => $npc['id'],
            'winner_id'   => $fightResult['winner']['id'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
            'log_data'    => $logJson
        ];
        $this->battleLogModel->insert($battleData);
    }

    /**
     * Строим финальное сообщение.
     */
    private function buildFightResultMessage(array $player, array $npc, array $fightResult): string
    {
        $text = "🤖 <b>PvE-бой завершён</b>\n";

        $first = $fightResult['firstAttacker'] ?? '???';
        $rounds= $fightResult['rounds'] ?? 0;
        $text .= "⚔️ <b>Первым атаковал:</b> {$first}\n";
        $text .= "🔁 <b>Раундов:</b> {$rounds}\n";

        // Если не определён loser => ничья/прерывание
        if (empty($fightResult['loser'])) {
            if ($fightResult['type'] === 'exhausted') {
                $text .= "<b>Оба выдохлись или бой прерван (MAX_ROUNDS)</b>";
            } else {
                $text .= "<b>Не определён победитель (возможно дружелюбный NPC)</b>";
            }
            return $text;
        }

        $winner = $fightResult['winner'];
        $loser  = $fightResult['loser'];

        $winnerName = $winner['name'] ?? $winner['npc_name_ru'] ?? '???';
        $loserName  = $loser['name']  ?? $loser['npc_name_ru']  ?? '???';

        $text .= "❌ <b>Проиграл:</b> {$loserName}\n"
            . "🏆 <b>Победил:</b> {$winnerName}\n\n";

        // Победил сам игрок?
        if (!empty($winner['id']) && $winner['id'] === $player['id']) {
            $text .= "🏆 <b>Ты одержал верх над {$npc['npc_name_ru']}!</b>\n";
            $gainedExp  = $npc['experience_reward'] ?? 0;
            $gainedGold = $npc['gold_reward'] ?? 0;
            $text .= "Награда:\n"
                . "• Опыт: +{$gainedExp}\n"
                . "• Золото: +{$gainedGold}\n"
                . "🔥 <i>Твой триумф вдохновляет!</i>";

            // --- ДОБАВЛЕННЫЕ СТРОКИ: сообщаем текущее здоровье/выносливость ---
            $curHP    = $player['health'] ?? 1;  // то что мы сохранили
            $curTired = $player['tired']  ?? 1;
            $text .= "\n\n<u>Текущее состояние:</u>\n";
            $text .= "❤️ Осталось здоровья: {$curHP}\n";
            $text .= "💤 Усталость (выносливость): {$curTired}\n";
            $text .= "⚠️ Будь осторожен, следующий бой может стать последним!";
            // --- КОНЕЦ ДОП. СТРОК ---

        } elseif (!empty($loser['id']) && $loser['id'] === $player['id']) {
            $text .= "❌ <b>Ты пал в бою против {$npc['npc_name_ru']}...</b>\n"
                . "Потери:\n"
                . "• Часть ресурсов\n"
                . "• Уменьшился опыт/характеристики\n"
                . "😰 <i>Горький привкус поражения...</i>";
        }

        return $text;
    }

    /**
     * Отправка итогового сообщения игроку в Telegram.
     */
    private function sendTelegramNotification(array $player, string $message): void
    {
        if (empty($player['telegram_user_id'])) {
            return;
        }
        $tgUserModel = new TelegramUserModel();
        $tgUser = $tgUserModel->find($player['telegram_user_id']);
        if (!$tgUser || empty($tgUser['telegram_id'])) {
            return;
        }

        Request::sendMessage([
            'chat_id'    => $tgUser['telegram_id'],
            'text'       => $message,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Пример награды за победу: игрок получает +5% к опыту (WINNER_EXP_BASE_BONUS).
     * Можно было бы добавить золото, предметы и т.д.
     */
    private function grantRewards(array $player, array $npc, array $fightResult): void
    {
        // К примеру, +5% к текущему experience
        $player['experience'] *= (1 + self::WINNER_EXP_BASE_BONUS);
        $this->characterModel->update($player['id'], $player);

        // Если хотим учесть $npc['experience_reward'] / $npc['gold_reward'],
        // тоже делаем player['experience'] += ..., player['gold'] += ...
    }

    /**
     * Коэффициент редкости оружия/брони.
     */
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
