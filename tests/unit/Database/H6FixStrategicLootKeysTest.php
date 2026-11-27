<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\Migrations\H6FixStrategicLootKeys;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * H6.1 — чистое преобразование `world_objects.contents` стратобъектов: каждый ключ должен находить
 * ресурс, предмет или оружие. Входы — содержимое пяти объектов в том виде, в каком оно лежит на проде
 * (SELECT 2026-11-27, совпадает с сид-миграциями). Повторный прогон ничего не меняет.
 */
final class H6FixStrategicLootKeysTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2026-11-27-120000_H6FixStrategicLootKeys.php';
    }

    /**
     * @return array<string, array{0: list<array<string, array<string, string>>>, 1: list<array<string, array<string, string>>>}>
     */
    public static function prodContents(): array
    {
        return [
            'Bunker' => [
                [[
                    'resources'     => ['Sulfur' => '40', 'RareMetals' => '50', 'Oil' => '60', 'Ironstone' => '120', 'Stones' => '80'],
                    'crafted_items' => ['PipeGun' => '2', 'MetalSpear' => '3', 'BasicMedKit' => '5', 'Bandage' => '8', 'Antiseptic' => '4'],
                ]],
                [[
                    'resources'     => ['Sulfur' => '40', 'RareMetals' => '50', 'Oil' => '60', 'Ironstone' => '120', 'Stones' => '80'],
                    'crafted_items' => ['FirstAidKit' => '5', 'Bandage' => '8', 'Antiseptic' => '4'],
                    'weapons'       => ['PipeGun' => '2', 'MetalSpear' => '3'],
                ]],
            ],
            'Technopark' => [
                [[
                    'resources'     => ['RareMetals' => '80', 'Wiring' => '40', 'Crystals' => '12', 'Minerals' => '30', 'RareOre' => '25'],
                    'crafted_items' => ['ElectronicComponents' => '15', 'GlassBags' => '8', 'Wiring' => '20', 'WorkbenchOne' => '1', 'TireIron' => '3'],
                ]],
                [[
                    'resources'     => ['RareMetals' => '80', 'Crystals' => '12', 'Minerals' => '30', 'RareOre' => '25'],
                    'crafted_items' => ['ElectronicComponents' => '15', 'GlassBags' => '8', 'Wiring' => '40', 'WorkbenchOne' => '1', 'TireIron' => '3'],
                ]],
            ],
            'GhostCity' => [
                [[
                    'resources'     => ['Wood' => '150', 'Pebble' => '180', 'Stones' => '120', 'Sand' => '100', 'Crops' => '60', 'Clay' => '50', 'Ironstone' => '40'],
                    'crafted_items' => ['LumberjackAxe' => '2', 'IronShovel' => '2', 'FoldingKnife' => '6', 'Hoe' => '2', 'Bandage' => '4'],
                ]],
                [[
                    'resources'     => ['Wood' => '150', 'Pebble' => '180', 'Stones' => '120', 'Sand' => '100', 'Crops' => '60', 'Clay' => '50', 'Ironstone' => '40'],
                    'crafted_items' => ['LumberjackAxe' => '2', 'IronShovel' => '2', 'FoldingKnife' => '6', 'Hoe' => '2', 'Bandage' => '4'],
                ]],
            ],
            'IslandFarm' => [
                [[
                    'resources'     => ['Crops' => '90', 'Fruit' => '60', 'Wood' => '70', 'Clay' => '40', 'Water' => '60'],
                    'crafted_items' => ['BasicMedKit' => '4', 'Bandage' => '6', 'Hoe' => '2'],
                ]],
                [[
                    'resources'     => ['Crops' => '90', 'Fruit' => '60', 'Wood' => '70', 'Clay' => '40', 'Water' => '60'],
                    'crafted_items' => ['FirstAidKit' => '4', 'Bandage' => '6', 'Hoe' => '2'],
                ]],
            ],
            'IslandHeart' => [
                [[
                    'resources'     => ['Stones' => '120', 'metalFragments' => '90', 'electronicComponents' => '40', 'Amber' => '30'],
                    'crafted_items' => ['BasicMedKit' => '5'],
                ]],
                [[
                    'resources'     => ['Stones' => '120', 'Amber' => '30'],
                    'crafted_items' => ['FirstAidKit' => '5', 'metalFragments' => '90', 'electronicComponents' => '40'],
                ]],
            ],
        ];
    }

    /**
     * @param list<array<string, array<string, string>>> $input
     * @param list<array<string, array<string, string>>> $expected
     */
    #[DataProvider('prodContents')]
    public function testFixesProdShapedContents(array $input, array $expected): void
    {
        $this->assertSame($expected, H6FixStrategicLootKeys::fixContents($input));
    }

    /**
     * @param list<array<string, array<string, string>>> $input
     */
    #[DataProvider('prodContents')]
    public function testSecondRunChangesNothing(array $input): void
    {
        $once = H6FixStrategicLootKeys::fixContents($input);
        $this->assertSame($once, H6FixStrategicLootKeys::fixContents($once));
    }

    public function testRenameMergesIntoExistingTargetKeepingTheLargerMax(): void
    {
        $input = [[
            'crafted_items' => ['BasicMedKit' => '5', 'FirstAidKit' => '3'],
        ]];

        $this->assertSame(
            [['crafted_items' => ['FirstAidKit' => '5']]],
            H6FixStrategicLootKeys::fixContents($input)
        );
    }
}
