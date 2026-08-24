<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Player;

use App\Services\Player\TeleportUse\TeleportUseMessageFormatter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * story backpack-teleport-base-choice-02 — экран «Куда прыгаем?» (`chooseBase()`)
 * и «база не найдена» (`baseNotFound()`).
 *
 * Проверяет только рендер форматтера (media-off, markdown-safe, 2–3 кнопки в ряд,
 * правильные callback_data) — не роутинг и не валидатор (см. story 01 для него).
 *
 * @internal
 */
final class TeleportUseMessageFormatterBaseChoiceTest extends CIUnitTestCase
{
    private TeleportUseMessageFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new TeleportUseMessageFormatter();
    }

    /** @param array<int, array<string,mixed>> $bases */
    private function decodeRows(array $payload): array
    {
        $decoded = json_decode($payload['reply_markup'], true);
        return $decoded['inline_keyboard'];
    }

    public function testChooseBaseIsSelfContainedTextForMediaOff(): void
    {
        $bases = [
            ['id' => 242, 'map_cell_id' => 10, 'camp_name' => 'Старый Маяк', 'coordinate_x' => 5, 'coordinate_y' => -3],
            ['id' => 315, 'map_cell_id' => 20, 'camp_name' => 'Форт-2', 'coordinate_x' => 12, 'coordinate_y' => 7],
        ];

        $payload = $this->formatter->chooseBase('Backpack', $bases);

        $this->assertSame('Markdown', $payload['parse_mode']);
        $this->assertStringContainsString('Старый Маяк', $payload['text']);
        $this->assertStringContainsString('Форт-2', $payload['text']);
        $this->assertStringContainsString('X=5, Y=-3', $payload['text']);
        $this->assertStringContainsString('X=12, Y=7', $payload['text']);
        $this->assertStringContainsString('2', $payload['text']);
    }

    public function testChooseBaseButtonsCarryKindAndBaseId(): void
    {
        $bases = [
            ['id' => 242, 'map_cell_id' => 10, 'camp_name' => 'Старый Маяк', 'coordinate_x' => 5, 'coordinate_y' => -3],
            ['id' => 315, 'map_cell_id' => 20, 'camp_name' => 'Форт-2', 'coordinate_x' => 12, 'coordinate_y' => 7],
        ];

        $payload = $this->formatter->chooseBase('WithGold', $bases);
        $rows    = $this->decodeRows($payload);

        $flat = array_merge(...$rows);
        $callbacks = array_column($flat, 'callback_data');

        $this->assertContains('TeleportUse_WithGold_242', $callbacks);
        $this->assertContains('TeleportUse_WithGold_315', $callbacks);
    }

    public function testChooseBaseNeverLeavesALoneButtonInARow(): void
    {
        // 2..7 баз — во всех рядах (кроме служебного «Назад/База») минимум 2 кнопки.
        foreach (range(2, 7) as $n) {
            $bases = [];
            for ($i = 1; $i <= $n; $i++) {
                $bases[] = ['id' => $i, 'map_cell_id' => $i, 'camp_name' => "База{$i}", 'coordinate_x' => $i, 'coordinate_y' => $i];
            }

            $payload   = $this->formatter->chooseBase('Portable', $bases);
            $rows      = $this->decodeRows($payload);
            $baseRows  = array_slice($rows, 0, -1); // последний ряд — служебный Назад/База

            foreach ($baseRows as $row) {
                $this->assertGreaterThanOrEqual(2, count($row), "n={$n}: одиночная кнопка в ряду баз");
            }
        }
    }

    public function testChooseBaseEscapesBreakingMarkdownInCampName(): void
    {
        $bases = [
            ['id' => 1, 'map_cell_id' => 1, 'camp_name' => 'Ferma_1 *Elite*', 'coordinate_x' => 1, 'coordinate_y' => 1],
            ['id' => 2, 'map_cell_id' => 2, 'camp_name' => 'Второй [домик]', 'coordinate_x' => 2, 'coordinate_y' => 2],
        ];

        $payload = $this->formatter->chooseBase('Backpack', $bases);
        $rows    = $this->decodeRows($payload);
        $flat    = array_merge(...$rows);

        foreach ($flat as $btn) {
            if (!str_starts_with($btn['callback_data'], 'TeleportUse_Backpack_')) {
                continue;
            }
            $this->assertStringNotContainsString('*', $btn['text']);
            $this->assertStringNotContainsString('_', $btn['text']);
            $this->assertStringNotContainsString('[', $btn['text']);
        }

        $this->assertStringNotContainsString('*Elite*', $payload['text']);
    }

    public function testChooseBaseFallsBackToGenericNameWhenCampNameEmpty(): void
    {
        $bases = [
            ['id' => 1, 'map_cell_id' => 1, 'camp_name' => null, 'coordinate_x' => 1, 'coordinate_y' => 1],
            ['id' => 2, 'map_cell_id' => 2, 'camp_name' => '', 'coordinate_x' => 2, 'coordinate_y' => 2],
        ];

        $payload = $this->formatter->chooseBase('WithExperience', $bases);

        $this->assertStringContainsString('База', $payload['text']);
    }

    /**
     * story backpack-teleport-base-choice-04 (ревью №8) — экран выбора называет способ,
     * который будет потрачен, но без чисел баланса (стоимость золота/опыта не печатается).
     */
    public function testChooseBaseNamesTheSpendingMethodForEachKind(): void
    {
        $bases = [
            ['id' => 1, 'map_cell_id' => 1, 'camp_name' => 'База', 'coordinate_x' => 1, 'coordinate_y' => 1],
        ];

        $expectations = [
            'Backpack'       => '🎒 Рюкзак-телепорт (1 заряд)',
            'WithGold'       => '💰 Телепорт за золото',
            'Portable'       => '📡 Портативный телепорт (1 заряд)',
            'WithExperience' => '✨ Телепорт за опыт',
        ];

        foreach ($expectations as $kind => $label) {
            $payload = $this->formatter->chooseBase($kind, $bases);
            $this->assertStringContainsString($label, $payload['text'], "kind={$kind}");
            $this->assertStringNotContainsString('💰 Списано', $payload['text'], "kind={$kind}: стоимость золота не печатается");
        }
    }

    public function testBaseNotFoundIsSelfContainedText(): void
    {
        $payload = $this->formatter->baseNotFound();

        $this->assertSame('Markdown', $payload['parse_mode']);
        $this->assertArrayNotHasKey('reply_markup', $payload);
        $this->assertNotSame('', trim($payload['text']));
    }
}
