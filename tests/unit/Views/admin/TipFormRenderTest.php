<?php

declare(strict_types=1);

namespace Tests\Unit\Views\Admin;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase D tail (2026-05-13) — view-level smoke на `_tip_form.php` partial.
 *
 * @internal
 */
final class TipFormRenderTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
    }

    public function testCreateRenders(): void
    {
        $html = view('admin/partials/_tip_form', ['mode' => 'create']);

        $this->assertStringContainsString('admin/tips/store', $html);
        $this->assertStringContainsString('Создать совет', $html);
        $this->assertStringContainsString('name="title_ru"', $html);
        $this->assertStringContainsString('name="title_en"', $html);
        $this->assertStringContainsString('name="tip_type"', $html);
        $this->assertStringContainsString('name="content"', $html);
        // tip_type — русские ENUM values (Phase C tail для нормализации)
        $this->assertStringContainsString('value="биомы"', $html);
        $this->assertStringContainsString('value="ресурсы"', $html);
        $this->assertStringContainsString('value="крафт"', $html);
        $this->assertStringContainsString('value="NPC"', $html);
        $this->assertStringContainsString('value="общие"', $html);
        // ADR-038 Фаза A — расширенный enum категорий (V1-V13 покрытие).
        $this->assertStringContainsString('value="земледелие"', $html);
        $this->assertStringContainsString('value="еда"', $html);
        $this->assertStringContainsString('value="квесты"', $html);
        $this->assertStringContainsString('value="фракции"', $html);
        $this->assertStringContainsString('value="бой"', $html);
        $this->assertStringContainsString('value="эндгейм"', $html);
        $this->assertStringContainsString('value="настройки"', $html);
    }

    public function testEditRendersWithValues(): void
    {
        $tip = [
            'id'       => 17,
            'title_ru' => 'Совет про крафт',
            'title_en' => 'Crafting tip',
            'tip_type' => 'крафт',
            'content'  => 'Содержимое совета про крафт',
        ];

        $html = view('admin/partials/_tip_form', ['mode' => 'edit', 'tip' => $tip]);

        $this->assertStringContainsString('admin/tips/update/17', $html);
        $this->assertStringContainsString('Обновить совет', $html);
        $this->assertStringContainsString('value="Совет про крафт"', $html);
        $this->assertStringContainsString('value="Crafting tip"', $html);
        $this->assertStringContainsString('selected>Крафт', $html);
        $this->assertStringContainsString('Содержимое совета про крафт', $html);
    }

    public function testEditPreservesNpcType(): void
    {
        // NPC — латиница, не должно быть путаницы с другими значениями.
        $tip = ['id' => 1, 'title_ru' => 'X', 'title_en' => 'X', 'tip_type' => 'NPC', 'content' => ''];
        $html = view('admin/partials/_tip_form', ['mode' => 'edit', 'tip' => $tip]);
        $this->assertStringContainsString('<option value="NPC" selected>NPC</option>', $html);
    }
}
