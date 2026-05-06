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

    // --- Смерть / штрафы ----------

    /** Доля опыта, теряемая при смерти (0.05 = 5%). */
    public float $deathExpLossPercent = 0.05;

    /** Доля статов (strength/agility/intellect), теряемая при смерти. */
    public float $deathStatLossPercent = 0.005;

    /** Здоровье при revive после insurance-save (penalty=0). */
    public float $insuranceRespawnHealth = 80.0;

    /** Усталость при revive после insurance-save. */
    public float $insuranceRespawnTired = 50.0;

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
}
