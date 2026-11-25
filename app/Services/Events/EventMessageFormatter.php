<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * v0.51.69 (NotificationPolicy decomp Step 2) — extract message + keyboard
 * building у dedicated formatter.
 *
 * Public API:
 *   buildStartMessage(eventRow, eventConfig): string  — Markdown event start (ADR-200: урон здоровью + база, ≤ 1024)
 *   healthHarmLines(eventRow, eventConfig): string    — строки «ранит здоровье / база укрывает» или ''
 *   buildEndMessage(eventRow, aggregate): string      — Markdown end-summary з deltas
 *   buildStartKeyboard(effectKind): array             — inline keyboard з mute buttons
 *   buildEndKeyboard(effectKind, aggregate): array    — keyboard + magnitude-based "Лечиться"
 */
final class EventMessageFormatter
{
    /** Лимит подписи фото Telegram (в UTF-16, после разметки). */
    private const CAPTION_LIMIT = 1024;

    /**
     * @param array<string, mixed> $eventRow
     * @param array<string, mixed> $eventConfig
     */
    public function buildStartMessage(array $eventRow, array $eventConfig): string
    {
        $name        = $eventRow['name']        ?? $eventRow['name_english'];
        $description = $eventRow['description'] ?? '';
        $duration    = (int)($eventConfig['duration_minutes'] ?? $eventRow['duration'] ?? 60);

        $head = "⚠️ *Событие: {$name}*\n\n";
        $tail = '';
        $harm = self::healthHarmLines($eventRow, $eventConfig);
        if ($harm !== '') {
            $tail .= $harm . "\n\n";
        }
        $tail .= "⏳ Продлится приблизительно: {$duration} мин.\n";
        $tail .= "_Сводку отправим, когда событие закончится._";

        // ADR-200: подпись фото ≤ 1024 (Telegram молча отвергает длиннее). Режется только описание из БД —
        // строка урона, срок и имя доходят всегда.
        if (self::tgLength($head . $tail) > self::CAPTION_LIMIT) {
            $head = '⚠️ *Событие: ' . mb_substr(is_scalar($name) ? (string) $name : '', 0, 120) . "…*\n\n";
        }
        if (is_string($description) && $description !== '') {
            $budget = self::CAPTION_LIMIT - self::tgLength($head . $tail) - 4; // `_` + `_` + "\n\n"
            $desc   = self::clip($description, $budget);
            if ($desc !== '') {
                $head .= "_{$desc}_\n\n";
            }
        }

        return $head . $tail;
    }

    /**
     * ADR-200 (sudden-death-1125 S3) — событие, бьющее по здоровью, говорит об этом на старте: примерный урон за
     * удар (диапазон из {@see \Config\WorldEvents}, до уровневого множителя и защитного предмета) и что своя база
     * укрывает. Пусто для событий без урона здоровью (добыча, золото, только выносливость).
     *
     * @param array<string, mixed> $eventRow
     * @param array<string, mixed> $eventConfig
     */
    public static function healthHarmLines(array $eventRow, array $eventConfig): string
    {
        $handlerKey = is_string($eventRow['handler_key'] ?? null) ? $eventRow['handler_key'] : null;
        $kind       = $handlerKey !== null && $handlerKey !== '' ? $handlerKey : ($eventConfig['effect_kind'] ?? null);
        $params     = is_array($eventConfig['effect_params'] ?? null) ? $eventConfig['effect_params'] : [];

        $range = self::healthHitRange($kind, $params);
        if ($range === null) {
            return '';
        }

        $hit    = $range === [] ? 'Ранит здоровье.' : "Ранит здоровье: примерно {$range[0]}–{$range[1]} за удар.";
        $mod    = is_array($params['state_modifier'] ?? null) ? $params['state_modifier'] : [];
        $onBase = is_numeric($mod['base_idle'] ?? null) ? (float) $mod['base_idle'] : 0.0;
        $base   = $onBase <= 0.0
            ? '🏠 Своя база — укрытие: на её клетке событие тебя не достанет.'
            : '🏠 Своя база — укрытие: на её клетке удар заметно слабее.';

        // ADR-200: пассивный урон не убивает — ниже пола `health.passive_floor` событие здоровье не опускает.
        return "❤️ {$hit} Не добьёт, но с низким здоровьем добыча в этом краю прервётся.\n{$base}";
    }

