<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Economy;

use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\GameSettingsModel;
use App\Services\Economy\CraftShopGate;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Гейты входа в лавку «🛍️ Купить крафт».
 *
 * Чинимый баг: порог золота читался из живой настройки только во входном экране
 * (`BuyCraftAction`), а `BuyCraftItemListAction` / `BuyCraftItemAction` /
 * `BuyCraftConfirmAction` держали константу 1000. Опусти админ порог — игрок проходил
 * первый экран и упирался во второй, где ему называли цифру, которой в игре уже нет.
 *
 * @internal
 */
final class CraftShopGateTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        service('cache')->clean();
    }

    /** @param array<string,int> $overrides */
    private function gate(bool $hasWarehouse, array $overrides = [], bool $warehouseDefined = true): CraftShopGate
    {
        $settingsModel = new class ($overrides) extends GameSettingsModel {
            /** @param array<string,int> $overrides */
            public function __construct(private array $overrides)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->overrides)) {
                    return null;
                }

                return [
                    'setting_key' => $key,
                    'value_type'  => 'int',
                    'value_int'   => $this->overrides[$key],
                ];
            }
        };

        $buildings = new class ($warehouseDefined) extends BuildingModel {
            public function __construct(private bool $defined)
            {
            }

            public function where($key, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function first()
            {
                return $this->defined ? ['id' => 7, 'name_en' => 'Warehouse'] : null;
            }
        };

        $characterBuildings = new class ($hasWarehouse) extends CharacterBuildingModel {
            public function __construct(private bool $built)
            {
            }

            public function where($key, $value = null, ?bool $escape = null): self
            {
                return $this;
            }

            public function first()
            {
                return $this->built ? ['id' => 42, 'character_id' => 1, 'building_id' => 7] : null;
            }
        };

        return new CraftShopGate($buildings, $characterBuildings, new GameSettingsService($settingsModel));
    }

    public function testOpenPathReturnsNull(): void
    {
        $this->assertNull($this->gate(true)->check(['id' => 1, 'gold' => 5000]));
    }

    public function testDefaultThresholdIsThousand(): void
    {
        $this->assertSame(1000, $this->gate(true)->minGold());

        $gate = $this->gate(true)->check(['id' => 1, 'gold' => 999]);
        $this->assertNotNull($gate);
        $this->assertSame('min_gold_threshold', $gate['reason']);
        $this->assertStringContainsString('1000', $gate['text']);
    }

    /**
     * Ядро фикса: опущенный админом порог обязан пропускать игрока — раньше три
     * экрана из четырёх продолжали требовать 1000.
     */
    public function testLoweredThresholdOpensThePathAndIsQuotedInRefusal(): void
    {
        $gate = $this->gate(true, [CraftShopGate::KEY_MIN_GOLD => 200]);

        $this->assertSame(200, $gate->minGold());
        $this->assertNull($gate->check(['id' => 1, 'gold' => 500]), 'при пороге 200 игрок с 500 должен проходить');

        $refused = $gate->check(['id' => 1, 'gold' => 100]);
        $this->assertNotNull($refused);
        $this->assertStringContainsString('200', $refused['text']);
        $this->assertStringNotContainsString('1000', $refused['text'], 'отказ называет мёртвую константу');
    }

    /** Поднятый порог обязан отсекать так же живо, как опущенный — пропускать. */
    public function testRaisedThresholdBlocks(): void
    {
        $gate = $this->gate(true, [CraftShopGate::KEY_MIN_GOLD => 9000]);

        $refused = $gate->check(['id' => 1, 'gold' => 5000]);
        $this->assertNotNull($refused);
        $this->assertSame('min_gold_threshold', $refused['reason']);
        $this->assertStringContainsString('9000', $refused['text']);
    }

    /**
     * Склад — самый узкий гейт (18 персонажей из 675 на 2026-08-06), поэтому отказ
     * обязан объяснять путь постройки, а не просто закрывать дверь.
     */
    public function testMissingWarehouseExplainsTheBuildPath(): void
    {
        $refused = $this->gate(false)->check(['id' => 1, 'gold' => 5000]);

        $this->assertNotNull($refused);
        $this->assertSame('no_warehouse', $refused['reason']);
        $this->assertStringContainsString('База', $refused['text']);
        $this->assertStringContainsString('Строить', $refused['text']);
        $this->assertStringContainsString('Склад', $refused['text']);
    }

    public function testGoldGateIsCheckedBeforeWarehouse(): void
    {
        $refused = $this->gate(false)->check(['id' => 1, 'gold' => 10]);

        $this->assertNotNull($refused);
        $this->assertSame('min_gold_threshold', $refused['reason']);
    }

    public function testMissingWarehouseDefinitionIsItsOwnReason(): void
    {
        $refused = $this->gate(true, [], false)->check(['id' => 1, 'gold' => 5000]);

        $this->assertNotNull($refused);
        $this->assertSame('warehouse_definition_missing', $refused['reason']);
    }

    /** Нечисловое золото не должно открывать дверь молча. */
    public function testNonNumericGoldIsTreatedAsZero(): void
    {
        $refused = $this->gate(true)->check(['id' => 1, 'gold' => null]);

        $this->assertNotNull($refused);
        $this->assertSame('min_gold_threshold', $refused['reason']);
    }

    /**
     * 🔴 Регрессия Tier-3 2026-08-06: `getUserAndCharacter()` отдаёт `CharacterEntity`,
     * и голый `array`-typehint под `strict_types` валил все четыре экрана лавки в
     * TypeError (HTTP 500). Unit-фикстуры массивами этого не ловили — фиксируем
     * Entity явно. См. memory `feedback_entity_strict_array_typehint_trap`.
     */
    public function testAcceptsCharacterEntityNotOnlyArray(): void
    {
        $entity = new \App\Entities\CharacterEntity();
        $entity->id   = 1;
        $entity->gold = 5000;

        $this->assertNull($this->gate(true)->check($entity), 'Entity должна проходить гейт как массив');

        $poor = new \App\Entities\CharacterEntity();
        $poor->id   = 1;
        $poor->gold = 10;

        $refused = $this->gate(true)->check($poor);
        $this->assertNotNull($refused);
        $this->assertSame('min_gold_threshold', $refused['reason']);
    }

    /** Markdown ломается на непарных звёздочках — тексты уходят с parse_mode. */
    public function testAllRefusalTextsAreMarkdownSafe(): void
    {
        $texts = [
            $this->gate(true)->check(['id' => 1, 'gold' => 0]),
            $this->gate(false)->check(['id' => 1, 'gold' => 5000]),
            $this->gate(true, [], false)->check(['id' => 1, 'gold' => 5000]),
        ];

        foreach ($texts as $refused) {
            $this->assertNotNull($refused);
            $this->assertSame(0, substr_count($refused['text'], '*') % 2, "непарные * в: {$refused['reason']}");
            $this->assertSame(0, substr_count($refused['text'], '_') % 2, "непарные _ в: {$refused['reason']}");
        }
    }

    /**
     * Анти-дрейф: экраны лавки обязаны спрашивать гейт, а не проверять золото сами.
     * Именно возврат константы в любой из них и был багом.
     */
    public function testBuyScreensDelegateGateToThisService(): void
    {
        $screens = [
            'BuyCraftAction',
            'BuyCraftItemListAction',
            'BuyCraftItemAction',
            'BuyCraftConfirmAction',
        ];

        foreach ($screens as $screen) {
            $path   = APPPATH . "Controllers/Telegram/Commands/Actions/Sell/{$screen}.php";
            $source = (string) file_get_contents($path);

            $this->assertStringContainsString('CraftShopGate', $source, "{$screen} не спрашивает гейт");
            $this->assertDoesNotMatchRegularExpression(
                "/gold'\]\s*<\s*1000/",
                $source,
                "{$screen} снова сравнивает золото с константой 1000"
            );
        }
    }
}
