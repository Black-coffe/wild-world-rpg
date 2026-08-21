<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use App\Services\Player\CraftService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Подпись хаба крафта: длина и честность замка.
 *
 * Экран уходит фотографией, а Telegram отклоняет подпись длиннее 1024 символов
 * молча — `ok=false`, никакой ошибки, игрок просто не получает хаб. Поэтому длина
 * меряется тестом на чистой render-функции, а не комментарием «тут коротко».
 */
final class CraftHubCaptionTest extends CIUnitTestCase
{
    private const TELEGRAM_CAPTION_LIMIT = 1024;

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function modes(): array
    {
        return [
            'нет цеха, ремонт включён'  => [false, true],
            'нет цеха, ремонт выключен' => [false, false],
            'цех есть, ремонт включён'  => [true, true],
            'цех есть, ремонт выключен' => [true, false],
        ];
    }

    /**
     * @dataProvider modes
     */
    public function testCaptionFitsTelegramLimit(bool $hasWorkbench, bool $repairHub): void
    {
        $caption = CraftService::hubCaption($hasWorkbench, $repairHub);

        $this->assertLessThanOrEqual(
            self::TELEGRAM_CAPTION_LIMIT,
            mb_strlen($caption),
            'Подпись хаба крафта переросла лимит Telegram — экран уйдёт в тишину.',
        );
    }

    /**
     * @dataProvider modes
     */
    public function testMarkdownAsterisksArePaired(bool $hasWorkbench, bool $repairHub): void
    {
        $caption = CraftService::hubCaption($hasWorkbench, $repairHub);

        $this->assertSame(
            0,
            substr_count($caption, '*') % 2,
            'Непарная `*` в legacy Markdown даёт 400 и тихий no-send.',
        );
    }

    public function testLockedModeExplainsWhatOpensTierThree(): void
    {
        $caption = CraftService::hubCaption(false, true);

        // Игрок без цеха жмёт кнопку раздела T3 и попадает на экран сборки верстака.
        // Подпись обязана предупредить об этом заранее, иначе кнопка читается как
        // дубль соседних «Верстаков» — ровно та жалоба, из которой вырос этот тест.
        $this->assertStringContainsString('🔒', $caption);
        $this->assertStringContainsString('Проф. крафт', $caption);
    }

    public function testUnlockedModeCarriesNoLockNotice(): void
    {
        $caption = CraftService::hubCaption(true, true);

        $this->assertStringNotContainsString(
            'пока заперт',
            $caption,
            'У игрока с цехом раздел T3 открыт — замковая приписка врёт.',
        );
    }
}
