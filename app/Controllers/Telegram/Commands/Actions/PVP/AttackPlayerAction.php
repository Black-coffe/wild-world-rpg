<?php

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\TelegramUserModel;
use App\Models\CharacterFactionModel;
use App\Models\FactionModel;
use App\Models\ExploredCellsModel;
use App\Models\ClaimedCellModel;

// Новые модели для оружия и брони:
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;

use App\Services\Player\PvPRestrictionService;
use App\Services\Player\DeathService;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;

/**
 * Класс AttackPlayerAction:
 * - Производит PvP-атаку одного игрока на другого
 * - Использует расширенную формулу урона:
 *     75% экипировка (оружие+броня+штраф дистанции+крит ≤50%)
 *     10% уровень
 *     10% характеристики
 *     5% инициатива (первый удар)
 * - Плюс LuckyStrike, OneShot, DeathService и т.д.
 */
class AttackPlayerAction extends BaseAction
{
    // --- Константы (боевая механика) ------------------

    // РАУНДЫ / УРОН
    private const ROUNDS_PER_DAMAGE_INCREASE = 15;   // Каждые N раундов повышаем damageBoost
    private const DAMAGE_INCREASE_PER_STEP   = 0.15; // На сколько повышаем урон каждые 15 раундов
    private const MAX_ROUNDS                 = 150;  // Лимит раундов боя, потом ничья

    // СМЕРТЬ / ШТРАФЫ
    private const DEATH_EXP_LOSS_PERCENT     = 0.05; // 5% потери опыта при смерти
    private const DEATH_STAT_LOSS_PERCENT    = 0.005;// 0.5% потери статов (strength/agi/int) при смерти

    // НАГРАДА ПОБЕДИТЕЛЮ
    private const WINNER_EXP_BASE_BONUS      = 0.05; // 5% базовый бонус к опыту
    private const WINNER_EXP_MAX_ADDITIVE    = 0.1;  // +10% сверху, если враг сильнее
    private const WINNER_ATTR_BONUS_CHANCE   = 20;   // 20% шанс слегка повысить стат
    private const WINNER_ATTR_BONUS_FACTOR   = 0.001;// +0.1%

    // ДОП. УВОРот
    private const MAX_DODGE_CHANCE_PERCENT   = 75;   // Макс. шанс уворота 75%

    // БИОМ
    private const DAMAGE_BIOME_BASE = 0.1; // Усиление урона в зависимости от danger_level

    // LUCKY STRIKE
    private const LUCKY_STRIKE_DIFF_FACTOR    = 0.3;
    private const LUCKY_STRIKE_MAX_CHANCE     = 40;
    private const LUCKY_STRIKE_DAMAGE_MULT    = 1.5;
    private const LUCKY_STRIKE_DEBUFF_PERCENT = 0.10;
    private const LUCKY_STRIKE_CHANCE_PER_AGI = 0.02;

    // ONE-SHOT
    private const ONESHOT_LEVELDIFF_THRESHOLD = 50;
    private const ONESHOT_MAX_CHANCE          = 50;

    // Разница уровней => ±2% за уровень до макс 5 => ±10%
    private const LEVEL_DIFF_BONUS_PER_LVL = 0.02;
    private const LEVEL_DIFF_CAP           = 5;

    // Бонус статов => 100 нужного стата = +10%
    private const STATS_BONUS_FACTOR       = 0.001;

    protected $characterModel;
    protected $mapModel;
    protected $biomeModel;
    protected $telegramUserModel;
    protected $characterFactionModel;
    protected $factionModel;
    protected $claimedCellModel;
    protected $exploredCellsModel;

    // Новые модели для оружия/брони
    protected $charactersWeaponsModel;
    protected $weaponsModel;
    protected $charactersOutfitsModel;
    protected $outfitsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterModel        = new CharacterModel();
        $this->mapModel              = new MapModel();
        $this->biomeModel            = new BiomeModel();
        $this->telegramUserModel     = new TelegramUserModel();
        $this->characterFactionModel = new CharacterFactionModel();
        $this->factionModel          = new FactionModel();
        $this->claimedCellModel      = new ClaimedCellModel();
        $this->exploredCellsModel    = new ExploredCellsModel();

