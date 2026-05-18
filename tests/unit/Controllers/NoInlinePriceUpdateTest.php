<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * S9 (2026-05-18) — anti-drift guard для удалённого реактивного обновления
 * цен.
 *
 * До v0.51.191:
 *   - `ResourceModel::updateResourcePrices()` обновляла цены ресурсов по
 *     формуле `purchased/sold * 10` с коридором [0.1x, 10x] и random buy
 *     markup. Вызывалась inline в `Sell\SellAction:handle()` (открытие меню
 *     продажи). В двух других местах — в комментариях.
 *   - Параллельно работает `ResourceBankUpdateHandler::process()` (cron
 *     `everyMinute()` через `Config\Tasks.php`) с другой формулой:
 *     ratio `(p+1)/(s+1)` в коридоре [0.35, 3.5], 5% spread, **+ aging**
 *     (постепенный возврат к базовой цене).
 *   - Реактивный вызов был мёртвым кодом: cron в течение 60s перетирал
 *     результат другой формулой. Race-condition между двумя моделями цен.
 *
 * S9 v0.51.191 cleanup: удалены `ResourceModel::updateResourcePrices()`,
 * `BaseAction::updateResourcePrices()` wrapper и все 3 callsite (1 live + 2
 * commented). Этот тест проверяет, что метод не вернётся скрытно через
 * copy-paste в каком-нибудь будущем PR.
 *
 * @internal
 */
final class NoInlinePriceUpdateTest extends CIUnitTestCase
{
    /**
     * Скан `app/` (исключая `tests/`) на любое упоминание
     * `updateResourcePrices`. После S9 ожидается 0 совпадений.
     */
    public function testNoUpdateResourcePricesReferencesInAppCode(): void
    {
        $appDir = APPPATH; // CodeIgniter constant — путь к app/

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $hits = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'updateResourcePrices')) {
                $hits[] = str_replace($appDir, '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $hits,
            'Найдены упоминания удалённой функции updateResourcePrices в коде: ' .
            implode(', ', $hits) .
            '. См. S9 v0.51.191 — реактивное обновление цен заменено cron ' .
            '(ResourceBankUpdateHandler). Не возвращай метод.'
        );
    }
}
