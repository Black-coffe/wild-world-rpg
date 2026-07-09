<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\NavMenuRefreshService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-150 — пере-аттач нижнего меню живым игрокам.
 *
 * Telegram не обновляет reply-клавиатуру сам; замер 2026-07-09 показал, что `/start` после
 * активации нажали 6 из 79 активных → новый каркас до остальных не доехал. Ловим устаревшую
 * клавиатуру по подписи старой кнопки и пере-аттачиваем ОДИН раз.
 *
 * Проверяем логику гейтов без Telegram/БД: подменяем сеймы, отправку не выполняем.
 *
 * @internal
 */
final class NavMenuRefreshServiceTest extends CIUnitTestCase
{
    /** Каркас не менялся (все nav-флаги OFF) → ничего не шлём и не помечаем. */
    public function testNoRefreshWhenLayoutUnchanged(): void
    {
        $svc = new class extends NavMenuRefreshService {
            public bool $marked = false;
            public bool $sent   = false;

            protected function layoutChanged(): bool
            {
                return false;
            }

            protected function alreadyRefreshed(int $charId): bool
            {
                return false;
            }

            protected function mark(int $charId): void
            {
                $this->marked = true;
            }
        };

        $svc->maybeRefresh(7, 123);

        $this->assertFalse($svc->marked, 'Пере-аттач при неизменном каркасе — лишнее сообщение игроку.');
    }

    /** Уже пере-аттачивали → второй раз не беспокоим (one-shot). */
    public function testNoRefreshWhenAlreadyDone(): void
    {
        $svc = new class extends NavMenuRefreshService {
            public bool $marked = false;

            protected function layoutChanged(): bool
            {
                return true;
            }

            protected function alreadyRefreshed(int $charId): bool
            {
                return true;
            }

            protected function mark(int $charId): void
            {
                $this->marked = true;
            }
        };

        $svc->maybeRefresh(7, 123);

        $this->assertFalse($svc->marked, 'One-shot нарушен: пере-аттач повторился.');
    }

    /** Некорректный персонаж/чат → тихо выходим (никаких исключений наружу). */
    public function testGuardsOnInvalidIds(): void
    {
        $svc = new class extends NavMenuRefreshService {
            public bool $marked = false;

            protected function layoutChanged(): bool
            {
                return true;
            }

            protected function alreadyRefreshed(int $charId): bool
            {
                return false;
            }

            protected function mark(int $charId): void
            {
                $this->marked = true;
            }
        };

        $svc->maybeRefresh(0, 123);
        $svc->maybeRefresh(7, 0);

        $this->assertFalse($svc->marked);
    }

    /** Маркер one-shot стабилен: по нему ищем в action_log, менять его нельзя случайно. */
    public function testMarkerConstantIsStable(): void
    {
        $this->assertSame('NavMenuRefreshed_adr150', NavMenuRefreshService::MARKER);
    }
}
