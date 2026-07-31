<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logging;

use App\Services\Logging\PlayerActionLogger;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-148 — firehose всех прямых действий игрока ({@see PlayerActionLogger}).
 *
 * Лочит: разбор сырого Telegram-update (callback / slash-команда / текст / forceReply /
 * не-текст / не-player апдейт), приоритет статусов (error > rejected > unrouted > ok),
 * defensive-commit (killswitch off / двойной commit / неактивный захват), коэрцию enum'ов
 * и обрезку длинных полей. БД/GameSettings — через test-double (DB-free CI). Реальный INSERT
 * + появление строки на тап — Tier-3 на testbot (Шаг 3).
 *
 * @internal
 */
final class PlayerActionLoggerTest extends CIUnitTestCase
{
    // ── begin(): разбор апдейта ───────────────────────────────────────────────

    public function testCallbackUpdateParsed(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('move_dir_north', 25, 555));
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('callback', $row['source']);
        $this->assertSame('move', $row['action_name'], 'action = первый сегмент callback_data');
        $this->assertSame('move_dir_north', $row['raw_input']);
        $this->assertSame(25, $row['telegram_user_id']);
        $this->assertSame(555, $row['chat_id']);
        $this->assertSame('ok', $row['status']);
        $this->assertSame(491, $row['character_id'], 'resolveCharacterId(25)=491');
    }

    public function testSlashCommandParsedStrippingBotAndArgs(): void
    {
        $log = $this->fake();
        $log->begin($this->msgUpdate('/start@WildWorldBot ref_25', 25, 25));
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('command', $row['source']);
        $this->assertSame('start', $row['action_name'], 'без / без @bot без аргументов');
        $this->assertSame('/start@WildWorldBot ref_25', $row['raw_input']);
    }

    public function testPlainTextParsedLowercased(): void
    {
        $log = $this->fake();
        $log->begin($this->msgUpdate('Крафт', 25, 25));
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('text', $row['source']);
        $this->assertSame('крафт', $row['action_name']);
        $this->assertSame('Крафт', $row['raw_input']);
    }

    public function testForceReplyDetectedByReplyTo(): void
    {
        $log    = $this->fake();
        $update = ['message' => [
            'text'             => '25000',
            'from'             => ['id' => 25],
            'chat'             => ['id' => 25],
            'reply_to_message' => ['text' => '[SELL:123]'],
        ]];
        $log->begin($update);
        $log->commit();

        $this->assertSame('forcereply', $log->lastRow()['source']);
    }

    public function testNonTextMessageMarkedPlaceholder(): void
    {
        $log = $this->fake();
        $log->begin($this->msgUpdate('', 25, 25)); // напр. фото/стикер без текста
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('text', $row['source']);
        $this->assertSame('(non-text)', $row['action_name']);
        $this->assertSame('(non-text)', $row['raw_input'], 'не-текст → placeholder в raw');
    }

    public function testNonPlayerUpdateNotLogged(): void
    {
        $log = $this->fake();
        $log->begin(['channel_post' => ['text' => 'hi']]);
        $this->assertFalse($log->isActive());
        $log->commit();
        $this->assertSame(0, $log->insertCount(), 'не-player апдейт → 0 строк');
    }

    // ── Статусы и аннотации ───────────────────────────────────────────────────

    public function testMarkUnroutedOnlyFromOk(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('deadButton', 25, 25));
        $log->markUnrouted();
        $this->assertSame('unrouted', $log->status());
    }

    public function testErrorOutranksRejectedAndUnrouted(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('buyCraft', 25, 25));
        $log->markRejected('not_enough_gold');
        $log->markError('TypeError boom');
        $log->markUnrouted(); // уже не ok → не перекрывает
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('error', $row['status']);
        $this->assertSame('TypeError boom', $row['error_text']);
    }

    public function testRejectedDoesNotDowngradeError(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('buyCraft', 25, 25));
        $log->markError('boom');
        $log->markRejected('later reason');
        $this->assertSame('error', $log->status(), 'rejected после error не понижает статус');
    }

    public function testRejectedRecordsReason(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('sell', 25, 25));
        $log->markRejected('not_enough_gold');
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('not_enough_gold', $row['error_text']);
    }

    // ── recordTaskCompletion (фоновые завершения) ─────────────────────────────

    public function testTaskCompletionOk(): void
    {
        $log = $this->fake();
        $log->recordTaskCompletion(491, 'Gather', 'ok');

        $row = $log->lastRow();
        $this->assertSame('task', $row['source']);
        $this->assertSame('Gather', $row['action_name']);
        $this->assertSame('ok', $row['status']);
        $this->assertSame(491, $row['character_id']);
        $this->assertNull($row['telegram_user_id'], 'task-строка не пишет raw telegram_user_id');
        $this->assertNull($row['raw_input']);
        $this->assertNull($row['error_text']);
    }

    public function testTaskCompletionErrorRecordsMessage(): void
    {
        $log = $this->fake();
        $log->recordTaskCompletion(491, 'craftBandage', 'error', 'TypeError boom');

        $row = $log->lastRow();
        $this->assertSame('task', $row['source']);
        $this->assertSame('error', $row['status']);
        $this->assertSame('TypeError boom', $row['error_text']);
    }

    public function testTaskCompletionInvalidStatusCoercedToOk(): void
    {
        $log = $this->fake();
        $log->recordTaskCompletion(491, 'Marching', 'weird');
        $this->assertSame('ok', $log->lastRow()['status']);
    }

    public function testTaskCompletionTruncatesError(): void
    {
        $log = $this->fake();
        $log->recordTaskCompletion(491, 'x', 'error', str_repeat('e', 700));
        $this->assertSame(500, self::len($log->lastRow()['error_text']));
    }

    public function testTaskCompletionKillswitchOffNoInsert(): void
    {
        $log     = $this->fake();
        $log->on = false;
        $log->recordTaskCompletion(491, 'Gather', 'ok');
        $this->assertSame(0, $log->insertCount());
    }

    public function testTaskCompletionSourceIsValidEnum(): void
    {
        $log = $this->fake();
        $log->recordTaskCompletion(491, 'Gather', 'ok');
        $this->assertContains($log->lastRow()['source'], ['callback', 'command', 'text', 'forcereply', 'other', 'task']);
    }

    // ── Defensive commit ──────────────────────────────────────────────────────

    public function testKillswitchOffNoInsert(): void
    {
        $log      = $this->fake();
        $log->on  = false;
        $log->begin($this->cbUpdate('move', 25, 25));
        $log->commit();
        $this->assertSame(0, $log->insertCount());
    }

    public function testDoubleCommitInsertsOnce(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('move', 25, 25));
        $log->commit();
        $log->commit();
        $this->assertSame(1, $log->insertCount());
    }

    public function testCommitWithoutBeginNoInsert(): void
    {
        $log = $this->fake();
        $log->commit();
        $this->assertSame(0, $log->insertCount());
    }

    // ── Коэрция / обрезка ─────────────────────────────────────────────────────

    public function testLongFieldsTruncated(): void
    {
        $log      = $this->fake();
        $longData = 'x' . str_repeat('y', 400); // 401 символ
        $log->begin($this->cbUpdate($longData, 25, 25));
        $log->markError(str_repeat('e', 700));
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame(255, self::len($row['raw_input']));
        $this->assertSame(255, self::len($row['action_name']));
        $this->assertSame(500, self::len($row['error_text']));
    }

    public function testInsertedStatusAndSourceAlwaysValidEnum(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('move', 25, 25));
        $log->commit();

        $row = $log->lastRow();
        $this->assertContains($row['status'], ['ok', 'error', 'rejected', 'unrouted', 'undelivered']);
        $this->assertContains($row['source'], ['callback', 'command', 'text', 'forcereply', 'other']);
    }

    // ── Сигнал доставки: «нажал, а ответа не ушло» ────────────────────────────

    public function testUndeliveredWhenEverySendFailed(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('sellResource_11_1_sell', 25, 25));
        $log->noteDelivery(false, 'editMessageMedia', 'message to edit not found');
        $log->noteDelivery(false, 'sendPhoto', 'there is no photo in the request');
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('undelivered', $row['status'], 'ни одна отправка не прошла');
        $this->assertIsString($row['error_text']);
        $this->assertStringContainsString('не доставлено', $row['error_text']);
        $this->assertStringContainsString(
            'there is no photo in the request',
            $row['error_text'],
            'держим ПОСЛЕДНЮЮ ошибку — она ближе всего к тому, чего игрок не увидел'
        );
    }

    public function testFailedEditWithSuccessfulFallbackStaysOk(): void
    {
        // Штатное поведение MediaSender::editOrSend: правка падает, фолбэк доставляет.
        $log = $this->fake();
        $log->begin($this->cbUpdate('inventory', 25, 25));
        $log->noteDelivery(false, 'editMessageMedia', 'there is no media in the message to edit');
        $log->noteDelivery(true, 'sendPhoto');
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('ok', $row['status'], 'провал одной отправки при успешном фолбэке — не тревога');
        $this->assertNull($row['error_text']);
    }

    public function testUpdateWithoutAnySendStaysOk(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('noop', 25, 25));
        $log->commit();

        $this->assertSame('ok', $log->lastRow()['status'], 'нет отправок — нет и провалов');
    }

    public function testErrorOutranksUndelivered(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('move', 25, 25));
        $log->noteDelivery(false, 'sendMessage', 'chat not found');
        $log->markError('TypeError: foo');
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('error', $row['status'], 'исключение объясняет причину точнее');
        $this->assertSame('TypeError: foo', $row['error_text']);
    }

    public function testUndeliveredKeepsEarlierRejectionReason(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('craft', 25, 25));
        $log->markRejected('CRAFT: не хватает ресурсов');
        $log->noteDelivery(false, 'sendMessage', 'bot was blocked by the user');
        $log->commit();

        $row = $log->lastRow();
        $this->assertSame('undelivered', $row['status']);
        $this->assertIsString($row['error_text']);
        $this->assertStringContainsString('не хватает ресурсов', $row['error_text'], 'прежняя причина не теряется');
        $this->assertStringContainsString('bot was blocked by the user', $row['error_text']);
    }

    public function testDeliveryCountersResetBetweenUpdates(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('first', 25, 25));
        $log->noteDelivery(false, 'sendMessage', 'boom');
        $log->commit();
        $this->assertSame('undelivered', $log->lastRow()['status']);

        $log->begin($this->cbUpdate('second', 25, 25));
        $log->noteDelivery(true, 'sendMessage');
        $log->commit();
        $this->assertSame('ok', $log->lastRow()['status'], 'счётчики не тянутся из прошлого апдейта');
    }

    public function testNoteDeliveryIgnoredWithoutActiveCapture(): void
    {
        $log = $this->fake();
        $log->noteDelivery(false, 'sendMessage', 'boom'); // begin() не вызывался
        $log->commit();

        $this->assertSame([], $log->lastRow(), 'не-player апдейт не порождает строку');
    }

    // ── Сигнал доставки для фоновых задач (Worker, source='task') ─────────────

    public function testTaskUndeliveredWhenNotificationNeverReached(): void
    {
        $log = $this->fake();
        $log->openDeliveryWindow();
        $log->noteDelivery(false, 'sendPhoto', 'Bad Request: chat not found');

        $undelivered = $log->deliveryFailureText();
        $this->assertNotNull($undelivered);
        $log->recordTaskCompletion(491, 'Добыча ресурсов', 'undelivered', $undelivered);

        $row = $log->lastRow();
        $this->assertSame('task', $row['source']);
        $this->assertSame('undelivered', $row['status']);
        $this->assertIsString($row['error_text']);
        $this->assertStringContainsString('chat not found', $row['error_text']);
    }

    public function testTaskDeliveryWindowDoesNotLeakBetweenTasks(): void
    {
        // Один прогон Worker'а завершает N задач в одном процессе: провал первой не должен
        // приписаться второй.
        $log = $this->fake();

        $log->openDeliveryWindow();
        $log->noteDelivery(false, 'sendPhoto', 'chat not found');
        $this->assertNotNull($log->deliveryFailureText(), 'первая задача — не доставлено');

        $log->openDeliveryWindow();
        $log->noteDelivery(true, 'sendPhoto');
        $this->assertNull($log->deliveryFailureText(), 'вторая задача чистая — окно сброшено');
    }

    public function testTaskWithoutNotificationIsNotFlagged(): void
    {
        // Полно хендлеров, которые просто меняют состояние и ничего не шлют.
        $log = $this->fake();
        $log->openDeliveryWindow();

        $this->assertNull($log->deliveryFailureText(), 'нет отправок — нет и провалов');
    }

    public function testFailedEditWithFallbackIsNotFlaggedForTasks(): void
    {
        $log = $this->fake();
        $log->openDeliveryWindow();
        $log->noteDelivery(false, 'editMessageMedia', 'message to edit not found');
        $log->noteDelivery(true, 'sendPhoto');

        $this->assertNull($log->deliveryFailureText(), 'фолбэк дошёл — тревоги нет');
    }

    public function testNoteDeliveryIgnoredWithoutOpenWindow(): void
    {
        $log = $this->fake(); // окно не открывали
        $log->noteDelivery(false, 'sendMessage', 'boom');

        $this->assertNull($log->deliveryFailureText(), 'вне окна отправки не считаются');
    }

    public function testUndeliveredErrorTextTruncatedTo500(): void
    {
        $log = $this->fake();
        $log->begin($this->cbUpdate('move', 25, 25));
        $log->markRejected(str_repeat('r', 480));
        $log->noteDelivery(false, 'sendMessage', str_repeat('d', 300));
        $log->commit();

        $this->assertSame(500, self::len($log->lastRow()['error_text']));
    }

    // ── Фабрики апдейтов ──────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function cbUpdate(string $data, int $fromId, int $chatId): array
    {
        return ['callback_query' => [
            'data'    => $data,
            'from'    => ['id' => $fromId],
            'message' => ['chat' => ['id' => $chatId]],
        ]];
    }

    /** @return array<string,mixed> */
    private function msgUpdate(string $text, int $fromId, int $chatId): array
    {
        return ['message' => [
            'text' => $text,
            'from' => ['id' => $fromId],
            'chat' => ['id' => $chatId],
        ]];
    }

    private function fake(): FakePlayerActionLogger
    {
        return new FakePlayerActionLogger();
    }

    /** Длина строки или -1, если значение не строка (без mixed-cast для phpstan). */
    private static function len(mixed $v): int
    {
        return is_string($v) ? mb_strlen($v) : -1;
    }
}

/**
 * Test-double: подменяет killswitch / INSERT / резолв персонажа (без БД/GameSettings).
 *
 * @internal
 */
final class FakePlayerActionLogger extends PlayerActionLogger
{
    public bool $on = true;

    /** @var list<array<string,mixed>> */
    public array $rows = [];

    protected function enabled(): bool
    {
        return $this->on;
    }

    /** @param array<string,mixed> $data */
    protected function insertRow(array $data): void
    {
        $this->rows[] = $data;
    }

    protected function resolveCharacterId(?int $telegramUserId): ?int
    {
        return $telegramUserId === 25 ? 491 : null;
    }

    public function insertCount(): int
    {
        return count($this->rows);
    }

    /** @return array<string,mixed> */
    public function lastRow(): array
    {
        return $this->rows[count($this->rows) - 1] ?? [];
    }
}
