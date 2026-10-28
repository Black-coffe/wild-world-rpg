<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * W4 (ADR-063) — atomic batch-ремонт. Callback `repairDroneRun`.
 *
 * Шаги (DB transaction):
 *   1. Re-validate killswitch / on-base / drone instance / charge / robots non-empty / gold.
 *   2. characters.gold — decrementIfAtLeast(cumulative_gold) ДО ремонта (s2-conditional-writes-03);
 *      отказ → откат, ни один робот не тронут.
 *   3. foreach robot in eligible: UPDATE crafted_items_log SET durability_count = base_durability.
 *   4. UPDATE drone log row SET durability_count -= drain.
 *
 * All-or-nothing: при провале tx ничего не списывается.
 * RNG-fence safe: ноль mt_rand. Чистая state-mutation.
 */
class RepairDroneRunAction extends RepairDroneBaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->errReply($chatId, 'Пользователь не найден.');
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($characterId <= 0) {
            return $this->errReply($chatId, 'Невозможно определить персонажа.');
        }

        $ctx = $this->buildContext($characterId);
        if ($ctx['ok'] !== true) {
            return $this->errReply($chatId, $ctx['message']);
        }

        $charge     = $ctx['droneCharge'];
        $drain      = $ctx['droneDrain'];
        $robots     = $ctx['robots'];
        $cumulative = $ctx['cumulativeGold'];
        $droneLog   = $ctx['droneLog'];
        $droneLogId = is_numeric($droneLog['id'] ?? null) ? (int) $droneLog['id'] : 0;

        if ($droneLogId <= 0) {
            return $this->errReply($chatId, 'Запись о дрон-ремонтнике повреждена.');
        }
        if ($charge < $drain) {
            return $this->errReply($chatId, "🪫 Заряд дрон-ремонтника ниже {$drain}. Подзарядка на базе.");
        }
        if (empty($robots)) {
            return $this->errReply($chatId, 'Все роботы в исправности — ремонтировать нечего.');
        }

        $haveGold = is_numeric($character['gold'] ?? null) ? (int) $character['gold'] : 0;
        if ($haveGold < $cumulative) {
            $need = $cumulative - $haveGold;
            return $this->errReply($chatId, "Не хватает {$need} 🪙 для batch-ремонта.");
        }

        $newCharge = max(0, $charge - $drain);
        $outcome   = $this->applyBatchRepair($characterId, $robots, $cumulative, $droneLogId, $newCharge);
        if ($outcome === 'refused') {
            // Тот же текст отказа, что у проверки выше; недостача — по свежему чтению (только для текста).
            $query    = \Config\Database::connect()->table('characters')->select('gold')->where('id', $characterId)->get();
            $fresh    = $query !== false ? $query->getRowArray() : null;
            $freshRaw = is_array($fresh) ? ($fresh['gold'] ?? null) : null;
            $need     = $cumulative - (is_numeric($freshRaw) ? (int) $freshRaw : 0);
            return $this->errReply($chatId, 'Не хватает ' . max(1, $need) . ' 🪙 для batch-ремонта.');
        }
        if ($outcome === 'failed') {
            log_message('error', "[RepairDroneRun] tx fail for character {$characterId}");
            return $this->errReply($chatId, 'Ошибка при batch-ремонте. Попробуй ещё раз.');
        }

        // E20 (ADR-120) — инструментация адопшена дронов (раньше use-path был немеряем).
        $this->logActivity($characterId, 'DRONE_REPAIR_RUN', 'robots=' . count($robots) . " gold={$cumulative}");

        $batteryMax = $ctx['droneBatteryMax'];
        $chargePct  = $batteryMax > 0 ? (int) round($newCharge * 100 / $batteryMax) : 0;
        $bar        = $this->renderChargeBar($chargePct);
        $count      = count($robots);

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId(),
            'text'              => 'Дрон-ремонтник в работе!',
        ]);

        $text  = "🔧 *Дрон-ремонтник вернулся!*\n\n";
        $text .= "Восстановлено роботов: *{$count}*\n";
        foreach ($robots as $r) {
            $text .= "  ✅ {$r['nameRus']} — `{$r['base']}/{$r['base']}`\n";
        }
        $text .= "\nСписано: `{$cumulative}` 🪙\n";
        $text .= "🔋 Остаток заряда: {$bar} `{$newCharge}/{$batteryMax}` ({$chargePct}%)\n";
        if ($newCharge < $drain) {
            $text .= "_Следующий batch — после подзарядки на базе (~4 часа)._";
        } else {
            $text .= "_Заряд позволяет ещё один batch если будут изношенные роботы._";
        }

        $keyboard = ['inline_keyboard' => [[
            ['text' => '🤖 Роботы', 'callback_data' => 'AllRobots'],
            ['text' => '🏠 База',  'callback_data' => 'Base'],
        ]]];

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * s2-conditional-writes-03 (F9, ADR-181) — запись batch-ремонта. Порядок перевёрнут: золото
     * списывается ОДНОЙ условной записью `decrementIfAtLeast` ДО прочности роботов и заряда дрона.
     * Раньше прочность писалась первой, а `decrement('gold')` — безусловно после: при золоте,
     * ушедшем между проверкой и оплатой, роботы чинились, а баланс уходил в минус. На отказе
     * ни один робот пакета не тронут. Транзакция завершается ровно один раз, исход — по возврату.
     *
     * @param list<array{logId:int,nameRus:string,current:int,base:int,goldCost:int}> $robots
     *
     * @return 'applied'|'refused'|'failed'
     */
    public function applyBatchRepair(int $characterId, array $robots, int $cumulative, int $droneLogId, int $newCharge): string
    {
        $db          = \Config\Database::connect();
        $depthBefore = $db->transDepth;
        $db->transStart();

        try {
            if ($cumulative > 0) {
                $paid = (new ConditionalWriteService($db))->decrementIfAtLeast('characters', $characterId, 'gold', $cumulative);
                if ($paid !== WriteOutcome::Applied) {
                    $db->transRollback();
                    if ($depthBefore === 0) {
                        $db->resetTransStatus();
                    }
                    if ($paid === WriteOutcome::Missing) {
                        log_message('error', "[RepairDroneRun] персонаж {$characterId} не найден при списании золота batch-ремонта");
                    }
                    return 'refused';
                }
            }

            foreach ($robots as $r) {
                $this->logModel->update($r['logId'], ['durability_count' => $r['base']]);
            }
            $this->logModel->update($droneLogId, ['durability_count' => $newCharge]);

            $committed = $db->transComplete();
            if (! $committed && $depthBefore === 0) {
                $db->resetTransStatus();
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            if ($depthBefore === 0) {
                $db->resetTransStatus();
            }
            throw $e;
        }

        return $committed ? 'applied' : 'failed';
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
