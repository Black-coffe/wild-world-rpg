<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BiomeModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use App\Models\FactionModel;
use App\Models\MapModel;
use App\Models\OutfitModel;
use App\Models\WeaponModel;
use App\Services\PVE\DuelService;
use App\Services\PVE\PvpLadderService;
use App\Services\PVE\PvpDamageCalculator;
use App\Services\PVE\PvpEquipmentRepository;
use App\Services\PVE\PvpFormulaService;
use App\Services\PVE\PvpRoundOrchestrator;
use Config\GameBalance;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W17 (ADR-071) — PvP-дуэль: opt-in честный бой (stat-equalize), БЕЗ летальных последствий.
 *
 * Callback `duel_<defenderId>`. RNG-fence-safe (ADR-070): equalize обоих бойцов (DuelService) →
 * НЕИЗМЕНЁННЫЙ `PvpRoundOrchestrator::simulateFight` (null defense, спорт без базовых структур) →
 * исход. 0 DB-записей по результату (реальные чары не теряют health/XP/ресурсы).
 *
 * Снаряжение НЕ нормализуется (грузится по реальному id внутри боя) → решает билд игрока.
 */
final class DuelAction extends BaseAction
{
    private MapModel $mapModel;
    private BiomeModel $biomeModel;
    private DuelService $duelService;
    private PvpLadderService $ladderService;
    private PvpEquipmentRepository $equipmentRepo;
    private PvpRoundOrchestrator $roundOrchestrator;
    private GameBalance $cfg;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->cfg         = config(GameBalance::class);
        $this->mapModel      = new MapModel();
        $this->biomeModel    = new BiomeModel();
        $this->duelService   = new DuelService();
        $this->ladderService = new PvpLadderService();

        $formulas            = new PvpFormulaService();
        $this->equipmentRepo = new PvpEquipmentRepository(
            new CharactersWeaponsModel(),
            new WeaponModel(),
            new CharactersOutfitsModel(),
            new OutfitModel(),
            $this->mapModel,
            new CharacterFactionModel(),
            new FactionModel()
        );
        $damageCalc              = new PvpDamageCalculator($formulas, $this->equipmentRepo);
        $this->roundOrchestrator = new PvpRoundOrchestrator($damageCalc, $formulas);
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();

        if (! $this->duelService->enabled()) {
            return $this->alert('Дуэли сейчас недоступны.');
        }

        $parts      = explode('_', (string) $this->callbackQuery->getData());
        $defenderId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        // E25 (ADR-124): арена-вызов (`arenaDuel_<id>`) — спорт без ставок, локация не важна →
        // adjacency-гейт снимается. Поле-детект (`duel_<id>`) остаётся cell-adjacent (как PvP).
        $isArena = $parts[0] === 'arenaDuel';

        [$user, $attacker] = $this->getUserAndCharacter();
        if (! $user || ! $attacker) {
            return $this->alert('Персонаж не найден.');
        }
        if ($defenderId <= 0) {
            return $this->alert('Не указан соперник.');
        }
        $defender = $this->characterModel->find($defenderId);
        if (! $defender) {
            return $this->alert('Соперник не найден.');
        }
        $attackerId = is_numeric($attacker['id'] ?? null) ? (int) $attacker['id'] : 0;
        if ($attackerId === $defenderId) {
            return $this->alert('Нельзя вызвать на дуэль самого себя.');
        }
        if ((int) ($defender['duels_open'] ?? 0) !== 1) {
            return $this->alert('Этот игрок не открыт для дуэлей. Открыться можно в ⚙️ Настройках.');
        }
        if (! $isArena && ! $this->isCellsCloseEnough($attacker, $defender)) {
            return $this->alert('Соперник слишком далеко — дуэль только в одной/соседней клетке.');
        }

        // Анти-спам кулдаун (как PvP, отдельный ключ).
        $cooldownSec = $this->cfg->pvpAttackCooldownSec;
        $cacheKey    = 'pvp_duel_cd_' . $attackerId;
        $cache       = \Config\Services::cache();
        $last        = $cache->get($cacheKey);
        if (is_int($last) && time() - $last < $cooldownSec) {
            return $this->alert('Подожди ' . ($cooldownSec - (time() - $last)) . ' сек. перед следующей дуэлью.');
        }
        $cache->save($cacheKey, time(), $cooldownSec);

        $mapRow = $this->mapModel->where('cell_number', $attacker['cell_number'])->first();
        if (! $mapRow) {
            return $this->alert('Не найдена локация.');
        }
        $rawBiomeId = is_array($mapRow) ? ($mapRow['biome_id'] ?? null) : ($mapRow->biome_id ?? null);
        $biome      = $this->biomeModel->find(is_numeric($rawBiomeId) ? (int) $rawBiomeId : 0);
        if (! $biome) {
            return $this->alert('Не найден биом.');
        }

        // Stat-equalize обоих (билд/снаряжение остаётся) → неизменный simulateFight, null defense (спорт).
        $attackerArr = $this->toArray($attacker);
        $defenderArr = $this->toArray($defender);
        $eqAttacker  = $this->duelService->equalize($attackerArr);
        $eqDefender  = $this->duelService->equalize($defenderArr);
        $result      = $this->roundOrchestrator->simulateFight($eqAttacker, $eqDefender, $biome, null);

