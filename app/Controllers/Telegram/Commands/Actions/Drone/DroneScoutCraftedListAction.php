<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\DroneService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W2 (ADR-058) — entry-point списка дрон-инстансов чара. Callback `droneScoutList`
 * (без аргументов). Показывает каждый row из crafted_items_log с qty>0 и
 * crafted_item_id = DroneScout.id, рисует charge-bar (durability/max) и кнопку
 * «🚁 Запустить» (только если можно — durability >= drain).
 *
 * Killswitch-aware: при drone.scout.enabled=false сообщает «фича отключена».
 *
 * Caption media-off-safe (ADR-058): полная информация в тексте, без обязательного фото.
 */
class DroneScoutCraftedListAction extends BaseAction
{
    private CraftedItemsLogModel $logModel;
    private CraftedItemsModel $itemModel;
    private DroneService $service;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel  = new CraftedItemsLogModel();
        $this->itemModel = new CraftedItemsModel();
        $this->service   = new DroneService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $this->service->isEnabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚁 *Дроны временно недоступны*\n\n_Фича отключена администрацией. Возвращайтесь позже._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterId = $this->extractInt($character, 'id');
        if ($characterId <= 0) {
            return $this->errReply($chatId, 'Невозможно определить персонажа.');
        }

        // Резолвим crafted_items.id по name_eng='DroneScout' (lookup).
        $droneItem = $this->itemModel->where('name_eng', 'DroneScout')->first();
        if (! is_array($droneItem)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚁 *Дроны не настроены*\n\n_Внутренняя ошибка: не найден предмет в справочнике._",
                'parse_mode' => 'Markdown',
            ]);
        }
        $rawDroneItemId = $droneItem['id'] ?? null;
        $droneItemId    = is_numeric($rawDroneItemId) ? (int) $rawDroneItemId : 0;
        if ($droneItemId <= 0) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚁 *Дроны не настроены*\n\n_Внутренняя ошибка: id предмета невалидный._",
                'parse_mode' => 'Markdown',
            ]);
        }

        // Все log-row'ы DroneScout с qty>0 для чара.
        $logRows = $this->logModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $droneItemId)
            ->where('quantity >', 0)
            ->orderBy('id', 'ASC')
            ->findAll();

        if (empty($logRows)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚁 *У тебя нет дронов*\n\nСкрафти `Дрона-разведчика` в мастерской — он быстро открывает 21×21 клеток вокруг тебя без передвижения. Заряжается на базе ~2 часа.",
                'parse_mode' => 'Markdown',
            ]);
        }

        $drain     = $this->service->batteryDrainPerLaunch();
        $batteryMax = $this->service->batteryMax();
        $radius    = $this->service->radiusCells();
        $cellsScan = (2 * $radius + 1) * (2 * $radius + 1);

        $text  = "🚁 *Твои дроны-разведчики*\n\n";
        $text .= "_Запуск открывает {$cellsScan} клеток ({$radius} радиусом) вокруг твоей текущей клетки. Тратит {$drain} заряда. Заряжается только когда ты на базе._\n\n";

        $rows = [];
        $instance = 1;
        foreach ($logRows as $log) {
            $logId      = $this->extractInt($log, 'id');
            $qty        = $this->extractInt($log, 'quantity');
            $charge     = $this->extractInt($log, 'durability_count');
            $chargePct  = $batteryMax > 0 ? (int) round($charge * 100 / $batteryMax) : 0;

            $bar = $this->renderChargeBar($chargePct);
            $statusEmoji = $this->service->canLaunch($charge) ? '✅' : '🪫';

            $text .= "{$statusEmoji} *Дрон #{$instance}* (×{$qty})\n";
            $text .= "Заряд: {$bar} `{$charge}/{$batteryMax}` ({$chargePct}%)\n\n";

            if ($this->service->canLaunch($charge)) {
                $rows[] = [
                    ['text' => "🚁 Запустить #{$instance}", 'callback_data' => "recceDrone_{$logId}"],
                ];
            }

            $instance++;
        }

        if (empty($rows)) {
            $text .= "_Ни один дрон не готов к запуску. Возвращайся на базу для зарядки._";
        }

        $rows[] = [
            ['text' => '🗺 Карта', 'callback_data' => 'inlineMap'],
            ['text' => '🏠 База', 'callback_data' => 'Base'],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    private function renderChargeBar(int $pct): string
    {
        $pct = max(0, min(100, $pct));
        $filled = (int) round($pct / 10);
        $empty  = 10 - $filled;
        return str_repeat('▰', $filled) . str_repeat('▱', $empty);
    }

    private function extractInt(mixed $row, string $key): int
    {
        if (is_array($row)) {
            $v = $row[$key] ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        if (is_object($row)) {
            $v = $row->{$key} ?? null;
            return is_numeric($v) ? (int) $v : 0;
        }
        return 0;
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
        ]);
        return Request::sendMessage(['chat_id' => $chatId, 'text' => $msg]);
    }
}
