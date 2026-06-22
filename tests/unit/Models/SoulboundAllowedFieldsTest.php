<?php

namespace Tests\Unit\Models;

use App\Models\CharactersOutfitsModel;
use App\Models\CharactersWeaponsModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * WB2 (ADR-137 «Узлы») — анти-дрифт гард: `is_soulbound` обязан быть в `$allowedFields`
 * обеих gear-моделей. Урок disable_media/last_planted_crop: ALTER-колонка без allowedFields
 * молча отфильтровывается CI4 при update → DataException «no data to update». Этот тест
 * ловит регресс на уровне unit (E2E ловит дороже).
 *
 * @internal
 */
final class SoulboundAllowedFieldsTest extends CIUnitTestCase
{
    public function testWeaponsModelAllowsIsSoulbound(): void
    {
        $this->assertContains('is_soulbound', $this->allowedFields(new CharactersWeaponsModel()));
    }

    public function testOutfitsModelAllowsIsSoulbound(): void
    {
        $this->assertContains('is_soulbound', $this->allowedFields(new CharactersOutfitsModel()));
    }

    /**
     * @return list<string>
     */
    private function allowedFields(object $model): array
    {
        $ref = new \ReflectionProperty($model, 'allowedFields');
        $ref->setAccessible(true);
        $val = $ref->getValue($model);
        $out = [];
        if (is_array($val)) {
            foreach ($val as $f) {
                $out[] = (string) $f;
            }
        }

        return $out;
    }
}
