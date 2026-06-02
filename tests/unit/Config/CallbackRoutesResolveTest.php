<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Config\CallbackRoutes;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Регресс-гейт роутинга inline-callback'ов.
 *
 * Прод-баг 2026-06-02 (ADR-089): ключи `npcAct_` / `npcDlg_` были зарегистрированы
 * в $prefixRoutes С хвостовым `_`. Но CallbackqueryCommand резолвит по ПЕРВОМУ
 * сегменту `explode('_', data)[0]` (никогда не содержит `_`), а resolve() матчит
 * через str_starts_with($action, $prefix). Для 'npcAct' vs ключа 'npcAct_'
 * (длиннее сегмента) str_starts_with всегда FALSE → все кнопки встречи с NPC
 * были мёртвыми (спиннер висит, 0 реакции). Пойман живым тапом, не Tier-3.
 *
 * Инвариант: ни один ключ $prefixRoutes НЕ может содержать `_`.
 */
final class CallbackRoutesResolveTest extends CIUnitTestCase
{
    private CallbackRoutes $cbRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cbRoutes = new CallbackRoutes();
    }

    /**
     * 🔴 Анти-дрифт: prefix-ключ с `_` НИКОГДА не матчится (action = первый сегмент).
     */
    public function testNoPrefixRouteKeyContainsUnderscore(): void
    {
        foreach (array_keys($this->cbRoutes->prefixRoutes) as $key) {
            $this->assertStringNotContainsString(
                '_',
                (string) $key,
                "prefixRoutes ключ '{$key}' содержит '_' — он никогда не сматчится, "
                . 'т.к. resolve() получает первый сегмент explode(\'_\')[0]. Убери \'_\'.'
            );
        }
    }

    /**
     * NPC-роуты встречи/диалога резолвятся по первому сегменту реального callback_data.
     */
    public function testNpcCallbackRoutesResolve(): void
    {
        // callback `npcAct_<action>_<spawnId>` → первый сегмент 'npcAct'
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\NPC\NpcActionChoiceAction::class,
            $this->cbRoutes->resolve(explode('_', 'npcAct_talk_843969')[0])
        );

        // callback `npcDlg_<spawnId>_<nodeKey>_<rel>` → первый сегмент 'npcDlg'
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\NPC\NpcDialogueAction::class,
            $this->cbRoutes->resolve(explode('_', 'npcDlg_843969_start_0')[0])
        );

        // экран встречи — exact-роут
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\NPC\NpcEncounterAction::class,
            $this->cbRoutes->resolve('npcEncounter')
        );
    }
}
