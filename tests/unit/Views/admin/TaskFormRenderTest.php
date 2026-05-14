<?php

declare(strict_types=1);

namespace Tests\Unit\Views\Admin;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase D tail (2026-05-13) — view-level smoke на `_task_form.php` partial.
 *
 * @internal
 */
final class TaskFormRenderTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['form', 'url']);
    }

    public function testCreateRenders(): void
    {
        $html = view('admin/partials/_task_form', ['mode' => 'create']);

        $this->assertStringContainsString('admin/tasks/store', $html);
        $this->assertStringContainsString('Создать задачу', $html);
        $this->assertStringContainsString('name="type"', $html);
        $this->assertStringContainsString('Ежеминутно', $html, 'minutely option должна быть в unified');
        $this->assertStringContainsString('name="min_duration"', $html);
        $this->assertStringContainsString('name="parallel_execution_allowed"', $html);
        $this->assertStringContainsString('0 — без ограничений', $html, 'Hint только в create');
    }

    public function testEditRendersWithValues(): void
    {
        $task = [
            'id'                         => 17,
            'name'                       => 'TestTask',
            'name_rus'                   => 'Тест-задача',
            'type'                       => 'craft',
            'min_duration'               => 5,
            'max_duration'               => 15,
            'difficulty_level'           => 3,
            'execution_limit'            => 0,
            'parallel_execution_allowed' => 1,
            'interruptible'              => 0,
            'description'                => 'desc',
        ];

        $html = view('admin/partials/_task_form', ['mode' => 'edit', 'task' => $task]);

        $this->assertStringContainsString('admin/tasks/update/17', $html);
        $this->assertStringContainsString('Сохранить изменения', $html);
        $this->assertStringContainsString('value="TestTask"', $html);
        $this->assertStringContainsString('value="Тест-задача"', $html);
        $this->assertStringContainsString('selected>Крафт', $html, 'type=craft selected');
        $this->assertStringNotContainsString('0 — без ограничений', $html, 'Hint только в create, не в edit');
    }

    public function testCreateFormRendersRegisteredTaskHandlers(): void
    {
        $taskHandlers = [
            ['key' => 'marching',      'displayName' => 'Поход',          'description' => 'Цепочка 1-клеточных задач.'],
            ['key' => 'generic_craft', 'displayName' => 'Универс. крафт', 'description' => 'Все craft* через CraftRecipes.'],
        ];

        $html = view('admin/partials/_task_form', [
            'mode'         => 'create',
            'taskHandlers' => $taskHandlers,
        ]);

        $this->assertStringContainsString('Зарегистрированные task-handler', $html);
        $this->assertStringContainsString('marching', $html);
        $this->assertStringContainsString('Поход', $html);
        $this->assertStringContainsString('generic_craft', $html);
    }

    public function testCreateFormRendersWithoutTaskHandlersGracefully(): void
    {
        // Explicit empty — CI4 View::view() saveData=true → test pollution защита.
        $html = view('admin/partials/_task_form', ['mode' => 'create', 'taskHandlers' => []]);

        $this->assertStringContainsString('Создать задачу', $html);
        $this->assertStringNotContainsString('Зарегистрированные task-handler', $html);
    }

    public function testEditPreservesInterruptibleZero(): void
    {
        // Регресс: булевы 0 не должны теряться (legacy bug pattern).
        $task = [
            'id' => 1, 'name' => 'X', 'name_rus' => 'X', 'type' => 'quest',
            'min_duration' => 1, 'max_duration' => 1, 'difficulty_level' => 1,
            'execution_limit' => 5, 'parallel_execution_allowed' => 0, 'interruptible' => 0,
            'description' => '',
        ];

        $html = view('admin/partials/_task_form', ['mode' => 'edit', 'task' => $task]);

        // parallel=0 selected → option "Нет" должен быть selected
        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="parallel_execution_allowed"[^>]*>.*?<option value="0" selected>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="interruptible"[^>]*>.*?<option value="0" selected>/s',
            $html,
        );
    }
}
