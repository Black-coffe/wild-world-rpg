<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NPC;

use App\Models\NpcDialogueNodeModel;
use App\Services\NPC\NpcDialogueTreeService;
use App\Services\NPC\NpcRelationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-089 Phase 6 — тесты ветвящегося диалога: парс новых полей опции (gate, act-*)
 * и гейтинг реплик по ступени отношения (standing).
 */
final class NpcDialoguePhase6Test extends CIUnitTestCase
{
    public function testNodeParsesGateAndActOutcomes(): void
    {
        $nodes = $this->createMock(NpcDialogueNodeModel::class);
        $nodes->method('nodeFor')->willReturn([
            'text_ru' => 'Корень.',
            'options' => json_encode([
                ['label' => 'Лор', 'next' => 'about', 'rel' => 2],
                ['label' => 'Есть работа?', 'next' => 'act-quest', 'rel' => 1],
                ['label' => 'Доверенная реплика', 'next' => 'deep', 'rel' => 3, 'gate' => 'ally'],
                ['label' => 'Провокация', 'next' => 'act-provoke', 'rel' => -30],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $svc  = new NpcDialogueTreeService($nodes);
        $node = $svc->node(1, 'root');

        $this->assertNotNull($node);
        $this->assertSame('Корень.', $node['text']);
        $this->assertCount(4, $node['options']);

        // act-* исход сохраняется в next как есть (диспатчится хендлером).
        $this->assertSame('act-quest', $node['options'][1]['next']);
        $this->assertSame('act-provoke', $node['options'][3]['next']);

        // gate парсится; отсутствие gate → пустая строка (опция видна всегда).
        $this->assertSame('ally', $node['options'][2]['gate']);
        $this->assertSame('', $node['options'][0]['gate']);
        $this->assertSame('', $node['options'][1]['gate']);
    }

    public function testNodeReturnsNullWhenAbsent(): void
    {
        $nodes = $this->createMock(NpcDialogueNodeModel::class);
        $nodes->method('nodeFor')->willReturn(null);

        $this->assertNull((new NpcDialogueTreeService($nodes))->node(1, 'missing'));
    }

    /**
     * meetsStanding fail-open: пустой гейт и неизвестный тир → опция видна всегда
     * (ранний возврат ДО обращения к settings/DB). Сравнение по score — в Tier-3 (нужна БД).
     */
    public function testMeetsStandingFailOpen(): void
    {
        $rel = new NpcRelationService();

        $this->assertTrue($rel->meetsStanding(1, 1, ''), 'пустой гейт = всегда видно');
        $this->assertTrue($rel->meetsStanding(1, 1, 'definitely-not-a-tier'), 'неизвестный тир = fail-open');
    }
}
