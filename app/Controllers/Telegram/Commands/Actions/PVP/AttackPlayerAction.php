<?php

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\TelegramUserModel;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;

use App\Models\CharacterFactionModel;
use App\Models\FactionModel;
use App\Models\ExploredCellsModel;
use App\Models\ClaimedCellModel;

class AttackPlayerAction extends BaseAction
{
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

    public function handle(): ServerResponse
    {
        // Ожидаем callback_data вида "attackPlayer_###"
        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData);
        $defenderId   = isset($parts[1]) ? (int)$parts[1] : 0;

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

        // Проверяем биом
        $mapRowAttacker = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        if (!$mapRowAttacker) {
            return $this->sendError("Не найдена локация атакующего!");
        }
        $biome = $this->biomeModel->find($mapRowAttacker['biome_id']);
        if (!$biome) {
            return $this->sendError("Не найден биом для локации #{$mapRowAttacker['id']}.");
        }

        // Фракции
        $attacker['faction'] = $this->getCharacterFaction($attacker['id']);
        $defender['faction'] = $this->getCharacterFaction($defender['id']);

        // Запускаем бой
        $fightResult = $this->simulateFight($attacker, $defender, $biome);

        // Краткая сводка
        $summaryText = $this->formatShortFightResult($fightResult);

        // Обработка «ничьи» (остались живы через 100 раундов)
        if ($fightResult['type'] === 'exhausted') {
            $this->processMutualExhaustion($attacker, $defender);
            $summaryText .= "\n\n*Оба бойца изнемогли* и решили прекратить схватку!\n"
                . "❤️ Здоровье и выносливость сброшены до 10.\n"
                . "🚶 _Они отступили, размышляя о будущем._";
        }
        // Есть проигравший
        elseif ($fightResult['loser'] !== null) {
            $loser  = $fightResult['loser'];
            $winner = $fightResult['winner'];

            // Проверка страховки
            $wasInsuranceUsed = $this->checkAndProcessInsurance($loser);

            if (!$wasInsuranceUsed) {
                $loserBefore = $loser;
                $this->processDeathAndRespawn($loser);
                $loserDiffText = $this->makeLoserDiffText($loserBefore);

                $summaryText .= "\n\n❌ *{$loser['name']}* пал в бою и возродился...\n"
                    . $loserDiffText;
            } else {
                // Страховка
                $summaryText .= "\n\n❌ *{$loser['name']}* потерпел поражение, но страховка спасла!";
            }

            // Победитель +2%
            $winnerBefore = $winner;
            $this->giveWinnerBonus($winner['id']);
            $winnerDiffText = $this->makeWinnerDiffText($winnerBefore);

            $summaryText .= "\n\n🏆 *{$winner['name']}* почувствовал вкус победы! {$winnerDiffText}";
        }