    /**
     * Диапазон урона здоровью за удар: `null` — события по здоровью не бьёт, `[]` — бьёт, а диапазона нет в
     * конфиге, `[min, max]` — объединение всех диапазонов здоровья события.
     *
     * @param array<array-key, mixed> $params
     * @return array{}|array{0: int, 1: int}|null
     */
    private static function healthHitRange(mixed $kind, array $params): ?array
    {
        $ranges = [];
        if ($kind === 'damage_health') {
            $target = $params['damage_target'] ?? 'health';
            if ($target === 'tired') {
                return null;
            }
            if ($target === 'biome_type_specific') {
                $spec = is_array($params['biome_type_specific'] ?? null) ? $params['biome_type_specific'] : [];
                foreach ($spec as $rules) {
                    if (is_array($rules) && array_key_exists('health', $rules)) {
                        $ranges[] = $rules['health'];
                    }
                }
                if ($ranges === []) {
                    return null;
                }
            } else {
                $ranges[] = $params['health_loss_range'] ?? $params['damage_range'] ?? null;
            }
        } else {
            // Побочный урон здоровью у событий другого рода (миражи: task_extend + side_effects).
            $side = is_array($params['side_effects'] ?? null) ? $params['side_effects'] : [];
            if (! array_key_exists('health_loss_range', $side)) {
                return null;
            }
            $ranges[] = $side['health_loss_range'];
        }

        $min = null;
        $max = null;
        foreach ($ranges as $r) {
            if (! is_array($r) || ! is_numeric($r[0] ?? null) || ! is_numeric($r[1] ?? null)) {
                continue;
            }
            $min = $min === null ? (float) $r[0] : min($min, (float) $r[0]);
            $max = $max === null ? (float) $r[1] : max($max, (float) $r[1]);
        }

        return $min === null || $max === null ? [] : [(int) round($min), (int) round($max)];
    }

    /** Длина в UTF-16 — метрика Telegram; разметка `*`/`_` считается, поэтому оценка с запасом. */
    private static function tgLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** Срез описания до бюджета по границе слова, с «…». Пусто, если бюджета нет. */
    private static function clip(string $text, int $budget): string
    {
        if ($budget < 20) {
            return '';
        }
        if (self::tgLength($text) <= $budget) {
            return $text;
        }
        // Символ кириллицы — 1 UTF-16 unit, эмодзи — 2: режем с запасом и досчитываем.
        $cut = mb_substr($text, 0, $budget - 1);
        while ($cut !== '' && self::tgLength($cut) > $budget - 1) {
            $cut = mb_substr($cut, 0, -1);
        }
        $space = mb_strrpos($cut, ' ');
        if (is_int($space) && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }
        // Описание идёт внутри `_…_`: непарные `*`/`_` в срезе ломают Markdown.
        $cut = str_replace(['_', '*'], '', rtrim($cut, " ,.;:—-"));

        return $cut === '' ? '' : $cut . '…';
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

        // Додаткова кнопка если HP loss значний — обе полки лечения (аптечка + провизия),
        // еда лечит сильнее лекарств (Debuffs.php), одна кнопка на pharmacy уводила
        // бы от лучшего варианта.
        $hd = (float)($aggregate['health_delta'] ?? 0);
        if ($hd < -10) {
            $rows[] = [
                ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                ['text' => '🍲 Провизия', 'callback_data' => 'provision'],
            ];
        }

        $rows[] = [
            ['text' => '🔕 Тишина на 1 час', 'callback_data' => 'eventPref_mute_1h'],
        ];

        return ['inline_keyboard' => $rows];
    }
}
