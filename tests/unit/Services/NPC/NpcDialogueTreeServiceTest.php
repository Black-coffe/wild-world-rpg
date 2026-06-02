<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NPC;

use App\Models\NpcDialogueNodeModel;
use App\Services\NPC\NpcDialogueTreeService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-089 Фаза 5+ — декодирование узлов ветвящегося диалога.
 *
 * @internal
 */
final class NpcDialogueTreeServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string,mixed>|null $node
     */
    private function tree(?array $node): NpcDialogueTreeService
    {
        $model = new class ($node) extends NpcDialogueNodeModel {
            /** @param array<string,mixed>|null $node */
            public function __construct(private ?array $node) {}

            /**
             * @return array<string,mixed>|null
             */
            public function nodeFor(int $npcId, string $nodeKey): ?array
            {
                return $this->node;
            }

            public function hasTree(int $npcId): bool
            {
                return $this->node !== null;
            }
        };

        return new NpcDialogueTreeService($model);
    }

    public function testNodeDecodesOptions(): void
    {
        $svc = $this->tree([
            'text_ru' => 'Говори.',
            'options' => json_encode([
                ['label' => 'Кто ты?', 'next' => 'about', 'rel' => 2],
                ['label' => 'Уйти', 'next' => '', 'rel' => 0],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $node = $svc->node(1, 'root');
        $this->assertNotNull($node);
        $this->assertSame('Говори.', $node['text']);
        $this->assertCount(2, $node['options']);
        $this->assertSame('Кто ты?', $node['options'][0]['label']);
        $this->assertSame('about', $node['options'][0]['next']);
        $this->assertSame(2, $node['options'][0]['rel']);
        $this->assertSame('', $node['options'][1]['next']);
    }

    public function testNodeNullWhenMissing(): void
    {
        $this->assertNull($this->tree(null)->node(1, 'root'));
    }

    public function testNodeHandlesEmptyOptions(): void
    {
        $svc  = $this->tree(['text_ru' => 'Конец.', 'options' => null]);
        $node = $svc->node(1, 'leaf');
        $this->assertNotNull($node);
        $this->assertSame('Конец.', $node['text']);
        $this->assertSame([], $node['options']);
    }
}
