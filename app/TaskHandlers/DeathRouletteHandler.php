<?php

namespace App\TaskHandlers;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;
use App\Services\Player\DeathService;
use Config\GameBalance;

/**
 * Класс DeathRouletteHandler:
 * "Рулетка смерти" для персонажей с health <= 0.99.
 * При неудачном броске - вызываем DeathService (учёт страховки/базы).
 * Применяем такую же логику респауна, как в PvP:
 *   - Если penalty=0 => страховка спасла, без урезания статов.
 *   - Иначе обрезаем статы/опыт и перемещаем в респаун-ячейку.
 *
 * v0.51.14: extends BaseTaskHandler (per F2.9 contract). Раніше extends Controller —
 * це історично-неправильно (handler НЕ контроллер). Telegram lazy-init через
 * BaseTaskHandler::telegram(), Request::sendMessage → safeSendMessage (з try/catch).
 * `process()` → `handle(array $task = []): void` (TaskHandlerInterface signature).
 */
class DeathRouletteHandler extends BaseTaskHandler
{
    private GameBalance $cfg;

    /**
     * F2.10 wire-in (v0.51.2): deathExpLossPercent + deathStatLossPercent
     * читаются из config('GameBalance') вместо hardcoded private const.
     * Раньше: self::DEATH_EXP_LOSS_PERCENT (0.05) / DEATH_STAT_LOSS_PERCENT (0.005).
     *
     * v0.51.14: removed Telegram init (now lazy via BaseTaskHandler::telegram()).
     */
    public function __construct(?GameBalance $cfg = null)
    {
        $this->cfg = $cfg ?? config('GameBalance');
    }

    /**
     * Метод, который вызывается воркером каждую минуту.
     *
     * @param array<string,mixed> $task TaskHandlerInterface signature (recurring tasks
     *                                  не приймають task data).
     */
    public function handle(array $task = []): void
    {
        // 2) Подключаем модели и DeathService
        $characterModel    = new CharacterModel();
        $deathService      = new DeathService();

        // 3) Ищем всех игроков, у кого здоровье <= 0.99
        $candidates = $characterModel
            ->where('health <=', 0.99)
            ->findAll();

        if (empty($candidates)) {
            return;
        }

        foreach ($candidates as $character) {
            $charId     = $character['id'];
            $health     = (float) $character['health'];
            $intHealth  = (int) floor($health * 100);
            $playerName = $character['name'];

            // 4) "Рулетка смерти": бросок от 1 до 100
            $roll = rand(1, 100);

            if ($roll <= $intHealth) {
                // Выжил
                continue;
            }

            // Иначе - смерть
            // 5) Списываем ресурсы/учитываем страховку
            $deathResult = $deathService->handlePlayerDeathAndReward($charId, null);
            // deathResult['penalty'] может быть:
            //   0.0 (страховка сработала),
            //   0.03 (есть база),
            //   0.50 (нет базы)

            // 6) Респаун/урезание статов, если penalty>0
            if ($deathResult['penalty'] > 0) {
                // Применяем -5% XP, -0.5% статы и перенос на базу
                $this->processDeathAndRespawn($character);
            } else {
                // penalty=0 => страховка => НЕТ урезания статы/XP
                // Но всё равно "оживаем" (считаем, что воскрес)
                $this->reviveSameCell($charId);
            }

            // 7) Отправляем уведомление
            $this->sendDeathMessage($character, $deathResult);
        }
    }

    /**
     * Если страховка сработала (penalty=0), то можно просто "воскресить" игрока
     * на той же клетке, без потери статов. Restore values configured через GameBalance
     * (insuranceRespawnHealth/insuranceRespawnTired — wire-in v0.51.5).
     */
    private function reviveSameCell(int $charId): void
    {
        $model = new CharacterModel();
        $model->update($charId, [
            'health' => $this->cfg->insuranceRespawnHealth,
            'tired'  => $this->cfg->insuranceRespawnTired,
        ]);
    }

