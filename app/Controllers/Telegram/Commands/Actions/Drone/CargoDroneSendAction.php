<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\Player\DroneService;

/**
 * W3b (ADR-060) — атомарная доставка cargo. Callback prefix
 * `cargoDroneSend_<crafted_items_log_id>_<resource_id>` (через CallbackPrefixDispatcher).
 *
 * Шаги (DB transaction):
 *   1. Validate ownership (log.character_id = char.id).
 *   2. Validate qty>0 / charge >= drain.
 *   3. Resolve resource info (qty в инвентаре + weight).
 *   4. Compute send_qty = min(invQty, floor(payload_kg / weight)).
 *   5. CharacterResourceModel::decrementIfAtLeast(charId, resId, send_qty) — условный UPDATE,
 *      отказ игроку при race (exploit-fix-06).
 *   6. BaseStorageModel::deliver(charId, resId, send_qty, fromCell=current_cell).
 *   7. UPDATE crafted_items_log SET durability_count -= drain.
 *
 * RNG-fence safe: ноль `mt_rand`, ноль геометрии. Pure data move.
 *
 * NOT'on_base требований нет — cargo логичен с любой клетки (включая базу:
 * «переложить из кармана на склад»). Ресурс не теряется — переходит в base_storage.
 */
class CargoDroneSendAction extends BaseAction
{
    private CraftedItemsLogModel $logModel;
    /** @var CharacterResourceModel */
    protected $resourceModel;
    private BaseStorageModel $storageModel;
    private DroneService $service;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel      = new CraftedItemsLogModel();
        $this->resourceModel = new CharacterResourceModel();
        $this->storageModel  = new BaseStorageModel();
        $this->service       = new DroneService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        if (! $this->service->cargoIsEnabled()) {
            return $this->errReply($chatId, '🚚 Карго-дрон временно отключён.');
        }

        $callbackData = $this->callbackQuery->getData();
        $parts = explode('_', $callbackData);
        if (count($parts) < 3 || $parts[0] !== 'cargoDroneSend') {
            return $this->errReply($chatId, 'Неверный формат запроса.');
        }
        $logId = is_numeric($parts[1]) ? (int) $parts[1] : 0;
        $resId = is_numeric($parts[2]) ? (int) $parts[2] : 0;
        if ($logId <= 0 || $resId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор груза.');
        }

        $log = $this->logModel->find($logId);
        if (! is_array($log)) {
            return $this->errReply($chatId, 'Дрон не найден.');
        }

        $charId    = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $logCharId = is_numeric($log['character_id'] ?? null) ? (int) $log['character_id'] : 0;
        if ($charId !== $logCharId) {
            return $this->errReply($chatId, 'Этот дрон принадлежит не тебе.');
        }

        $qty    = is_numeric($log['quantity'] ?? null) ? (int) $log['quantity'] : 0;
        $charge = is_numeric($log['durability_count'] ?? null) ? (int) $log['durability_count'] : 0;
        $drain  = $this->service->cargoBatteryDrainPerLaunch();

        if ($qty <= 0) {
            return $this->errReply($chatId, 'Дрона уже нет.');
        }
        if ($charge < $drain) {
            return $this->errReply($chatId, "🪫 Заряд карго-дрона ниже {$drain} — нужно подзарядить на базе.");
        }

        $db = \Config\Database::connect();
        $resQuery = $db->query(
            'SELECT cr.quantity AS inv_qty, r.name, r.weight
             FROM character_resources cr
             INNER JOIN resources r ON r.id = cr.id_resources
             WHERE cr.id_characters = ? AND cr.id_resources = ?',
            [$charId, $resId]
        );
        $resRow = is_object($resQuery) && method_exists($resQuery, 'getRowArray') ? $resQuery->getRowArray() : null;
        if (! is_array($resRow)) {
            return $this->errReply($chatId, 'Ресурс не найден в инвентаре.');
        }

        $invQty     = is_numeric($resRow['inv_qty'] ?? null) ? (int) $resRow['inv_qty'] : 0;
        $unitWeight = is_numeric($resRow['weight'] ?? null) ? (float) $resRow['weight'] : 0.0;
        $rawResName = $resRow['name'] ?? '';
        $resName    = is_string($rawResName) ? $rawResName : '';
        if ($invQty <= 0 || $unitWeight <= 0) {
            return $this->errReply($chatId, 'В инвентаре нет этого ресурса для отправки.');
        }

