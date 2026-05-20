<?php

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BattleLogModel;
use App\Models\BiomeModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use App\Models\ClaimedCellModel;
use App\Models\ExploredCellsModel;
use App\Models\FactionModel;
use App\Models\MapModel;
use App\Models\OutfitModel;
use App\Models\TelegramUserModel;
use App\Models\WeaponModel;

use App\Services\Endgame\EndgameProgressionService;
use App\Services\Player\DeathService;
use App\Services\Player\PvPRestrictionService;
use App\Services\PVE\DefenseStructureService;
use App\Services\PVE\PvpDamageCalculator;
use App\Services\PVE\PvpEquipmentRepository;
use App\Services\PVE\PvpFormulaService;
use App\Services\PVE\PvpRewardOrchestrator;
use App\Services\PVE\PvpRoundOrchestrator;

use Config\GameBalance;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

/**
 * Контроллер PvP-атаки.
 *
 * После F2.3b декомпозиции (v0.10.0–v0.14.1):
 *   - PvpFormulaService       — pure формулы (level/stats/dodge/range/rarity)
 *   - PvpEquipmentRepository  — DB reads (weapon / outfits / map / faction)
 *   - PvpDamageCalculator     — формула урона + biome coefficient
 *   - PvpRoundOrchestrator    — simulateFight loop + lucky strike + initiative
 *   - PvpRewardOrchestrator   — DB writes (reward / death / exhaustion)
 *
 * Все игровые константы — в `Config\GameBalance`.
 *
 * handle() — thin orchestrator:
 *   validate → fetch biome → simulateFight → branch (exhausted / death) →
 *   notify both sides via Telegram.
 */
class AttackPlayerAction extends BaseAction
{
    protected $characterModel;
    protected $mapModel;
    protected $biomeModel;
    protected $telegramUserModel;
    protected $battleLogModel;

    private PvpFormulaService $pvpFormulas;
    private PvpEquipmentRepository $equipmentRepo;
    private PvpDamageCalculator $damageCalc;
    private PvpRoundOrchestrator $roundOrchestrator;
    private PvpRewardOrchestrator $rewardOrchestrator;
    private GameBalance $cfg;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->cfg               = config('GameBalance');
        $this->characterModel    = new CharacterModel();
        $this->mapModel          = new MapModel();
        $this->biomeModel        = new BiomeModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->battleLogModel    = new BattleLogModel();

        // Локальные модели — нужны только для DI в сервисы.
        $charactersWeapons = new CharactersWeaponsModel();
        $weapons           = new WeaponModel();
        $charactersOutfits = new CharactersOutfitsModel();
        $outfits           = new OutfitModel();
        $characterFaction  = new CharacterFactionModel();
        $faction           = new FactionModel();
        $claimedCell       = new ClaimedCellModel();
        $exploredCells     = new ExploredCellsModel();

