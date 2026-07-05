<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * F2.10 — единый источник игровых констант баланса.
 *
 * До этой фазы константы хардкодились внутри handler'ов и сервисов
 * (`AttackPlayerAction.php:43-79`, `DamageService`, `EffectService`,
 * `DeathService`, `TaxCollectionHandler` и т.п.). Это означало, что
 * любой ребаланс — деплой кода. Цель этого файла:
 *
 *   1. Центральное место для game balance (можно ребалансировать
 *      без прохода по 30 файлам).
 *   2. Возможность переопределить через `.env` для test/dev окружений
 *      (CI4 BaseConfig автоматически читает env() с тем же именем).
 *   3. Подготовка к выносу в БД (когда понадобится hot-reload без
 *      деплоя — например, во время сезонов).
 *
 * Текущая стратегия миграции:
 *   - В этой фазе (F2.10) только ОПИСЫВАЕМ константы здесь.
 *     Внутренние `private const ...` в handler'ах оставляем, чтобы
 *     не сломать прод. Это «параллельная истина».
 *   - В отдельных коммитах F2.3 / F2.7 / F2.8 (декомпозиция god-классов
 *     с unit-тестами) переключаем `private const X = ...` на
 *     `config('GameBalance')->X`.
 *
 * Источник истины на 2026-05-04 — `AttackPlayerAction.php:43-79`.
 * См. также mmorpg-vault/lore/refactor/Architecture.md (item 10) и
 * mmorpg-vault/lore/combat/PvP.md (таблица из 21 константы).
 */
class GameBalance extends BaseConfig
{
    // ===================================================================
    // PVP — основной баланс (источник: AttackPlayerAction.php:43-79)
    // ===================================================================

    /** Каждые N раундов повышаем damageBoost. */
    public int $pvpRoundsPerDamageIncrease = 15;

    /** На сколько повышаем урон каждые `roundsPerDamageIncrease` раундов. */
    public float $pvpDamageIncreasePerStep = 0.15;

    /** Лимит раундов боя; после — ничья / взаимное изнеможение. */
    public int $pvpMaxRounds = 150;

    /**
     * v0.51.44 — anti-spam cooldown между PvP-атаками одного атакующего.
     * Игрок не может атаковать (любую цель) чаще раз в N секунд.
     * Default 30 сек — мягкий guard от двойных кликов та agressive scripts.
     * Per Security-telegram §7 — закриває гpамлення без UX болю на legit гравцях.
     */
    public int $pvpAttackCooldownSec = 30;

    // --- Смерть / штрафы ----------

    /** Доля опыта, теряемая при смерти (0.05 = 5%). */
    public float $deathExpLossPercent = 0.05;

    /** Доля статов (strength/agility/intellect), теряемая при смерти. */
    public float $deathStatLossPercent = 0.005;

    /** Здоровье при revive после insurance-save (penalty=0). */
    public float $insuranceRespawnHealth = 80.0;

    /** Усталость при revive после insurance-save. */
    public float $insuranceRespawnTired = 50.0;

    /**
     * Death-validation batch 4: grace-окно после возрождения (минуты), в течение которого
     * персонаж иммунен к damage-событиям и не попадает в «рулетку смерти» — чтобы то же
     * событие/смерть не накидывалось мгновенно. НЕ распространяется на голод и PvP (анти-абуз).
     */
    public int $respawnGraceMinutes = 5;

    // --- Награда победителю ----------

    /** Базовый бонус к опыту победителя за PvP-победу. */
    public float $winnerExpBaseBonus = 0.05;

    /** Макс дополнительный бонус опыта если враг сильнее (на разнице уровней). */
    public float $winnerExpMaxAdditive = 0.1;

    /** Шанс (%) что после победы один стат победителя слегка повысится. */
    public int $winnerAttrBonusChance = 20;

    /** Множитель повышения стата при срабатывании `winnerAttrBonusChance`. */
    public float $winnerAttrBonusFactor = 0.001;

    // --- Уворот ----------

    /** Максимальный шанс уворота, % (формула: agility × 0.25, capped). */
    public int $maxDodgeChancePercent = 75;

    // --- Биом ----------

