<?php

namespace App\Services\Events;

use App\Models\ActiveEventModel;
use App\Models\CharacterModel;
use App\Models\EventModel;
use App\Models\TelegramUserModel;
use Config\WorldEvents;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Throwable;

/**
 * F7.4 — обробка завершення події: читає накопичений effect_log
 * та надсилає end-summary нотіфікацію кожному гравцеві (один раз).
 *
 * Викликається з EventActivationHandler::updateExpiredEventsStatus()
 * перед тим як event row перейде у status='completed'.
 *
 * Замість ~30 окремих tick-нотіфікацій під час події гравець отримує
 * ОДНУ:
 *   "🌪 Hurricane закінчився.
 *    📉 Сумарний урон: -23 HP.
 *    Подія тривала 60 хв."
 *
 * F7.5 (NotificationPolicy v2) додасть інтерактивні кнопки + magnitude
 * triggers (override throttle) + per-user mute pref.
 */
final class EventCloseHandler
{
    private WorldEvents $cfg;
    private ActiveEventModel $activeEventModel;
    private EventModel $eventModel;
    private CharacterModel $charModel;
    private TelegramUserModel $tgUserModel;
    private EffectAccumulator $accumulator;
    private ?Telegram $telegram = null;

    public function __construct(
        ?WorldEvents $cfg = null,
        ?ActiveEventModel $activeEventModel = null,
        ?EventModel $eventModel = null,
        ?CharacterModel $charModel = null,
        ?TelegramUserModel $tgUserModel = null,
        ?EffectAccumulator $accumulator = null,
    ) {
        $this->cfg              = $cfg              ?? config('WorldEvents');
        $this->activeEventModel = $activeEventModel ?? new ActiveEventModel();
        $this->eventModel       = $eventModel       ?? new EventModel();
        $this->charModel        = $charModel        ?? new CharacterModel();
        $this->tgUserModel      = $tgUserModel      ?? new TelegramUserModel();
        $this->accumulator      = $accumulator      ?? new EffectAccumulator($this->activeEventModel);
    }

    /**
     * Закрити подію: прочитати effect_log → надіслати summary кожному
     * гравцеві → повернути stats.
     *
     * @return array{summaries_sent: int, summaries_skipped: int, errors: int}
     */
    public function closeEvent(array $activeEvent): array
    {
        $stats = ['summaries_sent' => 0, 'summaries_skipped' => 0, 'errors' => 0];

        $eventRow = $this->eventModel->find((int)$activeEvent['event_id']);
        if ($eventRow === null) {
            return $stats;
        }

        $config = $this->cfg->get($eventRow['name_english']);
        if ($config === null) {
            return $stats;
        }

        $accumulated = $this->accumulator->readForEvent((int)$activeEvent['id']);
        if (empty($accumulated)) {
            // Подія була активна але нікого не зацепила (або занадто короткою) — нема кому слати
            return $stats;
        }

        foreach ($accumulated as $charId => $agg) {
            try {
                if ($this->isEmpty($agg)) {
                    $stats['summaries_skipped']++;
                    continue;
                }

                $char = $this->charModel->find($charId);
                if ($char === null) {
                    $stats['summaries_skipped']++;
                    continue;
                }

                $sent = $this->sendEndSummary($char, $eventRow, $agg);
                if ($sent) {
                    $stats['summaries_sent']++;
                } else {
                    $stats['summaries_skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                log_message('error', "[EventCloseHandler] char_id={$charId}: " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Чи агрегат «порожній» (нічого корисного для summary).
     */
    private function isEmpty(array $agg): bool
    {
        $hasDeltas = ($agg['health_delta'] ?? 0) !== 0
            || ($agg['tired_delta'] ?? 0) !== 0
            || ($agg['gold_delta']  ?? 0) !== 0
            || !empty($agg['attribute_deltas'])
            || !empty($agg['resource_grants']);
        return !$hasDeltas;
    }

    /**
     * Сформувати + надіслати end-summary через Telegram.
     */
    private function sendEndSummary(array $char, array $eventRow, array $agg): bool
    {
        $tgId = $this->resolveTelegramId($char);
        if ($tgId === null) {
            return false;
        }

        $name        = $eventRow['name'] ?? $eventRow['name_english'];
        $appliedCnt  = (int)($agg['applied_count'] ?? 0);
        $lines       = ["✅ *Подія завершилась: {$name}*\n"];
        $lines[]     = "_За {$appliedCnt} тіків діяло на тебе. Підсумок:_\n";

        $hd = (float)($agg['health_delta'] ?? 0);
        $td = (float)($agg['tired_delta'] ?? 0);
        $gd = (int)  ($agg['gold_delta']   ?? 0);

        if ($hd < 0) {
            $lines[] = "❤️ Здоров'я: " . round($hd, 2);
        } elseif ($hd > 0) {
            $lines[] = "❤️ Здоров'я: +" . round($hd, 2);
        }
        if ($td < 0) {
            $lines[] = "💪 Витривалість: " . round($td, 2);
        } elseif ($td > 0) {
            $lines[] = "💪 Витривалість: +" . round($td, 2);
        }
        if ($gd > 0) {
            $lines[] = "🪙 Золото: +{$gd}";
        }

        foreach (($agg['attribute_deltas'] ?? []) as $attr => $delta) {
            $sign      = $delta > 0 ? '+' : '';
            $deltaStr  = round((float)$delta, 2);
            $lines[]   = "📈 {$attr}: {$sign}{$deltaStr}";
        }

        if (!empty($agg['resource_grants'])) {
            $byKw = [];
            foreach ($agg['resource_grants'] as $g) {
                $kw          = $g['keyword'] ?? '?';
                $byKw[$kw]   = ($byKw[$kw] ?? 0) + (int)($g['amount'] ?? 0);
            }
            foreach ($byKw as $kw => $amt) {
                $lines[] = "🎁 {$kw}: +{$amt}";
            }
        }

        $message = implode("\n", $lines);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events'],
                ],
            ],
        ];

        $this->ensureTelegramInitialized();

        try {
            Request::sendMessage([
                'chat_id'      => $tgId,
                'text'         => $message,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
            return true;
        } catch (TelegramException $e) {
            log_message('error', "[EventCloseHandler] sendMessage failed: " . $e->getMessage());
            return false;
        }
    }

    private function resolveTelegramId(array $char): ?int
    {
        $tgUserId = $char['telegram_user_id'] ?? null;
        if (!$tgUserId) {
            return null;
        }
        $tgUser = $this->tgUserModel->find($tgUserId);
        return $tgUser ? (int)$tgUser['telegram_id'] : null;
    }

    private function ensureTelegramInitialized(): void
    {
        if ($this->telegram !== null) {
            return;
        }
        try {
            $this->telegram = new Telegram(getenv('telegram.API_KEY'), getenv('telegram.BOT_USERNAME'));
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', "[EventCloseHandler] Telegram init failed: " . $e->getMessage());
        }
    }
}
