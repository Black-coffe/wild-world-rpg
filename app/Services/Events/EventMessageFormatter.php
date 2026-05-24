<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * v0.51.69 (NotificationPolicy decomp Step 2) — extract message + keyboard
 * building у dedicated formatter.
 *
 * Public API:
 *   buildStartMessage(eventRow, eventConfig): string  — Markdown event start
 *   buildEndMessage(eventRow, aggregate): string      — Markdown end-summary з deltas
 *   buildStartKeyboard(effectKind): array             — inline keyboard з mute buttons
 *   buildEndKeyboard(effectKind, aggregate): array    — keyboard + magnitude-based "Лечиться"
 */
final class EventMessageFormatter
{
    /**
     * @param array<string, mixed> $eventRow
     * @param array<string, mixed> $eventConfig
     */
    public function buildStartMessage(array $eventRow, array $eventConfig): string
    {
        $name        = $eventRow['name']        ?? $eventRow['name_english'];
        $description = $eventRow['description'] ?? '';
        $duration    = (int)($eventConfig['duration_minutes'] ?? $eventRow['duration'] ?? 60);

        $msg  = "⚠️ *Событие: {$name}*\n\n";
        if ($description !== '') {
            $msg .= "_{$description}_\n\n";
        }
        $msg .= "⏳ Продлится приблизительно: {$duration} мин.\n";
        $msg .= "_Сводку отправим, когда событие закончится._";

        return $msg;
    }

    /**
     * @param array<string, mixed> $eventRow
     * @param array<string, mixed> $aggregate
     */
    public function buildEndMessage(array $eventRow, array $aggregate): string
    {
        $name       = $eventRow['name'] ?? $eventRow['name_english'];
        $appliedCnt = (int)($aggregate['applied_count'] ?? 0);
        $lines      = ["✅ *Событие завершилось: {$name}*\n"];
        $lines[]    = "_За {$appliedCnt} тиков действовало на тебя. Сводка:_\n";

        $hd = (float)($aggregate['health_delta'] ?? 0);
        $td = (float)($aggregate['tired_delta']  ?? 0);
        $gd = (int)  ($aggregate['gold_delta']   ?? 0);

        if ($hd < 0) {
            $lines[] = "❤️ Здоровье: " . round($hd, 2);
        } elseif ($hd > 0) {
            $lines[] = "❤️ Здоровье: +" . round($hd, 2);
        }
        if ($td < 0) {
            $lines[] = "💪 Выносливость: " . round($td, 2);
        } elseif ($td > 0) {
            $lines[] = "💪 Выносливость: +" . round($td, 2);
        }
        if ($gd > 0) {
            $lines[] = "🪙 Золото: +{$gd}";
        }

        foreach (($aggregate['attribute_deltas'] ?? []) as $attr => $delta) {
            $sign     = $delta > 0 ? '+' : '';
            $deltaStr = round((float)$delta, 2);
            $lines[]  = "📈 {$attr}: {$sign}{$deltaStr}";
        }

        if (!empty($aggregate['resource_grants'])) {
            $byKw = [];
            foreach ($aggregate['resource_grants'] as $g) {
                $kw         = $g['keyword'] ?? '?';
                $byKw[$kw]  = ($byKw[$kw] ?? 0) + (int)($g['amount'] ?? 0);
            }
            foreach ($byKw as $kw => $amt) {
                $lines[] = "🎁 {$kw}: +{$amt}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Кнопки для start-уведомлении.
     *
     * Callback formats (F7.5b — handler EventPrefAction):
     *   - 'eventPref_mute_1h'              — silenced_until +1h
     *   - 'eventPref_muteKind_<kind>'      — toggle kind в muted_kinds
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function buildStartKeyboard(string $effectKind): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События',       'callback_data' => 'events'],
                ],
                [
                    // Обе кнопки — про УВЕДОМЛЕНИЯ, не про сам ивент: событие всё равно
                    // происходит и влияет на игрока, кнопки лишь приглушают сообщения.
                    ['text' => '🔕 Тишина на 1 час', 'callback_data' => 'eventPref_mute_1h'],
                    ['text' => '🔕 Не уведомлять о таких событиях', 'callback_data' => "eventPref_muteKind_{$effectKind}"],
                ],
            ],
        ];
    }

    /**
     * Кнопки для end-summary. Magnitude-based — додаткова кнопка лікування при HP critical.
     *
     * @param array<string, mixed> $aggregate
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function buildEndKeyboard(string $effectKind, array $aggregate): array
    {
        $rows = [
            [
                ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ['text' => '🎉 События',       'callback_data' => 'events'],
            ],
        ];

        // Додаткова кнопка если HP loss значний
        $hd = (float)($aggregate['health_delta'] ?? 0);
        if ($hd < -10) {
            $rows[] = [
                ['text' => '🩹 Лечиться', 'callback_data' => 'pharmacy'],
            ];
        }

        $rows[] = [
            ['text' => '🔕 Тишина на 1 час', 'callback_data' => 'eventPref_mute_1h'],
        ];

        return ['inline_keyboard' => $rows];
    }
}
