<?php

declare(strict_types=1);

namespace Tests\Unit\Transport;

use App\Controllers\Telegram\Commands\Actions\Faction\ChooseFaction;
use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;

/**
 * transport-11 (ADR-174, docs/specs/transport-system/) — экран выбора фракции
 * (`ChooseFaction::renderFactionInfoText`) обязан назвать машину каждой фракции,
 * её уровень и цену необратимости ДО того, как игрок нажмёт кнопку.
 *
 * Чистая render-функция без побочных эффектов и БД — `Config\CraftRecipes` тот же
 * источник, что читает крафт (story 06), поэтому расхождение имени/уровня между
 * экраном и каталогом здесь и ловится.
 *
 * @internal
 */
final class FactionChoiceVehicleLineTest extends CIUnitTestCase
{
    /** factionId => [recipeKey, ожидаемое имя, ожидаемый уровень]. */
    private const EXPECTED = [
        1 => ['Snowmobile', 'Снегоход', 14],       // Милитари
        2 => ['MountainBike', 'Горный велосипед', 12], // Партизаны
        3 => ['AutonomousDrone', 'Автономный дрон', 16], // Инженеры
        4 => ['DraftCart', 'Тягловая повозка', 14], // Фермеры
    ];

    public function testEachFactionCarriesItsVehicleLineWithNameAndLevel(): void
    {
        $recipes = new CraftRecipes();
        foreach (self::EXPECTED as $factionId => [$recipeKey, $name, $level]) {
            $line = ChooseFaction::vehicleLine($factionId, $recipes);
            $this->assertNotSame('', $line, "factionId {$factionId}: строка не должна быть пустой");
            $this->assertStringContainsString($name, $line, "factionId {$factionId}: имя машины не совпало");
            $this->assertStringContainsString((string) $level, $line, "factionId {$factionId}: уровень не совпал");
        }
    }

    /**
     * Единый источник правды: имя и уровень в строке экрана обязаны буквально совпасть
     * с тем, что лежит в рецепте `Config\CraftRecipes` (story 06), а не быть набраны
     * руками отдельно на экране.
     */
    public function testVehicleLineNameAndLevelMatchCraftRecipesCatalog(): void
    {
        $recipes = new CraftRecipes();
        $recipeKeys = [
            1 => 'Snowmobile',
            2 => 'MountainBike',
            3 => 'AutonomousDrone',
            4 => 'DraftCart',
        ];

        foreach ($recipeKeys as $factionId => $recipeKey) {
            $recipe = $recipes->get($recipeKey);
            $this->assertIsArray($recipe, "рецепт {$recipeKey} обязан существовать в каталоге");

            $line = ChooseFaction::vehicleLine($factionId, $recipes);

            $this->assertStringContainsString(
                (string) $recipe['item_name_rus'],
                $line,
                "factionId {$factionId}: имя на экране разошлось с каталогом"
            );
            $this->assertStringContainsString(
                (string) $recipe['required_level'],
                $line,
                "factionId {$factionId}: уровень на экране разошлось с каталогом"
            );
        }
    }

    public function testUnknownFactionIdReturnsEmptyLine(): void
    {
        $this->assertSame('', ChooseFaction::vehicleLine(999, new CraftRecipes()));
        $this->assertSame('', ChooseFaction::vehicleLine(5, new CraftRecipes())); // faction_id=5 = «не выбрано»
    }

    public function testScreenStatesIrreversibilityAndThatItAlsoLocksTheVehicle(): void
    {
        $text = ChooseFaction::renderFactionInfoText();

        $this->assertStringContainsString('необратим', mb_strtolower($text));
        $this->assertStringContainsString('машина', mb_strtolower($text));
    }

    /**
     * Caption ≤ 1024 знаков — Telegram молча не отправит фото длиннее (feedback
     * `feedback_caption_length_needs_a_test_not_a_note`). Проверяется и без стимула,
     * и с ним — стимул удлиняет текст динамической строкой при выборе.
     */
    public function testCaptionFitsWithinTelegramPhotoLimitWithAndWithoutIncentive(): void
    {
        $withoutIncentive = ChooseFaction::renderFactionInfoText('');
        $this->assertLessThanOrEqual(1024, mb_strlen($withoutIncentive), 'без стимула caption обязан влезать в 1024');

        $withIncentive = ChooseFaction::renderFactionInfoText(
            '💰 *Бонус за выбор фракции: +1,000💰* — начислим сразу при вступлении.'
        );
        $this->assertLessThanOrEqual(1024, mb_strlen($withIncentive), 'со стимулом caption обязан влезать в 1024');
    }

    /**
     * Media-off (ADR-020): экран не отправляет фото, но текст обязан быть
     * самодостаточным — не короче ощутимого содержательного минимума.
     */
    public function testCaptionIsSelfContainedTextOnly(): void
    {
        $text = ChooseFaction::renderFactionInfoText();
        $this->assertGreaterThan(200, mb_strlen($text), 'экран не должен схлопнуться до пустой заглушки');
    }

    /**
     * Markdown-safe: непарная `*` в Legacy Markdown роняет отправку молча
     * (feedback `feedback_legacy_markdown_no_backslash_escape`).
     */
    public function testAsterisksArePairedInRenderedText(): void
    {
        $text = ChooseFaction::renderFactionInfoText();
        $count = substr_count($text, '*');
        $this->assertSame(0, $count % 2, 'непарная * роняет Legacy Markdown отправку молча');
    }

    /**
     * Точность (Редколлегия-этос): экран не обещает чисел баланса и не обещает
     * возможностей, которых нет («уникальные умения»).
     */
    public function testScreenDoesNotPromiseBalanceNumbersOrUnimplementedAbilities(): void
    {
        $recipes = new CraftRecipes();
        $vehicleLines = implode("\n", [
            ChooseFaction::vehicleLine(1, $recipes),
            ChooseFaction::vehicleLine(2, $recipes),
            ChooseFaction::vehicleLine(3, $recipes),
            ChooseFaction::vehicleLine(4, $recipes),
        ]);

        // Строки машин — только человеческая фраза о характере, ни одного «+N%» (§Non-goals).
        $this->assertDoesNotMatchRegularExpression('/\d+%/', $vehicleLines, 'строка машины не обещает числа баланса (они в GameSettings)');

        $text = ChooseFaction::renderFactionInfoText();
        $this->assertStringNotContainsString('уникальн', mb_strtolower($text), 'экран не обещает несуществующих уникальных умений');
    }
}
