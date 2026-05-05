<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Events\KindLabels;
use Longman\TelegramBot\Request;

/**
 * F7.5b — обробка кнопок mute/throttle на event-уведомлениях.
 *
 * Підтримувани callback patterns (зареєстровано в CallbackqueryCommand):
 *   - 'eventPref_mute_1h'       → silenced_until = NOW + 1 година
 *   - 'eventPref_muteKind_<X>'  → toggle X в muted_kinds (X може містити
 *                                 '_': например 'damage_health' → суффикс
 *                                 'damage_health' после prefix 'eventPref_muteKind_')
 *
 * Поведінка:
 *   - Зчитує/обновляесть telegram_users.event_pref JSON
 *   - answerCallbackQuery с підтвердженням (toast на 3 сек)
 *   - Не міняесть оригінальне сообщение (щоб игрок бачив контекст подіи)
 */
final class EventPrefAction extends BaseAction
{
    public function handle()
    {
        $callbackId   = $this->callbackQuery->getId();
        $callbackData = $this->callbackQuery->getData();

        [$user, ] = $this->getUserAndCharacter();
        if ($user === null) {
            return Request::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text'              => 'Пользователь не найден',
                'show_alert'        => false,
            ]);
        }

        $currentPref = self::readPref($user);
        $update      = self::computeUpdate($callbackData, $currentPref);

        if ($update === null) {
            // Unknown action — все ж таки answerCallback, щоб loader не висів
            return Request::answerCallbackQuery([
                'callback_query_id' => $callbackId,
                'text'              => '',
                'show_alert'        => false,
            ]);
        }

        $this->telegramUserModel->update($user['id'], [
            'event_pref' => json_encode($update['pref'], JSON_UNESCAPED_UNICODE),
        ]);

        return Request::answerCallbackQuery([
            'callback_query_id' => $callbackId,
            'text'              => $update['confirm'],
            'show_alert'        => false,
        ]);
    }

    /**
     * Pure-logic: вычислити новый pref + confirmation text для callback_data.
     * Returns null если callback не зматчено.
     *
     * @param array<string, mixed> $currentPref
     * @return array{pref: array<string, mixed>, confirm: string}|null
     */
    public static function computeUpdate(string $callbackData, array $currentPref): ?array
    {
        $pref = $currentPref;

        if ($callbackData === 'eventPref_mute_1h') {
            $until                  = date('Y-m-d H:i:s', time() + 3600);
            $pref['silenced_until'] = $until;
            return [
                'pref'    => $pref,
                'confirm' => "🚫 Уведомления событий выключены до " . date('H:i', strtotime($until)),
            ];
        }

        if (str_starts_with($callbackData, 'eventPref_muteKind_')) {
            $kind = substr($callbackData, strlen('eventPref_muteKind_'));
            if ($kind === '') {
                return null;
            }
            $label = KindLabels::ru($kind);
            $muted = (array)($pref['muted_kinds'] ?? []);
            if (in_array($kind, $muted, true)) {
                $muted   = array_values(array_filter($muted, fn($k) => $k !== $kind));
                $confirm = "🔔 События {$label} снова включены";
            } else {
                $muted[] = $kind;
                $confirm = "🚫 События {$label} больше показываться не будут";
            }
            $pref['muted_kinds'] = $muted;
            return ['pref' => $pref, 'confirm' => $confirm];
        }

        if ($callbackData === 'eventPref_unmute') {
            $pref['silenced_until'] = null;
            $pref['muted_kinds']    = [];
            return [
                'pref'    => $pref,
                'confirm' => '🔔 Все уведомления снова включены',
            ];
        }

        return null;
    }

    /**
     * Прочитать event_pref JSON resilient (NULL/invalid → []).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function readPref(array $user): array
    {
        $raw = $user['event_pref'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