        // Отправляем итог атакующему
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👤 Персонаж',         'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь',        'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '🗺️ Изучить местность','callback_data' => 'explore'],
                    ['text' => '🏠 База',             'callback_data' => 'Base'],
                ],
            ]
        ];

        Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $summaryText,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);

        // Уведомляем защищающегося
        $this->notifyDefender($defender, $attacker, $summaryText);

        return Request::emptyResponse();
    }

    /**
     * Изменённый метод simulateFight, где каждые 10 раундов урон повышается (пример).
     */
    private function simulateFight(array $p1, array $p2, array $biome): array
    {
        $attacker = $this->determineInitiative($p1, $p2);
        $defender = ($attacker['id'] === $p1['id']) ? $p2 : $p1;

        $round      = 0;
        $maxRounds  = 150;
        $winner     = null;
        $loser      = null;
        $firstName  = $attacker['name'];

        // Будем накапливать «множитель урона» в зависимости от раундов
        // Начальное значение (можно 1.0)
        $damageBoost = 1.0;

        while ($attacker['health'] > 0 && $defender['health'] > 0 && $round < $maxRounds) {
            $round++;

            // Каждые 10 раундов немного увеличиваем damageBoost
            // Например, +0.15 каждые 10 ходов, чтобы на 70–90 раунде урон уже очень большой.
            if ($round % 10 === 0) {
                $damageBoost += 0.25;
            }

            // Считаем урон
            $damage = $this->computeDamage($attacker, $defender, $biome);

            // Применяем повышающий множитель
            $damage *= $damageBoost;

            $defender['health'] = max(0, $defender['health'] - $damage);

            // Меняем местами
            [$attacker, $defender] = [$defender, $attacker];
        }

        // Если мы вышли, потому что round=100, а оба живы
        if ($round >= $maxRounds && $attacker['health'] > 0 && $defender['health'] > 0) {
            return [
                'type'          => 'exhausted',
                'rounds'        => $round,
                'firstAttacker' => $firstName,
                'winner'        => null,
                'loser'         => null,
            ];
        }

        // Иначе кто-то умер
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

    // === Всё остальное (isCellsCloseEnough, computeDamage, etc.) без изменений, кроме того что computeDamage уже вызван в simulateFight и умножается на damageBoost.

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

    private function determineInitiative(array $c1, array $c2): array
    {
        $i1 = $c1['agility'] + $c1['level'] * 0.5;
        $i2 = $c2['agility'] + $c2['level'] * 0.5;
        return ($i1 >= $i2) ? $c1 : $c2;
    }

    private function computeDamage(array $attacker, array $defender, array $biome): float
    {
        // Прежняя логика:
        $baseDamage = $attacker['strength'] * 0.5;
        $lvlCoeffA  = 1 + ($attacker['level'] / 1000.0);
        $sumLevels  = $attacker['level'] + $defender['level'];
        $K          = 1 + ($sumLevels / 1000.0);

        $biomeCoeff = 1 + (($biome['danger_level'] - 5) * 0.02);

        // dodge
        $dodgeChance = $defender['agility'] * 0.3;
        if (mt_rand(0, 100) < $dodgeChance) {
            return 0.0;
        }

        $attackValue = $baseDamage * $lvlCoeffA * $K * $biomeCoeff;
        // defense
        $defPower = $defender['strength'] * 0.3;

        $damage = max(0, $attackValue - $defPower);
        return $damage;
    }

    private function formatShortFightResult(array $res): string
    {
        $fa     = $res['firstAttacker'];
        $rounds = $res['rounds'];

        if ($res['type'] === 'exhausted') {
            return "*PvP-бой завершён*\n"
                . "⚔️ *Первым атаковал:* {$fa}\n"
                . "🔁 *Раундов:* {$rounds}\n"
                . "*Оба выдохлись!*";
        }

        $w = $res['winner'];
        $l = $res['loser'];
        if (!$l) {
            return "*PvP-бой завершён*\n"
                . "⚔️ *Первым атаковал:* {$fa}\n"
                . "🔁 *Раундов:* {$rounds}\n"
                . "*Ничья?*";
        }
        return "*PvP-бой завершён*\n"
            . "⚔️ *Первым атаковал:* {$fa}\n"
            . "🔁 *Всего обменов ударами (раундов):* {$rounds}\n"
            . "❌ *Проиграл:* {$l['name']}\n"
            . "🏆 *Победил:* {$w['name']}";
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
            return false;
        }
        $this->characterModel->update($loser['id'], [
            'gold' => $loserDb['gold'] - $cost,
        ]);
        $respawnCell = $this->findRespawnCell($loser['id']);
        $this->characterModel->update($loser['id'], [
            'cell_number' => $respawnCell,
        ]);
        return true;
    }

    private function calculateInsuranceCost(array $char): int
    {
        $lvlPart  = $char['level'];
        $expPart  = $char['experience'];
        $avgAttr  = ($char['strength'] + $char['agility'] + $char['intellect']) / 3.0;
        $base     = $lvlPart + $expPart + $avgAttr;
        $cost     = $base * 2;
        return (int)ceil($cost);
    }

    private function processDeathAndRespawn(array $loser): void
    {
        $minusPercent = 0.10;
        $before = $this->characterModel->find($loser['id']);
        if (!$before) return;

        $loserOldExp = $before['experience'];
        $loserOldStr = $before['strength'];
        $loserOldAgi = $before['agility'];
        $loserOldInt = $before['intellect'];

        $updatedLoser = [
            'health'    => 0,
            'experience'=> max(0, $loserOldExp * (1 - $minusPercent)),
            'strength'  => max(0, $loserOldStr * (1 - $minusPercent)),
            'agility'   => max(0, $loserOldAgi * (1 - $minusPercent)),
            'intellect' => max(0, $loserOldInt * (1 - $minusPercent)),
        ];
        $this->characterModel->update($loser['id'], $updatedLoser);

        $respawnCell = $this->findRespawnCell($loser['id']);
        $this->characterModel->update($loser['id'], [
            'health'     => 50,
            'tired'      => 50,
            'cell_number'=> $respawnCell,
        ]);
    }

    private function giveWinnerBonus(int $winnerId): void
    {
        $w = $this->characterModel->find($winnerId);
        if (!$w) return;

        $plusPercent = 0.02;
        $updated = [
            'experience'=> $w['experience'] * (1 + $plusPercent),
            'strength'  => $w['strength']   * (1 + $plusPercent),
            'agility'   => $w['agility']    * (1 + $plusPercent),
            'intellect' => $w['intellect']  * (1 + $plusPercent),
        ];
        $this->characterModel->update($winnerId, $updated);
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

        return "\n*Потери:* \n"
            . "• Опыт: `-{$lostExp}`\n"
            . "• Сила: `-{$lostStr}`\n"
            . "• Ловкость: `-{$lostAgi}`\n"
            . "• Интеллект: `-{$lostInt}`\n"
            . "😰 *Горький привкус поражения...*";
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

        return "\n*Награда за победу:* \n"
            . "• Опыт: `+{$gainExp}`\n"
            . "• Сила: `+{$gainStr}`\n"
            . "• Ловкость: `+{$gainAgi}`\n"
            . "• Интеллект: `+{$gainInt}`\n"
            . "🔥 *Вкус триумфа вдохновляет!*";
    }

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

    private function notifyDefender(array $defender, array $attacker, string $summaryText): void
    {
        try {
            $defUser = $this->telegramUserModel->find($defender['telegram_user_id']);
            if ($defUser && !empty($defUser['telegram_id'])) {
                $msg = "😱 *Тебя атаковал:* {$attacker['name']}!\n\n" . $summaryText;
                Request::sendMessage([
                    'chat_id'    => $defUser['telegram_id'],
                    'text'       => $msg,
                    'parse_mode' => 'Markdown',
                ]);
            }
        } catch (TelegramException $e) {
            log_message('error', "notifyDefender error: " . $e->getMessage());
        }
    }

    private function sendError(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);
        return Request::sendMessage([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'    => $msg,
        ]);
    }
}
