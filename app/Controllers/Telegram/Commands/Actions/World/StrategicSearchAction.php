<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\World;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\MapModel;
use App\Services\World\StrategicObjectService;
use App\TaskHandlers\Objects\StrategicLootHandler;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * ADR-129 — кнопка «🔍 Обыскать» для стратегического объекта на клетке игрока.
 *
 * Корень проблемы (прод, 2026-06-15): радиосигнал (ADR-098, {@see \App\Services\World\ObjectSignalService})
 * ведёт к strategic-объекту, но одиночный шаг по карте (`move_dir_*`) НЕ запускает
 * discovery — только марш ({@see \App\TaskHandlers\MarchingTaskHandler}). Игрок доходил
 * до клетки и «ничего не происходило». Эта кнопка делает лут opt-in и discoverable
 * (как «🏚 Войти» у поселений), без авто-лута на каждом шаге → баланс не меняется.
 *
 * Callback `strategicSearch` (без аргумента): по клетке персонажа находит active
 * strategic-объект и вызывает {@see StrategicLootHandler} (лут с фото ИЛИ «нужен инструмент»).
 */
final class StrategicSearchAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->alert('Персонаж не найден. Попробуйте /start.');
        }

        $cellNumber = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $object     = (new StrategicObjectService())->activeStrategicOnCell($cellNumber);
        if ($object === null) {
            return $this->alert('Здесь нечего обыскивать — объекта на этой клетке нет (возможно, его уже забрали, либо ты на соседней клетке).');
        }

        $cell = (new MapModel())->where('cell_number', $cellNumber)->first() ?? [];

        // StrategicLootHandler сам шлёт сообщение: лут с фото ЛИБО «нужен инструмент»
        // (discovery_tools-гейт, напр. Мотыга для «Старой фермы»).
        (new StrategicLootHandler())->handle($object, $cell, $character);

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return Request::emptyResponse();
    }

    private function alert(string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => mb_substr($msg, 0, 200),
            'show_alert'        => true,
        ]);

        return Request::emptyResponse();
    }
}
