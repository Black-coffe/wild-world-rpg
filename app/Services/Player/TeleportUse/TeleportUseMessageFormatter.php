<?php

namespace App\Services\Player\TeleportUse;

use App\Services\Display\MarkdownSafe;
use App\Services\Telegram\ButtonPacker;

/**
 * v0.51.64 (TeleportUseAction decomp Step 2) — extract Markdown templates
 * + inline keyboards у dedicated formatter service.
 *
 * Усі методи повертають sendMessage payload-array (БЕЗ chat_id — caller додає):
 *   ['text' => string, 'parse_mode'? => 'Markdown', 'reply_markup'? => string]
 *
 * Templates:
 *  - userOrCharacterNotFound() / unknownTeleportType() — router errors
 *  - successBackpack(remainingCharges)        — Markdown + inline keyboard {Base/Actions}
 *  - successGold(cost, newGold)               — Markdown
 *  - successPortable()                        — Markdown + inline keyboard {Base/Actions}
 *  - successExperience()                      — Markdown
 *  - chooseBase(kind, bases)                  — story 02: экран выбора базы
 *    (`reason=choose_base` от TeleportUseValidator), кнопки `TeleportUse_<Kind>_<id>`.
 *  - baseNotFound()                           — story 02: `reason=no_base`.
 *
 * Validator's error strings залишаються pass-through (already final text).
 * Action wraps їх через sendError() helper. Step 2 не зачіпає error formatting —
 * 1:1 byte-equivalent UX.
 */
class TeleportUseMessageFormatter
{
    private const ROBI_PREFIX = "🤖 Это снова я – *Роби*!\n\n";

    /**
     * Generic error wrapper для validator's pass-through error strings.
     * $robiPrefix=true → додає ROBI_PREFIX + Markdown parse_mode.
     *
     * @return array{text: string, parse_mode?: string}
     */
    public function error(string $text, bool $robiPrefix = false): array
    {
        if ($robiPrefix) {
            return [
                'text'       => self::ROBI_PREFIX . $text,
                'parse_mode' => 'Markdown',
            ];
        }
        return ['text' => $text];
    }

    /** @return array{text: string, parse_mode: string} */
    public function userOrCharacterNotFound(): array
    {
        return [
            'text'       => self::ROBI_PREFIX . "Пользователь или персонаж не найден.",
            'parse_mode' => 'Markdown',
        ];
    }

    /** @return array{text: string, parse_mode: string} */
    public function unknownTeleportType(): array
    {
        return [
            'text'       => self::ROBI_PREFIX . "Неизвестная команда телепортации.",
            'parse_mode' => 'Markdown',
        ];
    }

    /** @return array{text: string, parse_mode: string, reply_markup: string} */
    public function successBackpack(int $remainingCharges): array
    {
        return [
            'text'         => "Ты успешно использовал *Рюкзак телепорт*!\n"
                            . "Теперь у тебя осталось зарядов: *{$remainingCharges}*.\n\n"
                            . "Следующий телепорт будет доступен через 60 минут.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => $this->baseAndActionsKeyboard(),
        ];
    }

    /** @return array{text: string, parse_mode: string} */
    public function successGold(int $cost, int $newGold): array
    {
        $formattedCost = number_format($cost, 0, '.', ' ');
        $formattedGold = number_format($newGold, 0, '.', ' ');

        return [
            'text'       => "Ты успешно телепортировался за золото!\n\n"
                          . "Списано: {$formattedCost} 💰\n"
                          . "Остаток золота: {$formattedGold} 💰",
            'parse_mode' => 'Markdown',
        ];
    }

    /** @return array{text: string, parse_mode: string, reply_markup: string} */
    public function successPortable(): array
    {
        return [
            'text'         => self::ROBI_PREFIX . "Ты успешно использовал портативный телепорт и телепортировался на базу.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => $this->baseAndActionsKeyboard(),
        ];
    }

    /** @return array{text: string, parse_mode: string} */
    public function successExperience(): array
    {
        return [
            'text'       => self::ROBI_PREFIX . "Ты успешно использовал опыт для телепортации и телепортировался на базу.",
            'parse_mode' => 'Markdown',
        ];
    }

    /**
     * story backpack-teleport-base-choice-02 — экран «Куда прыгаем?» при ≥2 активных
     * базах. Ничего не списывается: выбор только выбирает `claimedCellId`, дальше
     * игрок попадает в тот же `useXTeleport()`, что и при одной базе.
     *
     * Самодостаточен без картинки (ADR-020): текст несёт число баз и список координат,
     * кнопки дублируют то же самое.
     *
     * @param  array<int, array<string,mixed>> $bases
     * @return array{text: string, parse_mode: string, reply_markup: string}
     */
    public function chooseBase(string $kind, array $bases): array
    {
        $count  = count($bases);
        $method = $this->methodLabel($kind);
        $text   = self::ROBI_PREFIX
                . "Активных баз: *{$count}*. {$method} — куда прыгаем?\n\n";

        $buttons = [];
        foreach ($bases as $base) {
            $campNameRaw = $base['camp_name'] ?? '';
            $name = MarkdownSafe::name(is_scalar($campNameRaw) ? (string) $campNameRaw : '', 'База');
            $xRaw = $base['coordinate_x'] ?? null;
            $yRaw = $base['coordinate_y'] ?? null;
            $x     = is_scalar($xRaw) ? (string) $xRaw : '?';
            $y     = is_scalar($yRaw) ? (string) $yRaw : '?';
            $idRaw = $base['id'] ?? null;
            $id    = is_scalar($idRaw) ? (string) $idRaw : '';
            $text .= "🏠 {$name} (X={$x}, Y={$y})\n";
            $buttons[] = [
                'text'          => "🏠 {$name} ({$x},{$y})",
                'callback_data' => "TeleportUse_{$kind}_{$id}",
            ];
        }

        $rows   = ButtonPacker::pack($buttons);
        $rows[] = [
            ['text' => '↩️ Назад', 'callback_data' => 'TeleportToCamp'],
            ['text' => '🏠 База',  'callback_data' => 'Base'],
        ];

        return [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode(['inline_keyboard' => $rows]),
        ];
    }

    /**
     * story backpack-teleport-base-choice-04 (ревью №8) — экран выбора базы называет
     * СПОСОБ, который будет потрачен при выборе базы, но без чисел баланса (стоимость
     * золота/опыта не печатается — анти-дрейф баланса).
     */
    private function methodLabel(string $kind): string
    {
        return match ($kind) {
            'Backpack'       => '🎒 Рюкзак-телепорт (1 заряд)',
            'WithGold'       => '💰 Телепорт за золото',
            'Portable'       => '📡 Портативный телепорт (1 заряд)',
            'WithExperience' => '✨ Телепорт за опыт',
            default          => 'Телепорт',
        };
    }

    /** @return array{text: string, parse_mode: string} */
    public function baseNotFound(): array
    {
        return [
            'text'       => self::ROBI_PREFIX . "База не найдена — возможно, её уже снесли. Попробуй ещё раз.",
            'parse_mode' => 'Markdown',
        ];
    }

    /**
     * Стандартний 2-button inline keyboard {🏠 База / 🧑‍🌾 Действия 🛠️}
     * — використовується після успішного teleport (backpack + portable).
     */
    private function baseAndActionsKeyboard(): string
    {
        return (string) json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
            ],
        ]);
    }
}
