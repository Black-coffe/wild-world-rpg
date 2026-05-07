<?php

namespace App\Services\Player\TeleportBeacon;

/**
 * v0.51.53 (TeleportBeaconSetAction decomp Step 2) — extract 6 Markdown
 * message templates у dedicated formatter service.
 *
 * Кожен метод повертає sendMessage payload-array готовий до:
 *   Request::sendMessage(array_merge(['chat_id' => $chatId], $payload))
 *
 * Templates:
 *  - installSuccess() — "⚙️ Установка телепорт-маяка завершена!" з biome details
 *  - captureOption() — prompt про перехват чужого маяка
 *  - captureSuccess() — "✅ Маяк перехвачен!" (для editMessageText OR sendMessage fallback)
 *  - oldOwnerAlert() — "‼️ Тревога! Твой маяк перехвачен..."
 *  - cancel() — "Операция установки... отменена"
 *  - error() — generic error wrapper (raw text у Markdown)
 */
class BeaconMessageFormatter
{
    /**
     * Common helper: builds Markdown sendMessage payload.
     *
     * @return array{text: string, parse_mode: string}
     */
    private function md(string $text): array
    {
        return [
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];
    }

    /**
     * Success прийняття маяка з повними biome characteristics.
     *
     * @param array<string,mixed>|null $biomeRow biomes row OR null якщо не знайдено
     * @return array{text: string, parse_mode: string, reply_markup: string}
     */
    public function installSuccess(
        int $coordX,
        int $coordY,
        int $cellNumber,
        ?array $biomeRow,
        int $beaconLeft,
        int $updatedCount,
        int $maxBeacons
    ): array {
        $biomeName        = '???';
        $biomeDescription = '';
        $biomeType        = '';
        $dangerLevelText  = '';
        $biomeDangerLevel = 0;
        $survivalText     = '';
        $survivalValue    = 0;
        $occurrenceRate   = 0;

        if ($biomeRow) {
            $biomeName        = $biomeRow['name'] ?? '???';
            $biomeDescription = $biomeRow['description'] ?? '';
            $biomeType        = $biomeRow['biome_type'] ?? '';
            $dangerLevelText  = $biomeRow['danger_level_text'] ?? '';
            $biomeDangerLevel = (int) ($biomeRow['danger_level'] ?? 0);
            $survivalText     = $biomeRow['survival_difficulty_text'] ?? '';
            $survivalValue    = (int) ($biomeRow['survival_difficulty'] ?? 0);
            $occurrenceRate   = (float) ($biomeRow['occurrence_rate'] ?? 0);
        }

        $text = "⚙️ *Установка телепорт-маяка завершена!*\n\n"
              . "Ты успешно разместил маяк:\n"
              . "• Координаты: `X={$coordX}, Y={$coordY}` (#{$cellNumber})\n"
              . "• Биом: *{$biomeName}*\n"
              . "   _{$biomeDescription}_\n\n"
              . "🔋 *Запас прочности:* 100 использований\n\n"
              . "📋 *Характеристики биома:*\n"
              . "• Тип: `{$biomeType}`\n"
              . "• Опасность: {$biomeDangerLevel}/10 «{$dangerLevelText}»\n"
              . "• Сложность выживания: {$survivalValue}/10 «{$survivalText}»\n"
              . "• Частота встречаемости: {$occurrenceRate}%\n\n"
              . "📦 *Маяков в инвентаре:* {$beaconLeft}\n"
              . "🏗 Теперь у тебя *{$updatedCount}* маяков из макс. *{$maxBeacons}*\n\n"
              . "🔎 Надеюсь, никто не отыщет твой маяк и не разберёт его на детали...";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🏠 База',            'callback_data' => 'Base'],
                ],
            ],
        ];

        return [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    /**
     * Prompt про чужий маяк: "Перехватить" / "Отменить".
     *
     * @param array<string,mixed> $beacon teleport_beacons row (потрібен `id` + `remaining_uses`)
     * @return array{text: string, parse_mode: string, reply_markup: string}
     */
    public function captureOption(array $beacon): array
    {
        $uses = (int) ($beacon['remaining_uses'] ?? 0);
        $text = "На этой точке уже обнаружен *чужой* маяк!\n"
              . "Остаток телепортов: {$uses}\n\n"
              . "Ты можешь его *перехватить* (присвоить себе) или *отказаться* (ничего не делать).";

        $captureData = "teleportBeaconSet_capture_id={$beacon['id']}";
        $cancelData  = "teleportBeaconSet_cancel";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👀 Перехватить!', 'callback_data' => $captureData],
                    ['text' => '🚫 Отменить',     'callback_data' => $cancelData],
                ],
            ],
        ];

        return [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    /**
     * Текст після перехвату маяка (used у editMessageText OR fallback sendMessage).
     *
     * @param array<string,mixed> $beaconRow teleport_beacons row
     */
    public function captureSuccess(array $beaconRow): string
    {
        $x    = $beaconRow['coordinate_x'] ?? '?';
        $y    = $beaconRow['coordinate_y'] ?? '?';
        $uses = $beaconRow['remaining_uses'] ?? 0;

        return "✅ *Маяк перехвачен!*\n\n"
             . "Теперь он твой (ownership_type='captured').\n"
             . "Координаты: (X={$x}, Y={$y})\n"
             . "Остаток телепортов: {$uses}";
    }

    /**
     * Alert старому власнику: "Тревога! Твой маяк перехвачен".
     *
     * @param array<string,mixed> $beaconRow
     * @return array{text: string, parse_mode: string}
     */
    public function oldOwnerAlert(array $beaconRow): array
    {
        $x    = $beaconRow['coordinate_x'] ?? '?';
        $y    = $beaconRow['coordinate_y'] ?? '?';
        $uses = $beaconRow['remaining_uses'] ?? 0;

        return $this->md(
            "‼️ *Тревога!*\n"
            . "Твой маяк на координатах (X={$x}, Y={$y}) перехвачен.\n"
            . "Остаток телепортов: {$uses}\n"
            . "Теперь маяк тебе не принадлежит :("
        );
    }

    /** @return array{text: string, parse_mode: string} */
    public function cancel(): array
    {
        return $this->md("Операция установки (или перехвата) маяка отменена.");
    }

    /** @return array{text: string, parse_mode: string} */
    public function error(string $msg): array
    {
        return $this->md($msg);
    }
}
