<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers\Craft;

use App\TaskHandlers\Craft\GenericCraftCompletionHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Числа лечения в итоге крафта лекарства (d-dead-res-heal-line; ask 6 спеки d-dead-res).
 *
 * Итог «📌 Крафт завершён!» читается без фото (ADR-020): у лекарства с лечением в GameSettings
 * (`medical.<snake>.heal_health` / `.heal_tired`) — строка с числами. Ветки: есть ключ → числа;
 * нет ключа → null (legacy-лекарства без строки); не `drug` → null; ноль опускается. Строка уходит
 * с parse_mode=Markdown — без `*` и `_`, чтобы не порвать разметку.
 *
 * @internal
 */
final class CraftCompletionHealLineTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function line(array $item, array $settings): ?string
    {
        return GenericCraftCompletionHandler::healLine(
            $item,
            static fn (string $key): mixed => $settings[$key] ?? null
        );
    }

    public function testDrugWithSettingsShowsBothNumbers(): void
    {
        $line = $this->line(
            ['type' => 'drug', 'name_eng' => 'ChitinPatch'],
            ['medical.chitin_patch.heal_health' => 18, 'medical.chitin_patch.heal_tired' => 5]
        );

        $this->assertSame('🩺 Лечит: ❤️ +18 здоровья, ⚡ +5 выносливости', $line);
    }

    public function testZeroTiredIsOmitted(): void
    {
        $line = $this->line(
            ['type' => 'drug', 'name_eng' => 'Antivenom'],
            ['medical.antivenom.heal_health' => 25, 'medical.antivenom.heal_tired' => 0]
        );

        $this->assertSame('🩺 Лечит: ❤️ +25 здоровья', $line);
    }

    public function testSnakeHandlesSpacesLikePharmacy(): void
    {
        $line = $this->line(
            ['type' => 'drug', 'name_eng' => 'Common cold tincture'],
            ['medical.common_cold_tincture.heal_health' => 45, 'medical.common_cold_tincture.heal_tired' => 36]
        );

        $this->assertSame('🩺 Лечит: ❤️ +45 здоровья, ⚡ +36 выносливости', $line);
    }

    public function testDrugWithoutSettingHasNoLine(): void
    {
        $this->assertNull($this->line(['type' => 'drug', 'name_eng' => 'Bandage'], []));
    }

    public function testNonDrugHasNoLineEvenWithKey(): void
    {
        $this->assertNull($this->line(
            ['type' => 'tool', 'name_eng' => 'Antivenom'],
            ['medical.antivenom.heal_health' => 25]
        ));
    }

    public function testLineIsMarkdownSafe(): void
    {
        $line = (string) $this->line(
            ['type' => 'drug', 'name_eng' => 'MineralTablets'],
            ['medical.mineral_tablets.heal_health' => 5, 'medical.mineral_tablets.heal_tired' => 30]
        );

        $this->assertStringNotContainsString('*', $line);
        $this->assertStringNotContainsString('_', $line);
    }
}