    /**
     * Метод полностью повторяет логику "PVP-смерти":
     *   - урезаем -5% XP, -0.5% статы
     *   - здоровье=0, затем перемещаем на базу/исследованную локацию
     *   - восстанавливаем здоровье/усталость
     */
    private function processDeathAndRespawn(array|\App\Entities\CharacterEntity $loser): void
    {
        $model = new CharacterModel();
        $before = $model->find($loser['id']);
        if (!$before) {
            return;
        }

        // 1) урезаем опыт/статы
        $loserOldExp = $before['experience'];
        $loserOldStr = $before['strength'];
        $loserOldAgi = $before['agility'];
        $loserOldInt = $before['intellect'];

        $updatedLoser = [
            'experience' => max(0, $loserOldExp * (1 - $this->cfg->deathExpLossPercent)),
        ];

        $updatedLoser['strength']  = max($loser['strength'],  $loserOldStr * (1 - $this->cfg->deathStatLossPercent));
        $updatedLoser['agility']   = max($loser['agility'],   $loserOldAgi * (1 - $this->cfg->deathStatLossPercent));
        $updatedLoser['intellect'] = max($loser['intellect'], $loserOldInt * (1 - $this->cfg->deathStatLossPercent));

        // Здоровье в 0 (считаем "умер")
        $updatedLoser['health'] = 0;

        // Округлим
        $updatedLoser['experience'] = round($updatedLoser['experience'], 2);
        $updatedLoser['strength']   = round($updatedLoser['strength'],   2);
        $updatedLoser['agility']    = round($updatedLoser['agility'],    2);
        $updatedLoser['intellect']  = round($updatedLoser['intellect'],  2);

        $model->update($loser['id'], $updatedLoser);

        // 2) Определяем клетку респауна (база или исследованная, иначе #1)
        $respawnCell = $this->findRespawnCell($loser['id']);

        // 3) Воскрешаем в респаун-ячейке со 100/100 (или иные значения)
        $model->update($loser['id'], [
            'health'     => ($loser['max_health'] ?? 100),
            'tired'      => ($loser['max_tired']  ?? 100),
            'cell_number'=> $respawnCell,
        ]);
    }

    /**
     * Death roulette respawn — claimed.status='active' → explored → fallback 1.
     *
     * Це 1 з 3 різних respawn implementations у repo:
     * - {@see PvpRewardOrchestrator::findRespawnCell} — PvP exhaustion (same logic)
     * - {@see \App\Services\Player\Death\PlayerRespawner::respawn} — general death (biome whitelist)
     * - {@see DeathRouletteHandler::findRespawnCell} (this) — death roulette
     *
     * Semantically intentional divergence (3 типи смерті — різні fallback стратегії).
     */
    private function findRespawnCell(int $charId): int
    {
        // Модель claimedCell
        $claimedCellModel  = new ClaimedCellModel();
        $exploredCellsModel= new ExploredCellsModel();

        $claimed = $claimedCellModel->where('character_id', $charId)
            ->where('status', 'active')
            ->first();

        if ($claimed) {
            return (int) $claimed['map_cell_id'];
        }

        // Если нет базы, смотрим изученные
        $explored = $exploredCellsModel->where('character_id', $charId)->findAll();
        if (!empty($explored)) {
            $rnd = $explored[\array_rand($explored)];
            return (int) $rnd['map_cell_id'];
        }
        // fallback
        return 1;
    }

    /**
     * Отправляем сообщение о смерти и потерях.
     * Если penalty=0 => страховка сработала.
     *
     * v0.51.14: Request::sendMessage → safeSendMessage (BaseTaskHandler).
     */
    private function sendDeathMessage(array|\App\Entities\CharacterEntity $character, array $deathResult): void
    {
        $telegramUserModel = new TelegramUserModel();
        $telegramUser = $telegramUserModel->find($character['telegram_user_id']);

        if (!$telegramUser) {
            log_message('error', 'Не найден телеграм-пользователь для character_id=' . $character['id']);
            return;
        }

        $chatId     = $telegramUser['telegram_id'];
        $playerName = $character['name'];

        // penalty=0.0 => страховка спасла; 0.03 => есть база; 0.5 => без базы
        $penaltyPercent = (int) ($deathResult['penalty'] * 100);

        if ($penaltyPercent === 0) {
            // Страховка спасла
            $text = "😵 *{$playerName}*, ты умер(ла), но страховка уберегла твои вещи!\n\n"
                . "Ты не потерял(а) никаких ресурсов или золота. Будь осторожнее!";
        } else {
            // penalty=3% или 50%
            $text = "😵 *{$playerName}*, увы, твой персонаж погиб.\n\n"
                . "В результате смерти ты потерял(а) примерно *{$penaltyPercent}%* "
                . "от всех ресурсов, крафтовых предметов и золота.\n"
                . "Будь осторожнее в следующий раз!";
        }

        // safeSendMessage с overrideм parse_mode на Markdown (BaseTaskHandler default — HTML)
        $this->safeSendMessage($chatId, $text, ['parse_mode' => 'Markdown']);
    }
}
