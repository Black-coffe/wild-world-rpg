<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\PVP;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CharacterFactionModel;
use App\Models\FactionModel;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\PvpLadderService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W18 (ADR-072) — экран «🏆 Рейтинг PvP». Callback `pvpLadder` (global) /
 * `pvpLadder_global` / `pvpLadder_faction_<id>` (первый сегмент `pvpLadder` → этот handler).
 *
 * Топ-N по all-time points (дуэли W17 + летальные PvP-атаки), + личная позиция игрока.
 * Табы: 🌍 Глобальный / 🏳️ Моя фракция (если игрок во фракции). edit-in-place (ADR-018).
 * Killswitch pvp.ladder.enabled: при dormant — alert (кнопка входа скрыта, прямой вызов = info).
 */
final class PvpLadderAction extends BaseAction
{
    private PvpLadderService $ladder;
    private FactionModel $factionModel;
    private CharacterFactionModel $characterFactions;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->ladder            = new PvpLadderService();
        $this->factionModel      = new FactionModel();
        $this->characterFactions = new CharacterFactionModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        if (! $this->ladder->enabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🏆 *Рейтинг PvP временно недоступен*\n\n_Раздел отключён администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $topN        = $this->ladder->broadcastTopN();
        $data        = (string) $this->callbackQuery->getData();

        // Определяем вкладку из callback_data.
        $factionId = null;
        if (preg_match('/^pvpLadder_faction_(\d+)$/', $data, $m) === 1) {
            $factionId = (int) $m[1];
        }

        if ($factionId !== null) {
            $rows   = $this->ladder->topByFaction($factionId, $topN);
            $header = "🏆 *Рейтинг PvP — {$this->factionName($factionId)}*\n\n";
        } else {
            $rows   = $this->ladder->topGlobal($topN);
            $header = "🏆 *Рейтинг PvP — 🌍 Глобальный*\n\n";
        }

        $text = $header . $this->renderRows($rows);

        // Личная позиция игрока (по all-time points).
        $myRow  = $characterId > 0 ? $this->ladder->rowOf($characterId) : null;
        $myRank = $characterId > 0 ? $this->ladder->rankOf($characterId) : 0;
        if (is_array($myRow) && is_numeric($myRow['points'] ?? null) && (int) $myRow['points'] > 0) {
            $pts = (int) $myRow['points'];
            $dw  = is_numeric($myRow['duel_wins'] ?? null) ? (int) $myRow['duel_wins'] : 0;
            $pw  = is_numeric($myRow['pvp_wins'] ?? null) ? (int) $myRow['pvp_wins'] : 0;
            $rankStr = $myRank > 0 ? "#{$myRank}" : '—';
            $text .= "\n👤 *Ты:* {$rankStr} · {$pts} очк. (дуэли: {$dw}, PvP: {$pw})";
        } else {
            $text .= "\n👤 *Ты* ещё не в рейтинге — выиграй дуэль, чтобы попасть в таблицу.";
        }

        $text .= "\n\n_Очки за победы: дуэль и летальное PvP. Рейтинг — престиж, без игровых наград._";

        // Табы: глобальный + моя фракция (если игрок во фракции).
        $tabs      = [];
        $myFaction = $characterId > 0 ? $this->characterFactions->getFactionId($characterId) : 0;
        if ($factionId !== null) {
            $tabs[] = ['text' => '🌍 Глобальный', 'callback_data' => 'pvpLadder_global'];
        }
        if ($myFaction > 0 && $factionId === null) {
            $tabs[] = ['text' => '🏳️ Моя фракция', 'callback_data' => 'pvpLadder_faction_' . $myFaction];
        }
        $rowsKb = [];
        if (! empty($tabs)) {
            $rowsKb[] = $tabs;
        }
        // E25 (ADR-124) — вход на «🏟 Арену» прямо из рейтинга (climb-the-ladder discoverability).
        $rowsKb[] = [
            ['text' => '🏟 Арена', 'callback_data' => 'arena'],
            ['text' => '◀️ Я', 'callback_data' => 'character'],
        ];

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rowsKb]) ?: '{}',
        ]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function renderRows(array $rows): string
    {
        if (empty($rows)) {
            return "_Пока никто не набрал очков. Стань первым!_\n";
        }
        $medals = ['🥇', '🥈', '🥉'];
        $out    = '';
        $i      = 0;
        foreach ($rows as $r) {
            $name = is_string($r['name'] ?? null) && $r['name'] !== '' ? $r['name'] : ('№' . (is_numeric($r['character_id'] ?? null) ? (int) $r['character_id'] : '?'));
            $pts  = is_numeric($r['points'] ?? null) ? (int) $r['points'] : 0;
            $dw   = is_numeric($r['duel_wins'] ?? null) ? (int) $r['duel_wins'] : 0;
            $pw   = is_numeric($r['pvp_wins'] ?? null) ? (int) $r['pvp_wins'] : 0;
            $pos  = $medals[$i] ?? (($i + 1) . '.');
            $out .= "{$pos} *{$name}* — {$pts} очк. _(дуэли {$dw}, PvP {$pw})_\n";
            $i++;
        }
        return $out;
    }

    private function factionName(int $factionId): string
    {
        if ($factionId <= 0) {
            return 'Нейтральные';
        }
        $row = $this->factionModel->find($factionId);
        if (is_array($row) && is_string($row['name'] ?? null) && $row['name'] !== '') {
            return $row['name'];
        }
        if (is_object($row) && isset($row->name) && is_string($row->name) && $row->name !== '') {
            return $row->name;
        }
        return 'Фракция #' . $factionId;
    }
}
