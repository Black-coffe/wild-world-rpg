<?php

declare(strict_types=1);

namespace App\Services\Bases;

/**
 * v0.51.73 (BaseInfoAction decomp Step 1) — extract Markdown templates
 * + inline keyboards у dedicated formatter.
 *
 * Усі методи повертають sendMessage/sendPhoto payload-array (БЕЗ chat_id —
 * caller додає при відправці):
 *   ['text'/'caption' => string, 'parse_mode' => 'Markdown', 'reply_markup' => string]
 *
 * Templates:
 *   userOrCharacterNotFound()                        — handle() invalid user error
 *   noBaseCellTaken()                                — current cell вже має чужий camp
 *   noBaseEmptyCell(coordX, coordY, biome?)          — happy path для розбивки
 *   noBaseNoCellNumber()                             — character ще не на карті
 *   noBaseNoMapRow(cellNumber)                       — map row not found
 *   notOnBaseError()                                 — map row not found at base
 *   notOnBasePhysically(coordX, coordY, biomeName)   — base в іншій ячейці + photo path
 *   baseBuildings(...)                               — happy path з buildings list + photo + remote text
 *   baseMapNotFound()                                — base map row not found
 */
final class BaseInfoMessageFormatter
{
    private const ROBI_PREFIX = "🤖 Это снова я – *Роби*!\n\n";

    /** @return array{text: string, parse_mode: string} */
    public function userOrCharacterNotFound(): array
    {
        return [
            'text'       => '🤖 Это снова я – *Роби*!\n\nПользователь не найден или персонаж не определён.',
            'parse_mode' => 'Markdown',
        ];
    }

    /** @return array{text: string, parse_mode: string, reply_markup: string} */
    public function noBaseCellTaken(): array
    {
        $text = self::ROBI_PREFIX
            . "Ты находишься в ячейке, которая уже занята чужим лагерем.\n"
            . "Здесь нельзя разбить собственный лагерь!";

        return [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $this->actionsOnlyKeyboard(),
        ];
    }

    /** @return array{text: string, parse_mode: string, reply_markup: string} */
    public function noBaseNoCellNumber(): array
    {
        return [
            'text' => self::ROBI_PREFIX
                . "У тебя нет разбитого лагеря, и не найдены координаты. "
                . "Похоже, ты ещё не на карте?",
            'parse_mode'   => 'Markdown',
            'reply_markup' => $this->buildCampKeyboard(),
        ];
    }

    /** @return array{text: string, parse_mode: string, reply_markup: string} */
    public function noBaseMapNotFound(int $cellNumber): array
    {
        return [
            'text' => self::ROBI_PREFIX
                . "У тебя нет ещё разбитого лагеря и не найдена карта для cell_number={$cellNumber}.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => $this->buildCampKeyboard(),
        ];
    }

    /** @return array{text: string, parse_mode: string, reply_markup: string} */
    public function noBaseBiomeNotFound(int $coordX, int $coordY, int $biomeId): array
    {
        return [
            'text' => self::ROBI_PREFIX
                . "У тебя нет ещё разбитого лагеря, "
                . "координаты: X={$coordX}, Y={$coordY}, "
                . "но биом (ID={$biomeId}) не найден.",
            'parse_mode'   => 'Markdown',
            'reply_markup' => $this->buildCampKeyboard(),
        ];
    }

    /**
     * Happy path для розбивки — біом info + координати + Razбити кнопка.
     *
     * @param array<string, mixed> $biomeRow
     * @return array{text: string, parse_mode: string, reply_markup: string}
     */
    public function noBaseHappyPath(int $coordX, int $coordY, array $biomeRow): array
    {
        $bName  = $biomeRow['name']               ?? '???';
        $bDesc  = $biomeRow['description']        ?? 'Описание недоступно';
        $dLevel = $biomeRow['danger_level']       ?? 0;
        $sDiff  = $biomeRow['survival_difficulty'] ?? 0;

        $text = self::ROBI_PREFIX
            . "У тебя *нет* ещё разбитого лагеря, а значит и нет базы.\n"
            . "Но ты сейчас находишься в ячейке:\n"
            . "• Координаты: X={$coordX}, Y={$coordY}\n"
            . "• Биом: *{$bName}*\n"
            . "• Опасность: {$dLevel}\n"
            . "• Сложность выживания: {$sDiff}\n"
            . "• *Описание*: {$bDesc}\n\n"
            . "Для разбивки лагеря используй кнопки ниже.";

        return [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $this->buildCampKeyboard(),
        ];
    }