    /** База биом-модификатора урона (умножается на danger_level биома). */
    public float $damageBiomeBase = 0.1;

    // --- Lucky Strike ----------

    public float $luckyStrikeDiffFactor    = 0.3;
    public int   $luckyStrikeMaxChance     = 40;   // ⚠️ 30 в GAME_DESCRIPTION.md, 40 в коде — синхронизировано на 40 в e99cc00.
    public float $luckyStrikeDamageMult    = 1.5;
    public float $luckyStrikeDebuffPercent = 0.10;
    public float $luckyStrikeChancePerAgi  = 0.02;

    // --- One-Shot ----------

    public int $oneshotLevelDiffThreshold = 50;
    public int $oneshotMaxChance          = 50;

    // --- Разница уровней ----------

    /** ±N% за каждую ступеньку разницы уровней. */
    public float $levelDiffBonusPerLvl = 0.02;

    /** Cap на количество ступенек (5 × 0.02 = ±10%). */
    public int $levelDiffCap = 5;

    // --- Бонус статов ----------

    /** 100 нужного стата = +10% (факт: 100 × 0.001 = 0.1). */
    public float $statsBonusFactor = 0.001;

    // ===================================================================
    // Налоги (TaxCollectionHandler) — заполняется в F2.10 продолжении.
    // Сейчас захардкожено в handler'е и в админке (Admin/TaskController).
    // ===================================================================

    /** Час суток, в который TaxCollectionHandler собирает налоги (Europe/Kiev). */
    public int $taxCollectionHour = 3;

    // ===================================================================
    // Питание (FoodAndWaterConsumptionHandler) — формула из канона:
    //   formula = (level × biome.difficulty) / 10
    //   еда   = formula × 0.6 × 3
    //   вода  = formula × 0.7 × 3
    // ===================================================================

    public float $foodMultiplier  = 0.6;
    public float $waterMultiplier = 0.7;
    public int   $mealsPerDay     = 3;
    public int   $hungerHealthPenaltyDivisor = 2; // здоровье урезается в 2 раза при нехватке

    // ===================================================================
    // Регенерация (HealthRegenerationHandler)
    // ===================================================================

    /** Прибавка к здоровью на тик. */
    public float $healthRegenPerTick = 0.05;

    /** Прибавка к выносливости на тик. */
    public float $tiredRegenPerTick = 0.1;

    /** До какого уровня действует регенерация. */
    public int $regenLevelCap = 20;

    // ===================================================================
    // PvE BattleService — главный движок боя против NPC
    // ===================================================================

    /** Максимум раундов в PvE-бою. После — ничья. */
    public int $pveMaxRounds = 100;

    // ===================================================================
    // Робот-сборщик (CompleteRobotGatheringHandler)
    // ===================================================================

    /** ±N% к итоговому количеству ресурсов из robot gathering. */
    public int $robotGatheringRandomPercent = 20;

    /**
     * Базовые объёмы добычи робота "за 2 часа", по rarity (10..1).
     * Чем выше rarity — тем больше base yield.
     *
     * @var array<int, int>
     */
    public array $robotGatheringBaseAt2h = [
        10 => 500,
        9  => 400,
        8  => 350,
        7  => 300,
        6  => 200,
        5  => 150,
        4  => 100,
        3  => 50,
        2  => 20,
        1  => 10,
    ];

    /**
     * Damping coefficients для low-rarity (1-3) при hoursSpent > 2.
     * timeCoef = pow(hoursSpent/2, damping[rarity]) — diminishing returns.
     *
     * @var array<int, float>
     */
    public array $robotGatheringLowRarityDamping = [
        3 => 0.9,
        2 => 0.8,
        1 => 0.7,
    ];

    // ===================================================================
    // Радиус обнаружения PvP (PlayerDetectionService)
    //   радиус = base + floor(level / divisor), capped на max
    // ===================================================================

    public int $detectionRadiusBase    = 2;
    public int $detectionRadiusDivisor = 500;
    public int $detectionRadiusMax     = 3;

