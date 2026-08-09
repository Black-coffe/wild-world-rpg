<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CraftedItemsLogModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Багрепорт 2026-08-09 («бесконечный медовый сбитень»): у строки стака
 * `crafted_items_log.durability_count` лежала историческая константа 100, а шаблон
 * предмета даёт 1 дозу. Расход препарата вычитал ИЗ ЭТОЙ КОНСТАНТЫ, `quantity`
 * не трогал — и экран честно повторял «Остаток: 1 шт.» сотню применений подряд.
 *
 * Инвариант: остаток доз строки НИКОГДА не превышает ёмкость шаблона.
 *
 * @internal
 */
final class CraftedItemsLogChargesClampTest extends CIUnitTestCase
{
    public function testBaseChargesTreatsEmptyTemplateAsSingleUse(): void
    {
        $this->assertSame(1, CraftedItemsLogModel::baseCharges(0));
        $this->assertSame(1, CraftedItemsLogModel::baseCharges(null));
        $this->assertSame(1, CraftedItemsLogModel::baseCharges(''));
        $this->assertSame(1, CraftedItemsLogModel::baseCharges(-5));
        $this->assertSame(5, CraftedItemsLogModel::baseCharges(5));
        $this->assertSame(5, CraftedItemsLogModel::baseCharges('5')); // из БД приходит строкой
    }

    public function testStoredChargesAreClampedToTemplate(): void
    {
        // Ровно тот случай из прода: медовый сбитень, база 1, в строке 29 (было 100).
        $this->assertSame(1, CraftedItemsLogModel::effectiveCharges(29, 1));
        $this->assertSame(1, CraftedItemsLogModel::effectiveCharges(100, 1));

        // Еда с пустым шаблоном — тоже одноразовая, а не стодозовая.
        $this->assertSame(1, CraftedItemsLogModel::effectiveCharges(100, 0));

        // Многодозовый препарат остаётся многодозовым (Антисептик: 5 применений).
        $this->assertSame(5, CraftedItemsLogModel::effectiveCharges(5, 5));
        $this->assertSame(3, CraftedItemsLogModel::effectiveCharges(3, 5));
        $this->assertSame(3, CraftedItemsLogModel::effectiveCharges('3', '5'));
    }

    public function testEmptyOrBrokenStoredValueStillAllowsOneUse(): void
    {
        // Строка с 0/NULL (blind INSERT со значением колонки по умолчанию) не должна
        // становиться неиспользуемой — это последняя доза, дальше тратится quantity.
        $this->assertSame(1, CraftedItemsLogModel::effectiveCharges(0, 1));
        $this->assertSame(1, CraftedItemsLogModel::effectiveCharges(0, 5));
        $this->assertSame(1, CraftedItemsLogModel::effectiveCharges(-7, 5));
        $this->assertSame(5, CraftedItemsLogModel::effectiveCharges(null, 5)); // поля нет → полная упаковка
    }

    /**
     * Анти-дрейф по исходникам: точки, где заряды читаются или выдаются, обязаны
     * идти через зажим. Юнитом расход не воспроизвести (Telegram + БД), поэтому
     * фиксируем контракт по коду — как GuideCatalogTest / UsePharmacyAtomicUpdateTest.
     */
    public function testConsumptionAndRewardPathsUseTheClamp(): void
    {
        $use = file_get_contents(APPPATH . 'Controllers/Telegram/Commands/Actions/UsePharmacyAction.php');
        $this->assertIsString($use);
        $this->assertStringContainsString('CraftedItemsLogModel::effectiveCharges', $use);
        $this->assertStringNotContainsString(
            "if (\$itemUsage['durability_count'] > 1)",
            $use,
            'Остаток доз нельзя брать из БД как есть — только через effectiveCharges()'
        );

        $reward = file_get_contents(APPPATH . 'Services/PVE/RewardService.php');
        $this->assertIsString($reward);
        $this->assertStringNotContainsString(
            '? $tplDur : 100',
            $reward,
            'PvE-награда не должна подставлять 100 зарядов предметам с пустым шаблоном'
        );
        $this->assertStringContainsString('CraftedItemsLogModel::baseCharges', $reward);
    }
}
