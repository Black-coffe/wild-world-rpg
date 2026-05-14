<?php

declare(strict_types=1);

namespace Tests\Unit\Views\Admin;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase B5 (ADR-023, 2026-05-14) — smoke на `_handler_options_panel.php`
 * partial (рендер info-панели «зарегистрированные handlers» в admin-формах).
 *
 * @internal
 */
final class HandlerOptionsPanelTest extends CIUnitTestCase
{
    /**
     * @return list<array{key: string, displayName: string, description: string}>
     */
    private function fixtureOptions(): array
    {
        return [
            ['key' => 'foo_handler', 'displayName' => 'Foo Display', 'description' => 'Foo описание handler-а.'],
            ['key' => 'bar_handler', 'displayName' => 'Bar Display', 'description' => 'Bar короткое описание.'],
        ];
    }

    public function testRendersWithOptions(): void
    {
        $html = view('admin/partials/_handler_options_panel', [
            'options' => $this->fixtureOptions(),
            'title'   => 'Test Panel',
            'hint'    => 'Test hint subtitle',
            'emoji'   => '🎲',
        ]);

        $this->assertStringContainsString('Test Panel', $html);
        $this->assertStringContainsString('Test hint subtitle', $html);
        $this->assertStringContainsString('foo_handler', $html);
        $this->assertStringContainsString('Foo Display', $html);
        $this->assertStringContainsString('Foo описание handler-а.', $html);
        $this->assertStringContainsString('bar_handler', $html);
        $this->assertStringContainsString('Bar Display', $html);
        $this->assertStringContainsString('🎲', $html);
        // Count badge: «(2)»
        $this->assertStringContainsString('(2)', $html);
    }

    public function testRendersEmptyWhenNoOptions(): void
    {
        $html = view('admin/partials/_handler_options_panel', [
            'options' => [],
            'title'   => 'Empty Panel',
            'hint'    => '',
            'emoji'   => '🌐',
        ]);

        // Title/hint/emoji не рендерятся когда options пуст (no panel).
        $this->assertStringNotContainsString('Empty Panel', $html);
        $this->assertStringNotContainsString('alert-info', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testEscapesHtmlInOptionFields(): void
    {
        $html = view('admin/partials/_handler_options_panel', [
            'options' => [
                ['key' => '<script>x', 'displayName' => 'Bad <name>', 'description' => 'Description & co.'],
            ],
            'title'   => 'XSS Test',
            'hint'    => '',
            'emoji'   => '',
        ]);

        $this->assertStringNotContainsString('<script>x', $html);
        $this->assertStringContainsString('&lt;script&gt;x', $html);
        $this->assertStringContainsString('Bad &lt;name&gt;', $html);
        $this->assertStringContainsString('Description &amp; co.', $html);
    }
}
