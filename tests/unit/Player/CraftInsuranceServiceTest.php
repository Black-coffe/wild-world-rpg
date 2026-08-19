<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Services\Player\CraftInsuranceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Гейт V24.2 — экран «🛡 Страховка крафта» врал «нет предметов под страховку»
 * игроку, оплатившему полис двух верстаков, потому что единственным текстом
 * для пустого `$rows` были машинные `crafted_items.type` (`robots, workbench,
 * transport`), а действующие полисы вообще не выводились.
 *
 * Тест держит контракт словаря `typeLabel()`/`typeLabels()`, на котором
 * стоит вся правка:
 *  - реальные значения `crafted_items.type` с прода переведены на русский;
 *  - незнакомый тип деградирует в сам токен, а не в пустоту (новая настройка
 *    `craft_insurance.eligible_types` не должна ронять экран);
 *  - список переводов не даёт пустых сегментов («, ,») ни на смеси
 *    известных/неизвестных токенов.
 */
final class CraftInsuranceServiceTest extends CIUnitTestCase
{
    /** Реальные значения crafted_items.type, снятые с прода 19.08.2026. */
    private const PROD_TYPES = [
        'drug', 'weapon', 'tool', 'food', 'clothing', 'building', 'component',
        'transport', 'utility', 'decorative', 'magical item', 'military',
        'accessory', 'teleport', 'workbench', 'robots', 'defense', 'drones',
    ];

    public function testEveryProdTypeHasARussianLabel(): void
    {
        $service = new CraftInsuranceService();

        foreach (self::PROD_TYPES as $type) {
            $label = $service->typeLabel($type);
            $this->assertNotSame('', trim($label), "Тип «{$type}» перевёлся в пустоту.");
            $this->assertNotSame($type, $label, "Тип «{$type}» не переведён — в player-facing текст попадёт машинный токен.");
        }
    }

    public function testCurrentEligibleTypesTranslateAsExpected(): void
    {
        $service = new CraftInsuranceService();

        $this->assertSame('робот', $service->typeLabel('robots'));
        $this->assertSame('верстак', $service->typeLabel('workbench'));
        $this->assertSame('транспорт', $service->typeLabel('transport'));
    }

    public function testUnknownTypeDegradesToTokenInsteadOfBreaking(): void
    {
        $service = new CraftInsuranceService();

        // Настройка craft_insurance.eligible_types может завтра получить тип,
        // которого нет в словаре — экран не должен упасть или показать пустоту.
        $this->assertSame('some_new_type', $service->typeLabel('some_new_type'));
    }

    public function testTypeLabelsJoinsKnownTypesWithComma(): void
    {
        $service = new CraftInsuranceService();

        $this->assertSame(
            'робот, верстак, транспорт',
            $service->typeLabels(['robots', 'workbench', 'transport'])
        );
    }

    public function testTypeLabelsMixedWithUnknownHasNoEmptySegments(): void
    {
        $service = new CraftInsuranceService();

        $joined = $service->typeLabels(['robots', 'some_new_type', 'workbench']);

        $this->assertSame('робот, some_new_type, верстак', $joined);
        $this->assertStringNotContainsString(', ,', $joined);
        $this->assertStringNotContainsString(',,', $joined);
    }

    public function testTypeLabelsOfEmptyListIsEmptyString(): void
    {
        $service = new CraftInsuranceService();

        $this->assertSame('', $service->typeLabels([]));
    }
}
