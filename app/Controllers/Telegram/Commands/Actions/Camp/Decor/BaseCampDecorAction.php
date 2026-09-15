<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Decor;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Housing\BaseCampDecorService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W21 (ADR-076) — Housing customisation: экран декора базы.
 *
 * Callbacks (W21 — экстерьер):
 *   campDecor              — обзорный экран (текущий декор + кнопки изменить)
 *   campDecorName          — палитра из 12 пресетных имён
 *   campDecorFlag          — палитра из 16 emoji-флагов
 *   campSetName_<idx>      — сохранить имя по индексу → вернуть обзор
 *   campSetFlag_<idx>      — сохранить флаг по индексу → вернуть обзор
 *
 * Callbacks (W22 — интерьер, interior items):
 *   campDecorHearth        — палитра из 6 пресетов очага
 *   campDecorFurniture     — палитра из 6 пресетов обстановки
 *   campDecorPet           — палитра из 6 пресетов питомца
 *   campSetHearth_<idx>    — сохранить очаг → вернуть обзор
 *   campSetFurniture_<idx> — сохранить обстановку → вернуть обзор
 *   campSetPet_<idx>       — сохранить питомца → вернуть обзор
 *
 * Killswitch housing.decoration.enabled (W22 переиспользует тот же — единый housing-feature).
 * edit-in-place через MediaSender::editTextOrSend.
 * Caption самодостаточен (media-off safe: только текст, без фото).
 *
 * story multibase-picker-06 — декор правит ВЫБРАННУЮ базу. Суффикс `_b<id>` в
 * `callback_data` (см. {@see BaseCallbackSuffix}) резолвится через
 * {@see BaseScopeResolver::resolveForBase()}; `unavailable` — честный отказ, запись не
 * происходит. Без суффикса — прежнее правило {@see BaseScopeResolver::resolve()}. Суффикс
 * несут все кнопки экрана декора дальше по цепочке (палитры, «Назад», сохранение пресета),
 * иначе выбор базы терялся бы на первом же клике вглубь экрана.
 */
