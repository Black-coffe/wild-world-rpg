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

use App\Services\Player\PvPRestrictionService;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;

/**
 * Класс AttackPlayerAction:
 * Производит PvP-атаку одного игрока на другого, обрабатывает бой, распределяет награды и штрафы.
 * Добавлена механика lucky strike (удачный удар), ваншот и улучшенные формулы награды/штрафов.
 */
class AttackPlayerAction extends BaseAction
{
    // --- Ключевые константы (магические числа) ------------------

    // РАУНДЫ / УРОН
    private const ROUNDS_PER_DAMAGE_INCREASE = 15;  // Каждые N раундов повышаем damageBoost
    private const DAMAGE_INCREASE_PER_STEP   = 0.15; // На сколько повышаем урон каждые 15 раундов
    private const MAX_ROUNDS                 = 150;  // Лимит раундов боя, потом ничья

    // СМЕРТЬ / ШТРАФЫ / СТРАХОВКА
    private const BASE_INSURANCE_COST_FACTOR = 5;    // Стоимость страховки (x5 от суммы lvl+exp+avgStats)
    private const DEATH_EXP_LOSS_PERCENT     = 0.05; // 5% потери опыта при смерти
    private const DEATH_STAT_LOSS_PERCENT    = 0.005;// 0.5% потери статов при смерти (strength/agi/int)

    // НАГРАДА ПОБЕДИТЕЛЮ
    private const WINNER_EXP_BASE_BONUS      = 0.05; // 5% базовый бонус к опыту
    private const WINNER_EXP_MAX_ADDITIVE    = 0.1;  // +10% сверху, если враг сильнее
    private const WINNER_ATTR_BONUS_CHANCE   = 20;   // 20% шанс слегка повысить стат
    private const WINNER_ATTR_BONUS_FACTOR   = 0.001;// +0.1%

    // DODGE / ЗАЩИТА
    private const MAX_DODGE_CHANCE_PERCENT   = 75;   // Макс. шанс уворота 75%
    private const DEFENDER_STR_FACTOR        = 0.2;  // 20% силы идёт в защиту
    private const DEFENDER_TIRED_FACTOR      = 0.1;  // 10% tired идёт в защиту

    // БИОМ
    private const DAMAGE_BIOME_BASE = 0.1;  // 0.1 вместо 0.02 (сильнее влияем биомом)

    // ЛОГИКА УДАЧНОГО УДАРА (Lucky Strike)
    private const LUCKY_STRIKE_DIFF_FACTOR     = 0.3;   // Слабый игрок + этот коэффициент на разницу уровней
    private const LUCKY_STRIKE_MAX_CHANCE      = 40;    // cap в 40%
    private const LUCKY_STRIKE_DAMAGE_MULT     = 1.5;   // Х1.5 урона при удачном ударе
    private const LUCKY_STRIKE_DEBUFF_PERCENT  = 0.10;  // –10% к одному стату у защищающегося
    private const LUCKY_STRIKE_CHANCE_PER_AGI  = 0.02;  // +2% шанса удачи на 1 AGI разницы

    // ЛОГИКА ВАНШОТА (One-Shot)
    private const ONESHOT_LEVELDIFF_THRESHOLD  = 50;  // Срабатывает при разнице уровней >= 50
    private const ONESHOT_MAX_CHANCE           = 50;  // Макс 50% шанс
    // -----------------------------------------------------------

