<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Services\Player\DroneService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W3b (ADR-060) — entry-point для отправки cargo'а. Callback `cargoDroneList`
 * (без аргументов). Показывает первый рабочий инстанс DroneCargo + charge-bar,
 * затем список ресурсов на руках персонажа с per-resource кнопками
 * «🚚 Отправить N kg → база».
 *
 * Killswitch-aware (cargoIsEnabled). Media-off safe (caption самодостаточен,
 * никаких фото).
 *
 * UX-flow:
 *   1. Resolve DroneCargo crafted_item.id → найти первый log-row с qty>0 для чара.
 *   2. Если нет инстанса → сообщить о крафте.
 *   3. Если есть → charge-bar; если зарядка < drain → «Подожди подзарядки на базе».
 *   4. Если готов → SELECT character_resources JOIN resources WHERE qty>0 →
 *      per-resource кнопка `cargoDroneSend_<log_id>_<res_id>`.
 */
class CargoDroneSelectAction extends BaseAction
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

        if (! $this->service->cargoIsEnabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🚚 *Карго-дрон временно недоступен*\n\n_Фича отключена администрацией. Возвращайтесь позже._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId <= 0) {
            return $this->errReply($chatId, 'Невозможно определить персонажа.');
        }

        $cargoRow = $this->itemModel->where('name_eng', 'DroneCargo')->first();
        if (! is_array($cargoRow)) {
            return $this->noCargoMessage($chatId);
        }
        $cargoItemId = is_numeric($cargoRow['id'] ?? null) ? (int) $cargoRow['id'] : 0;
        if ($cargoItemId <= 0) {
            return $this->noCargoMessage($chatId);
        }

        $log = $this->logModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $cargoItemId)
            ->where('quantity >', 0)
            ->orderBy('id', 'ASC')
            ->first();

        if (! is_array($log)) {
            return $this->noCargoMessage($chatId);
        }

        $logId      = is_numeric($log['id'] ?? null) ? (int) $log['id'] : 0;
        $charge     = is_numeric($log['durability_count'] ?? null) ? (int) $log['durability_count'] : 0;
        $batteryMax = $this->service->cargoBatteryMax();
        $drain      = $this->service->cargoBatteryDrainPerLaunch();
        $payloadKg  = $this->service->cargoPayloadKg();
        $chargePct  = $batteryMax > 0 ? (int) round($charge * 100 / $batteryMax) : 0;
        $bar        = $this->renderChargeBar($chargePct);

        $text  = "🚚 *Карго-дрон*\n\n";
        $text .= "Заряд: {$bar} `{$charge}/{$batteryMax}` ({$chargePct}%)\n";
        $text .= "Полезная нагрузка: до *{$payloadKg} кг* за вылет.\n\n";

        if ($charge < $drain) {
            $text .= "🪫 _Заряд ниже {$drain} — вернись на базу для подзарядки (~3 часа до полного)._";
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ['text' => '🗺 Карта', 'callback_data' => 'inlineMap'],
                ]]]),
            ]);
        }

        $resourceRows = $this->loadCharResources($characterId);
        if (empty($resourceRows)) {
            $text .= "_На руках нет ресурсов для отправки. Сначала собери что-нибудь — добычей, гатеринг'ом или походом._";
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🗺 Карта', 'callback_data' => 'inlineMap'],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                ]]]),
            ]);
        }

        $text .= "Выбери, что отправить — карго возьмёт максимум что влезет в *{$payloadKg} кг*:\n\n";

        $buttons = [];
        $shownRows = 0;
        foreach ($resourceRows as $row) {
            $rawName    = $row['name'] ?? '';
            $resName    = is_string($rawName) ? $rawName : '';
            $resId      = is_numeric($row['id_resources'] ?? null) ? (int) $row['id_resources'] : 0;
            $invQty     = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;
            $unitWeight = is_numeric($row['weight'] ?? null) ? (float) $row['weight'] : 0.0;
            if ($resId <= 0 || $invQty <= 0 || $unitWeight <= 0 || $resName === '') {
                continue;
            }
            $maxByWeight = (int) floor($payloadKg / $unitWeight);
            if ($maxByWeight < 1) {
                continue;
            }
            $sendQty = min($invQty, $maxByWeight);
            $sendKg  = (float) round($sendQty * $unitWeight, 1);
            $emoji   = ResourceIconHelper::for($resName);
            $text   .= "{$emoji} {$resName}: на руках {$invQty} шт. ({$unitWeight} кг/шт)\n";
            $buttons[] = [[
                'text'          => "🚚 {$emoji} {$resName} × {$sendQty} ({$sendKg} кг)",
                'callback_data' => "cargoDroneSend_{$logId}_{$resId}",
            ]];
            $shownRows++;
            if ($shownRows >= 8) {
                $text .= "\n_Показаны первые 8 ресурсов — отправь часть и вернись за остальным._";
                break;
            }
        }

        if (empty($buttons)) {
            $text .= "\n_Ничего из инвентаря не помещается в {$payloadKg} кг — добудь что-то полегче или подкопи единиц._";
        }

        $buttons[] = [
            ['text' => '🗺 Карта', 'callback_data' => 'inlineMap'],
            ['text' => '🏠 База', 'callback_data' => 'Base'],
        ];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
        ]);
    }

    /**
     * SELECT character_resources JOIN resources WHERE qty > 0 — return [name, id_resources, quantity, weight].
     *
     * @return list<array<string,mixed>>
     */
    private function loadCharResources(int $characterId): array
    {
        $db = \Config\Database::connect();
        $q  = $db->query(
            'SELECT cr.id_resources, cr.quantity, r.name, r.weight, r.rarity
             FROM character_resources cr
             INNER JOIN resources r ON r.id = cr.id_resources
             WHERE cr.id_characters = ? AND cr.quantity > 0
             ORDER BY (cr.quantity * r.weight) DESC',
            [$characterId]
        );
        if (! is_object($q) || ! method_exists($q, 'getResultArray')) {
            return [];
        }
        $rows = $q->getResultArray();
        $out  = [];
        foreach ($rows as $r) {
            if (is_array($r)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    private function noCargoMessage(int $chatId): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => "🚚 *У тебя нет карго-дрона*\n\nЗайди в 🛠 Крафт → 🔧 Стандартный верстак → 🚚 Карго-дрон (нужен RoboticsWorkshop L2).",
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => '🛠 К крафту', 'callback_data' => 'standardCraft'],
                ['text' => '🏠 База',    'callback_data' => 'Base'],
            ]]]),
        ]);
    }

    private function renderChargeBar(int $pct): string
    {
        $pct = max(0, min(100, $pct));
        $filled = (int) round($pct / 10);
        $empty  = 10 - $filled;
        return str_repeat('▰', $filled) . str_repeat('▱', $empty);
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
