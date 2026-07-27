<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers\Craft;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Прод-баг (лог 2026-07-25): `[Worker] GenericCraftCompletionHandler упал на task
 * #37451: Undefined array key "agility_bonus"`.
 *
 * Бонусы статов — НЕ обязательные поля рецепта: `agility_bonus` отсутствует у 2
 * рецептов, `intellect_bonus` — у 15 (всё оружие + фракционная броня). Шаг «bump
 * статов» читал оба ключа без `?? 0`, хотя три остальных чтения в том же файле
 * застрахованы → 17 рецептов роняли ЗАВЕРШЕНИЕ крафта уже ПОСЛЕ выдачи предмета и
 * ДО уведомления. Worker помечает задачу `completed` ещё до `handle()`, поэтому
 * повтора не происходит: игрок получал вещь, но ни статов, ни сообщения о готовности.
 *
 * Тест держит инвариант с двух сторон: (1) в handler'е нет ни одного незастрахованного
 * чтения `*_bonus` из рецепта; (2) в конфиге действительно есть рецепты без этих
 * ключей — т.е. страховка не декоративна, а закрывает существующие данные.
 *
 * Source-scan (как GuideCatalogTest / UsePharmacyAtomicUpdateTest): падение внутри
 * task-worker'а юнитом не воспроизвести — фиксируем контракт по исходнику.
 *
 * @internal
 */
final class CraftCompletionBonusKeysGuardedTest extends CIUnitTestCase
{
    private const HANDLER = APPPATH . 'TaskHandlers/Craft/GenericCraftCompletionHandler.php';

    public function testEveryBonusReadFromRecipeIsGuarded(): void
    {
        $src = file_get_contents(self::HANDLER);
        $this->assertIsString($src);

        $lines    = preg_split('/\R/', $src) ?: [];
        $unguarded = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/\$recipe\[\s*\'[a-z_]*bonus\'\s*\]/', $line) !== 1) {
                continue;
            }
            // Застраховано, если на строке есть `??` (значение по умолчанию) либо
            // строка — часть is_numeric-нарроуинга.
            if (str_contains($line, '??') || str_contains($line, 'is_numeric')) {
                continue;
            }
            $unguarded[] = ($i + 1) . ': ' . trim($line);
        }

        $this->assertSame(
            [],
            $unguarded,
            "Чтение бонуса из рецепта без `?? 0` роняет завершение крафта у рецептов, "
            . "где ключа нет (прод-баг task #37451):\n" . implode("\n", $unguarded)
        );
    }

    /**
     * Страховка не декоративна: в конфиге есть рецепты без бонус-ключей. Если однажды
     * все рецепты получат оба ключа, тест не станет ложно-зелёным «просто потому что» —
     * он честно скажет, что предпосылка изменилась.
     */
    public function testConfigStillContainsRecipesWithoutBonusKeys(): void
    {
        $src = file_get_contents(APPPATH . 'Config/CraftRecipes.php');
        $this->assertIsString($src);

        // Рецепты разделены ключом 'task_name'; смотрим окно после каждого вхождения.
        $blocks = array_slice(preg_split("/'task_name'/", $src) ?: [], 1);
        $this->assertGreaterThan(50, count($blocks), 'ожидаем ~100 рецептов в конфиге');

        $missingAgility   = 0;
        $missingIntellect = 0;
        foreach ($blocks as $block) {
            $window = substr($block, 0, 2500);
            if (! str_contains($window, 'agility_bonus')) {
                $missingAgility++;
            }
            if (! str_contains($window, 'intellect_bonus')) {
                $missingIntellect++;
            }
        }

        $this->assertGreaterThan(
            0,
            $missingAgility + $missingIntellect,
            'предпосылка бага исчезла — перечитать тест, а не удалять страховку'
        );
    }
}
