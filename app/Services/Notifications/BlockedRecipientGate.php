<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\Web\DeliveryContext;
use App\Services\Web\WebDelivery;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use Throwable;

/**
 * B16 (спека b16-blocked-recipients-1126): фоновая отправка игроку, заблокировавшему бота, не уходит в Telegram.
 *
 * {@see \App\Services\Telegram\Request} спрашивает {@see skip()} **после** `WebDelivery::route()` и **до**
 * транспорта: копия во «входящие» `/play` привязанному игроку и доставка виртуальным чатам уже сделаны
 * `route()`, пропускается только вызов Bot API. Игровое действие отправителя (списание, налог, урон) не
 * трогается — оно выполнено до отправки.
 *
 * Когда пропускаем — все три условия сразу:
 * - фон: `DeliveryContext::actor() === null` (cron, Worker, spark). Ответ на апдейт игрока шлётся всегда —
 *   `UpdatePipeline` снимает метку только после обработки апдейта, и без этого условия ответ на первый
 *   `/start` вернувшегося игрока был бы проглочен;
 * - получатель — настоящий личный чат ({@see BlockedRecipient::isMarkable()}): группа и виртуальный чат — нет;
 * - его `telegram_id` в наборе `telegram_users.blocked_at IS NOT NULL`.
 *
 * Набор читается одним запросом при первой фоновой отправке процесса и держится в памяти (крон — один процесс
 * на прогон). Метка, поставленная {@see BlockedRecipient::handle()} в этом же процессе, добавляется сразу
 * ({@see remember()}). Экземпляр живёт в `Factories` (`libraries`): тесты CI4 сбрасывают Factories перед каждым
 * тестом, поэтому набор одного теста не протекает в следующий.
 *
 * Не читается набор, пока открыта чужая транзакция (упавший запрос оставил бы ей `transStatus=false`), — тогда
 * отправка идёт как раньше. Сбой чтения (нет колонки, нет таблицы) — пустой набор, одна WARNING на процесс,
 * отправка как раньше: фильтр никогда не ломает доставку.
 *
 * Пропуск отвечает `ok=false` с {@see SKIPPED_DESCRIPTION}. Описание сознательно не содержит фраз блока
 * Telegram (`bot was blocked`, `forbidden`): ни {@see BlockedRecipient::isBlockDescription()}, ни
 * `BroadcastDeliveryClassifier::isBlocked()` не принимают пропуск за новый отказ. Отправители узнают его через
 * {@see isSkipped()} и не пишут в лог.
 */
final class BlockedRecipientGate
{
    public const SKIPPED_DESCRIPTION = 'Not sent: recipient has the bot blocked (telegram_users.blocked_at)';

    /** @var array<string, true>|null telegram_id => true; null — ещё не прочитан */
    private ?array $blocked = null;

    /**
     * Пропуск фоновой отправки заблокировавшему: готовый ответ «не отправлено» или null — слать как раньше.
     *
     * @param array<string,mixed> $data
     */
    public static function skip(string $action, array $data): ?ServerResponse
    {
        if (DeliveryContext::actor() !== null) {
            return null;
        }
        $chatId = WebDelivery::chatIdOf($data);
        if ($chatId === null || ! BlockedRecipient::isMarkable($chatId)) {
            return null;
        }
        if (! self::instance()->isBlocked((string) $chatId)) {
            return null;
        }

        return new ServerResponse(['ok' => false, 'error_code' => 0, 'description' => self::SKIPPED_DESCRIPTION], '');
    }

    /** Ответ — пропуск этим фильтром (а не отказ Telegram). */
    public static function isSkipped(ServerResponse $response): bool
    {
        return ! $response->isOk() && $response->getDescription() === self::SKIPPED_DESCRIPTION;
    }

    /**
     * Метка поставлена в этом процессе: следующие фоновые отправки ему пропускаются сразу.
     *
     * @param int|string $chatId
     */
    public static function remember($chatId): void
    {
        $gate = self::instance();
        if ($gate->blocked !== null) {
            $gate->blocked[(string) $chatId] = true;
        }
    }

    /**
     * Метка снята в этом процессе: следующие фоновые отправки ему снова идут.
     *
     * @param int|string $chatId
     */
    public static function forget($chatId): void
    {
        $gate = self::instance();
        if ($gate->blocked !== null) {
            unset($gate->blocked[(string) $chatId]);
        }
    }

    private static function instance(): self
    {
        $gate = Factories::get('libraries', self::class);
        if ($gate instanceof self) {
            return $gate;
        }
        $gate = new self();
        Factories::injectMock('libraries', self::class, $gate);

        return $gate;
    }

    private function isBlocked(string $telegramId): bool
    {
        if ($this->blocked === null && ! $this->load()) {
            return false;
        }

        return isset($this->blocked[$telegramId]);
    }

    /** Прочитать набор. False — сейчас читать нельзя (открыта транзакция), набор остаётся непрочитанным. */
    private function load(): bool
    {
        try {
            $db = Database::connect();
            if ($db->transDepth > 0) {
                return false;
            }
            $this->blocked = [];
            $result        = $db->query('SELECT telegram_id FROM telegram_users WHERE blocked_at IS NOT NULL');
            if (! $result instanceof ResultInterface) {
                log_message('warning', '[BlockedRecipientGate] blocked set not read, sending as before');

                return true;
            }
            foreach ($result->getResultArray() as $row) {
                $id = $row['telegram_id'] ?? null;
                if (is_int($id) || is_string($id)) {
                    $this->blocked[(string) $id] = true;
                }
            }
        } catch (Throwable $e) {
            $this->blocked = [];
            log_message('warning', '[BlockedRecipientGate] blocked set not read, sending as before: ' . $e->getMessage());
        }

        return true;
    }
}
