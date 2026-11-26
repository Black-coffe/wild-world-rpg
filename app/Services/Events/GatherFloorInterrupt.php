<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\ResourceModel;
use App\Services\Craft\CraftQueueActivationNotice;
use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\Notifications\BlockedRecipientGate;
use App\Services\Player\Gather\GatherResultPersister;
use App\Services\Telegram\NavKeyboards;
use App\Services\Telegram\Request;
use App\Services\Telegram\TelegramBridge;
use App\TaskHandlers\GatherTaskHandler;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Throwable;

/**
 * ADR-200 (спека sudden-death-1125, S2) — цена пассивного пола.
 *
 * Мировое событие довело здоровье добытчика до пола `health.passive_floor` (или ударило того, кто уже ниже) →
 * добыча в биоме события прерывается: строка Gather `in_work → interrupted`
 * ({@see ConditionalWriteService::transitionIfCurrent()}), игрок получает долю добычи по прошедшему времени —
 * один обычный бросок `GatherTaskHandler::rollLoot()` × прошло/всего, вниз, без гарантированного минимума — и одно
 * сообщение «иди на базу», где названо добытое. Голод сам добычу не прерывает: он доводит до пола, а ближайший
 * удар события в биоме добычи видит здоровье на полу и прерывает (условие {@see shouldInterrupt()}).
 *
 * Платит только вызов, выигравший переход: Worker захватывает задачу тем же условным UPDATE `in_work → completed`,
 * поэтому прерывание и обычное завершение в одну минуту платят один раз; повторное прерывание — no-op.
 * Ручная отмена (`CancelGatherAction`) не меняется: прерванная игроком добыча по-прежнему не даёт ничего.
 *
 * Сообщение — только текст (полно при выключенных картинках), через `Request` приложения: доходит и в Telegram,
 * и во входящие `/play` (виртуальный чат, ADR-189).
 *
 * @phpstan-import-type Promoted from \App\Services\Craft\CraftQueueService
 */
class GatherFloorInterrupt
{
    /** @var BaseConnection<object, object>|null */
    private ?BaseConnection $db;

    /**
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db;
    }

    /**
     * Прервать добычу персонажа на полу. `true` — этот вызов выиграл переход и заплатил; `false` — добычи нет,
     * её уже завершил Worker или прервал другой вызов.
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     */
    public function interrupt(array|\App\Entities\CharacterEntity $character, string $eventName, float $healthAfter): bool
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        if ($charId < 1) {
            return false;
        }
        $task = $this->inWorkGather($charId);
        if ($task === null) {
            return false;
        }
        $taskId = is_numeric($task['id'] ?? null) ? (int) $task['id'] : 0;
        $start  = is_string($task['start_time'] ?? null) ? $task['start_time'] : '';
        $end    = is_string($task['end_time'] ?? null) ? $task['end_time'] : '';

        $db = $this->db();
        $db->transBegin();
        try {
            $outcome = (new ConditionalWriteService($db))->transitionIfCurrent('character_tasks', $taskId, 'status', 'in_work', 'interrupted');
            if ($outcome !== WriteOutcome::Applied) {
                $db->transRollback();

                return false;
            }

            $now            = $this->now();
            $share          = self::shareOf($start, $end, $now);
            $elapsedMinutes = self::elapsedMinutes($start, $end, $now);
            $partial        = $share > 0.0 ? self::scaleLoot($this->rollFullGather($task, $character, intdiv($elapsedMinutes, 10)), $share) : [];
            $cargoNote      = $partial !== [] ? $this->persistLoot($partial, $character, $task) : null;

            if ($db->transStatus() === false) {
                $db->transRollback();
                $db->resetTransStatus();
                log_message('error', "[GatherFloorInterrupt] task #{$taskId}: write failed, rolled back");

                return false;
            }
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', "[GatherFloorInterrupt] task #{$taskId}: " . $e->getMessage());

            return false;
        }

        // 🔒-слот освободился — очередь крафта стартует, как после ручной отмены; её сообщение — после нашего.
        $promoted = $this->promoteCraftQueue($charId);