    /**
     * Cooldown между detection notifications для одной (detector, detected)-пары.
     * До v0.51.1 был bug: код `> 3` (секунды) при коментарі "1 час 3600 сек".
     * Залишок від testing-fix PvP runAway flow (commit 82c31a01, 2025-01-10).
     * 15+ місяців silent spam → fix через wire-in у GameBalance.
     */
    public int $playerDetectionCooldownSec = 3600;

    // ===================================================================
    // Прочее
    // ===================================================================

    /** Стартовое золото нового персонажа. */
    public int $startingGold = 1000;

    /** Стартовое значение trading_karma (см. lore/economy/Карма-торговли). */
    public int $startingTradingKarma = 100;

    /** Бонус кармы за продажу крафта (per item). */
    public float $tradingKarmaSellBonus = 0.0002;

    /** Штраф кармы за покупку крафта (per item). */
    public float $tradingKarmaBuyPenalty = 0.0002;

    // ===================================================================
    // Теплица (GreenhouseProductionHandler) — C/F6 expansion (v0.51.24)
    //   Раніше hardcoded `private $greenhouseLevels` у handler.
    //   1..10 уровень: water cost + harvest table (Fruit/Berries/Mushrooms/Crops).
    // ===================================================================

    /**
     * Расход воды + harvest за tick по уровню теплицы (1..10).
     *
     * @var array<int, array<string, int>>
     */
    public array $greenhouseLevels = [
        1  => ['water' => 1,  'Fruit' => 2, 'Berries' => 1],
        2  => ['water' => 2,  'Fruit' => 2, 'Berries' => 2],
        3  => ['water' => 3,  'Fruit' => 3, 'Berries' => 2],
        4  => ['water' => 4,  'Fruit' => 3, 'Berries' => 3],
        5  => ['water' => 5,  'Fruit' => 3, 'Berries' => 3, 'Mushrooms' => 1],
        6  => ['water' => 6,  'Fruit' => 4, 'Berries' => 3, 'Mushrooms' => 1],
        7  => ['water' => 7,  'Fruit' => 4, 'Berries' => 4, 'Mushrooms' => 1],
        8  => ['water' => 8,  'Fruit' => 4, 'Berries' => 4, 'Mushrooms' => 2],
        9  => ['water' => 9,  'Fruit' => 4, 'Berries' => 4, 'Mushrooms' => 2, 'Crops' => 1],
        10 => ['water' => 10, 'Fruit' => 5, 'Berries' => 5, 'Mushrooms' => 3, 'Crops' => 2],
    ];

    /** Cooldown (seconds) між water-shortage notifications для теплиці. */
    public int $greenhouseWaterShortageCooldownSec = 1800;

    /** Поріг води, нижче якого надсилається warning (≤). */
    public int $greenhouseWaterShortageThreshold = 3;

    // ===================================================================
    // Спортзал (Built/GymProductionHandler) — C/F6 expansion (v0.51.24)
    //   Раніше hardcoded `private $gymLevels` у handler.
    //   Прибавка к strength раз на tick за уровнем gym (1..10).
    // ===================================================================

    /**
     * Прибавка к strength за tick по уровню спортзала (1..10).
     *
     * @var array<int, float>
     */
    public array $gymStrengthByLevel = [
        1  => 0.01,
        2  => 0.02,
        3  => 0.03,
        4  => 0.04,
        5  => 0.07,
        6  => 0.09,
        7  => 0.11,
        8  => 0.12,
        9  => 0.14,
        10 => 0.15,
    ];

    /** Інтервал між gym ticks (хвилин). 30 → раз на 30 хв. */
    public int $gymTickIntervalMinutes = 30;

    // ===================================================================
    // Ручна свердловина (Built/HandPumpProductionHandler) — C/F6 expansion (v0.51.24)
    // ===================================================================

    /**
     * Базове кол-во води за tick по рівню (1..10).
     *
     * @var array<int, int>
     */
    public array $handPumpLevels = [
        1  => 1,
        2  => 2,
        3  => 3,
        4  => 4,
        5  => 7,
        6  => 9,
        7  => 11,
        8  => 14,
        9  => 17,
        10 => 20,
    ];

    /**
     * Біом-множник для production воді у HandPump.
     *
     * @var array<string, float>
     */
    public array $handPumpBiomeMultipliers = [
        'wet'      => 1.2,
        'cold'     => 0.9,
        'dry'      => 0.5,
        'volcanic' => 0.7,
        'cave'     => 0.6,
        'plain'    => 1.0,
    ];

