<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ClaimedCellModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\DroneService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

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
    private ClaimedCellModel $claimedCellModel;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel         = new CraftedItemsLogModel();
        $this->itemModel        = new CraftedItemsModel();
        $this->service          = new DroneService();
        $this->claimedCellModel = new ClaimedCellModel();
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

        // Заряжается только на захваченной клетке базы — нужно знать, стоит ли
        // игрок сейчас на ней, чтобы показать честный ETA вместо «возвращайся на базу».
        $onBase = $this->isCharacterOnBase($characterId, $this->extractInt($character, 'cell_number'));

        $text  = "🚁 *Твои дроны-разведчики*\n\n";
        $text .= "_Запуск открывает {$cellsScan} клеток ({$radius} радиусом) вокруг твоей текущей клетки. Тратит {$drain} заряда. Заряжается только когда ты стоишь на захваченной клетке базы (~1% в минуту)._\n\n";

        $rows = [];
        $instance = 1;
        $nearestReadyEta = null; // минут до готовности ближайшего невзведённого дрона
        foreach ($logRows as $log) {
            $logId      = $this->extractInt($log, 'id');
            $qty        = $this->extractInt($log, 'quantity');
            $charge     = $this->extractInt($log, 'durability_count');
            $chargePct  = $batteryMax > 0 ? (int) round($charge * 100 / $batteryMax) : 0;

            $bar = $this->renderChargeBar($chargePct);
            $ready = $this->service->canLaunch($charge);
            $statusEmoji = $ready ? '✅' : '🪫';

            $text .= "{$statusEmoji} *Дрон #{$instance}* (×{$qty})\n";
            $text .= "Заряд: {$bar} `{$charge}/{$batteryMax}` ({$chargePct}%)\n";

            if ($ready) {
                $text .= "Готов к запуску ✅\n";
                $rows[] = [
                    ['text' => "🚁 Запустить #{$instance}", 'callback_data' => "recceDrone_{$logId}"],
                ];
            } else {
                $eta  = $this->service->minutesToReady($charge);
                $need = max(0, $drain - $charge);
                if ($nearestReadyEta === null || $eta < $nearestReadyEta) {
                    $nearestReadyEta = $eta;
                }
                if ($onBase) {
                    $text .= "🔋 Заряжается — до запуска ~{$eta} мин.\n";
                } else {
                    $text .= "🏠 Встань на клетку базы для зарядки (добрать {$need} ед., ~{$eta} мин на базе).\n";
                }
            }

            $text .= "\n";
            $instance++;
        }

        if (empty($rows)) {
            if ($onBase && $nearestReadyEta !== null) {
                $text .= "_Дроны заряжаются прямо сейчас — ближайший будет готов через ~{$nearestReadyEta} мин. Можешь подождать здесь и заглянуть позже._";
            } else {
                $text .= "_Ни один дрон не готов. Встань на захваченную клетку своей базы — зарядка пойдёт сама (~1% в минуту, полный заряд ~2 часа)._";
            }
        }

        $rows[] = [
            ['text' => \App\Services\Telegram\BotMenuService::menuLabel('world'), 'callback_data' => 'move'],
            ['text' => '🏠 База', 'callback_data' => 'Base'],
        ];

        return \App\Services\Notifications\MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /**
     * Игрок стоит на СВОЕЙ активной claimed-клетке (базе) — именно там идёт
     * зарядка дронов. Канонический способ ADR-095 (`claimed_cells.map_cell_id ==
     * characters.cell_number`, status='active'), зеркало DroneRechargeCron.
     */
    private function isCharacterOnBase(int $characterId, int $currentCell): bool
    {
        if ($characterId <= 0 || $currentCell <= 0) {
            return false;
        }
        return $this->claimedCellModel->findActiveCell($characterId, $currentCell) !== null;
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