    protected $characterModel;
    protected $mapModel;
    protected $biomeModel;
    protected $telegramUserModel;
    protected $characterFactionModel;
    protected $factionModel;
    protected $claimedCellModel;
    protected $exploredCellsModel;

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
    }

    /**
     * Главный метод обработки PvP-атаки.
     */
    public function handle(): ServerResponse
    {
        // Ожидаем callback_data вида "attackPlayer_###"
        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData);
        $defenderId   = isset($parts[1]) ? (int)$parts[1] : 0;

        // Находим атакующего (текущего пользователя)
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

        // Проверка близости
        if (!$this->isCellsCloseEnough($attacker, $defender)) {
            return $this->sendError("Игрок слишком далеко. Атаковать можно только в одной или соседней ячейке!");
        }

        // Проверка PvP-ограничений
        $pvpRestrictionService = new PvPRestrictionService();
        $check = $pvpRestrictionService->checkPvPAllowed($attacker, $defender);
        if (!$check['allowed']) {
            return $this->sendError("PvP недоступно: {$check['reason']}");
        }

        // Проверка биома
        $mapRowAttacker = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        if (!$mapRowAttacker) {
            return $this->sendError("Не найдена локация атакующего!");
        }
        $biome = $this->biomeModel->find($mapRowAttacker['biome_id']);
        if (!$biome) {
            return $this->sendError("Не найден биом для локации #{$mapRowAttacker['id']}.");
        }

        // Фракции (если нужно)
        $attacker['faction'] = $this->getCharacterFaction($attacker['id']);
        $defender['faction'] = $this->getCharacterFaction($defender['id']);

        // Запускаем бой
        $fightResult = $this->simulateFight($attacker, $defender, $biome);

        // Формируем сводку
        $summaryText = $this->formatShortFightResult($fightResult);

        $attackerIntro = '';
        $defenderIntro = '';
        $loser  = $fightResult['loser']  ?? null;
        $winner = $fightResult['winner'] ?? null;

        $attackerName = $attacker['name'];
        $defenderName = $defender['name'];

        // --- Ничья ---
        if ($fightResult['type'] === 'exhausted') {
            $this->processMutualExhaustion($attacker, $defender);
            $summaryText .= "\n\n<b>Оба бойца изнемогли</b> и решили прекратить схватку!\n"
                . "❤️ Здоровье и выносливость сброшены до 10.\n"
                . "🚶 <i>Они отступили, обдумывая ошибки...</i>";

            $attackerIntro = "Ты участвовал в битве, но оба упали без сил.";
            $defenderIntro = "Тебя атаковали, но сражение закончилось взаимным изнеможением.";
        }
        // --- Есть победитель/проигравший ---
        elseif ($loser !== null && $winner !== null) {

            // Проверяем страховку
            $wasInsuranceUsed = $this->checkAndProcessInsurance($loser);
            if (!$wasInsuranceUsed) {
                // 1) Списываем ресурсы/крафт/золото и даём часть победителю
                $deathService = new \App\Services\Player\DeathService();
                $deathResult  = $deathService->handlePlayerDeathAndReward($loser['id'], $winner['id']);

                // 2) -5% XP, -0.5% статов (ваша «старая» логика)
                $loserBefore = $loser;
                $this->processDeathAndRespawn($loser);
                $loser       = $this->characterModel->find($loser['id']);

                // Процент, который «срезали»
                $penaltyPercent = (int)($deathResult['penalty'] * 100);
                $loserDiffText  = $this->makeLoserDiffText($loserBefore);

                // Текст о том, база или нет
                if ($deathResult['hasBase']) {
                    // У него есть база => 3% ушли победителю
                    // (3% утеряно у проигравшего, 3% получил победитель)
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> повержен и возродился...\n"
                        . $loserDiffText
                        . "\nТы потерял лишь <b>{$penaltyPercent}%</b> ресурсов/крафта/золота, ведь база частично спасла запасы."
                        . "\nНо <b>враг забрал</b> эти <b>{$penaltyPercent}%</b>!";
                } else {
                    // Без базы => 50% списано, 25% победителю, 25% вникуда
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> проиграл бой и был возрождён...\n"
                        . $loserDiffText
                        . "\nТы оказался <b>без базы</b>, так что потерял <b>50%</b> ресурсов/крафта/золота: "
                        . "<i>половина исчезла бесследно, половина (25%) досталась врагу.</i>";
                }
            } else {
                $summaryText .= "\n\n❌ <b>{$loser['name']}</b> потерпел поражение, но страховка спасла от потери имущества!";
            }

            // 3) Бонус для победителя
            $winnerBefore     = $winner;
            $this->giveWinnerBonus($winner['id']);
            $winner           = $this->characterModel->find($winner['id']);
            $winnerDiffText   = $this->makeWinnerDiffText($winnerBefore);
            $summaryText     .= "\n\n🏆 <b>{$winner['name']}</b> торжествует! {$winnerDiffText}";

            // Формируем короткие вводные
            $attackerIntro = ($attacker['id'] === $winner['id'])
                ? "Ты атаковал и разгромил врага!"
                : "Ты начал бой, но оказался слабее в этот раз...";

            $defenderIntro = ($defender['id'] === $winner['id'])
                ? "На тебя напали, но ты защитился и победил!"
                : "Тебя атаковали, и ты пал в этом бою...";
        }

        // --- Подготовка финальных сообщений ---
        $attackerFinalText = "🤺 <b>{$attackerName}</b>, {$attackerIntro}\n\n{$summaryText}";
        $defenderFinalText = "🛡 <b>{$defenderName}</b>, {$defenderIntro}\n\n{$summaryText}";

        // Отправка атакующему
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👤 Персонаж',   'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь',  'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '🗺️ Изучить местность','callback_data' => 'explore'],
                    ['text' => '🏠 База',         'callback_data' => 'Base'],
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
     * simulateFight():
     * Пошаговая симуляция боя с учётом damageBoost каждые 15 раундов,
     * а также механик Lucky Strike и One-Shot.
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

        $damageBoost = 1.0;

        while ($attacker['health'] > 0 && $defender['health'] > 0 && $round < $maxRounds) {
            $round++;

            // Каждые 15 раундов повышаем урон
            if ($round % self::ROUNDS_PER_DAMAGE_INCREASE === 0) {
                $damageBoost += self::DAMAGE_INCREASE_PER_STEP;
            }

            $luckyStrikeActive = $this->checkLuckyStrike($attacker, $defender);
            $damage = $this->computeDamage($attacker, $defender, $biome, $luckyStrikeActive);
            $damage *= $damageBoost;

            $defender['health'] = max(0, $defender['health'] - $damage);

            // Меняем местами
            [$attacker, $defender] = [$defender, $attacker];
        }

        if ($round >= $maxRounds && $attacker['health'] > 0 && $defender['health'] > 0) {
            return [
                'type'          => 'exhausted',
                'rounds'        => $round,
                'firstAttacker' => $firstName,
                'winner'        => null,
                'loser'         => null,
            ];
        }

        if ($attacker['health'] <= 0) {
            $loser  = $attacker;
            $winner = $defender;
        } elseif ($defender['health'] <= 0) {
            $loser  = $defender;
            $winner = $attacker;
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
     * computeDamage():
     * Рассчитываем урон с учётом логарифмического роста уровня, разницы уровней и биома.
     * Параллельно проверяем Lucky Strike (x1.5) и One-Shot (при разнице >= 50).
     */
    private function computeDamage(
        array $attacker,
        array $defender,
        array $biome,
        bool  $luckyStrikeActive = false
    ): float
    {
        $baseDamage  = $attacker['strength'];
        $attackerLvl = max(1, $attacker['level']);
        $lvlCoeffA   = 1 + \log($attackerLvl, 10);

        $levelDiff     = $attacker['level'] - $defender['level'];
        $levelDiffCoeff = 1.0;
        if ($levelDiff > 0) {
            $levelDiffCoeff = 1 + min($levelDiff, 300) / 300 * 0.5;
        } elseif ($levelDiff < 0) {
            $levelDiffCoeff = max(0.5, 1 - abs($levelDiff) / 300 * 0.5);
        }

        $danger     = $biome['danger_level'] ?? 1;
        $biomeCoeff = 1 + ((max(1, $danger) - 1) * self::DAMAGE_BIOME_BASE);

        // Уворот (макс 75%)
        $rawDodgeChance = $defender['agility'] * 0.25;
        $dodgeChance    = min(self::MAX_DODGE_CHANCE_PERCENT, $rawDodgeChance);
        if (mt_rand(0, 100) < $dodgeChance) {
            return 0.0; // уворот
        }

        // Защита
        $defPower = ($defender['strength'] * self::DEFENDER_STR_FACTOR)
            + ($defender['tired']   * self::DEFENDER_TIRED_FACTOR);

        $damage = $baseDamage * $lvlCoeffA * $levelDiffCoeff * $biomeCoeff;
        $damage = max(0, $damage - $defPower);

        if ($luckyStrikeActive) {
            $damage *= self::LUCKY_STRIKE_DAMAGE_MULT; // +50%
            $this->applyLuckyStrikeDebuff($defender);
        }

        // One-Shot при разнице >= 50
        if ($levelDiff >= self::ONESHOT_LEVELDIFF_THRESHOLD) {
            $oneShotChance = min(self::ONESHOT_MAX_CHANCE, ($levelDiff / 1000)*100);
            if (mt_rand(0, 100) < $oneShotChance) {
                // Сразу убиваем
                $damage = $defender['health'];
            }
        }

        return $damage;
    }

    /**
     * checkLuckyStrike():
     * Если атакующий слабее (levelDiff < 0), даём шанс на удачный удар (x1.5 + debuff).
     */
    private function checkLuckyStrike(array $attacker, array $defender): bool
    {
        $levelDiff = $attacker['level'] - $defender['level'];
        if ($levelDiff >= 0) {
            return false;
        }
        $absDiff = abs($levelDiff);
        $chance = $absDiff * self::LUCKY_STRIKE_DIFF_FACTOR; // -50 => 15%
        $agiDiff = $attacker['agility'] - $defender['agility'];
        if ($agiDiff > 0) {
            $chance += ($agiDiff * self::LUCKY_STRIKE_CHANCE_PER_AGI * 100);
        }
        $chance = min(self::LUCKY_STRIKE_MAX_CHANCE, $chance);

        return (mt_rand(0, 100) < $chance);
    }

    private function applyLuckyStrikeDebuff(array &$defender): void
    {
        $stats = ['strength', 'agility', 'intellect'];
        $stat  = $stats[array_rand($stats)];
        $defender[$stat] = max(1, $defender[$stat] * (1 - self::LUCKY_STRIKE_DEBUFF_PERCENT));
    }

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

    private function checkAndProcessInsurance(array $loser): bool
    {
        $loserDb = $this->characterModel->find($loser['id']);
        if (!$loserDb || !$loserDb['insurance']) {
            return false;
        }
        $cost = $this->calculateInsuranceCost($loserDb);
        if ($loserDb['gold'] < $cost) {
            $this->characterModel->update($loser['id'], ['insurance' => 0]);
            return false;
        }
        $this->characterModel->update($loser['id'], [
            'gold'      => $loserDb['gold'] - $cost,
            'insurance' => 0,
        ]);
        $respawnCell = $this->findRespawnCell($loser['id']);
        $this->characterModel->update($loser['id'], [
            'cell_number' => $respawnCell,
            'health'      => $loser['max_health'] ?? 100,
            'tired'       => $loser['max_tired']  ?? 100,
        ]);
        return true;
    }

    private function calculateInsuranceCost(array $char): int
    {
        $lvlPart = $char['level'];
        $expPart = $char['experience'];
        $avgAttr = ($char['strength'] + $char['agility'] + $char['intellect']) / 3.0;
        $base    = $lvlPart + $expPart + $avgAttr;
        $cost    = $base * self::BASE_INSURANCE_COST_FACTOR;
        return (int) \ceil($cost);
    }

    private function processDeathAndRespawn(array $loser): void
    {
        $before = $this->characterModel->find($loser['id']);
        if (!$before) return;

        $loserOldExp = $before['experience'];
        $loserOldStr = $before['strength'];
        $loserOldAgi = $before['agility'];
        $loserOldInt = $before['intellect'];

        $updatedLoser = [
            'experience' => max(0, $loserOldExp * (1 - self::DEATH_EXP_LOSS_PERCENT)),
        ];

        $updatedLoser['strength']  = max($loser['strength'],  $loserOldStr * (1 - self::DEATH_STAT_LOSS_PERCENT));
        $updatedLoser['agility']   = max($loser['agility'],   $loserOldAgi * (1 - self::DEATH_STAT_LOSS_PERCENT));
        $updatedLoser['intellect'] = max($loser['intellect'], $loserOldInt * (1 - self::DEATH_STAT_LOSS_PERCENT));

        $updatedLoser['health'] = 0;

        $updatedLoser['experience'] = round($updatedLoser['experience'], 2);
        $updatedLoser['strength']   = round($updatedLoser['strength'],   2);
        $updatedLoser['agility']    = round($updatedLoser['agility'],    2);
        $updatedLoser['intellect']  = round($updatedLoser['intellect'],  2);

        $this->characterModel->update($loser['id'], $updatedLoser);

        $respawnCell = $this->findRespawnCell($loser['id']);
        $this->characterModel->update($loser['id'], [
            'health'     => round(($loser['max_health'] ?? 100), 2),
            'tired'      => round(($loser['max_tired']  ?? 100), 2),
            'cell_number'=> $respawnCell,
        ]);
        $loser = $this->characterModel->find($loser['id']);
    }

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

        $expBonus = self::WINNER_EXP_BASE_BONUS;
        if ($levelDiff > 0) {
            $expBonus += min($levelDiff, 100) / 100 * self::WINNER_EXP_MAX_ADDITIVE;
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
        $winner = $this->characterModel->find($winnerId);
    }

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

    private function findRespawnCell(int $charId): int
    {
        $claimed = $this->claimedCellModel->where('character_id', $charId)->first();
        if ($claimed) {
            return (int) $claimed['map_cell_id'];
        }
        $explored = $this->exploredCellsModel->where('character_id', $charId)->findAll();
        if (!empty($explored)) {
            $rnd = $explored[\array_rand($explored)];
            return (int) $rnd['map_cell_id'];
        }
        return 1;
    }

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
     * Проверяем близость ячеек (1 клетка в любую сторону).
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
        $dx = \abs($mapA['coordinate_x'] - $mapB['coordinate_x']);
        $dy = \abs($mapA['coordinate_y'] - $mapB['coordinate_y']);
        return ($dx <= 1 && $dy <= 1);
    }

    /**
     * determineInitiative():
     * Кто ходит первым? agility + (level+1)*0.5
     */
    private function determineInitiative(array $c1, array $c2): array
    {
        $i1 = $c1['agility'] + ($c1['level'] + 1)*0.5;
        $i2 = $c2['agility'] + ($c2['level'] + 1)*0.5;
        return ($i1 >= $i2) ? $c1 : $c2;
    }

    /**
     * Короткий результат боя (Markdown -> HTML)
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
            return "<b>PvP-бой завершён</b>\n"
                . "⚔️ <b>Первым атаковал:</b> {$fa}\n"
                . "🔁 <b>Раундов:</b> {$rounds}\n"
                . "<b>Ничья?</b>";
        }
        return "<b>PvP-бой завершён</b>\n"
            . "⚔️ <b>Первым атаковал:</b> {$fa}\n"
            . "🔁 <b>Всего обменов ударами (раундов):</b> {$rounds}\n"
            . "❌ <b>Проиграл:</b> {$l['name']}\n"
            . "🏆 <b>Победил:</b> {$w['name']}";
    }

    /**
     * Унифицированный метод отправки ошибки (alert).
     */
    private function sendError(string $msg): ServerResponse
    {
        // Показываем alert
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);

        // Также отправляем обычное сообщение
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => "⚠️ <b>Ошибка:</b> {$msg}",
            'parse_mode' => 'HTML',
        ]);
    }
}