    /** @return array{text: string} */
    public function notOnBaseMapError(): array
    {
        return ['text' => 'Ошибка: не удалось найти карту для базы.'];
    }

    /**
     * Off-base + tower out-of-range: photo з instruction повернення на базу.
     *
     * @return array{caption: string, parse_mode: string, reply_markup: string}
     */
    public function notOnBasePhysically(int $coordX, int $coordY, string $biomeName): array
    {
        $caption = self::ROBI_PREFIX
            . "Твоя база находится в другой игровой ячейке, "
            . "и (без вышки связи или вне её радиуса) ты не можешь управлять ею дистанционно.\n\n"
            . "Чтобы начать строительство, вернись на базу:\n"
            . "1️⃣ перемещение пешком\n"
            . "2️⃣ телепорт\n\n"
            . "📍 *Координаты базы*: x={$coordX}, y={$coordY}\n"
            . "🌍 *Биом*: {$biomeName}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📡 Телепорт',  'callback_data' => 'TeleportToCamp'],
                    ['text' => '🚜 Переехать', 'callback_data' => 'move'],
                ],
            ],
        ];

        return [
            'caption'      => $caption,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    /** @return array{text: string} */
    public function baseMapNotFound(): array
    {
        return ['text' => 'Ошибка: карта не найдена (ячейка базы).'];
    }

    /**
     * Happy path при on-base АБО tower-covered: список построек + tax + remote-management text.
     *
     * @param array<string, mixed>|null $coverageResult
     * @return array{caption: string, parse_mode: string, reply_markup: string}
     */
    public function baseBuildings(
        int $coordX,
        int $coordY,
        string $biomeName,
        int $buildingCount,
        int $totalTax,
        string $buildingList,
        ?array $coverageResult = null
    ): array {
        $remoteText = "";
        if ($coverageResult !== null && !empty($coverageResult['isCovered'])) {
            $lvl      = $coverageResult['towerLevel']     ?? 1;
            $distance = $coverageResult['distanceToBase'] ?? 0;
            $maxCov   = $coverageResult['maxCoverage']    ?? ($lvl * 100);

            $remoteText = "_Вы находитесь не на базе, но_ *Вышка связи* (ур. {$lvl}) "
                . "покрывает расстояние *{$distance}* / *{$maxCov}*.\n"
                . "Управление базой доступно *дистанционно*!️\n\n";
        }

        $caption = "🤖 Это я – *Роби*!\n\n"
            . $remoteText
            . "📍 *Координаты базы*: x={$coordX}, y={$coordY}\n"
            . "🌍 *Биом*: {$biomeName}\n\n"
            . "*Твоя база содержит:*\n"
            . "*Построек:* {$buildingCount} шт.\n"
            . "*Налог:* {$totalTax} ед. золота в сутки\n\n"
            . "*Список построек:*\n"
            . "{$buildingList}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏗 Строить',    'callback_data' => 'Build'],
                    ['text' => '🏘 Постройки',  'callback_data' => 'construction'],
                    ['text' => '📡 Маяки',      'callback_data' => 'teleportBeacon'],
                ],
                [
                    ['text' => '❌ Удалить базу',         'callback_data' => 'DeleteBase'],
                    ['text' => '🚚 Полноценный переезд', 'callback_data' => 'DeleteBase_FullRelocation'],
                ],
            ],
        ];

        return [
            'caption'      => $caption,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    private function actionsOnlyKeyboard(): string
    {
        return (string) json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
            ],
        ]);
    }

    private function buildCampKeyboard(): string
    {
        return (string) json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
            ],
        ]);
    }
}
