<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Db\WriteOutcome;
use App\Services\Player\CargoAutoLoadService;
use App\Services\Player\DroneService;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Автовывоз карго-дроном: один тап — дрон сам набирает груз и увозит на склад.
 *
 * Просьба игрока (Анжела, 18.08.2026): «карго-дрон должен иметь возможность
 * автоматического перемещения ресурса из рюкзака на склад, начиная с высшей редкости и
 * по убыванию, кроме аптечки, еды и воды».
 *
 * Отличие от обычной отправки ({@see CargoDroneSendAction}): там игрок выбирает ОДИН
 * ресурс, здесь груз подбирается сам — по убыванию редкости, до заполнения
 * грузоподъёмности, минуя еду, воду и семена ({@see CargoAutoLoadService}).
 *
 * 🔴 Почему не фоновая автоматика, а кнопка. Дрон, который сам решает и сам вывозит без
 * спроса, однажды увезёт то, что игрок держал под крафт, — и это будет выглядеть как
 * кража. Кнопка даёт ту же экономию тапов (вместо 8 отправок — одна) и оставляет решение
 * за игроком. Расход заряда тот же, что у обычного вылета: автоматизация не должна быть
 * ещё и дешевле ручной работы.
 *
 * Callback `cargoDroneAuto_<log_id>` (первый сегмент — ключ роута, хвост разбираем сами).
 */
final class CargoDroneAutoSendAction extends BaseAction
{
    /** Сколько строк груза перечисляем в отчёте (остальное — счётчиком). */
    private const REPORT_LINES = 6;

    private CraftedItemsLogModel $logModel;
    /** @var CharacterResourceModel */
    protected $resourceModel;
    private BaseStorageModel $storageModel;
    private DroneService $service;
    private CargoAutoLoadService $planner;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel      = new CraftedItemsLogModel();
        $this->resourceModel = new CharacterResourceModel();
        $this->storageModel  = new BaseStorageModel();
        $this->service       = new DroneService();
        $this->planner       = new CargoAutoLoadService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        if (! $this->service->cargoIsEnabled()) {
            return $this->errReply($chatId, '🚚 Карго-дрон временно отключён.');
        }

        $parts = explode('_', (string) $this->callbackQuery->getData());
        $logId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Неверный идентификатор дрона.');
        }

        $log = $this->logModel->find($logId);
        if (! is_array($log)) {
            return $this->errReply($chatId, 'Дрон не найден.');
        }

        $charId    = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $logCharId = is_numeric($log['character_id'] ?? null) ? (int) $log['character_id'] : 0;
        if ($charId <= 0 || $charId !== $logCharId) {
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

        $plan = $this->planner->plan($charId, $this->service->cargoPayloadKg());

        if ($plan['items'] === []) {
            $text = "🚚 *Автовывоз: брать нечего*\n\n";
            $text .= $plan['skipped_kinds'] > 0
                ? "В рюкзаке только еда, вода и семена — их автовывоз не трогает намеренно, "
                    . "чтобы не увезти твой запас на выживание. Отправь такое вручную, если нужно."
                : "Подходящих ресурсов нет — сначала добудь что-нибудь.";

            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'],
                    ['text' => '🗺 Карта',      'callback_data' => 'move'],
                ]]]),
            ]);
        }

        $fromCell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : null;

        $db = \Config\Database::connect();
        $db->transStart();

        $delivered  = [];
        $totalUnits = 0;
        $totalKg    = 0.0;
        foreach ($plan['items'] as $item) {
            $outcome = $this->resourceModel->decrementIfAtLeast($charId, $item['resource_id'], $item['qty']);
            if ($outcome !== WriteOutcome::Applied) {
                continue;
            }
            $this->storageModel->deliver($charId, $item['resource_id'], $item['qty'], $fromCell);
            $delivered[]  = $item;
            $totalUnits  += $item['qty'];
            $totalKg     += $item['qty'] * $item['weight'];
        }

        // exploit-fix-15 (M1) — заряд списывается только при увезённом грузе: раньше
        // durability_count уменьшался безусловно, вне ветки успеха и до проверки
        // $delivered, — при полностью провалившемся автовывозе (близнец CargoDroneSendAction
        // списывает только внутри WriteOutcome::Applied) заряд уходил впустую.
        $newCharge = $charge;
        if ($delivered !== []) {
            $newCharge = max(0, $charge - $drain);
            $this->logModel->update($logId, ['durability_count' => $newCharge]);
        }

        $db->transComplete();

        if ($delivered === []) {
            return $this->errReply($chatId, 'Рюкзак опустел раньше, чем дрон успел взлететь, — кто-то успел потратиться. Попробуй ещё раз.');
        }

        $totalKg = round($totalKg, 1);

        $this->logActivity(
            $charId,
            'DRONE_CARGO_AUTO_SEND',
            "kinds=" . count($delivered) . " units={$totalUnits} kg={$totalKg}"
        );

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Карго взлетел!',
        ]);

        $batteryMax = $this->service->cargoBatteryMax();
        $chargePct  = $batteryMax > 0 ? (int) round($newCharge * 100 / $batteryMax) : 0;

        $text  = "🚚 *Автовывоз выполнен*\n\n";
        $text .= "Дрон взял самое ценное — по убыванию редкости — и увёз на склад базы:\n";

        foreach (array_slice($delivered, 0, self::REPORT_LINES) as $item) {
            $emoji = ResourceIconHelper::for($item['name']);
            $kg    = round($item['qty'] * $item['weight'], 1);
            $text .= "  {$emoji} *{$item['name']}* × *{$item['qty']}* ({$kg} кг)\n";
        }

        if (count($delivered) > self::REPORT_LINES) {
            $text .= "  _…и ещё " . (count($delivered) - self::REPORT_LINES) . " видов_\n";
        }

        $text .= "\nВсего: *{$totalUnits}* шт., *{$totalKg}* кг\n";
        $text .= "🔋 Остаток заряда: `{$newCharge}/{$batteryMax}` ({$chargePct}%)\n";
        if (count($delivered) < count($plan['items'])) {
            $text .= "_Часть добычи утекла из рюкзака до вылета — увезено только то, что реально нашлось._\n";
        }

        if ($plan['skipped_kinds'] > 0) {
            $text .= "\n_Еда, вода и семена остались при тебе — автовывоз их не трогает._";
        }
        if ($newCharge < $drain) {
            $text .= "\n_Следующий вылет — после подзарядки на базе._";
        }

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [
                    ['text' => '📦 Склад базы', 'callback_data' => 'baseStorageList'],
                    ['text' => '🚚 Карго-дрон', 'callback_data' => 'cargoDroneList'],
                ],
                [
                    ['text' => '🗺 Карта', 'callback_data' => 'move'],
                    ['text' => '🏠 База',  'callback_data' => 'Base'],
                ],
            ]]),
        ]);
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
