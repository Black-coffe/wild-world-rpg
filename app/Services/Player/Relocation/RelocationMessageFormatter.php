<?php

namespace App\Services\Player\Relocation;

/**
 * v0.51.50 (BaseShiftingCommand decomp Step 4) — extract 11 Markdown
 * message templates у dedicated formatter service.
 *
 * Кожен метод повертає payload-array готовий до `Request::sendMessage()`:
 *   ['text' => string, 'parse_mode' => 'Markdown', 'reply_markup' => ?string]
 *
 * Це дає 2 переваги:
 *  1. SRP — UI/text logic окремо від business orchestration.
 *  2. Testability — можна unit-test'ити форматування без live Telegram bot.
 *
 * Типи повідомлень:
 *  - badFormat() / coordsOutOfRange() / cellNotFound() — input validation errors
 *  - cellUnavailable*() — cell зайнята / резерв (з/без alternative)
 *  - cellNotExplored*() — cell не вивчена (з/без alternative)
 *  - confirmPrompt() — happy path з inline button "Поехали 🚀"
 *  - confirmRaceCondition() / confirmNotExplored() — re-validation у callback
 *  - taskStarted() — final success notification
 */
class RelocationMessageFormatter
{
    /**
     * Common helper: builds Markdown sendMessage payload.
     *
     * @return array{chat_id?: int|string, text: string, parse_mode: string, reply_markup?: string}
     */
    private function md(string $text): array
    {
        return [
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];
    }

    /** @return array{text: string, parse_mode: string} */
    public function badFormat(): array
    {
        return $this->md("⚠️ Формат команды: `/base_shifting X=123Y=456`\nБез лишних пробелов!");
    }

    /** @return array{text: string, parse_mode: string} */
    public function coordsOutOfRange(int $x, int $y): array
    {
        return $this->md("❌ Координаты X,Y должны быть 1..1000.\nВы ввели: X={$x}, Y={$y}");
    }

    /** @return array{text: string, parse_mode: string} */
    public function cellNotFound(int $x, int $y): array
    {
        return $this->md("❌ Нет ячейки с координатами X={$x}, Y={$y}!");
    }

    /** @return array{text: string, parse_mode: string} */
    public function cellUnavailableNoAlt(int $x, int $y, string $reason): array
    {
        return $this->md(
            "🚫 *Ты не можешь переехать в выбранную ячейку* (X={$x}, Y={$y})\n"
            . "Причина: _{$reason}_\n\n"
            . "И увы, поблизости нет других свободных и изученных локаций.\n"
            . "Придётся поискать другие координаты позже…"
        );
    }

    /**
     * ADR-122 (UX-хвост): если передан `$altMapCellId`, вместо «скопируй команду» показываем
     * кнопку — иначе forceReply-флоу упирался бы в тот самый синтаксис, ради ухода от которого
     * он и делался. Без id (легаси-путь) текст byte-identical.
     *
     * @return array{text: string, parse_mode: string, reply_markup?: string}
     */
    public function cellUnavailableWithAlt(int $x, int $y, string $reason, int $altX, int $altY, string $biomeName, ?int $altMapCellId = null): array
    {
        $head = "🚫 *Ты не можешь переехать в выбранную ячейку* (X={$x}, Y={$y})\n"
            . "Причина: _{$reason}_\n\n"
            . "Но мы нашли *ближайшую* подходящую ячейку:\n"
            . "X={$altX}, Y={$altY}\n"
            . "Биом: *{$biomeName}*\n\n";

        if ($altMapCellId !== null) {
            return $this->altOffer($head, $altX, $altY, $altMapCellId);
        }

        return $this->md(
            $head
            . "Если тебя это устраивает, просто скопируй команду:\n"
            . "`/base_shifting X={$altX}Y={$altY}`\n\n"
            . "Удачи в пути, сталкер! 🤠"
        );
    }

    /**
     * Предложение переехать в найденную альтернативу одной кнопкой.
     * Callback тот же, что у happy path — `StartRelocationConfirm_X_Y_cell`.
     *
     * @return array{text: string, parse_mode: string, reply_markup: string}
     */
    private function altOffer(string $head, int $altX, int $altY, int $altMapCellId): array
    {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => "🚚 Перенести туда ({$altX}:{$altY})", 'callback_data' => "StartRelocationConfirm_{$altX}_{$altY}_{$altMapCellId}"],
                ],
            ],
        ];

        return [
            'text'         => $head . "_Задача займёт ~24 часа. Следующий переезд не раньше, чем через 10 дней._",
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    /** @return array{text: string, parse_mode: string} */
    public function cellNotExploredNoAlt(int $x, int $y): array
    {
        return $this->md(
            "🚫 *Ты не можешь переехать в выбранную ячейку* (X={$x}, Y={$y})\n"
            . "Причина: _Не изучена_\n\n"
            . "И поблизости нет других свободных локаций.\n"
        );
    }

    /**
     * ADR-122 (UX-хвост): см. {@see cellUnavailableWithAlt} — с `$altMapCellId` предлагаем кнопку.
     *
     * @return array{text: string, parse_mode: string, reply_markup?: string}
     */
    public function cellNotExploredWithAlt(int $x, int $y, int $altX, int $altY, string $biomeName, ?int $altMapCellId = null): array
    {
        $head = "🚫 *Ты не можешь переехать в выбранную ячейку* (X={$x}, Y={$y})\n"
            . "Причина: _Не изучена_\n\n"
            . "Но вот ближайшая изученная:\n"
            . "X={$altX}, Y={$altY}\n"
            . "Биом: *{$biomeName}*\n\n";

        if ($altMapCellId !== null) {
            return $this->altOffer($head, $altX, $altY, $altMapCellId);
        }

        return $this->md(
            $head
            . "Хочешь туда? Скопируй:\n"
            . "`/base_shifting X={$altX}Y={$altY}`"
        );
    }

    /**
     * Happy path: confirm prompt з inline button "Поехали 🚀".
     *
     * @return array{text: string, parse_mode: string, reply_markup: string}
     */
    public function confirmPrompt(int $x, int $y, string $biomeName, int $mapCellId): array
    {
        $callbackData = "StartRelocationConfirm_{$x}_{$y}_{$mapCellId}";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Поехали 🚀', 'callback_data' => $callbackData],
                ],
            ],
        ];

        return [
            'text'         => "✅ *Ты переезжаешь в выбранную ячейку* (X={$x}, Y={$y})\n"
                            . "Биом: *{$biomeName}*\n\n"
                            . "Если всё устраивает — просто жми кнопку \"Поехали!\" 🚀\n"
                            . "_Задача займёт ~24 часа. Следующий переезд не раньше, чем через 10 дней._",
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    /**
     * У handleCallback при re-validation: ячейка тепер недоступна.
     *
     * @return array{text: string, parse_mode: string}
     */
    public function confirmRaceCondition(string $reason): array
    {
        return $this->md("Увы, пока ты думал, ячейка стала недоступна. Причина: {$reason}.\nПопробуй другую!");
    }

    /** @return array{text: string} */
    public function confirmNotExplored(): array
    {
        return ['text' => "Ячейка оказалась не изучена тобой! Выбери другую."];
    }

    /** @return array{text: string, parse_mode: string} */
    public function taskStarted(int $x, int $y): array
    {
        return $this->md("🚀 Поехали! Переезд в X={$x},Y={$y} запущен.\nПродлится 24 часа.");
    }
}
