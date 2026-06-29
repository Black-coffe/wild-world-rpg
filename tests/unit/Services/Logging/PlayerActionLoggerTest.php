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
        $this->assertContains($row['status'], ['ok', 'error', 'rejected', 'unrouted']);
        $this->assertContains($row['source'], ['callback', 'command', 'text', 'forcereply', 'other']);
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
