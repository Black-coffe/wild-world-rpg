<?php

declare(strict_types=1);

namespace Tests\Unit\Views\Admin;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase D tail (2026-05-13) — view-level smoke на `_quest_form.php` partial.
 *
 * Покрывает bonus-fix: в legacy create-форме был дубль `name="reward"`
 * (select + number-input одновременно). После unification: `reward` (numeric)
 * и `reward_type` (select) — разделены.
 *
 * @internal
 */
final class QuestFormRenderTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
    }

    public function testCreateRenders(): void
    {
        $html = view('admin/partials/_quest_form', ['mode' => 'create']);

        $this->assertStringContainsString('admin/quests/store', $html);
        $this->assertStringContainsString('Добавить квест', $html);
        $this->assertStringContainsString('name="title_ru"', $html);
        $this->assertStringContainsString('name="title_en"', $html);
        $this->assertStringContainsString('name="reward"', $html);
        $this->assertStringContainsString('name="reward_type"', $html);
        $this->assertStringContainsString('name="status"', $html);
    }

    public function testCreateHasNoDuplicateNameReward(): void
    {
        // Bonus-fix регресс: legacy форма имела дубль name="reward" (select + number).
        // После unification — должен быть РОВНО один input/select с name="reward".
        $html = view('admin/partials/_quest_form', ['mode' => 'create']);

        $rewardCount = preg_match_all('/\sname="reward"/', $html);
        $this->assertSame(1, $rewardCount, 'Только одно поле с name="reward" (number); тип награды — name="reward_type"');
    }

    public function testEditRendersWithValues(): void
    {
        $quest = [
            'id'          => 42,
            'title_ru'    => 'Тестовый квест',
            'title_en'    => 'Test Quest',
            'status'      => 'available',
            'reward'      => 250,
            'reward_type' => 'gold',
            'min_level'   => 5,
            'description' => 'Quest description',
        ];

        $html = view('admin/partials/_quest_form', ['mode' => 'edit', 'quest' => $quest]);

        $this->assertStringContainsString('admin/quests/update/42', $html);
        $this->assertStringContainsString('Обновить квест', $html);
        $this->assertStringContainsString('Тестовый квест', $html);
        $this->assertStringContainsString('value="250"', $html);
        $this->assertStringContainsString('selected>Доступный', $html);
        $this->assertStringContainsString('selected>Золото', $html);
    }
}
