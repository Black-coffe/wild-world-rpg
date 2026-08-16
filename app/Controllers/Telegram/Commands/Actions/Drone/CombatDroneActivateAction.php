<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\CraftedItemsLogModel;
use App\Services\Player\DroneService;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W5 (ADR-064) — атомарная активация Combat-drone. Callback prefix
 * `combatDroneActivate_<log_id>` (через CallbackPrefixDispatcher).
 *
 * Шаги (DB transaction):
 *   1. Re-validate: killswitch on / log ownership / qty>0 / charge>=drain /
 *      нет уже активного active_until > NOW (защита от double-activation).
 *   2. UPDATE characters SET combat_drone_active_until = NOW + activation_minutes.
 *   3. UPDATE crafted_items_log SET durability_count = durability_count - drain.
 *
 * All-or-nothing. RNG-fence safe: ноль mt_rand. Out-of-band — не пересекается
 * с simulateFight.
 */
final class CombatDroneActivateAction extends BaseAction
{
    private CraftedItemsLogModel $logModel;
    private DroneService $service;

    public function __construct(\Longman\TelegramBot\Entities\CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->logModel = new CraftedItemsLogModel();
        $this->service  = new DroneService();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }
        if (! $this->service->combatIsEnabled()) {
            return $this->errReply($chatId, '🛡 Боевые дроны временно отключены.');
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId <= 0) {
            return $this->errReply($chatId, 'Невозможно определить персонажа.');
        }

        // Парсим log_id из callback_data: combatDroneActivate_<log_id>
        $data  = (string) $this->callbackQuery->getData();
        $parts = explode('_', $data);
        $logId = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;
        if ($logId <= 0) {
            return $this->errReply($chatId, 'Некорректный идентификатор дрона.');
        }

        $log = $this->logModel->find($logId);
        if (! is_array($log)) {
            return $this->errReply($chatId, 'Запись о дроне не найдена.');
        }

        $logChar = is_numeric($log['character_id'] ?? null) ? (int) $log['character_id'] : 0;
        if ($logChar !== $characterId) {
            return $this->errReply($chatId, 'Этот дрон принадлежит другому персонажу.');
        }

        $qty = is_numeric($log['quantity'] ?? null) ? (int) $log['quantity'] : 0;
        if ($qty <= 0) {
            return $this->errReply($chatId, 'У этого дрона нет рабочих единиц.');
        }

        $charge = is_numeric($log['durability_count'] ?? null) ? (int) $log['durability_count'] : 0;
        $drain  = $this->service->combatBatteryDrainPerLaunch();
        if ($charge < $drain) {
            return $this->errReply($chatId, "🪫 Заряд боевого дрона ниже {$drain}. Заряжается на базе (~6 часов до полного).");
        }

        // Защита от double-activation: если уже активен — отвергаем без drain.
        $activeUntilRaw = $character['combat_drone_active_until'] ?? null;
        $activeUntilTs  = is_string($activeUntilRaw) && $activeUntilRaw !== '' ? strtotime($activeUntilRaw) : 0;
        if ($activeUntilTs !== false && $activeUntilTs > time()) {
            $minsLeft = max(1, (int) ceil(($activeUntilTs - time()) / 60));
            return $this->errReply($chatId, "🛡 Боевой дрон уже активен (осталось {$minsLeft} мин). Дожди окончания.");
        }

        $activationMinutes = $this->service->combatActivationMinutes();
        $bonusPercent      = $this->service->combatInitiativeBonusPercent();
        $newCharge         = max(0, $charge - $drain);
        $activeUntil       = date('Y-m-d H:i:s', time() + $activationMinutes * 60);

        $db = Database::connect();
        $db->transStart();

        $charModel = $this->characterModel instanceof \App\Models\CharacterModel
            ? $this->characterModel
            : new \App\Models\CharacterModel();
        $charModel->update($characterId, ['combat_drone_active_until' => $activeUntil]);
        $this->logModel->update($logId, ['durability_count' => $newCharge]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            log_message('error', "[CombatDroneActivate] tx fail char={$characterId} log={$logId}");
            return $this->errReply($chatId, 'Ошибка активации. Попробуй ещё раз.');
        }

        // E20 (ADR-120) — инструментация адопшена дронов (раньше use-path был немеряем).
        $this->logActivity($characterId, 'DRONE_COMBAT_ACTIVATE', "minutes={$activationMinutes} bonus={$bonusPercent}%");

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Боевой дрон активирован!',
        ]);

        $batteryMax = $this->service->combatBatteryMax();
        $chargePct  = $batteryMax > 0 ? (int) round($newCharge * 100 / $batteryMax) : 0;

        $text  = "🛡 *Боевой дрон активирован*\n\n";
        $text .= "Защитное окно: *{$activationMinutes} мин*\n";
        $text .= "Бонус инициативы защитника: *+{$bonusPercent}%* (layer'ится с Дозорной вышкой, cap 25%).\n\n";
        $text .= "🔋 Остаток заряда: `{$newCharge}/{$batteryMax}` ({$chargePct}%)\n";
        if ($newCharge < $drain) {
            $text .= "_Следующая активация — после подзарядки на базе (~6 часов)._";
        } else {
            $text .= "_Заряд позволит ещё одну активацию после окончания текущего окна._";
        }

        $keyboard = ['inline_keyboard' => [[
            ['text' => '◀️ Перс', 'callback_data' => 'character'],
            ['text' => '🏠 База', 'callback_data' => 'Base'],
        ]]];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function errReply(int $chatId, string $msg): ServerResponse
    {
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => $msg,
            'show_alert'        => true,
        ]);
        return Request::emptyResponse();
    }
}