final class BaseCampDecorAction extends BaseAction
{
    private BaseCampDecorService $decor;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->decor = new BaseCampDecorService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        if (! $this->decor->enabled()) {
            return $this->editText($chatId, "🎨 *Декор базы временно недоступен*\n\n_Раздел отключён администрацией._", [
                [['text' => '◀️ База', 'callback_data' => 'Base']],
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        [$data, $baseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $currentCell      = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $resolver         = new BaseScopeResolver();

        if ($baseId !== null) {
            $resolved = $resolver->resolveForBase($charId, $currentCell, $baseId);
            if ($resolved['cell'] === null) {
                return $this->editText($chatId, $resolved['text'], [
                    [['text' => '◀️ База', 'callback_data' => 'Base']],
                ]);
            }
            $cell = $resolved['cell'];
        } else {
            $legacy = $resolver->resolve($charId, $currentCell);
            if ($legacy['cell'] === null) {
                return $this->editText($chatId, (string) $legacy['text'], [
                    [['text' => '◀️ База', 'callback_data' => 'Base']],
                ]);
            }
            $cell = $legacy['cell'];
        }

        if (preg_match('/^campSetName_(\d+)$/', $data, $m) === 1) {
            $this->decor->setCampName($charId, (int) $m[1], $cell);
            return $this->showOverview($chatId, $charId, $cell, $baseId);
        }

        if (preg_match('/^campSetFlag_(\d+)$/', $data, $m) === 1) {
            $this->decor->setCampFlag($charId, (int) $m[1], $cell);
            return $this->showOverview($chatId, $charId, $cell, $baseId);
        }

        if (preg_match('/^campSetHearth_(\d+)$/', $data, $m) === 1) {
            $this->decor->setCampHearth($charId, (int) $m[1], $cell);
            return $this->showOverview($chatId, $charId, $cell, $baseId);
        }

        if (preg_match('/^campSetFurniture_(\d+)$/', $data, $m) === 1) {
            $this->decor->setCampFurniture($charId, (int) $m[1], $cell);
            return $this->showOverview($chatId, $charId, $cell, $baseId);
        }

        if (preg_match('/^campSetPet_(\d+)$/', $data, $m) === 1) {
            $this->decor->setCampPet($charId, (int) $m[1], $cell);
            return $this->showOverview($chatId, $charId, $cell, $baseId);
        }

        return match ($data) {
            'campDecorName'      => $this->showNamePalette($chatId, $baseId),
            'campDecorFlag'      => $this->showFlagPalette($chatId, $baseId),
            'campDecorHearth'    => $this->showPalette($chatId, '🔥 *Выбери очаг базы:*', BaseCampDecorService::PRESET_HEARTHS, 'campSetHearth', 2, $baseId),
            'campDecorFurniture' => $this->showPalette($chatId, '🪑 *Выбери обстановку:*', BaseCampDecorService::PRESET_FURNITURE, 'campSetFurniture', 2, $baseId),
            'campDecorPet'       => $this->showPalette($chatId, '🐾 *Выбери питомца-маскота:*', BaseCampDecorService::PRESET_PETS, 'campSetPet', 2, $baseId),
            default              => $this->showOverview($chatId, $charId, $cell, $baseId),
        };
    }

    private function showOverview(int $chatId, int $charId, int $cellNumber, ?int $baseId): ServerResponse
    {
        $d    = $this->decor->getCampDecor($charId, $cellNumber > 0 ? $cellNumber : null);
        $name = $d['name'];
        $flag = $d['flag'];

        $exterior = ($name !== null || $flag !== null)
            ? trim(($flag !== null ? $flag . ' ' : '') . ($name ?? '(без имени)'))
            : '_не настроен_';

        // W22: блок интерьера — каждый слот отдельной строкой (media-off safe).
        $interiorLines = [];
        if ($d['hearth'] !== null) {
            $interiorLines[] = $d['hearth'];
        }
        if ($d['furniture'] !== null) {
            $interiorLines[] = $d['furniture'];
        }
        if ($d['pet'] !== null) {
            $interiorLines[] = $d['pet'];
        }
        $interior = $interiorLines !== [] ? implode("\n", $interiorLines) : '_пусто_';

        $text = "🎨 *Декор базы*\n\n"
            . "🏕️ Вид снаружи: {$exterior}\n\n"
            . "🏠 *Обустройство:*\n{$interior}\n\n"
            . "Выбери, что изменить:";

        return $this->editText($chatId, $text, [
            [
                ['text' => '✏️ Имя лагеря', 'callback_data' => $this->withSuffix('campDecorName', $baseId)],
                ['text' => '🏴 Флаг',       'callback_data' => $this->withSuffix('campDecorFlag', $baseId)],
            ],
            [
                ['text' => '🔥 Очаг',       'callback_data' => $this->withSuffix('campDecorHearth', $baseId)],
                ['text' => '🪑 Обстановка', 'callback_data' => $this->withSuffix('campDecorFurniture', $baseId)],
                ['text' => '🐾 Питомец',    'callback_data' => $this->withSuffix('campDecorPet', $baseId)],
            ],
            [['text' => '◀️ База', 'callback_data' => $this->withSuffix('Base', $baseId)]],
        ]);
    }

    /**
     * W22: generic палитра interior items — кнопки-пресеты + «Назад».
     *
     * @param list<string> $presets  отображаемые подписи (они же display-строки)
     * @param string       $prefix   callback-prefix без `_<idx>`
     * @param int<1, max>  $perRow   кнопок в строке
     */
    private function showPalette(int $chatId, string $title, array $presets, string $prefix, int $perRow, ?int $baseId): ServerResponse
    {
        $rows   = [];
        $groups = array_chunk($presets, $perRow, true);
        foreach ($groups as $group) {
            $row = [];
            foreach ($group as $idx => $label) {
                $row[] = ['text' => $label, 'callback_data' => $this->withSuffix("{$prefix}_{$idx}", $baseId)];
            }
            $rows[] = $row;
        }
        $rows[] = [['text' => '◀️ Назад', 'callback_data' => $this->withSuffix('campDecor', $baseId)]];

        return $this->editText($chatId, $title, $rows);
    }

    private function showNamePalette(int $chatId, ?int $baseId): ServerResponse
    {
        $rows   = [];
        $groups = array_chunk(BaseCampDecorService::PRESET_NAMES, 3, true);
        foreach ($groups as $group) {
            $row = [];
            foreach ($group as $idx => $name) {
                $row[] = ['text' => $name, 'callback_data' => $this->withSuffix("campSetName_{$idx}", $baseId)];
            }
            $rows[] = $row;
        }
        $rows[] = [['text' => '◀️ Назад', 'callback_data' => $this->withSuffix('campDecor', $baseId)]];

        return $this->editText($chatId, "✏️ *Выбери имя лагеря:*", $rows);
    }

    private function showFlagPalette(int $chatId, ?int $baseId): ServerResponse
    {
        $rows   = [];
        $groups = array_chunk(BaseCampDecorService::PRESET_FLAGS, 4, true);
        foreach ($groups as $group) {
            $row = [];
            foreach ($group as $idx => $flag) {
                $row[] = ['text' => $flag, 'callback_data' => $this->withSuffix("campSetFlag_{$idx}", $baseId)];
            }
            $rows[] = $row;
        }
        $rows[] = [['text' => '◀️ Назад', 'callback_data' => $this->withSuffix('campDecor', $baseId)]];

        return $this->editText($chatId, "🏴 *Выбери флаг лагеря:*", $rows);
    }

    /** story multibase-picker-06 — переносит выбор базы дальше по цепочке кнопок декора. */
    private function withSuffix(string $callbackData, ?int $baseId): string
    {
        return $baseId !== null ? BaseCallbackSuffix::append($callbackData, $baseId) : $callbackData;
    }

    /**
     * @param list<list<array{text: string, callback_data: string}>> $rows
     */
    private function editText(int $chatId, string $text, array $rows): ServerResponse
    {
        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }
}
