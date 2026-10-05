<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart;
use Config\CraftRecipes;
use PHPUnit\Framework\TestCase;

/**
 * craft-batch-price-confirm-01: строка «Списано» в сообщении о старте крафта.
 *
 * Исходный случай — жалоба 05.10.2026: 50 сапёрных лопат одним нажатием списали 300 000 золота,
 * 400 редких металлов, 150 пластика и 100 проводки, а сообщение о старте о цене молчало.
 */
final class CraftSpentLineTest extends TestCase
{
    public function testShovelBatchFromThePlayerReportReadsInFull(): void
    {
        $line = GenericCraftActionStart::spentLine([
            'gold'          => 300000,
            'resources'     => ['Редкие металлы' => 400, 'Промышленный пластик' => 150],
            'crafted_items' => ['Проводка' => 100],
        ]);

        $this->assertSame(
            '💸 *Списано:* 300 000 💰 · Редкие металлы ×400 · Промышленный пластик ×150 · Проводка ×100',
            $line
        );
    }

    public function testNoGoldAndNoItemsPrintOnlyWhatWasTaken(): void
    {
        $this->assertSame(
            '💸 *Списано:* Травы ×4 · Кора деревьев ×1 000',
            GenericCraftActionStart::spentLine(['gold' => 0, 'resources' => ['Травы' => 4, 'Кора деревьев' => 1000], 'crafted_items' => []])
        );
        $this->assertSame('', GenericCraftActionStart::spentLine(['gold' => 0, 'resources' => [], 'crafted_items' => []]));
        $this->assertSame('', GenericCraftActionStart::spentLine(['gold' => 0, 'resources' => ['Травы' => 0], 'crafted_items' => []]));
    }

    /** Соседняя форма: имя из БД с markdown-метасимволами не должно разбить разметку caption. */
    public function testNamesWithMarkdownMetacharactersKeepTheMarkupPaired(): void
    {
        $line = GenericCraftActionStart::spentLine([
            'gold'          => 5,
            'resources'     => ['Сырьё_с*звездой' => 2],
            'crafted_items' => ['[Проводка]' => 1, '_*`' => 3],
        ]);

        $this->assertSame('💸 *Списано:* 5 💰 · Сырьёсзвездой ×2 · Проводка ×1', $line);
        $this->assertSame(0, substr_count($line, '*') % 2, 'непарная * роняет весь caption');
        $this->assertSame(0, substr_count($line, '_') % 2, 'непарная _ роняет весь caption');
    }

    /**
     * Самый тяжёлый рецепт конфига на максимальной ступени (×100): строка остаётся короткой, чтобы
     * не толкать caption старта (≤1024) за предел.
     */
    public function testHeaviestRecipeAtMaxStepStaysShort(): void
    {
        $longest = 0;
        $cfg = new CraftRecipes();
        foreach ($cfg->keys() as $key) {
            $recipe = $cfg->get($key) ?? [];
            $res   = [];
            $items = [];
            foreach ((array) ($recipe['resources'] ?? []) as $name => $n) {
                $res[(string) $name] = (int) $n * 100;
            }
            foreach ((array) ($recipe['crafted_items'] ?? []) as $name => $n) {
                $items[(string) $name] = (int) $n * 100;
            }
            $line    = GenericCraftActionStart::spentLine(['gold' => (int) ($recipe['gold_required'] ?? 0) * 100, 'resources' => $res, 'crafted_items' => $items]);
            $longest = max($longest, mb_strlen($line));
        }

        $this->assertGreaterThan(0, $longest);
        $this->assertLessThan(400, $longest);
    }
}