        $chatId = $this->chatIdFor($character);
        if ($chatId !== null) {
            $lines = [];
            $names = $this->resourceNames(array_map(static fn (array $l): int => $l['resource_id'], $partial));
            foreach ($partial as $line) {
                $lines[] = ['name' => $names[$line['resource_id']] ?? "ресурс #{$line['resource_id']}", 'amount' => $line['amount']];
            }
            $text = self::messageText($eventName, $healthAfter, $elapsedMinutes, self::totalMinutes($start, $end), $lines, $cargoNote);
            $this->send($chatId, $text, NavKeyboards::whatNextWith());
        }
        $this->sendCraftQueue($promoted);

        return true;
    }

    /**
     * Решение пути урона: персонаж добывает, событие запросило урон здоровью, и здоровье после удара на полу или
     * ниже (ниже — после похода; удар его не опустил, но копать здесь всё равно нельзя).
     */
    public static function shouldInterrupt(bool $isGathering, mixed $requestedHealthDelta, mixed $healthAfter, float $floor): bool
    {
        if (! $isGathering || ! is_numeric($requestedHealthDelta) || (float) $requestedHealthDelta >= 0.0 || ! is_numeric($healthAfter)) {
            return false;
        }

        return (float) $healthAfter <= $floor + 0.001;
    }

    /** Доля прошедшего времени задачи, 0…1. */
    public static function shareOf(string $startTime, string $endTime, int $now): float
    {
        $start = strtotime($startTime);
        $end   = strtotime($endTime);
        if ($start === false || $end === false) {
            return 0.0;
        }
        if ($now >= $end) {
            return 1.0;
        }
        if ($now <= $start || $end <= $start) {
            return 0.0;
        }

        return ($now - $start) / ($end - $start);
    }

    /**
     * Доля броска: `floor(amount × share)` на строку, нулевые строки выпадают. Никогда не больше броска.
     *
     * @param list<array<string, mixed>> $found
     * @return list<array{resource_id: int, amount: int, type: string}>
     */
    public static function scaleLoot(array $found, float $share): array
    {
        $share = max(0.0, min(1.0, $share));
        $out   = [];
        foreach ($found as $line) {
            $rid    = is_numeric($line['resource_id'] ?? null) ? (int) $line['resource_id'] : 0;
            $amount = is_numeric($line['amount'] ?? null) ? (int) $line['amount'] : 0;
            // +1e-9 гасит float-хвост (0.3 × 10 = 2.9999…), не поднимая долю выше честной.
            $part = (int) floor($amount * $share + 1e-9);
            if ($rid < 1 || $part < 1) {
                continue;
            }
            $out[] = ['resource_id' => $rid, 'amount' => min($amount, $part), 'type' => is_string($line['type'] ?? null) ? $line['type'] : ''];
        }

        return $out;
    }

    /**
     * Текст сообщения (HTML). Полон без картинки: событие, здоровье, что добыто за сколько, что делать.
     *
     * @param list<array{name: string, amount: int}> $lines
     */
    public static function messageText(string $eventName, float $health, int $elapsedMinutes, int $totalMinutes, array $lines, ?string $cargoNote): string
    {
        $e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = '⚠️ <b>Добыча прервана: ' . $e($eventName) . "</b>\n\n"
            . 'Событие «' . $e($eventName) . '» ранит всех в этом биоме. Здоровье на пределе: <b>'
            . $e(self::fmt($health)) . "</b>.\n\n";

        if ($lines === []) {
            $text .= "🎒 Успел собрать: ничего — прошло {$elapsedMinutes} из {$totalMinutes} мин.\n\n";
        } else {
            $parts = array_map(static fn (array $l): string => $e($l['name']) . ' × ' . $l['amount'], $lines);
            $text .= "🎒 Успел собрать за {$elapsedMinutes} из {$totalMinutes} мин: " . implode(', ', $parts) . ".\n\n";
        }
        if ($cargoNote !== null && $cargoNote !== '') {
            $text .= $e($cargoNote) . "\n\n";
        }

        return $text . '🏠 Иди на базу — там событие не достанет — и подлечись едой или медициной. '
            . 'Пока здоровье низкое, новая добыча в биоме этого события не начнётся.';
    }

    /**
     * Обычный бросок добычи за всё время задачи (износ инструментов — только за прошедшие блоки). Шов для тестов.
     *
     * @param array<string, mixed>                               $task
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     * @return list<array<string, mixed>>
     */
    protected function rollFullGather(array $task, array|\App\Entities\CharacterEntity $character, int $wearBlocks): array
    {
        return (new GatherTaskHandler())->rollLoot($task, $character, $wearBlocks)['found'];
    }

    /**
     * Единственная точка записи добычи. Груз-нота возвращается в текст, а не уходит отдельным сообщением.
     *
     * @param list<array{resource_id: int, amount: int, type: string}> $found
     * @param array<string, mixed>|\App\Entities\CharacterEntity       $character
     * @param array<string, mixed>                                     $task
     */
    protected function persistLoot(array $found, array|\App\Entities\CharacterEntity $character, array $task): ?string
    {
        return (new GatherResultPersister())->persist($found, $character, $task, true);
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    protected function resourceNames(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ((new ResourceModel())->whereIn('id', $ids)->findAll() as $row) {
            $id   = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (is_numeric($id) && is_scalar($name)) {
                $out[(int) $id] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * Отправка через `Request` приложения: Telegram или входящие `/play`. Никогда не бросает.
     *
     * @param array<string, mixed> $keyboard
     */
    protected function send(int $chatId, string $text, array $keyboard): void
    {
        if (! TelegramBridge::ensure()) {
            return;
        }
        try {
            $response = Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($keyboard),
            ]);
            if (! $response->isOk() && ! BlockedRecipientGate::isSkipped($response)) {
                log_message('error', '[GatherFloorInterrupt] message not sent: ' . $response->getDescription());
            }
        } catch (Throwable $e) {
            log_message('error', '[GatherFloorInterrupt] send failed: ' . $e->getMessage());
        }
    }

    /**
     * @return list<Promoted>
     */
    protected function promoteCraftQueue(int $characterId): array
    {
        return CraftQueueActivationNotice::promote($characterId);
    }

    /** @param list<Promoted> $promoted */
    protected function sendCraftQueue(array $promoted): void
    {
        if ($promoted === [] || ! TelegramBridge::ensure()) {
            return;
        }
        CraftQueueActivationNotice::send($promoted);
    }

    protected function now(): int
    {
        return time();
    }

    /**
     * chat_id персонажа = `telegram_users.telegram_id` (виртуальный для веб-игрока — тоже годится).
     *
     * @param array<string, mixed>|\App\Entities\CharacterEntity $character
     */
    protected function chatIdFor(array|\App\Entities\CharacterEntity $character): ?int
    {
        $userId = is_numeric($character['telegram_user_id'] ?? null) ? (int) $character['telegram_user_id'] : 0;
        if ($userId < 1) {
            return null;
        }
        $result = $this->db()->table('telegram_users')->select('telegram_id')->where('id', $userId)->get();
        $row    = $result instanceof BaseResult ? $result->getRowArray() : null;
        $tg     = is_array($row) ? ($row['telegram_id'] ?? null) : null;

        return is_numeric($tg) && (int) $tg !== 0 ? (int) $tg : null;
    }

    /**
     * Строка Gather в работе.
     *
     * @return array<string, mixed>|null
     */
    private function inWorkGather(int $charId): ?array
    {
        $db     = $this->db();
        $tasks  = $db->prefixTable('tasks');
        $ct     = $db->prefixTable('character_tasks');
        $result = $db->query(
            "SELECT ct.* FROM {$ct} ct JOIN {$tasks} t ON t.id = ct.task_id
              WHERE ct.character_id = ? AND ct.status = 'in_work' AND t.name = 'Gather'
              ORDER BY ct.id LIMIT 1",
            [$charId]
        );
        $row = $result instanceof BaseResult ? $result->getRowArray() : null;

        return is_array($row) ? $row : null;
    }

    private static function elapsedMinutes(string $startTime, string $endTime, int $now): int
    {
        $start = strtotime($startTime);
        $end   = strtotime($endTime);
        if ($start === false || $end === false) {
            return 0;
        }

        return intdiv(max(0, min($now, $end) - $start), 60);
    }

    private static function totalMinutes(string $startTime, string $endTime): int
    {
        $start = strtotime($startTime);
        $end   = strtotime($endTime);

        return $start === false || $end === false ? 0 : intdiv(max(0, $end - $start), 60);
    }

    private static function fmt(float $value): string
    {
        $s = number_format($value, 2, '.', '');

        return rtrim(rtrim($s, '0'), '.');
    }

    /** @return BaseConnection<object, object> */
    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }
}
