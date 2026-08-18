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
        int $maxBeacons,
        int $maxUses
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
              . "🔋 *Запас телепортов:* {$maxUses} — столько раз можно переместиться на этот маяк\n\n"
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

    /**
     * Сколько установленных маяков показываем списком. Остальные — строкой
     * «…и ещё N», иначе caption у фото уезжает за 1024 символа и Telegram молча
     * не отправляет сообщение (урок photo-caption).
     */
    public const OVERVIEW_LIST_LIMIT = 5;

    /**
     * Caption экрана «📡 Маяки» — заряд, налог и где что стоит.
     *
     * Зачем метод отдельный и чистый. Вопрос игрока (Анжела, 18.08.2026): «как узнать,
     * сколько заряда осталось в маяке?» Остаток существовал (`remaining_uses`), но
     * попадал на глаза ровно в одном месте — хвостом строки списка перемещения в виде
     * «ТП. 87», а на самом экране маяков не было ни одного установленного маяка: только
     * лимиты и счётчик в инвентаре. Текст вынесен из хендлера, чтобы длину caption'а
     * можно было мерить тестом, а не обещанием.
     *
     * @param list<array{x:int, y:int, uses:int, max_uses:int, biome:string, tax:int}> $beacons
     *        установленные маяки игрока (порядок = порядок показа)
     * @param array{x:int|string, y:int|string, cell:int, biome:string}                $position
     *        где игрок стоит сейчас
     */
    public function beaconsOverview(
        array $beacons,
        array $position,
        int $playerLevel,
        int $baseMaxByPlayer,
        int $teleportCenterLevel,
        int $maxBeacons,
        int $beaconQuantity,
        int $newBeaconUses,
        int $newBeaconTax
    ): string {
        $installed = count($beacons);

        $text = "📡 *Маяки телепорта*\n\n"
            . "Маяк — своя точка возврата: поставил здесь, потом переместишься сюда с любого места.\n"
            . "Новый маяк держит *{$newBeaconUses}* телепортов и стоит *{$newBeaconTax}\$* налога в сутки, пока цел.\n\n";

        if ($installed === 0) {
            $text .= "📡 *Установлено маяков: 0 из {$maxBeacons}*\n"
                . "_Пока ни одного — заряд показывать не у чего._\n\n";
        } else {
            $text .= "📡 *Установлено маяков: {$installed} из {$maxBeacons}*\n";

            $totalTax = 0;
            foreach ($beacons as $i => $b) {
                $totalTax += $b['tax'];
                if ($i < self::OVERVIEW_LIST_LIMIT) {
                    $text .= "• `X={$b['x']} Y={$b['y']}` — ⚡ *{$b['uses']}* из {$b['max_uses']} телепортов · {$b['biome']}\n";
                }
            }

            if ($installed > self::OVERVIEW_LIST_LIMIT) {
                $rest = $installed - self::OVERVIEW_LIST_LIMIT;
                $text .= "_…и ещё {$rest} — все видны в «Переместиться на маяк»._\n";
            }

            $text .= "💸 Налог за них: *{$totalTax}\$* в сутки\n\n";
        }

        $text .= "🎒 В инвентаре: *{$beaconQuantity}* шт. маяков\n"
            . "📍 Ты здесь: `X={$position['x']} Y={$position['y']}` (#{$position['cell']}), {$position['biome']}\n\n"
            . "⚙ Лимит: 1 маяк за каждые *10 уровней* (твой {$playerLevel} → {$baseMaxByPlayer}) "
            . "плюс уровень *Центра телепортации* (сейчас {$teleportCenterLevel}) — итого *{$maxBeacons}*.";

        return $text;
    }
}
