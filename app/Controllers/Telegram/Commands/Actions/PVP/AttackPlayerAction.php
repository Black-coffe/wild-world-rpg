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
use App\Models\BattleLogModel;

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
    protected $battleLogModel;

    // F2.3 first slice: чистые PvP-формулы вынесены в сервис.
    private \App\Services\PVE\PvpFormulaService $pvpFormulas;
    // F2.3b Step 1: DB-чтения экипировки/карты/фракции вынесены в репо
    // (+ N+1 fix на outfits).
    private \App\Services\PVE\PvpEquipmentRepository $equipmentRepo;
    // F2.3b Step 2: формулы урона (computeDamage / Equipment / Armor /
    // Distance / Biome) — pure-ish сервис с DI.
    private \App\Services\PVE\PvpDamageCalculator $damageCalc;
    // F2.3b Step 3: simulateFight loop + checkLuckyStrike +
    // applyLuckyStrikeDebuff + determineInitiative.
    private \App\Services\PVE\PvpRoundOrchestrator $roundOrchestrator;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->pvpFormulas = new \App\Services\PVE\PvpFormulaService();

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

        // Новое свойство для записи логов боёв:
        $this->battleLogModel = new BattleLogModel();

        // F2.3b Step 1: repo переиспользует уже-созданные модели,
        // чтобы тесты могли подменить их через Reflection.
        $this->equipmentRepo = new \App\Services\PVE\PvpEquipmentRepository(
            $this->charactersWeaponsModel,
            $this->weaponsModel,
            $this->charactersOutfitsModel,
            $this->outfitsModel,
            $this->mapModel,
            $this->characterFactionModel,
            $this->factionModel
        );

        // F2.3b Step 2: damage calculator, использует те же formulas+repo.
        $this->damageCalc = new \App\Services\PVE\PvpDamageCalculator(
            $this->pvpFormulas,
            $this->equipmentRepo,
            null // GameBalance — config('GameBalance') по умолчанию
        );

        // F2.3b Step 3: round orchestrator. Использует уже инициализированный
        // damageCalc + formulas, чтобы fence test инжектил one-source-of-truth.
        $this->roundOrchestrator = new \App\Services\PVE\PvpRoundOrchestrator(
            $this->damageCalc,
            $this->pvpFormulas,
            null
        );
    }

    /**
     * Главный метод обработки PvP-атаки (логика из старого класса).
     */
    public function handle(): ServerResponse
    {
        // Фиксируем время начала боя
        $battleStartTime = date('Y-m-d H:i:s');

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

        // 2) Формируем короткий текст итогов (для Телеграм)
        $summaryText    = $this->formatShortFightResult($fightResult);
        $loser          = $fightResult['loser']  ?? null;
        $winner         = $fightResult['winner'] ?? null;
        $attackerName   = $attacker['name'];
        $defenderName   = $defender['name'];
        $attackerIntro  = '';
        $defenderIntro  = '';

        // Проверка состояний
        if ($fightResult['type'] === 'exhausted') {
            // Оба выдохлись
            $this->processMutualExhaustion($attacker, $defender);
            $summaryText .= "\n\n<b>Оба бойца изнемогли</b> и решили прекратить схватку!\n"
                . "❤️ Здоровье и выносливость сброшены до 10.\n"
                . "🚶 <i>Они отступили, обдумывая ошибки...</i>";

            $attackerIntro = "Ты участвовал в битве, но оба упали без сил.";
            $defenderIntro = "Тебя атаковали, но сражение закончилось взаимным изнеможением.";
        }
        elseif ($loser !== null && $winner !== null) {
            // Есть победитель
            $deathService   = new DeathService();
            $deathResult    = $deathService->handlePlayerDeathAndReward($loser['id'], $winner['id']);
            $penaltyPercent = (int)($deathResult['penalty'] * 100);

            if ($penaltyPercent === 0) {
                $summaryText .= "\n\n❌ <b>{$loser['name']}</b> потерпел поражение, но страховка спасла от потери имущества!";
            } else {
                $loserBefore  = $loser;
                $this->processDeathAndRespawn($loser);
                $loser        = $this->characterModel->find($loser['id']); // обновлённый
                $loserDiffText= $this->makeLoserDiffText($loserBefore);

                if ($deathResult['hasBase']) {
                    // Есть база
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> повержен и возродился...\n"
                        . $loserDiffText
                        . "\nТы потерял лишь <b>{$penaltyPercent}%</b> ресурсов, ведь база частично спасла запасы."
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
            $winnerBefore    = $winner;
            $this->giveWinnerBonus($winner['id']);
            $winner          = $this->characterModel->find($winner['id']);
            $winnerDiffText  = $this->makeWinnerDiffText($winnerBefore);
            $summaryText    .= "\n\n🏆 <b>{$winner['name']}</b> торжествует! {$winnerDiffText}";

            // Вступления
            $attackerIntro = ($attacker['id'] === $winner['id'])
                ? "Ты атаковал и разгромил врага!"
                : "Ты начал бой, но оказался слабее в этот раз...";

            $defenderIntro = ($defender['id'] === $winner['id'])
                ? "На тебя напали, но ты защитился и победил!"
                : "Тебя атаковали, и ты пал в этом бою...";
        }

        // Формируем финальные тексты (без ссылки)
        $attackerFinalText = "🤺 <b>{$attackerName}</b>, {$attackerIntro}\n\n{$summaryText}";
        $defenderFinalText = "🛡 <b>{$defenderName}</b>, {$defenderIntro}\n\n{$summaryText}";

        // Ответ на callback, но пока не отправляем сообщение
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // --- Логирование боя в базу ---
        $endTime    = date('Y-m-d H:i:s');
        $battleType = 'PVP';
        $winnerId   = $winner ? $winner['id'] : null;

        // Готовим JSON-лог
        $logDetails = [
            'characters' => [
                'attacker' => [
                    'id'       => $attacker['id'],
                    'name'     => $attacker['name'],
                    'level'    => $attacker['level'],
                    'faction'  => $attacker['faction']['name'] ?? null,
                    'strength' => $attacker['strength'],
                    'agility'  => $attacker['agility'],
                    'intellect'=> $attacker['intellect'],
                    'health'   => $attacker['health'],
                ],
                'defender' => [
                    'id'       => $defender['id'],
                    'name'     => $defender['name'],
                    'level'    => $defender['level'],
                    'faction'  => $defender['faction']['name'] ?? null,
                    'strength' => $defender['strength'],
                    'agility'  => $defender['agility'],
                    'intellect'=> $defender['intellect'],
                    'health'   => $defender['health'],
                ],
            ],
            'rounds' => $fightResult['roundLogs'] ?? [],
            'outcome' => [
                'type'     => $fightResult['type'],
                'winnerId' => $winnerId,
                'loserId'  => ($loser) ? $loser['id'] : null,
            ],
        ];
        $logJson = json_encode($logDetails, JSON_UNESCAPED_UNICODE);

        $battleData = [
            'battle_type' => $battleType,
            'player1_id'  => $attacker['id'],
            'player2_id'  => $defender['id'],
            'winner_id'   => $winnerId,
            'created_at'  => $battleStartTime,
            'finished_at' => $endTime,
            'log_data'    => $logJson
        ];

        // Сохраняем и получаем ID боя
        $battleId = $this->battleLogModel->insert($battleData);

        // Формируем ссылку на просмотр боя (основной домен)
        $battleUrl = base_url('battles/view/') . $battleId;

        // Добавляем ссылку в тексты
        $attackerFinalText .= "\n\n<a href=\"{$battleUrl}\">[Посмотреть детали боя]</a>";
        $defenderFinalText .= "\n\n<a href=\"{$battleUrl}\">[Посмотреть детали боя]</a>";

        // Клавиатура
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'explore'],
                ],
            ]
        ];

        // Теперь отправляем сообщение атакующему (уже с ссылкой)
        Request::sendMessage([
            'chat_id'                  => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'                     => $attackerFinalText,
            'parse_mode'               => 'HTML',
            'reply_markup'             => json_encode($keyboard),
            'disable_web_page_preview' => true,
        ]);

        // Отправляем сообщение проигравшему/защищающемуся (также с ссылкой)
        try {
            $defUser = $this->telegramUserModel->find($defender['telegram_user_id']);
            if ($defUser && !empty($defUser['telegram_id'])) {
                Request::sendMessage([
                    'chat_id'                  => $defUser['telegram_id'],
                    'text'                     => $defenderFinalText,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
            }
        } catch (TelegramException $e) {
            log_message('error', "notifyDefender error: " . $e->getMessage());
        }

        return Request::emptyResponse();
    }

    /**
     * F2.3b Step 3: simulateFight полностью делегирован в PvpRoundOrchestrator.
     */
    private function simulateFight(array $p1, array $p2, array $biome): array
    {
        return $this->roundOrchestrator->simulateFight($p1, $p2, $biome);
    }

    /**
     * F2.3b Step 2: вся формула урона делегирована в PvpDamageCalculator.
     * Метод-обёртка оставлен для backward-compat внутреннего вызова из
     * simulateFight (Step 3 переедет в RoundOrchestrator).
     */
    private function computeDamage(
        array $attacker,
        array $defender,
        array $biome,
        bool  $luckyStrikeActive,
        bool  $isFirstHit
    ): array
    {
        return $this->damageCalc->computeDamage($attacker, $defender, $biome, $luckyStrikeActive, $isFirstHit);
    }

    // ----------------------------------------------------------------
    // F2.3b Step 2: computeEquipmentDamage делегирован в DamageCalculator.
    // Оставлен thin wrapper для совместимости с возможными внешними
    // вызовами (хотя метод private — на всякий случай в Step 3 уберём).
    // ----------------------------------------------------------------

    private function computeEquipmentDamage(array $attacker, array $defender): float
    {
        return $this->damageCalc->computeEquipmentDamage($attacker, $defender);
    }

    /**
     * F2.3b Step 1: DB-чтения вынесены в PvpEquipmentRepository.
     * Делегирующий метод оставлен — на Step 2 переедет в DamageCalculator.
     */
    private function getEquippedWeapon(array $attacker): ?array
    {
        return $this->equipmentRepo->getEquippedWeapon((int) $attacker['id']);
    }

    /**
     * F2.3b Step 2: делегирует в DamageCalculator (выборка outfit'ов
     * через repo + sum + clamp 0.9).
     */
    private function computeArmorResistance(array $defender, string $damageType): float
    {
        $outfits = $this->equipmentRepo->getEquippedOutfitsWithDetails((int) $defender['id']);
        return $this->damageCalc->computeArmorResistance($outfits, $damageType);
    }

    /**
     * Вычисляем штраф по дистанции:
     * Если distance <= weaponRange => 1.0
     * Иначе линейное снижение. (Можно усложнить.)
     */
    private function computeRangePenalty(float $weaponRange, float $distance): float
    {
        // F2.3 first slice: вынесено в PvpFormulaService.
        return $this->pvpFormulas->computeRangePenalty($weaponRange, $distance);
    }

    /**
     * F2.3b Step 2: делегирует в DamageCalculator (pure dx/dy после
     * фетча клеток через репо).
     */
    private function computeDistance(array $charA, array $charB): float
    {
        $mapA = $this->equipmentRepo->getMapCell((int) $charA['cell_number']);
        $mapB = $this->equipmentRepo->getMapCell((int) $charB['cell_number']);
        return $this->damageCalc->computeDistance($mapA, $mapB);
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
        return $this->pvpFormulas->computeLevelBonus($attacker, $defender);
    }

    private function computeStatsBonus(array $attacker): float
    {
        return $this->pvpFormulas->computeStatsBonus($attacker);
    }

    private function getDodgeChance(array $defender): float
    {
        return $this->pvpFormulas->getDodgeChance($defender);
    }

    private function rollPercent(float $chance): bool
    {
        return $this->pvpFormulas->rollPercent($chance);
    }

    // ----------------------------------------------------------------
    // F2.3b Step 3: checkLuckyStrike, applyLuckyStrikeDebuff,
    // determineInitiative — переехали в PvpRoundOrchestrator.
    // Старая логика DeathService, Respawn остаётся пока (Step 4).
    // ----------------------------------------------------------------

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
     * F2.3b Step 1: делегирует в репо.
     */
    private function getCharacterFaction(int $charId)
    {
        return $this->equipmentRepo->getCharacterFaction($charId);
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
     * F2.3b Step 1: map lookups через репо.
     */
    private function isCellsCloseEnough(array $charA, array $charB): bool
    {
        if ($charA['cell_number'] === $charB['cell_number']) {
            return true;
        }
        $mapA = $this->equipmentRepo->getMapCell((int) $charA['cell_number']);
        $mapB = $this->equipmentRepo->getMapCell((int) $charB['cell_number']);
        if (!$mapA || !$mapB) {
            return false;
        }
        $dx = abs($mapA['coordinate_x'] - $mapB['coordinate_x']);
        $dy = abs($mapA['coordinate_y'] - $mapB['coordinate_y']);
        return ($dx <= 1 && $dy <= 1);
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
        // F2.3 first slice: вынесено в PvpFormulaService.
        return $this->pvpFormulas->getRarityCoefficient($rarity);
    }

}
