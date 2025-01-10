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
use DateTime;

/**
 * Обработчик нажатия кнопки "⚔️ Атаковать" в PVP.
 *
 * Ключевые изменения:
 *  - Проверяем, могут ли игроки драться, если они стоят в одной ячейке
 *    ИЛИ находятся в соседних ячейках (dx <= 1 и dy <= 1).
 *  - Логи боя упрощены.
 */
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

        $this->characterModel       = new CharacterModel();
        $this->mapModel             = new MapModel();
        $this->biomeModel           = new BiomeModel();
        $this->telegramUserModel    = new TelegramUserModel();
        $this->characterFactionModel= new CharacterFactionModel();
        $this->factionModel         = new FactionModel();
        $this->claimedCellModel     = new ClaimedCellModel();
        $this->exploredCellsModel   = new ExploredCellsModel();
    }

    public function handle(): ServerResponse
    {
        // Предполагаем callback_data вида: attackPlayer_<defenderId>
        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData);
        $defenderId   = isset($parts[1]) ? (int)$parts[1] : 0;

        // Определяем атакующего
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

        // Проверяем дистанцию: либо одна и та же ячейка, либо соседние
        if (!$this->isCellsCloseEnough($attacker, $defender)) {
            return $this->sendError("Игрок слишком далеко. Атаковать можно только в одной или соседней ячейке!");
        }

        // Находим биом (важен для боя)
        $mapRowAttacker = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        if (!$mapRowAttacker) {
            return $this->sendError("Не найдена локация атакующего!");
        }
        $biome = $this->biomeModel->find($mapRowAttacker['biome_id']);
        if (!$biome) {
            return $this->sendError("Не найден биом для локации #{$mapRowAttacker['id']}.");
        }

        // Подгружаем фракции
        $attacker['faction'] = $this->getCharacterFaction($attacker['id']);
        $defender['faction'] = $this->getCharacterFaction($defender['id']);

        // Запуск пошагового боя (без подробных логов)
        $fightResult = $this->simulateFight($attacker, $defender, $biome);

        // Краткие итоги боя
        $summaryText = $this->formatShortFightResult($fightResult);

        // Если есть проигравший => применяем смерть/respawn
        if ($fightResult['loser']) {
            $loser  = $fightResult['loser'];
            $winner = $fightResult['winner'];
            $this->processDeathAndRespawn($loser, $winner);
            // Добавляем инфу о -10% / +2% статов (или как в логике)
            $summaryText .= "\n\n<b>{$loser['name']}</b> погиб и возродился. Статы скорректированы!";
        }

        // Отправка итогов атакующему
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👤 Персонаж',        'callback_data' => 'character'],
                    ['text' => '🎒 Инвентарь',       'callback_data' => 'inventory'],
                ],
                [
                    ['text' => '🗺️ Изучить местность','callback_data' => 'explore'],
                    ['text' => '🏠 База',            'callback_data' => 'Base'],
                ],
            ]
        ];
        Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $summaryText,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode($keyboard),
        ]);

        // Уведомляем защищающегося (если хотим)
        $this->notifyDefender($defender, $attacker, $summaryText);

        return Request::emptyResponse();
    }

    /**
     * Проверка, что игроки находятся максимум в соседних ячейках.
     * Либо cell_number равны (одна ячейка),
     * либо координаты X и Y отличаются не более чем на 1.
     */
    private function isCellsCloseEnough(array $charA, array $charB): bool
    {
        // Если оба в одной ячейке
        if ($charA['cell_number'] === $charB['cell_number']) {
            return true;
        }
        // Иначе проверим соседство по координатам
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
     * Упрощённая функция пошагового боя: возвращает массив c:
     *  - 'firstAttacker': имя или id,
     *  - 'rounds': количество,
     *  - 'winner': массив игрока (или null),
     *  - 'loser': массив игрока (или null).
     */
    private function simulateFight(array $p1, array $p2, array $biome): array
    {
        // Определяем, кто первый
        $attacker = $this->determineInitiative($p1, $p2);
        $defender = ($attacker['id'] === $p1['id']) ? $p2 : $p1;

        $round     = 0;
        $maxRounds = 50; // safety limit
        while ($attacker['health'] > 0 && $defender['health'] > 0 && $round < $maxRounds) {
            $round++;
            // Расчёт атаки
            $res = $this->executeAttack($attacker, $defender, $biome);
            $defender['health'] = max(0, $defender['health'] - $res['damage']);
            // Меняем местами
            [$attacker, $defender] = [$defender, $attacker];
        }

        // Кто умер?
        $winner = null;
        $loser  = null;
        if ($attacker['health'] <= 0) {
            $loser  = $attacker;
            $winner = $defender;
        } elseif ($defender['health'] <= 0) {
            $loser  = $defender;
            $winner = $attacker;
        }

        return [
            'firstAttacker' => $attacker['id'] === $p1['id'] ? $p1['name'] : $p2['name'],
            'rounds'        => $round,
            'winner'        => $winner,
            'loser'         => $loser,
        ];
    }

    private function determineInitiative(array $c1, array $c2): array
    {
        $i1 = $c1['agility'] + $c1['level'] * 0.5;
        $i2 = $c2['agility'] + $c2['level'] * 0.5;
        return ($i1 >= $i2) ? $c1 : $c2;
    }

    private function executeAttack(array $attacker, array $defender, array $biome): array
    {
        $damage = $this->computeDamage($attacker, $defender, $biome);
        return [
            'damage' => $damage,
        ];
    }

    private function computeDamage(array $attacker, array $defender, array $biome): float
    {
        // Минимальная формула
        $baseDamage = $attacker['strength'] * 0.5;
        $levelCoeff = 1 + ($attacker['level'] / 1000);
        $damage = $baseDamage * $levelCoeff;
        // Произвольно учитываем danger_level
        $biomeCoeff = 1 + (($biome['danger_level'] - 5) * 0.02);
        $damage *= $biomeCoeff;
        // Уклонение defender
        $dodgeroll = mt_rand(0, 100);
        $dodgeChance = $defender['agility'] * 0.3;
        if ($dodgeroll < $dodgeChance) {
            // уклон
            return 0.0;
        }
        // Защита
        $defPower = $defender['strength'] * 0.3;
        $finalDamage = max(0, $damage - $defPower);
        // Можно слегка варьировать
        return $finalDamage;
    }

    /**
     * Короткий итог боя:
     * "Первым атаковал имя/ID, было N раундов, проиграл имя/ID!"
     */
    private function formatShortFightResult(array $res): string
    {
        $firstAttacker = $res['firstAttacker'];
        $rounds        = $res['rounds'];
        $winner        = $res['winner'];
        $loser         = $res['loser'];

        // Если нет loser — ничья
        if (!$loser) {
            return "<b>PvP-бой завершён</b>\n"
                . "Первым атаковал: <i>{$firstAttacker}</i>\n"
                . "Раундов: <b>{$rounds}</b>\n"
                . "<b>Ничья!</b>";
        }

        return "<b>PvP-бой завершён</b>\n"
            . "Первым атаковал: <i>{$firstAttacker}</i>\n"
            . "Всего обменов ударами (раундов): <b>{$rounds}</b>\n"
            . "<b>Проиграл:</b> <i>{$loser['name']}</i>\n"
            . "<b>Победил:</b> <i>{$winner['name']}</i>";
    }

    /**
     * Обработка гибели: минус 10% статов у проигравшего, +2% у победителя (пример),
     * перенос на новую ячейку с HP=50, tired=50.
     */
    private function processDeathAndRespawn(array $loser, array $winner): void
    {
        // -10% проигравшему
        $minusPercent = 0.10; // допустим
        $updatedLoser = [
            'health'    => 0,
            'experience'=> max(0, $loser['experience'] * (1 - $minusPercent)),
            'strength'  => max(0, $loser['strength']   * (1 - $minusPercent)),
            'agility'   => max(0, $loser['agility']    * (1 - $minusPercent)),
            'intellect' => max(0, $loser['intellect']  * (1 - $minusPercent)),
        ];
        $this->characterModel->update($loser['id'], $updatedLoser);

        // +2% победителю
        $plusPercent = 0.02;
        $updatedWinner = [
            'health'    => max(1, $winner['health']),
            'experience'=> $winner['experience'] * (1 + $plusPercent),
            'strength'  => $winner['strength']   * (1 + $plusPercent),
            'agility'   => $winner['agility']    * (1 + $plusPercent),
            'intellect' => $winner['intellect']  * (1 + $plusPercent),
        ];
        $this->characterModel->update($winner['id'], $updatedWinner);

        // Выбираем точку возрождения (база / изученные ячейки)
        $respawnCellNumber = $this->findRespawnCell($loser['id']);

        // Устанавливаем HP=50, tired=50, cell_number = respawnCellNumber
        $this->characterModel->update($loser['id'], [
            'health'     => 50,
            'tired'      => 50,
            'cell_number'=> $respawnCellNumber,
        ]);
    }

    /**
     * Поиск ячейки для возрождения (база или любая изученная).
     */
    private function findRespawnCell(int $loserId): int
    {
        // Ищем базу
        $claimed = $this->claimedCellModel->where('character_id', $loserId)->first();
        if ($claimed) {
            return $claimed['map_cell_id'];
        }

        // Или любую изученную ячейку
        $explored = $this->exploredCellsModel->where('character_id', $loserId)->findAll();
        if (!empty($explored)) {
            $rnd = $explored[array_rand($explored)];
            return (int)$rnd['map_cell_id'];
        }

        // Если вообще нет вариантов
        return 1; // или любая доступная
    }

    /**
     * Получение фракции персонажа.
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
     * Уведомление защищающегося.
     */
    private function notifyDefender(array $defender, array $attacker, string $summaryText): void
    {
        try {
            $defenderUser = $this->telegramUserModel->find($defender['telegram_user_id']);
            if ($defenderUser && !empty($defenderUser['telegram_id'])) {
                $msg = "Тебя атаковал <b>{$attacker['name']}</b>!\n\n" . $summaryText;
                Request::sendMessage([
                    'chat_id'    => $defenderUser['telegram_id'],
                    'text'       => $msg,
                    'parse_mode' => 'HTML',
                ]);
            }
        } catch (TelegramException $e) {
            log_message('error', "notifyDefender error: " . $e->getMessage());
        }
    }

    /**
     * Универсальная отправка ошибки.
     */
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
