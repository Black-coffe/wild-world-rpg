<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart;
use Config\CraftRecipes;
use PHPUnit\Framework\TestCase;

/**
 * craft-batch-price-confirm — колбэк, текст и кнопки экрана подтверждения крупной партии в боте.
 */
final class CraftBatchConfirmCallbackTest extends TestCase
{
    public function testCallbackForms(): void
    {
        $this->assertSame(['SapperShovel', 50, false], GenericCraftActionStart::parseCallback('genericCraft_SapperShovel_50'));
        $this->assertSame(['SapperShovel', 50, true], GenericCraftActionStart::parseCallback('genericCraft_SapperShovel_50_ok'));
        $this->assertSame(['Bandage', 1, false], GenericCraftActionStart::parseCallback('genericCraft_Bandage'));
        $this->assertSame(['Bandage', 5, false], GenericCraftActionStart::parseCallback('genericCraft_Bandage_5_no'));
    }

    /** Telegram отвергает callback_data длиннее 64 байт — самый длинный ключ на максимальной ступени. */
    public function testLongestConfirmCallbackFitsTelegramLimit(): void
    {
        $cfg     = new CraftRecipes();
        $longest = '';
        foreach ($cfg->keys() as $key) {
            if (strlen($key) > strlen($longest)) {
                $longest = $key;
            }
        }
        $row = GenericCraftActionStart::confirmKeyboard($longest, 100, ['info_callback' => 'x']);
        $this->assertLessThanOrEqual(64, strlen($row[0][0]['callback_data']));
        $this->assertSame(['SapperShovel', 100, true], GenericCraftActionStart::parseCallback(
            GenericCraftActionStart::confirmKeyboard('SapperShovel', 100, [])[0][0]['callback_data']
        ));
    }

    public function testKeyboardIsOneRowOfTwoAndBackGoesToTheRecipe(): void
    {
        $rows = GenericCraftActionStart::confirmKeyboard('SapperShovel', 50, ['info_callback' => 'craftPreviewT3Utility_SapperShovel']);
        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[0]);
        $this->assertSame('genericCraft_SapperShovel_50_ok', $rows[0][0]['callback_data']);
        $this->assertSame('craftPreviewT3Utility_SapperShovel', $rows[0][1]['callback_data']);

        $noInfo = GenericCraftActionStart::confirmKeyboard('Bandage', 25, []);
        $this->assertSame('WorkbenchChoice', $noInfo[0][1]['callback_data']);
    }

    public function testConfirmTextCarriesTheWholeBatch(): void
    {
        $text = GenericCraftActionStart::confirmText('🪏 *Сапёрная лопата (T3)*', [
            'qty' => 50, 'gold' => 300000, 'minutes_total' => 3550,
            'resources' => ['Редкие металлы' => 400, 'Промышленный пластик' => 150],
            'crafted_items' => ['Проводка' => 100],
        ]);

        $this->assertStringContainsString('x50 шт.', $text);
        $this->assertStringContainsString('💰 Золото: *300 000*', $text);
        $this->assertStringContainsString('📦 Ресурсы: Редкие металлы ×400 · Промышленный пластик ×150', $text);
        $this->assertStringContainsString('🛠 Компоненты: Проводка ×100', $text);
        $this->assertStringContainsString('⏱ Время: *59 ч 10 мин*', $text);
        $this->assertSame(0, substr_count($text, '*') % 2);
        $this->assertSame(0, substr_count($text, '_') % 2);
        $this->assertLessThan(1024, mb_strlen($text));
    }

    /** Соседняя форма: без золота и компонентов строки не печатаются, имя с метасимволом не рвёт разметку. */
    public function testEmptyGroupsAreOmittedAndNamesAreMarkdownSafe(): void
    {
        $text = GenericCraftActionStart::confirmText('🩹 *Повязка*', [
            'qty' => 25, 'gold' => 0, 'minutes_total' => 60,
            'resources' => ['Тра_вы*' => 50], 'crafted_items' => [],
        ]);

        $this->assertStringNotContainsString('💰 Золото', $text);
        $this->assertStringNotContainsString('Компоненты', $text);
        $this->assertStringContainsString('📦 Ресурсы: Травы ×50', $text);
        $this->assertSame(0, substr_count($text, '*') % 2);
        $this->assertSame(0, substr_count($text, '_') % 2);
    }

    /** Самый тяжёлый рецепт конфига на ×100 не выводит экран за предел caption. */
    public function testHeaviestRecipeConfirmTextFitsCaption(): void
    {
        $cfg = new CraftRecipes();
        foreach ($cfg->keys() as $key) {
            $recipe = $cfg->get($key) ?? [];
            $res    = [];
            $items  = [];
            foreach ((array) ($recipe['resources'] ?? []) as $name => $n) {
                $res[(string) $name] = (int) $n * 100;
            }
            foreach ((array) ($recipe['crafted_items'] ?? []) as $name => $n) {
                $items[(string) $name] = (int) $n * 100;
            }
            $caption = is_string($recipe['start_caption_name'] ?? null) ? $recipe['start_caption_name'] : $key;
            $text    = GenericCraftActionStart::confirmText($caption, [
                'qty' => 100, 'gold' => (int) ($recipe['gold_required'] ?? 0) * 100, 'minutes_total' => 99999,
                'resources' => $res, 'crafted_items' => $items,
            ]);
            $this->assertLessThan(1024, mb_strlen($text), $key);
        }
    }
}
