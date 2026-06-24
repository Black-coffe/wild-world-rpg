<?php

declare(strict_types=1);

namespace Tests\Unit\Navigation;

use App\Services\Navigation\NavigationMapService;
use App\Services\Telegram\CallbackPrefixDispatcher;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Анти-дрифт гейт E28 «ревизия мёртвых кнопок → 0».
 *
 * {@see NavigationMapService::PREFIX_DISPATCHER} ОБЯЗАН зеркалить
 * {@see CallbackPrefixDispatcher::tryDispatch()} 1:1 — тот же набор префиксов
 * в том же порядке (резолв «первый str_starts_with выиграл»).
 *
 * Дрейф 2026-06-24: реальный диспетчер обрабатывал 18 префиксов (ремонт зданий
 * ADR-041, караван ADR-057/068, дроны ADR-058/060/064, страховка ADR-056,
 * NPC-ремонт ADR-055), а зеркало знало лишь 6 → /admin/navigation помечал 12
 * РАБОЧИХ префиксов «мёртвыми кнопками» (ложные срабатывания). Без этого гейта
 * каждый новый prefix-роут будет молча возвращать ложный мёртвый-счёт.
 */
final class PrefixDispatcherMirrorSyncTest extends CIUnitTestCase
{
    public function testNavigationMapMirrorsAllDispatcherPrefixesInOrder(): void
    {
        $dispatcherPrefixes = $this->extractDispatcherPrefixes();

        /** @var array<string, class-string> $mirror */
        $mirror        = (new ReflectionClass(NavigationMapService::class))->getConstant('PREFIX_DISPATCHER');
        $mirrorPrefixes = array_keys($mirror);

        $this->assertSame(
            $dispatcherPrefixes,
            $mirrorPrefixes,
            'NavigationMapService::PREFIX_DISPATCHER разошёлся с '
            . 'CallbackPrefixDispatcher::tryDispatch(). Синхронизируй зеркало '
            . '(тот же набор и порядок префиксов), иначе /admin/navigation покажет '
            . 'ЛОЖНЫЕ мёртвые кнопки для рабочих на проде callback-ов.'
        );
    }

    /**
     * Извлекает префиксы в порядке проверки внутри tryDispatch().
     * `str_starts_with($callbackData, '<prefix>')` встречается ТОЛЬКО в tryDispatch()
     * (приватные dispatch-методы парсят callback иначе — explode и т.п.).
     *
     * @return list<string>
     */
    private function extractDispatcherPrefixes(): array
    {
        $file = (new ReflectionClass(CallbackPrefixDispatcher::class))->getFileName();
        $this->assertIsString($file);

        $src = file_get_contents($file);
        $this->assertIsString($src);

        preg_match_all(
            "/str_starts_with\\(\\\$callbackData,\\s*'([^']+)'\\)/",
            $src,
            $matches
        );

        $prefixes = $matches[1];
        $this->assertNotEmpty($prefixes, 'Не нашли ни одного prefix-чека в CallbackPrefixDispatcher.');

        return $prefixes;
    }
}
