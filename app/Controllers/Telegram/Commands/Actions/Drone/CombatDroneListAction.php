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
 * W5 (ADR-064) — entry-point Combat-drone списка. Callback `combatDroneList`.
 *
 * Состояния (lazy-expiry):
 *   - killswitch off → сообщение «отключено»
 *   - нет скрафченных DroneCombat → «скрафти в Стандартном верстаке (RoboticsWorkshop L4)»
 *   - active_until > NOW → «🛡 Активен X мин» — кнопка активации скрыта
 *   - active_until ≤ NOW и charge ≥ drain → charge-bar + «🛡 Активировать»
 *   - charge < drain → «🪫 Подзарядка ~6 часов на базе»
 *
 * Caption media-off-safe (текст самодостаточен).
 */
final class CombatDroneListAction extends BaseAction
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

        if (! $this->service->combatIsEnabled()) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🛡 *Боевые дроны временно недоступны*\n\n_Фича отключена администрацией._",
                'parse_mode' => 'Markdown',
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId <= 0) {
            return $this->errReply($chatId, 'Невозможно определить персонажа.');
        }

        $droneItem = $this->itemModel->where('name_eng', 'DroneCombat')->first();
        if (! is_array($droneItem)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🛡 *Боевые дроны не настроены*\n\n_Внутренняя ошибка: предмет отсутствует в справочнике._",
                'parse_mode' => 'Markdown',
            ]);
        }
        $rawId       = $droneItem['id'] ?? null;
        $droneItemId = is_numeric($rawId) ? (int) $rawId : 0;
        if ($droneItemId <= 0) {
            return $this->errReply($chatId, 'Внутренняя ошибка: id боевого дрона невалидный.');
        }

        $logRows = $this->logModel
            ->where('character_id', $characterId)
            ->where('crafted_item_id', $droneItemId)
            ->where('quantity >', 0)
            ->orderBy('id', 'ASC')
            ->findAll();

        if (empty($logRows)) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => "🛡 *У тебя нет боевого дрона*\n\nСкрафти его в Стандартном верстаке. Требуется Мастерская робототехники *4 уровня*. Заряжается на базе ~6 часов.",
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🛠 К крафту', 'callback_data' => 'standardCraft'],
                    ['text' => '◀️ Перс',    'callback_data' => 'character'],
                ]]]),
            ]);
        }

        $drain          = $this->service->combatBatteryDrainPerLaunch();
        $batteryMax     = $this->service->combatBatteryMax();
        $bonusPercent   = $this->service->combatInitiativeBonusPercent();
        $activationMin  = $this->service->combatActivationMinutes();
        $activeUntilRaw = $character['combat_drone_active_until'] ?? null;
        $activeUntilTs  = is_string($activeUntilRaw) && $activeUntilRaw !== '' ? strtotime($activeUntilRaw) : 0;
        $nowTs          = time();
        $isActive       = $activeUntilTs !== false && $activeUntilTs > $nowTs;

        $text  = "🛡 *Боевой дрон*\n\n";
        $text .= "_Защитный дрон: при активации даёт защитнику +{$bonusPercent}% инициативы в PvP-бою на {$activationMin} мин. Layer'ится поверх Дозорной вышки (cap 25%). Активируется по запросу, тратит весь заряд за раз. Заряжается только на базе._\n\n";

        if ($isActive) {
            $minsLeft = max(1, (int) ceil(($activeUntilTs - $nowTs) / 60));
            $text .= "🛡 *Сейчас активен:* осталось `{$minsLeft}` мин.\n";
            $text .= "_В любой входящей атаке защитник получает +{$bonusPercent}% к инициативе. По истечении окна нужна повторная активация (заряженный дрон)._\n";

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

        // Не активен: список инстансов + кнопки активации.
        $rows     = [];
        $instance = 1;
        foreach ($logRows as $log) {
            $logId     = $this->extractInt($log, 'id');
            $qty       = $this->extractInt($log, 'quantity');
            $charge    = $this->extractInt($log, 'durability_count');
            $chargePct = $batteryMax > 0 ? (int) round($charge * 100 / $batteryMax) : 0;
            $bar       = $this->renderChargeBar($chargePct);
            $emoji     = $this->service->canActivateCombat($charge) ? '✅' : '🪫';

            $text .= "{$emoji} *Дрон #{$instance}* (×{$qty})\n";
            $text .= "Заряд: {$bar} `{$charge}/{$batteryMax}` ({$chargePct}%)\n\n";

            if ($this->service->canActivateCombat($charge) && $logId > 0) {
                $rows[] = [
                    ['text' => "🛡 Активировать #{$instance}", 'callback_data' => "combatDroneActivate_{$logId}"],
                ];
            }
            $instance++;
        }

        if (empty($rows)) {
            $text .= "_Ни один дрон не готов к активации. Заряжается только на базе (~6 часов до полного)._";
        }

        $rows[] = [
            ['text' => '◀️ Перс', 'callback_data' => 'character'],
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
        $pct    = max(0, min(100, $pct));
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
