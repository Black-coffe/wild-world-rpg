<?php

namespace Tests\Unit\Services\Trade;

use App\Services\Player\Trade\CraftTypeLabels;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Анти-дрейф словаря категорий крафта (экраны лавки «Продать/Купить крафт»).
 *
 * Повод 2026-07-31: словарь жил в 4 копиях и покрывал 7 типов из 18 — как только
 * экраны ожили (фикс тихой потери photo-сообщений), игрок увидел кнопки `drones`,
 * `clothing`, `❓utility`. Тест держит инвариант: у каждого типа из контента есть
 * человекочитаемое имя, и оно не пустое.
 *
 * @internal
 */
final class CraftTypeLabelsTest extends CIUnitTestCase
{
    /**
     * Типы, реально встречающиеся в `crafted_items` (снято с preprod 2026-07-31).
     * Появился новый тип в контенте → строка сюда И в CraftTypeLabels.
     *
     * @return list<array{0: string}>
     */
    public static function contentTypes(): array
    {
        return array_map(
            static fn (string $t): array => [$t],
            [
                'accessory', 'building', 'clothing', 'component', 'decorative', 'defense',
                'drones', 'drug', 'food', 'magical item', 'military', 'robots', 'teleport',
                'tool', 'transport', 'utility', 'weapon', 'workbench',
            ]
        );
    }

    /**
     * @dataProvider contentTypes
     */
    public function testEveryContentTypeHasHumanLabel(string $type): void
    {
        $label = CraftTypeLabels::rus($type);

        $this->assertStringNotContainsString(
            '❓',
            $label,
            "тип '{$type}' встречается в контенте, но у него нет названия — игрок увидит сырой ключ"
        );
        $this->assertNotSame($type, $label, "название категории '{$type}' не должно быть самим ключом");
    }

    public function testUnknownTypeStaysVisibleInsteadOfBreakingScreen(): void
    {
        // Пробел заметен, но экран продолжает работать.
        $this->assertSame('❓nonexistent', CraftTypeLabels::rus('nonexistent'));
    }

    public function testLabelsAreNonEmptyAndTrimmed(): void
    {
        foreach (CraftTypeLabels::all() as $type => $label) {
            $this->assertNotSame('', trim($label), "пустое название у типа '{$type}'");
            $this->assertSame(trim($label), $label, "лишние пробелы по краям у типа '{$type}'");
        }
    }
}
