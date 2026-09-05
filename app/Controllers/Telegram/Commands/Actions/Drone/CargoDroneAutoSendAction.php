<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Drone;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\BaseStorageModel;
use App\Models\CharacterResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Db\ConditionalWriteService;
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
                    ['text' => \App\Services\Telegram\BotMenuService::menuLabel('world'),      'callback_data' => 'move'],
                ]]]),
            ]);
        }

        $fromCell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : null;

        $db = \Config\Database::connect();
        // exploit-fix-41 (R5-minor, m6) — резервируется ДО transStart(), чтобы решить ниже,
        // была ли эта транзакция верхнего уровня для соединения: resetTransStatus() после
        // тихого отказа безопасен только когда мы сами открыли транзакцию (глубина была 0),
        // иначе мы затёрли бы состояние отказа, принадлежащее внешней транзакции.
        $transDepthBeforeStart = $db->transDepth;
        $db->transStart();

        try {
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
            // durability_count уменьшался безусловно, вне ветки успеха и до проверки $delivered.
            // exploit-fix-26 (R2-major) — и это по-прежнему был безусловный UPDATE абсолютным
            // значением, посчитанным из $charge, прочитанного до транзакции: два параллельных
            // вылета читают один заряд и пишут одно и то же уменьшенное — второй вылет
            // оказывается бесплатным. Теперь списание — decrementIfAtLeast() на
            // crafted_items_log.durability_count: исход решает WHERE durability_count >= $drain
            // и affectedRows(), а не прочитанное ранее число.
            $newCharge     = $charge;
            $chargeOutcome = WriteOutcome::Applied;
            if ($delivered !== []) {
                $chargeOutcome = (new ConditionalWriteService($db))->decrementIfAtLeast(
                    'crafted_items_log',
                    $logId,
                    'durability_count',
                    $drain
                );
                $newCharge = max(0, $charge - $drain);
            }

            $chargeRefused = $chargeOutcome !== WriteOutcome::Applied;
            if ($chargeRefused) {
                // Груз уже списан с рюкзака и доставлен на склад выше в этой же транзакции — оба
                // writes реально применились (affectedRows > 0) и сами по себе не откатят
                // transStatus. Явный rollback нужен, иначе транзакция «успешно» закоммитит доставку
                // без списания заряда — тот самый бесплатный вылет.
                $db->transRollback();
                // R6-major 1 — комментарий ниже (у resetTransStatus() под transComplete()) раньше
                // утверждал, что здесь сбрасывать нечего, потому что rollback сделан вручную «без
                // отказа самого transComplete()». Неверно: флаг ставит
                // BaseConnection::handleTransStatus() при ЛЮБОМ упавшем запросе внутри
                // транзакции — а decrementIfAtLeast() на durability_count не может отличить
                // честный отказ по заряду (affectedRows()===0) от тихо упавшего UPDATE
                // (lock-wait-timeout, деадлок, affected_rows=-1) — оба дают Refused и ведут сюда.
                if ($transDepthBeforeStart === 0) {
                    $db->resetTransStatus();
                }
            }

            // exploit-fix-23 — исход читаем по возврату transComplete(): откат по любой
            // причине не должен вести в ветку «взлетел». Не зовём transComplete() после уже
            // сделанного вручную transRollback() — иначе CI4 пытается завершить транзакцию
            // с нулевой глубиной.
            $committed = !$chargeRefused && $db->transComplete();
            if (! $committed && ! $chargeRefused && $transDepthBeforeStart === 0) {
                // exploit-fix-41 (R5-minor, m6) — путь «запрос упал молча, исключения нет»:
                // при transStrict=true transComplete() уже сделал transRollback() и оставил
                // transStatus=false до конца PHP-запроса (следующий transStart() того же
                // соединения унаследовал бы чужой сбой). Story 36 закрыла тот же инвариант
                // для пути исключения — здесь тот же resetTransStatus() нужен на тихом пути.
                // Ветку $chargeRefused здесь не трогаем — тот сброс сделан выше, сразу после
                // её собственного transRollback() (R6-major 1).
                $db->resetTransStatus();
            }
        } catch (\Throwable $e) {
            // exploit-fix-36 (R4-major) — исключение между transStart() и завершением (любой
            // запрос внутри тела — decrementIfAtLeast/deliver/ConditionalWriteService) раньше
            // пробрасывалось мимо transRollback(): транзакция оставалась открытой на глубине 1.
            // Тот же класс потери, что exploit-fix-31 закрыл для веток отказа по WriteOutcome —
            // здесь тот же инвариант нужен на пути исключения. transStrict() включён
            // (Config\Database), поэтому после отката transStatus() держится false до явного
            // resetTransStatus() (feedback_transcomplete_false_success_when_strict_off).
            // R6-minor m1 — тот же гард «сбрасываем только верхнеуровневую транзакцию», что и
            // на путях отказа выше: сегодня у экшена нет вызывающего с открытой транзакцией
            // (BotController её не открывает), но правило обязано быть одинаковым на всех
            // путях выхода, а не только на новых.
            $db->transRollback();
            if ($transDepthBeforeStart === 0) {
                $db->resetTransStatus();
            }
            throw $e;
        }

        if ($delivered === []) {
            return $this->errReply($chatId, 'Рюкзак опустел раньше, чем дрон успел взлететь, — кто-то успел потратиться. Попробуй ещё раз.');
        }
        // exploit-fix-31 (R3 m1) — раньше все три причины отказа (пустой рюкзак, отказ
        // списания заряда, откат transComplete()) отвечали одним текстом про рюкзак; на
        // путях $chargeRefused/!$committed рюкзак уже списан и доставлен успешно — причина
        // отказа не в нём. Текст теперь называет заряд, как у ручного дрона
        // (CargoDroneSendAction.php).
        if ($chargeRefused) {
            return $this->errReply($chatId, 'Заряд дрона списал кто-то другой быстрее — груз не отправлен, попробуй ещё раз.');
        }
        if (!$committed) {
            return $this->errReply($chatId, 'Отправка не сохранилась — транзакция откатилась, попробуй ещё раз.');
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
                    ['text' => \App\Services\Telegram\BotMenuService::menuLabel('world'), 'callback_data' => 'move'],
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
