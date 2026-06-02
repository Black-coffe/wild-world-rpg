<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NPC;

use App\Models\GameSettingsModel;
use App\Models\NpcDialogueModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\NPC\NpcInteractionService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-089 Фаза 1 — killswitch встреч + фолбэк реплик NpcInteractionService.
 *
 * @internal
 */
final class NpcInteractionServiceTest extends CIUnitTestCase
{
    private function settings(bool $on): GameSettingsService
    {
        $model = new class ($on) extends GameSettingsModel {
            public function __construct(private bool $on = false) {}

            public function findByKey(string $key): ?array
            {
                if ($key === 'npc.interaction_enabled') {
                    return [
                        'id' => 1, 'setting_key' => $key, 'value_type' => 'bool',
                        'value_bool' => $this->on ? 1 : 0, 'hard_min' => null, 'hard_max' => null,
                    ];
                }
                return null;
            }
        };

        return new GameSettingsService($model);
    }

    /** Диалог-модель, отдающая канон-строку только для talk, иначе null (→ фолбэк). */
    private function dialogues(): NpcDialogueModel
    {
        return new class extends NpcDialogueModel {
            public function __construct() {}

            public function lineFor(int $npcId, string $type): ?string
            {
                return $type === 'talk' ? 'Канон-реплика из БД.' : null;
            }
        };
    }

    public function testKillswitchOffByDefaultStyle(): void
    {
        $svc = new NpcInteractionService($this->settings(false), null, null, $this->dialogues());
        $this->assertFalse($svc->enabled());
    }

    public function testKillswitchOn(): void
    {
        $svc = new NpcInteractionService($this->settings(true), null, null, $this->dialogues());
        $this->assertTrue($svc->enabled());
    }

    public function testLineUsesDbWhenPresent(): void
    {
        $svc = new NpcInteractionService($this->settings(true), null, null, $this->dialogues());
        $this->assertSame('Канон-реплика из БД.', $svc->line(1, 'talk'));
    }

    public function testQuestgiverDisabledByDefaultStyle(): void
    {
        $svc = new NpcInteractionService($this->settings(false), null, null, $this->dialogues());
        $this->assertFalse($svc->questgiverEnabled());
    }

    public function testNoOfferedQuestWhenQuestgiverOff(): void
    {
        // questgiver OFF → offeredQuestFor возвращает null до обращения к моделям.
        $svc = new NpcInteractionService($this->settings(false), null, null, $this->dialogues());
        $this->assertNull($svc->offeredQuestFor(1, 4));
    }

    public function testLineFallsBackWhenAbsent(): void
    {
        $svc = new NpcInteractionService($this->settings(true), null, null, $this->dialogues());
        // greeting нет в БД-стабе → непустой скупой фолбэк.
        $greeting = $svc->line(1, 'greeting');
        $this->assertNotSame('', $greeting);
        $this->assertStringContainsString('Незнакомец', $greeting);
        // неизвестный тип → '…' (не пусто).
        $this->assertSame('…', $svc->line(1, 'whatever'));
    }
}
