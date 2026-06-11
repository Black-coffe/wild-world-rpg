<?php

declare(strict_types=1);

namespace App\Services\Bases;

/**
 * v0.51.81 (BaseService decomp Step 1) — extract Markdown templates
 * + inline keyboards для BaseService у dedicated formatter.
 *
 * BaseService texts/keyboards мають свою специфіку: verbose location info,
 * additional "Телепорт/Переехать" buttons у baseBuildings keyboard, separate
 * showCampCreation flow.
 *
 * Templates (5):
 *   noBaseInfo(coordX, coordY, biomeName, biomeDesc, dangerLevel, survivalDiff)
 *   notOnBaseError() / mapNotFoundError() / cellNumberMissingError()
 *   notOnBasePhysically(coordX, coordY, biomeName)
 *   baseBuildings(coordX, coordY, biomeName, count, totalTax, list, coverageResult?)
 *   campCreationConfirm(coordX, coordY, biomeName)
 *   campCreationAlreadyHave() / campCellTaken() / campMapNotFound(cellNumber)
 */
final class BaseServiceMessageFormatter
{
    private const ROBI_PREFIX = "🤖 Это снова я – *Роби*!\n\n";

    /** @return array{text: string, parse_mode: string, reply_markup: string} */
    public function noBaseInfo(
        int|string $coordX,
        int|string $coordY,
        string $biomeName,
        string $biomeDesc,
        int $dangerLevel,
        int $survivalDiff
    ): array {
        $text = self::ROBI_PREFIX
            . "У тебя *нет* ещё разбитого лагеря, а значит и нет базы.\n\n"
            . "🏚 *База* — твой дом: на ней строятся *Склад*, *Теплица*, *Мастерская* и другие "
            . "постройки. _В магазине построек нет — их возводят на базе._\n\n"
            . "Ты сейчас находишься в локации:\n"
            . "• Координаты: X={$coordX}, Y={$coordY}\n"
            . "• Биом: *{$biomeName}*\n"
            . "• Опасность: {$dangerLevel}\n"
            . "• Сложность выживания: {$survivalDiff}\n";

        $text .= $biomeDesc !== ''
            ? "• _Описание_: {$biomeDesc}\n\n"
            : "\n";

        $text .= "Выбирай место с умом — биом базы влияет на доступные ресурсы. "
            . "Чтобы разбить лагерь, жми кнопку ниже:";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp']],
            ],
        ];

        return [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    /** @return array{text: string} */
    public function notOnBaseMapError(): array
    {
        return ['text' => 'Ошибка: не удалось найти карту для базы.'];
    }

    /** @return array{text: string} */
    public function baseMapNotFoundError(): array
    {
        return ['text' => 'Ошибка: карта не найдена (ячейка базы).'];
    }

    /** @return array{text: string} */
    public function cellNumberMissingError(): array
    {
        return ['text' => 'Ошибка: у персонажа нет cell_number!'];
    }

    /** @return array{text: string} */
    public function alreadyHaveActiveCampError(): array
    {
        return ['text' => 'У вас уже есть активная база. Сначала удалите её, чтобы создать новую.'];
    }

    /** @return array{text: string} */
    public function campCellTakenError(): array
    {
        return ['text' => '❗ Невозможно разбить лагерь: эта территория уже занята другим игроком.'];
    }

    /** @return array{text: string} */
    public function campMapNotFoundError(int $cellNumber): array
    {
        return ['text' => "Ошибка: не найдена карта для cell_number={$cellNumber}"];
    }

    /**
     * Off-base + tower out-of-range: photo з instruction повернення на базу.
     *
     * @return array{caption: string, parse_mode: string, reply_markup: string}
     */
    public function notOnBasePhysically(int|string $coordX, int|string $coordY, string $biomeName): array
    {
        $caption = self::ROBI_PREFIX
            . "Твоя база находится в другой ячейке, и сигнал вышки связи туда не дотягивается.\n"
            . "Чтобы начать строительство или управлять базой, вернись физически:\n\n"
            . "1️⃣ дойти пешком\n"
            . "2️⃣ телепорт\n\n"
            . "📍 *Координаты базы*: x={$coordX} y={$coordY}\n"
            . "🌍 *Биом*: {$biomeName}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
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

    /**
     * Happy path при on-base АБО tower-covered.
     *
     * @param array<string, mixed>|null $coverageResult
     * @param array{name?: string|null, flag?: string|null, hearth?: string|null, furniture?: string|null, pet?: string|null}|null $interior W22 interior items
     * @return array{caption: string, parse_mode: string, reply_markup: string}
     */
    public function baseBuildings(
        int|string $coordX,
        int|string $coordY,
        string $biomeName,
        int $buildingCount,
        int $totalTax,
        string $buildingList,
        ?array $coverageResult = null,
        ?string $campName = null,
        ?string $campFlag = null,
        bool $decorEnabled = false,
        ?array $interior = null
    ): array {
        $coverageText = "";
        if ($coverageResult !== null && !empty($coverageResult['isCovered'])) {
            $towerLevel  = $coverageResult['towerLevel'];
            $distance    = $coverageResult['distanceToBase'];
            $maxCoverage = $coverageResult['maxCoverage'];

            $coverageText = "\n_Вы сейчас не физически на базе,_\n"
                . "_но сигнал *Вышки связи* (ур. {$towerLevel}) покрывает *{$distance}* ходов_\n"
                . "_(лимит: {$maxCoverage} ходов)._\n"
                . "**Управление базой доступно дистанционно!**\n\n";
        }

        // W21: декор базы — имя + флаг в шапке caption (media-off safe: только текст).
        $campHeader = '';
        if ($campName !== null || $campFlag !== null) {
            $label      = trim(($campFlag !== null ? $campFlag . ' ' : '') . ($campName ?? ''));
            $campHeader = "🏕️ *{$label}*\n";
        }

        // W22: интерьер — очаг/обстановка/питомец отдельным блоком (media-off safe).
        $interiorBlock = '';
        if ($interior !== null) {
            $interiorLines = [];
            foreach (['hearth', 'furniture', 'pet'] as $slot) {
                $val = $interior[$slot] ?? null;
                if (is_string($val) && $val !== '') {
                    $interiorLines[] = $val;
                }
            }
            if ($interiorLines !== []) {
                $interiorBlock = "\n🏠 *Обустройство:*\n" . implode("\n", $interiorLines) . "\n";
            }
        }

        $caption = "🤖 Это я – *Роби*!\n\n"
            . $campHeader
            . ($coverageText ?: '')
            . "📍 *Координаты базы*: x={$coordX} y={$coordY}\n"
            . "🌍 *Биом*: {$biomeName}\n"
            . $interiorBlock
            . "\n*Твоя база содержит:*\n"
            . "*Построек:* {$buildingCount} шт.\n"
            . "*Налог:* {$totalTax} ед. золота в сутки\n\n"
            . "*Список построек:*\n"
            . "{$buildingList}";

        // ADR-103 Часть B Слой 4 — на пустой базе подсказываем вход в стройку.
        if ($buildingCount === 0) {
            $caption .= "\n\n💡 _Нажми «🏗 Строить», чтобы возвести первую постройку — Склад, Теплицу и др._";
        }

        $kbRows = [
            [
                ['text' => '🏗 Строить',   'callback_data' => 'Build'],
                ['text' => '🏘 Постройки', 'callback_data' => 'construction'],
                ['text' => '📡 Маяки',     'callback_data' => 'teleportBeacon'],
            ],
        ];
        // E20 (ADR-120): «🤖 Ангар» — хаб автоматизации; всегда виден
        // (UX-discoverability), lock-state рендерит сам HangarAction.
        $hangarRow = [['text' => '🤖 Ангар', 'callback_data' => 'hangar']];
        if ($decorEnabled) {
            $hangarRow[] = ['text' => '🎨 Декор', 'callback_data' => 'campDecor'];
        }
        $kbRows[] = $hangarRow;
        $kbRows[] = [
            ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
            ['text' => '🚜 Переехать', 'callback_data' => 'move'],
        ];
        $kbRows[] = [
            ['text' => '❌ Удалить базу',         'callback_data' => 'DeleteBase'],
            ['text' => '🚚 Полноценный переезд', 'callback_data' => 'DeleteBase_FullRelocation'],
        ];

        $keyboard = ['inline_keyboard' => $kbRows];

        return [
            'caption'      => $caption,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }

    /** @return array{text: string, parse_mode: string, reply_markup: string} */
    public function campCreationConfirm(int|string $coordX, int|string $coordY, string $biomeName): array
    {
        $text = "Ты собираешься разбить лагерь на клетке (X={$coordX}, Y={$coordY}), биом: *{$biomeName}*.\n\n"
            . "Разбивка лагеря – серьёзный шаг. Если потом снести базу,\n"
            . "все построенные сооружения будут утеряны!\n\n"
            . "Подтверждаешь создание лагеря?";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ Подтвердить', 'callback_data' => 'CampCreateConfirm']],
            ],
        ];

        return [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => (string) json_encode($keyboard),
        ];
    }
}
