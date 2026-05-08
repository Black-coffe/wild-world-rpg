<?php

namespace App\Services\Player\TeleportUse;

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