    // ===================================================================
    // Робот-исследователь (CompleteRobotExplorationHandler) — C/F6 expansion (v0.51.24)
    //   cellsPerHour = base + max(0, level-1) × perLevel
    //   default 50 cells/hour at level 1, +10 cells/hour per workshop level.
    // ===================================================================

    /** Базове кол-во ячеек/час що відкриває робот на 1-му рівні мастерської. */
    public int $roboticsExplorationCellsBase = 50;

    /** +N ячеек/час за кожен рівень мастерської понад 1. */
    public int $roboticsExplorationCellsPerLevel = 10;

    // ===================================================================
    // ADR-019 cleanup-тег: блок exploration* (ExplorationTaskHandler, C/F6 +
    // community idea #5) удалён вместе со стоячим explore — ручная разведка
    // заменена «Походом» (см. marchMinutesPerCell / marchTiredPerCell ниже).
    // ===================================================================

    // ===================================================================
    // Craft queue (GenericCraftActionStart) — community idea #1, v0.51.129
    // ===================================================================

    /**
     * Максимум одночасних distinct active+queued recipes per character.
     * Якщо гравець вже використовує N distinct recipes (active OR з queue) —
     * новий recipe blocked. Same recipe можна стакати у queue до окремого ліміту.
     */
    public int $craftMaxConcurrentSlots = 3;

    /**
     * Максимум tasks per recipe (active + queued sum). 1-10 крафтів стакаються
     * у one-recipe queue. Понад цього — sendError("queue full").
     */
    public int $craftMaxQueuePerRecipe = 10;

    // ===================================================================
    // Поход / Marching (MarchingTaskHandler, MarchAction) — ADR-019 Step 3
    //   «Движение И есть разведка». Направленный многоклеточный переход фоновой
    //   задачей: 1 клетка / тик, карта обновляется в сообщении по мере движения.
    //   Одиночный шаг (MoveCharacterToDirectionAction) остаётся дороже —
    //   точный инструмент; марш дешевле/клетку, но capped + авто-обрыв по полу
    //   выносливости + тайм-гейт. Расчёт до/после — ADR-019 §6.
    // ===================================================================

    /** Минут реального времени на один крон-тик марша (задержка между пачками клеток). */
    public int $marchMinutesPerCell = 1;

    // Клеток за крон-тик (батч) — вынесено в admin GameSettings `world.march.cells_per_tick`
    // (ADMIN-TUNABLE BALANCE, ADR-024). Читают MarchingTaskHandler/MarchAction через
    // GameSettingsReaderTrait::gsInt(..., 3). Здесь не держим (устранение «двойной правды»).

    /** Максимум клеток в одном заказе марша. Дальше — кнопка «➕ Продлить» / новый заказ. */
    public int $marchMaxStepsPerOrder = 60;

    /** Расход выносливости за клетку марша (vs одиночный шаг 3.35 — направленный переход эффективнее зигзага). */
    public float $marchTiredCostPerCell = 0.5;

    /** Расход здоровья за клетку марша. */
    public float $marchHealthCostPerCell = 0.02;

    /** Доп. расход здоровья за клетку в опасном биоме (danger_level ≥ 8). */
    public float $marchDangerHealthSurcharge = 1.0;

    /** Прирост опыта за клетку марша. */
    public float $marchXpPerCell = 0.03;

    /** Прирост характеристики (str/agi/int по ротации) за клетку марша. */
    public float $marchStatPerCell = 0.02;

    /**
     * Шанс (доля, 0..1) стычки с NPC за клетку марша. PvE auto-battle.
     * 0 = выключено (ship conservative — включаем после smoke и проверки PvE-on-march
     * механики; см. ADR-019 §6 «ship conservative + 2 недели мониторинга»).
     */
    public float $marchNpcEncounterChancePerCell = 0.0;

    /**
     * Порог здоровья (доля от максимума) — если стычка в пути уронила HP ниже,
     * марш встаёт на паузу «привал, зализываешь раны».
     */
    public float $marchHpHaltThreshold = 0.30;
}