        $payloadKg = $this->service->cargoPayloadKg();
        $maxByWeight = (int) floor($payloadKg / $unitWeight);
        if ($maxByWeight < 1) {
            return $this->errReply($chatId, "Этот ресурс слишком тяжёлый — единица весит {$unitWeight} кг, дрон выдержит до {$payloadKg} кг.");
        }
        $sendQty = min($invQty, $maxByWeight);

        $fromCell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : null;

        $db->transStart();
        $outcome       = $this->resourceModel->decrementIfAtLeast($charId, $resId, $sendQty);
        $newCharge     = $charge;
        $chargeOutcome = WriteOutcome::Applied;
        if ($outcome === WriteOutcome::Applied) {
            $this->storageModel->deliver($charId, $resId, $sendQty, $fromCell);
            // exploit-fix-26 (R2-major) — было безусловным UPDATE абсолютным значением,
            // посчитанным из $charge, прочитанного до транзакции: два параллельных вылета по
            // одному прочитанному заряду списывали одно и то же уменьшенное число — второй
            // вылет бесплатный. Теперь decrementIfAtLeast() решает по WHERE durability_count
            // >= $drain и affectedRows(), не по прочитанному ранее значению.
            $chargeOutcome = (new ConditionalWriteService($db))->decrementIfAtLeast(
                'crafted_items_log',
                $logId,
                'durability_count',
                $drain
            );
            $newCharge = max(0, $charge - $drain);
        }

        $chargeRefused = $outcome === WriteOutcome::Applied && $chargeOutcome !== WriteOutcome::Applied;
        if ($chargeRefused) {
            // Ресурс уже списан из рюкзака и доставлен на склад выше в этой же транзакции —
            // явный rollback нужен, иначе транзакция закоммитит доставку без списания заряда.
            $db->transRollback();
        }

        // exploit-fix-23 — исход читаем по возврату transComplete(): откат вне
        // ветки WriteOutcome не должен вести в ветку «взлетел». Не зовём transComplete()
        // после уже сделанного вручную transRollback().
        $committed = $outcome === WriteOutcome::Applied && !$chargeRefused && $db->transComplete();

        if ($outcome !== WriteOutcome::Applied) {
            return $this->errReply($chatId, $outcome === WriteOutcome::Missing
                ? 'Этого ресурса в инвентаре уже нет.'
                : 'В инвентаре стало меньше ресурса, чем при проверке — попробуй ещё раз.');
        }
        if ($chargeRefused) {
            return $this->errReply($chatId, 'Заряд дрона списал кто-то другой быстрее — груз не отправлен, попробуй ещё раз.');
        }
        if (!$committed) {
            // exploit-fix-26 (minor, R2 reviewer-2) — раньше этот текст повторял «попробуй
            // ещё раз» про ресурс, будто откатилась только нехватка инвентаря; на самом деле
            // сюда ведёт ЛЮБОЙ незакоммиченный transComplete() (deadlock, повторный тап), и
            // игрок должен читать про откат, а не про конкретную причину, которой могло не
            // быть.
            return $this->errReply($chatId, 'Отправка не сохранилась — транзакция откатилась, попробуй ещё раз.');
        }

        // E20 (ADR-120) — инструментация адопшена дронов (раньше use-path был немеряем).
        $this->logActivity($charId, 'DRONE_CARGO_SEND', "res={$resName} qty={$sendQty}");

        $sendKg     = (float) round($sendQty * $unitWeight, 1);
        $batteryMax = $this->service->cargoBatteryMax();
        $chargePct  = $batteryMax > 0 ? (int) round($newCharge * 100 / $batteryMax) : 0;
        $bar        = $this->renderChargeBar($chargePct);
        $emoji      = ResourceIconHelper::for($resName);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Карго взлетел!',
        ]);

        $text  = "🚚 *Карго-дрон вернулся!*\n\n";
        $text .= "Доставлено на склад базы:\n";
        $text .= "  {$emoji} *{$resName}* × *{$sendQty}* ({$sendKg} кг)\n\n";
        $text .= "🔋 Остаток заряда: {$bar} `{$newCharge}/{$batteryMax}` ({$chargePct}%)\n";
        if ($newCharge < $drain) {
            $text .= "_Следующая доставка — после подзарядки на базе (~3 часа)._";
        } else {
            $text .= "_Можешь отправить ещё._";
        }

        $keyboard = ['inline_keyboard' => [
            [
                ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'],
                ['text' => '📦 Склад',      'callback_data' => 'baseStorageList'],
            ],
            [
                ['text' => '🗺 Карта', 'callback_data' => 'move'],
                ['text' => '🏠 База',  'callback_data' => 'Base'],
            ],
        ]];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
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