        $this->pvpFormulas   = new PvpFormulaService();
        $this->equipmentRepo = new PvpEquipmentRepository(
            $charactersWeapons,
            $weapons,
            $charactersOutfits,
            $outfits,
            $this->mapModel,
            $characterFaction,
            $faction
        );
        $this->damageCalc        = new PvpDamageCalculator($this->pvpFormulas, $this->equipmentRepo);
        $this->roundOrchestrator = new PvpRoundOrchestrator($this->damageCalc, $this->pvpFormulas);
        $this->rewardOrchestrator = new PvpRewardOrchestrator(
            $this->characterModel,
            $claimedCell,
            $exploredCells
        );
    }

    /**
     * Обработка PvP-атаки.
     */
    public function handle(): ServerResponse
    {
        $battleStartTime = date('Y-m-d H:i:s');

        $callbackData = $this->callbackQuery->getData();
        $parts        = explode('_', $callbackData);
        $defenderId   = isset($parts[1]) ? (int) $parts[1] : 0;

        [$user, $attacker] = $this->getUserAndCharacter();
        if (!$user || !$attacker) {
            return $this->sendError("Не найден атакующий персонаж (вы).");
        }
        if ($defenderId <= 0) {
            return $this->sendError("Не указан ID цели атаки. Попробуйте ещё раз.");
        }

        $defender = $this->characterModel->find($defenderId);
        if (!$defender) {
            return $this->sendError("Цель (ID {$defenderId}) не найдена.");
        }
        if ($attacker['id'] === $defender['id']) {
            return $this->sendError("Нельзя атаковать самого себя!");
        }

        // v0.51.44 — anti-spam cooldown (Security-telegram §7).
        // Cache-based gate per attacker. Раннє повернення до DB queries
        // (PvPRestriction, MapModel, BiomeModel, simulateFight) — зменшує
        // навантаження від spammers, які жмуть кнопку 2-3× за секунду.
        $cooldownSec    = $this->cfg->pvpAttackCooldownSec;
        $cacheKey       = "pvp_attack_cd_{$attacker['id']}";
        $cache          = \Config\Services::cache();
        $lastAttackTime = $cache->get($cacheKey);
        if (is_int($lastAttackTime) && time() - $lastAttackTime < $cooldownSec) {
            $remaining = $cooldownSec - (time() - $lastAttackTime);
            return $this->sendError("Подождите {$remaining} сек. перед следующей атакой!");
        }
        $cache->save($cacheKey, time(), $cooldownSec);

        if (!$this->isCellsCloseEnough($attacker, $defender)) {
            return $this->sendError("Игрок слишком далеко. Атаковать можно только в одной или соседней ячейке!");
        }

        $pvpRestrictionService = new PvPRestrictionService();
        $check = $pvpRestrictionService->checkPvPAllowed($attacker, $defender);
        if (!$check['allowed']) {
            return $this->sendError("PvP недоступно: {$check['reason']}");
        }

        $mapRowAttacker = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        if (!$mapRowAttacker) {
            return $this->sendError("Не найдена локация (map) атакующего!");
        }
        $biome = $this->biomeModel->find($mapRowAttacker['biome_id']);
        if (!$biome) {
            return $this->sendError("Не найден биом для ячейки #{$mapRowAttacker['id']}.");
        }

        $attacker['faction'] = $this->equipmentRepo->getCharacterFaction((int) $attacker['id']);
        $defender['faction'] = $this->equipmentRepo->getCharacterFaction((int) $defender['id']);

        // S26 (ADR-030): defensive structures защитника, если он стоит на своей
        // клетке со структурами (active hp>0). null → бой без защиты (как раньше).
        $defenseService = new DefenseStructureService();
        $defenseProfile = $defenseService->getDefenseProfile(
            (int) $defender['id'],
            (int) ($defender['cell_number'] ?? 0),
        );

        // 1) Симуляция боя (с учётом защиты базы, если есть).
        $fightResult = $this->roundOrchestrator->simulateFight($attacker, $defender, $biome, $defenseProfile);

        // S26: износ структур за отбитую атаку (decay), если защита применялась.
        if ($defenseProfile !== null) {
            $defenseService->applyDecay($defenseProfile['structure_ids']);
        }

        // 2) Текст итогов.
        $summaryText   = $this->formatShortFightResult($fightResult);
        $loser         = $fightResult['loser']  ?? null;
        $winner        = $fightResult['winner'] ?? null;
        $attackerName  = $attacker['name'];
        $defenderName  = $defender['name'];
        $attackerIntro = '';
        $defenderIntro = '';

        if ($fightResult['type'] === 'exhausted') {
            $this->rewardOrchestrator->processMutualExhaustion($attacker, $defender);
            $summaryText .= "\n\n<b>Оба бойца изнемогли</b> и решили прекратить схватку!\n"
                . "❤️ Здоровье и выносливость сброшены до 10.\n"
                . "🚶 <i>Они отступили, обдумывая ошибки...</i>";
            $attackerIntro = "Ты участвовал в битве, но оба упали без сил.";
            $defenderIntro = "Тебя атаковали, но сражение закончилось взаимным изнеможением.";
        } elseif ($loser !== null && $winner !== null) {
            $deathService   = new DeathService();
            $deathResult    = $deathService->handlePlayerDeathAndReward($loser['id'], $winner['id']);
            $penaltyPercent = (int) ($deathResult['penalty'] * 100);

            if ($penaltyPercent === 0) {
                $summaryText .= "\n\n❌ <b>{$loser['name']}</b> потерпел поражение, но страховка спасла от потери имущества!";
            } else {
                $loserBefore = $loser;
                $this->rewardOrchestrator->processDeathAndRespawn($loser);
                $loser         = $this->characterModel->find($loser['id']);
                $loserDiffText = $this->rewardOrchestrator->makeLoserDiffText($loserBefore);

                if ($deathResult['hasBase']) {
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> повержен и возродился...\n"
                        . $loserDiffText
                        . "\nТы потерял лишь <b>{$penaltyPercent}%</b> ресурсов, ведь база частично спасла запасы."
                        . "\nНо <b>враг забрал</b> эти <b>{$penaltyPercent}%</b>!";
                } else {
                    $summaryText .= "\n\n❌ <b>{$loser['name']}</b> проиграл бой и был возрождён...\n"
                        . $loserDiffText
                        . "\nТы оказался <b>без базы</b>, так что потерял <b>50%</b> ресурсов/крафта/золота: "
                        . "<i>половина исчезла бесследно, половина (25%) досталась врагу.</i>";
                }
            }

            $winnerBefore = $winner;
            $this->rewardOrchestrator->giveWinnerBonus($winner['id']);
            $winner         = $this->characterModel->find($winner['id']);
            $winnerDiffText = $this->rewardOrchestrator->makeWinnerDiffText($winnerBefore);
            $summaryText   .= "\n\n🏆 <b>{$winner['name']}</b> торжествует! {$winnerDiffText}";

            // v0.51.112 endgame hook: PvP kill → winner's faction score.
            (new EndgameProgressionService())->recordPvpKill($winner);

            $attackerIntro = ($attacker['id'] === $winner['id'])
                ? "Ты атаковал и разгромил врага!"
                : "Ты начал бой, но оказался слабее в этот раз...";

            $defenderIntro = ($defender['id'] === $winner['id'])
                ? "На тебя напали, но ты защитился и победил!"
                : "Тебя атаковали, и ты пал в этом бою...";
        }

        $attackerFinalText = "🤺 <b>{$attackerName}</b>, {$attackerIntro}\n\n{$summaryText}";
        $defenderFinalText = "🛡 <b>{$defenderName}</b>, {$defenderIntro}\n\n{$summaryText}";

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
        ]);

        // 3) Лог боя в БД.
        $endTime  = date('Y-m-d H:i:s');
        $winnerId = $winner ? $winner['id'] : null;

        $logDetails = [
            'characters' => [
                'attacker' => [
                    'id'        => $attacker['id'],
                    'name'      => $attacker['name'],
                    'level'     => $attacker['level'],
                    'faction'   => $attacker['faction']['name'] ?? null,
                    'strength'  => $attacker['strength'],
                    'agility'   => $attacker['agility'],
                    'intellect' => $attacker['intellect'],
                    'health'    => $attacker['health'],
                ],
                'defender' => [
                    'id'        => $defender['id'],
                    'name'      => $defender['name'],
                    'level'     => $defender['level'],
                    'faction'   => $defender['faction']['name'] ?? null,
                    'strength'  => $defender['strength'],
                    'agility'   => $defender['agility'],
                    'intellect' => $defender['intellect'],
                    'health'    => $defender['health'],
                ],
            ],
            'rounds'  => $fightResult['roundLogs'] ?? [],
            'outcome' => [
                'type'     => $fightResult['type'],
                'winnerId' => $winnerId,
                'loserId'  => $loser ? $loser['id'] : null,
            ],
        ];

        $battleId = $this->battleLogModel->insert([
            'battle_type' => 'PVP',
            'player1_id'  => $attacker['id'],
            'player2_id'  => $defender['id'],
            'winner_id'   => $winnerId,
            'created_at'  => $battleStartTime,
            'finished_at' => $endTime,
            'log_data'    => json_encode($logDetails, JSON_UNESCAPED_UNICODE),
        ]);

        $battleUrl = base_url('battles/view/') . $battleId;
        $attackerFinalText .= "\n\n<a href=\"{$battleUrl}\">[Посмотреть детали боя]</a>";
        $defenderFinalText .= "\n\n<a href=\"{$battleUrl}\">[Посмотреть детали боя]</a>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎒 Инвентарь',          'callback_data' => 'inventory'],
                    ['text' => '🗺️ Изучить местность', 'callback_data' => 'march'],
                ],
            ],
        ];

        // 4) Уведомления обоим бойцам.
        Request::sendMessage([
            'chat_id'                  => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'                     => $attackerFinalText,
            'parse_mode'               => 'HTML',
            'reply_markup'             => json_encode($keyboard),
            'disable_web_page_preview' => true,
        ]);

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
     * Соседние клетки (dx ≤ 1 && dy ≤ 1) — пред-валидация перед боем.
     */
    private function isCellsCloseEnough(array|\App\Entities\CharacterEntity $charA, array|\App\Entities\CharacterEntity $charB): bool
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
     * Формат короткого текста итогов боя для Telegram.
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
            . "🔁 <b>Всего обменов ударами:</b> {$rounds}\n"
            . "❌ <b>Проиграл:</b> {$l['name']}\n"
            . "🏆 <b>Победил:</b> {$w['name']}";
    }

    /**
     * Telegram error response (callback alert + chat message).
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
}