        // W18.5 (ADR-073): детерминированный исход — тай-брейк ПОСЛЕ боя (fence-safe, из roundLogs).
        // Ничья устранена: всегда есть победитель (HP → билд → старшинство). Реальные чары не тронуты.
        $resolution = $this->duelService->resolveDuel($result, $attackerArr, $defenderArr);
        $winnerId   = $resolution['winnerId'];
        $loserId    = $resolution['loserId'];
        $reason     = $resolution['reason'];

        // W18 (ADR-072): post-combat scoring в PvP-ладдер (ПОСЛЕ боя, 0 mt_rand → fence-safe;
        // killswitch pvp.ladder.enabled внутри recordDuel = dormant no-op). Теперь всегда win/loss.
        if ($winnerId > 0 && $loserId > 0) {
            $this->ladderService->recordDuel($winnerId, $loserId, false);
        }

        // НЕТ death/reward — реальные чары не тронуты (спортивный поединок).
        $aName = is_string($attacker['name'] ?? null) && $attacker['name'] !== '' ? $attacker['name'] : ('№' . $attackerId);
        $dName = is_string($defender['name'] ?? null) && $defender['name'] !== '' ? $defender['name'] : ('№' . $defenderId);
        $winnerName = $winnerId === $attackerId ? $aName : $dName;

        $text = $this->formatDuelResult($result, $reason, $winnerName, $aName, $dName);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        $this->notifyDefender($defender, $text);

        $row = [
            ['text' => '◀️ Я', 'callback_data' => 'character'],
            ['text' => \App\Services\Telegram\BotMenuService::menuLabel('world'), 'callback_data' => 'move'],
        ];
        // W18 (ADR-072): «🏆 Рейтинг» только при активном ладдере (dormant — скрыта).
        if ($this->ladderService->enabled()) {
            $row[] = ['text' => '🏆 Рейтинг PvP', 'callback_data' => 'pvpLadder'];
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => [$row]]),
        ]);
    }

    /**
     * W18.5 (ADR-073): исход всегда называет победителя + причину (knockout/hp/build/seniority).
     * Caption самодостаточен (media-off safe). Ничьи больше нет.
     *
     * @param array<string,mixed> $result
     */
    private function formatDuelResult(array $result, string $reason, string $winnerName, string $aName, string $dName): string
    {
        $rounds = is_numeric($result['rounds'] ?? null) ? (int) $result['rounds'] : 0;

        $head = "🤺 <b>Дуэль (равный бой)</b>\n"
            . "{$aName} ⚔️ {$dName}\n"
            . "🔁 Обменов ударами: {$rounds}\n\n";

        $body = match ($reason) {
            'knockout'  => "🏆 Победитель: <b>{$winnerName}</b> (нокаут)",
            'hp'        => "🏆 Победа по очкам: <b>{$winnerName}</b>\n<i>Осталось больше здоровья.</i>",
            'build'     => "🏆 Победа по очкам: <b>{$winnerName}</b>\n<i>Крепче снаряжение.</i>",
            'seniority' => "🏆 Победа по очкам: <b>{$winnerName}</b>\n<i>Больше времени в Пустоши.</i>",
            default     => "🏆 Победитель: <b>{$winnerName}</b>",
        };

        return $head . $body . "\n\n<i>Спортивный поединок: ни здоровья, ни опыта не потеряно. На равных статах решают билд и удача.</i>";
    }

    /**
     * @param array<string,mixed>|object $defender
     */
    private function notifyDefender(mixed $defender, string $text): void
    {
        try {
            $tgUserId = is_array($defender) ? ($defender['telegram_user_id'] ?? null) : ($defender->telegram_user_id ?? null);
            if (! is_numeric($tgUserId)) {
                return;
            }
            $tg = $this->telegramUserModel->find((int) $tgUserId);
            $chat = is_array($tg) ? ($tg['telegram_id'] ?? null) : null;
            if (! is_numeric($chat)) {
                return;
            }
            Request::sendMessage([
                'chat_id'    => (int) $chat,
                'text'       => "Тебя вызвали на дуэль!\n\n" . $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[DuelAction] notifyDefender failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(mixed $c): array
    {
        $raw = [];
        if (is_array($c)) {
            $raw = $c;
        } elseif ($c instanceof \CodeIgniter\Entity\Entity) {
            $raw = $c->toRawArray();
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|\App\Entities\CharacterEntity $charA
     * @param array<string,mixed>|\App\Entities\CharacterEntity $charB
     */
    private function isCellsCloseEnough(array|\App\Entities\CharacterEntity $charA, array|\App\Entities\CharacterEntity $charB): bool
    {
        $cellA = is_numeric($charA['cell_number'] ?? null) ? (int) $charA['cell_number'] : 0;
        $cellB = is_numeric($charB['cell_number'] ?? null) ? (int) $charB['cell_number'] : 0;
        if ($cellA === $cellB) {
            return true;
        }
        $mapA = $this->equipmentRepo->getMapCell($cellA);
        $mapB = $this->equipmentRepo->getMapCell($cellB);
        if (! $mapA || ! $mapB) {
            return false;
        }
        $dx = abs($mapA['coordinate_x'] - $mapB['coordinate_x']);
        $dy = abs($mapA['coordinate_y'] - $mapB['coordinate_y']);
        return $dx <= 1 && $dy <= 1;
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);
        return Request::emptyResponse();
    }
}