        // Инициализируем модели:
        $this->charactersWeaponsModel = new CharactersWeaponsModel();
        $this->weaponsModel           = new WeaponModel();
        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->outfitsModel           = new OutfitModel();
    }

    /**
     * Главный метод обработки PvP-атаки (логика из старого класса).
     */
    public function handle(): ServerResponse
    {
        // Ожидаем callback_data вида "attackPlayer_###"
        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData);
        $defenderId   = isset($parts[1]) ? (int)$parts[1] : 0;

        // Находим атакующего
        [$user, $attacker] = $this->getUserAndCharacter();
        if (!$user || !$attacker) {
            return $this->sendError("Не найден атакующий персонаж (вы).");
        }
        if ($defenderId <= 0) {
            return $this->sendError("Не указан ID цели атаки. Попробуйте ещё раз.");
        }

        // Находим защищающегося
        $defender = $this->characterModel->find($defenderId);
        if (!$defender) {
            return $this->sendError("Цель (ID {$defenderId}) не найдена.");
        }
        if ($attacker['id'] === $defender['id']) {
            return $this->sendError("Нельзя атаковать самого себя!");
        }

        // Проверка близости ячеек
        if (!$this->isCellsCloseEnough($attacker, $defender)) {
            return $this->sendError("Игрок слишком далеко. Атаковать можно только в одной или соседней ячейке!");
        }

        // Проверка PvP-ограничений
        $pvpRestrictionService = new PvPRestrictionService();
        $check = $pvpRestrictionService->checkPvPAllowed($attacker, $defender);
        if (!$check['allowed']) {
            return $this->sendError("PvP недоступно: {$check['reason']}");
        }

        // Проверяем биом атакующего
        $mapRowAttacker = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        if (!$mapRowAttacker) {
            return $this->sendError("Не найдена локация (map) атакующего!");
        }
        $biome = $this->biomeModel->find($mapRowAttacker['biome_id']);
        if (!$biome) {
            return $this->sendError("Не найден биом для ячейки #{$mapRowAttacker['id']}.");
        }

        // Заполняем фракции (если нужно)
        $attacker['faction'] = $this->getCharacterFaction($attacker['id']);
        $defender['faction'] = $this->getCharacterFaction($defender['id']);

        // 1) Симуляция боя
        $fightResult = $this->simulateFight($attacker, $defender, $biome);

        // 2) Короткий текст о ходе боя
        $summaryText = $this->formatShortFightResult($fightResult);

        $attackerIntro = '';
        $defenderIntro = '';
        $loser  = $fightResult['loser']  ?? null;
        $winner = $fightResult['winner'] ?? null;

        $attackerName = $attacker['name'];
        $defenderName = $defender['name'];

        // --- Случай: ничья, оба выдохлись ---
        if ($fightResult['type'] === 'exhausted') {
            $this->processMutualExhaustion($attacker, $defender);
            $summaryText .= "\n\n<b>Оба бойца изнемогли</b> и решили прекратить схватку!\n"
                . "❤️ Здоровье и выносливость сброшены до 10.\n"
                . "🚶 <i>Они отступили, обдумывая ошибки...</i>";

            $attackerIntro = "Ты участвовал в битве, но оба упали без сил.";
            $defenderIntro = "Тебя атаковали, но сражение закончилось взаимным изнеможением.";
        }
        // --- Есть победитель и проигравший ---
        elseif ($loser !== null && $winner !== null) {
            // DeathService
            $deathService = new DeathService();
            $deathResult  = $deathService->handlePlayerDeathAndReward($loser['id'], $winner['id']);
            // penalty=0 => страховка; 0.03 => есть база; 0.5 => нет базы
            $penaltyPercent = (int)($deathResult['penalty'] * 100);

            if ($penaltyPercent === 0) {
                $summaryText .= "\n\n❌ <b>{$loser['name']}</b> потерпел поражение, но страховка спасла от потери имущества!";
            } else {
                // -5% XP, -0.5% статов
                $loserBefore = $loser;
                $this->processDeathAndRespawn($loser);
                $loser = $this->characterModel->find($loser['id']); // обновлённый

                $loserDiffText = $this->makeLoserDiffText($loserBefore);

                if ($deathResult['hasBase']) {
                    // База => 3%
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> повержен и возродился...\n"
                        . $loserDiffText
                        . "\nТы потерял лишь <b>{$penaltyPercent}%</b> ресурсов/крафта/золота, ведь база частично спасла запасы."
                        . "\nНо <b>враг забрал</b> эти <b>{$penaltyPercent}%</b>!";
                } else {
                    // Нет базы => 50%
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> проиграл бой и был возрождён...\n"
                        . $loserDiffText
                        . "\nТы оказался <b>без базы</b>, так что потерял <b>50%</b> ресурсов/крафта/золота: "
                        . "<i>половина исчезла бесследно, половина (25%) досталась врагу.</i>";
                }
            }

            // Награда победителю
            $winnerBefore     = $winner;
            $this->giveWinnerBonus($winner['id']);
            $winner           = $this->characterModel->find($winner['id']);
            $winnerDiffText   = $this->makeWinnerDiffText($winnerBefore);
            $summaryText     .= "\n\n🏆 <b>{$winner['name']}</b> торжествует! {$winnerDiffText}";

            $attackerIntro = ($attacker['id'] === $winner['id'])
                ? "Ты атаковал и разгромил врага!"
                : "Ты начал бой, но оказался слабее в этот раз...";

            $defenderIntro = ($defender['id'] === $winner['id'])
                ? "На тебя напали, но ты защитился и победил!"
                : "Тебя атаковали, и ты пал в этом бою...";
        }

        // Итоговые сообщения
        $attackerFinalText = "🤺 <b>{$attackerName}</b>, {$attackerIntro}\n\n{$summaryText}";
        $defenderFinalText = "🛡 <b>{$defenderName}</b>, {$defenderIntro}\n\n{$summaryText}";

        // Отправка атакующему
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь',    'callback_data' => 'inventory'],
                    ['text' => '🗺️ Изучить местность','callback_data' => 'explore'],
                ],
            ]
        ];

        Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $attackerFinalText,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ]);

        // Уведомляем защищающегося
        $this->notifyDefender($defender, $attacker, $defenderFinalText);

        return Request::emptyResponse();
    }

    /**
     * Симуляция боя — пошаговый цикл, пока один не упадёт
     * или не превысили MAX_ROUNDS (тогда ничья).
     */
    private function simulateFight(array $p1, array $p2, array $biome): array
    {
        $attacker = $this->determineInitiative($p1, $p2);
        $defender = ($attacker['id'] === $p1['id']) ? $p2 : $p1;

        $round      = 0;
        $maxRounds  = self::MAX_ROUNDS;
        $winner     = null;
        $loser      = null;
        $firstName  = $attacker['name'];
        $damageBoost= 1.0;
        $isFirstHit = true; // инициатива

        while ($attacker['health'] > 0 && $defender['health'] > 0 && $round < $maxRounds) {
            $round++;

            if ($round % self::ROUNDS_PER_DAMAGE_INCREASE === 0) {
                $damageBoost += self::DAMAGE_INCREASE_PER_STEP;
            }

            // LuckyStrike
            $luckyStrikeActive = $this->checkLuckyStrike($attacker, $defender);

            // Рассчитываем урон
            $damage = $this->computeDamage(
                $attacker,
                $defender,
                $biome,
                $luckyStrikeActive,
                $isFirstHit
            );

            // Увеличение damageBoost
            $damage *= $damageBoost;

            // Если LuckyStrike => x1.5 + debuff
            if ($luckyStrikeActive) {
                $damage *= self::LUCKY_STRIKE_DAMAGE_MULT;
                $this->applyLuckyStrikeDebuff($defender);
            }

            // Наносим урон
            $defender['health'] = max(0, $defender['health'] - $damage);

            // Проверяем смерть
            if ($defender['health'] <= 0) {
                $loser  = $defender;
                $winner = $attacker;
                break;
            }

            // Меняем роли
            [$attacker, $defender] = [$defender, $attacker];
            // После первого удара убираем +5% инициативы
            $isFirstHit = false;
        }

        if ($round >= $maxRounds && $attacker['health'] > 0 && $defender['health'] > 0) {
            // ничья
            return [
                'type'          => 'exhausted',
                'rounds'        => $round,
                'firstAttacker' => $firstName,
                'winner'        => null,
                'loser'         => null,
            ];
        }

        if (!$winner && !$loser) {
            // Проверим, не погиб ли кто после выхода из цикла
            if ($attacker['health'] <= 0) {
                $loser  = $attacker;
                $winner = $defender;
            } elseif ($defender['health'] <= 0) {
                $loser  = $defender;
                $winner = $attacker;
            }
        }

        return [
            'type'          => 'normal',
            'rounds'        => $round,
            'firstAttacker' => $firstName,
            'winner'        => $winner,
            'loser'         => $loser,
        ];
    }

    /**
     * Основная формула урона на один удар:
     * - 75% экип + 10% lvl + 10% статы + 5% инициатива
     * - затем уворот, biome, OneShot
     */
    private function computeDamage(
        array $attacker,
        array $defender,
        array $biome,
        bool  $luckyStrikeActive,
        bool  $isFirstHit
    ): float
    {
        // --- 1) D_экип (примерно 75%)
        $D_equip = $this->computeEquipmentDamage($attacker, $defender);

        // --- 2) D_level (около 10%)
        $levelBonus = $this->computeLevelBonus($attacker, $defender);
        $D_level    = $levelBonus * $D_equip;

        // --- 3) D_stats (около 10%)
        $statsBonus = $this->computeStatsBonus($attacker);
        $D_stats    = $statsBonus * $D_equip;

        // --- 4) D_init (5% только на первый удар)
        $D_init = $isFirstHit ? (0.05 * $D_equip) : 0;

        // Сумма
        $damage = $D_equip + $D_level + $D_stats + $D_init;

        // --- Уворот
        $dodgeChance = $this->getDodgeChance($defender);
        if ($this->rollPercent($dodgeChance)) {
            return 0.0; // уворот
        }

        // --- Бонус danger_level
        $danger     = $biome['danger_level'] ?? 1;
        $biomeCoeff = 1 + ((max(1, $danger) - 1) * self::DAMAGE_BIOME_BASE);
        $damage    *= $biomeCoeff;

        // --- One-Shot при diff >= 50
        $levelDiff = $attacker['level'] - $defender['level'];
        if ($levelDiff >= self::ONESHOT_LEVELDIFF_THRESHOLD) {
            $oneShotChance = min(self::ONESHOT_MAX_CHANCE, ($levelDiff / 1000) * 100);
            if ($this->rollPercent($oneShotChance)) {
                // Мгновенная смерть
                $damage = $defender['health'];
            }
        }

        return max(0, $damage);
    }

    // ----------------------------------------------------------------
    // Методы для подсчёта "экипировки" (оружие + броня)
    // ----------------------------------------------------------------

    /**
     * Возвращаем ~75% урона: D_base * K_rar * F_type * F_range * F_spec(крит).
     */
    private function computeEquipmentDamage(array $attacker, array $defender): float
    {
        // 1) Найдём экипированное оружие
        $weapon = $this->getEquippedWeapon($attacker);
        if (!$weapon) {
            // нет оружия => кулаки
            $D_base      = 2.0;
            $rarityCoeff = 1.0;
            $damageType  = 'Physical';
            $rangeVal    = 1.0;
            $critChance  = 0.0;
        } else {
            $D_base      = (float)($weapon['damage_value']  ?? 5.0);
            $rarity      = $weapon['rarity']                ?? 'Common';
            $rarityCoeff = $this->getRarityCoefficient($rarity);
            $damageType  = $weapon['damage_type']           ?? 'Physical';
            $rangeVal    = (float)($weapon['range_value']   ?? 1.0);

            // critChance (ограничим 50%)
            $weaponCrit  = !empty($weapon['crit_chance']) ? (float)$weapon['crit_chance'] : 0.0;
            $critChance  = min($weaponCrit, 50.0);
        }

        // 2) Считаем сопротивление брони у цели
        $R_armor = $this->computeArmorResistance($defender, $damageType);
        $F_type  = max(0, 1 - $R_armor);

        // 3) Штраф дистанции
        $distance = $this->computeDistance($attacker, $defender);
        $F_range  = $this->computeRangePenalty($rangeVal, $distance);

        // 4) Разыгрываем крит?
        $isCrit = $this->rollPercent($critChance);
        $critMultiplier = 2.0;
        $F_spec = $isCrit ? $critMultiplier : 1.0;

        // Итог
        $D_equip = $D_base * $rarityCoeff * $F_type * $F_range * $F_spec;
        return max(0, $D_equip);
    }

    /**
     * Ищем запись в characters_weapons, где equipped=1.
     * Возвращаем данные + поля из weapons (join) или отдельный поиск.
     */
    private function getEquippedWeapon(array $attacker): ?array
    {
        // 1) Ищем в characters_weapons
        $row = $this->charactersWeaponsModel
            ->where('character_id', $attacker['id'])
            ->where('equipped', 1)
            ->first();

        if (!$row) {
            return null; // нет экипированного оружия
        }

        // 2) Находим weaponRow в таблице weapons
        $weapon = $this->weaponsModel->find($row['weapon_id']);
        if (!$weapon) {
            return null;
        }

        // Можно вернуть "слитый" массив с ключевыми полями (damage_value, range_value etc.)
        // + crit_chance, если хранится
        // Допустим, в weapons таблице есть поле "crit_chance", "damage_type", "rarity"
        // Если нет, адаптируйте:
        $weaponData = [
            'damage_value' => (float)$weapon['damage_value'],
            'range_value'  => (float)$weapon['range_value'],
            'damage_type'  => $weapon['damage_type'],
            'rarity'       => $weapon['rarity'],
            // предположим, у нас есть поле crit_chance
            'crit_chance'  => isset($weapon['special_effect']) ? 10.0 : 0.0 // упрощённо
        ];

        return $weaponData;
    }

    /**
     * Суммируем сопротивление (R_armor) по всем надетым предметам. (0..0.9)
     * Если damageType = Physical, суммируем physical_resistance и т.д.
     */
    private function computeArmorResistance(array $defender, string $damageType): float
    {
        $equippedOutfits = $this->charactersOutfitsModel
            ->where('character_id', $defender['id'])
            ->where('equipped', 1)
            ->findAll();

        $resSum = 0.0;
        foreach ($equippedOutfits as $eq) {
            $outfit = $this->outfitsModel->find($eq['outfit_id']);
            if (!$outfit) continue;

            // Например, если physical, то берём $outfit['physical_resistance']
            // fire => $outfit['fire_resistance'], poison => poison_resistance, etc.
            switch (strtolower($damageType)) {
                case 'physical':
                default:
                    $resSum += ($outfit['physical_resistance'] ?? 0) / 100.0;
                    break;
                case 'fire':
                    $resSum += ($outfit['fire_resistance']     ?? 0) / 100.0;
                    break;
                case 'poison':
                    $resSum += ($outfit['poison_resistance']   ?? 0) / 100.0;
                    break;
            }
        }
        // ограничим максимум 90% (0.9), чтобы не было 100% иммунитета
        $resSum = min(0.9, $resSum);

        return $resSum;
    }

    /**
     * Вычисляем штраф по дистанции:
     * Если distance <= weaponRange => 1.0
     * Иначе линейное снижение. (Можно усложнить.)
     */
    private function computeRangePenalty(float $weaponRange, float $distance): float
    {
        if ($distance <= $weaponRange) {
            return 1.0;
        }
        // линейная функция
        $ratio = $weaponRange / $distance; // 0..1
        return max(0.0, $ratio);
    }

    /**
     * Считаем дистанцию (короткий метод, можно по координатам).
     */
    private function computeDistance(array $charA, array $charB): float
    {
        $mapA = $this->mapModel->where('cell_number', $charA['cell_number'])->first();
        $mapB = $this->mapModel->where('cell_number', $charB['cell_number'])->first();
        if (!$mapA || !$mapB) {
            // fallback
            return 1.0;
        }
        $dx = abs($mapA['coordinate_x'] - $mapB['coordinate_x']);
        $dy = abs($mapA['coordinate_y'] - $mapB['coordinate_y']);
        return sqrt($dx*$dx + $dy*$dy);
    }

    // ----------------------------------------------------------------
    // Методы для расчёта 10% уровня, 10% статов, уворота
    // ----------------------------------------------------------------

    /**
     * Разница уровня ±2% за шаг до 5 => ±10% макс
     * Возвращаем коэффициент, который потом умножаем на D_equip (F_lvl-1).
     */
    private function computeLevelBonus(array $attacker, array $defender): float
    {
        $diff = $attacker['level'] - $defender['level'];
        $bounded = max(-self::LEVEL_DIFF_CAP, min(self::LEVEL_DIFF_CAP, $diff));
        return $bounded * self::LEVEL_DIFF_BONUS_PER_LVL; // -0.1 .. +0.1
    }

    /**
     * Простой вариант: 100 силы даёт +10%.
     * Возвращаем число 0..0.1
     */
    private function computeStatsBonus(array $attacker): float
    {
        // Можно решить, какой стат влияет: физ => strength, дальний => agility, итд.
        // Здесь для упрощения — берём strength.
        $statVal = (float)($attacker['strength'] ?? 0);
        $bonus   = $statVal * self::STATS_BONUS_FACTOR;  // 100 => 0.1
        return min(0.1, $bonus);
    }

    /**
     * Уворот: agility * 0.25, макс 75%.
     */
    private function getDodgeChance(array $defender): float
    {
        $raw = ($defender['agility'] ?? 0) * 0.25;
        return min((float)$raw, self::MAX_DODGE_CHANCE_PERCENT);
    }

    /**
     * Кидаем кубик (0..100) < chance?
     */
    private function rollPercent(float $chance): bool
    {
        $roll = mt_rand(0, 10000)/100.0;
        return ($roll < $chance);
    }

    // ----------------------------------------------------------------
    // Старая логика LuckyStrike, DeathService, Respawn и т.д.
    // ----------------------------------------------------------------

    /**
     * Удачный удар, если аттакер слабее (diff<0).
     */
    private function checkLuckyStrike(array $attacker, array $defender): bool
    {
        $lvlDiff = $attacker['level'] - $defender['level'];
        if ($lvlDiff >= 0) return false;

        $absDiff = abs($lvlDiff);
        $chance  = $absDiff * self::LUCKY_STRIKE_DIFF_FACTOR; // base
        // прибавим разницу ловкости
        $agiDiff = $attacker['agility'] - $defender['agility'];
        if ($agiDiff > 0) {
            $chance += ($agiDiff * self::LUCKY_STRIKE_CHANCE_PER_AGI * 100);
        }
        $chance = min($chance, self::LUCKY_STRIKE_MAX_CHANCE);

        return $this->rollPercent($chance);
    }

    /**
     * Debuff: -10% к одному стату.
     */
    private function applyLuckyStrikeDebuff(array &$defender): void
    {
        $stats = ['strength','agility','intellect'];
        $st = $stats[array_rand($stats)];
        $defender[$st] = max(1, $defender[$st] * (1 - self::LUCKY_STRIKE_DEBUFF_PERCENT));
    }

    /**
     * Если оба выдохлись -> set health/tired=10, move to respawn
     */
    private function processMutualExhaustion(array $pA, array $pB): void
    {
        $this->moveExhaustedPlayer($pA['id']);
        $this->moveExhaustedPlayer($pB['id']);
    }

    private function moveExhaustedPlayer(int $charId): void
    {
        $this->characterModel->update($charId, [
            'health' => 10,
            'tired'  => 10,
        ]);
        $respawnCell = $this->findRespawnCell($charId);
        $this->characterModel->update($charId, [
            'cell_number' => $respawnCell,
        ]);
    }

    /**
     * processDeathAndRespawn: -5% XP, -0.5% статы, health=0, затем respawn
     */
    private function processDeathAndRespawn(array $loser): void
    {
        $before = $this->characterModel->find($loser['id']);
        if (!$before) return;

        $loserOldExp = $before['experience'];
        $loserOldStr = $before['strength'];
        $loserOldAgi = $before['agility'];
        $loserOldInt = $before['intellect'];

        $upd = [
            'experience' => max(0, $loserOldExp * (1 - self::DEATH_EXP_LOSS_PERCENT)),
            'strength'   => max($loser['strength'],  $loserOldStr * (1 - self::DEATH_STAT_LOSS_PERCENT)),
            'agility'    => max($loser['agility'],   $loserOldAgi * (1 - self::DEATH_STAT_LOSS_PERCENT)),
            'intellect'  => max($loser['intellect'], $loserOldInt * (1 - self::DEATH_STAT_LOSS_PERCENT)),
            'health'     => 0,
        ];
        $upd['experience'] = round($upd['experience'], 2);
        $upd['strength']   = round($upd['strength'],   2);
        $upd['agility']    = round($upd['agility'],    2);
        $upd['intellect']  = round($upd['intellect'],  2);

        $this->characterModel->update($loser['id'], $upd);

        // Respawn
        $respawnCell = $this->findRespawnCell($loser['id']);
        $this->characterModel->update($loser['id'], [
            'health'     => round(($loser['max_health'] ?? 100), 2),
            'tired'      => round(($loser['max_tired']  ?? 100), 2),
            'cell_number'=> $respawnCell,
        ]);
    }

    /**
     * Выдаём победителю +exp, шанс +stat
     */
    private function giveWinnerBonus(int $winnerId): void
    {
        $winner = $this->characterModel->find($winnerId);
        if (!$winner) return;

        $loser = $this->characterModel
            ->where('cell_number', $winner['cell_number'])
            ->where('id !=', $winner['id'])
            ->first();

        $levelDiff = 0;
        if ($loser) {
            $levelDiff = $loser['level'] - $winner['level'];
        }

        $expBonus = self::WINNER_EXP_BASE_BONUS; // 5%
        if ($levelDiff > 0) {
            $expBonus += min($levelDiff, 100)/100 * self::WINNER_EXP_MAX_ADDITIVE;
        }

        $winner['experience'] *= (1 + $expBonus);

        if (mt_rand(0, 100) < self::WINNER_ATTR_BONUS_CHANCE) {
            $winner['strength']  *= (1 + self::WINNER_ATTR_BONUS_FACTOR);
        }
        if (mt_rand(0, 100) < self::WINNER_ATTR_BONUS_CHANCE) {
            $winner['agility']   *= (1 + self::WINNER_ATTR_BONUS_FACTOR);
        }
        if (mt_rand(0, 100) < self::WINNER_ATTR_BONUS_CHANCE) {
            $winner['intellect'] *= (1 + self::WINNER_ATTR_BONUS_FACTOR);
        }

        $winner['experience'] = round($winner['experience'], 2);
        $winner['strength']   = round($winner['strength'],   2);
        $winner['agility']    = round($winner['agility'],    2);
        $winner['intellect']  = round($winner['intellect'],  2);

        $this->characterModel->update($winnerId, $winner);
    }

    /**
     * Считаем, что потерял проигравший (XP/статы).
     */
    private function makeLoserDiffText(array $loserBefore): string
    {
        $loserAfter = $this->characterModel->find($loserBefore['id']);
        if (!$loserAfter) {
            return "";
        }

        $lostExp = max(0, round($loserBefore['experience'] - $loserAfter['experience'], 2));
        $lostStr = max(0, round($loserBefore['strength']   - $loserAfter['strength'],   2));
        $lostAgi = max(0, round($loserBefore['agility']    - $loserAfter['agility'],    2));
        $lostInt = max(0, round($loserBefore['intellect']  - $loserAfter['intellect'],  2));

        return "\n<b>Потери:</b> \n"
            . "• Опыт: -{$lostExp}\n"
            . "• Сила: -{$lostStr}\n"
            . "• Ловкость: -{$lostAgi}\n"
            . "• Интеллект: -{$lostInt}\n"
            . "😰 <b>Горький привкус поражения...</b>";
    }

    /**
     * Что получил победитель
     */
    private function makeWinnerDiffText(array $winnerBefore): string
    {
        $winnerAfter = $this->characterModel->find($winnerBefore['id']);
        if (!$winnerAfter) {
            return "";
        }

        $gainExp = round($winnerAfter['experience'] - $winnerBefore['experience'], 2);
        $gainStr = round($winnerAfter['strength']   - $winnerBefore['strength'],   2);
        $gainAgi = round($winnerAfter['agility']    - $winnerBefore['agility'],    2);
        $gainInt = round($winnerAfter['intellect']  - $winnerBefore['intellect'],  2);

        return "\n<b>Награда за победу:</b>\n"
            . "• Опыт: +{$gainExp}\n"
            . "• Сила: +{$gainStr}\n"
            . "• Ловкость: +{$gainAgi}\n"
            . "• Интеллект: +{$gainInt}\n"
            . "🔥 <b>Вкус триумфа вдохновляет!</b>";
    }

    /**
     * Респаун-локация — либо claimed_cells, либо random explored_cell, либо #1
     */
    private function findRespawnCell(int $charId): int
    {
        $claimed = $this->claimedCellModel->where('character_id', $charId)->first();
        if ($claimed) {
            return (int)$claimed['map_cell_id'];
        }
        $explored = $this->exploredCellsModel->where('character_id', $charId)->findAll();
        if (!empty($explored)) {
            $rnd = $explored[array_rand($explored)];
            return (int)$rnd['map_cell_id'];
        }
        return 1; // fallback
    }

    /**
     * Узнаём фракцию (опционально).
     */
    private function getCharacterFaction(int $charId)
    {
        $cf = $this->characterFactionModel->where('character_id', $charId)->first();
        if (!$cf) {
            return null;
        }
        return $this->factionModel->find($cf['faction_id']);
    }

    /**
     * Уведомляем защищающегося
     */
    private function notifyDefender(array $defender, array $attacker, string $finalDefenderText): void
    {
        try {
            $defUser = $this->telegramUserModel->find($defender['telegram_user_id']);
            if ($defUser && !empty($defUser['telegram_id'])) {
                Request::sendMessage([
                    'chat_id'    => $defUser['telegram_id'],
                    'text'       => $finalDefenderText,
                    'parse_mode' => 'HTML',
                ]);
            }
        } catch (TelegramException $e) {
            log_message('error', "notifyDefender error: " . $e->getMessage());
        }
    }

    /**
     * Проверка, достаточно ли близко ячейки (dx<=1 && dy<=1).
     */
    private function isCellsCloseEnough(array $charA, array $charB): bool
    {
        if ($charA['cell_number'] === $charB['cell_number']) {
            return true;
        }
        $mapA = $this->mapModel->where('cell_number', $charA['cell_number'])->first();
        $mapB = $this->mapModel->where('cell_number', $charB['cell_number'])->first();
        if (!$mapA || !$mapB) {
            return false;
        }
        $dx = abs($mapA['coordinate_x'] - $mapB['coordinate_x']);
        $dy = abs($mapA['coordinate_y'] - $mapB['coordinate_y']);
        return ($dx <= 1 && $dy <= 1);
    }

    /**
     * Инициатива: c1 => i1= agility + (level+1)*0.5
     */
    private function determineInitiative(array $c1, array $c2): array
    {
        $i1 = $c1['agility'] + ($c1['level'] + 1)*0.5;
        $i2 = $c2['agility'] + ($c2['level'] + 1)*0.5;
        return ($i1 >= $i2) ? $c1 : $c2;
    }

    /**
     * Короткий итог боя.
     */
    private function formatShortFightResult(array $res): string
    {
        $fa     = $res['firstAttacker'];
        $rounds = $res['rounds'];

        if ($res['type'] === 'exhausted') {
            return "<b>PvP-бой завершён</b>\n"
                . "⚔️ <b>Первым атаковал:</b> {$fa}\n"
                . "🔁 <b>Раундов:</b> {$rounds}\n"
                . "<b>Оба выдохлись!</b>";
        }

        $w = $res['winner'];
        $l = $res['loser'];
        if (!$l) {
            // Допустим, теоретическая ничья
            return "<b>PvP-бой завершён</b>\n"
                . "⚔️ <b>Первым атаковал:</b> {$fa}\n"
                . "🔁 <b>Раундов:</b> {$rounds}\n"
                . "<b>Ничья?</b>";
        }
        return "<b>PvP-бой завершён</b>\n"
            . "⚔️ <b>Первым атаковал:</b> {$fa}\n"
            . "🔁 <b>Всего обменов ударами:</b> {$rounds}\n"
            . "❌ <b>Проиграл:</b> {$l['name']}\n"
            . "🏆 <b>Победил:</b> {$w['name']}";
    }

    /**
     * Отправка ошибки
     */
    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);

        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => "⚠️ <b>Ошибка:</b> {$msg}",
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Возвращает коэффициент редкости (K_rar) в зависимости от строки rarity.
     * Используется в computeEquipmentDamage().
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
                // По умолчанию 'common'
                return 1.0;
        }
    }

}
