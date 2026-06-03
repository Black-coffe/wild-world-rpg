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
     * 🔴 Регресс v0.51.342+ (sweep `inlineMap`→`move`): кнопка «🗺 Карта» во встречах,
     * караване, дронах, дуэли и складе слала callback_data `inlineMap`, который НИКОГДА
     * не был зарегистрирован → unrouted → мёртвая кнопка (спиннер висит). Рабочий роут —
     * exact `move` (→ MoveCharacterAction, рендер карты 12×12). Этот тест фиксирует, что
     * `move` резолвится, а мёртвый `inlineMap` — нет (документирует, почему его убрали).
     */
    public function testMapButtonUsesResolvableMoveRoute(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\MoveCharacterAction::class,
            $this->cbRoutes->resolve('move'),
            'callback_data «move» обязан резолвиться в MoveCharacterAction (кнопка «🗺 Карта»).'
        );

        $this->assertNull(
            $this->cbRoutes->resolve('inlineMap'),
            'callback_data «inlineMap» не зарегистрирован — мёртвая кнопка. Используй «move».'
        );
    }

    /**
     * 🔴 Анти-дрифт source-scan: мёртвый литерал `inlineMap` не должен вернуться в код
     * Actions. Class-of-bug «unrouted callback» уже бил дважды (npcAct_ + inlineMap);
     * урок feedback_control_tap_not_throwaway — гейтить на resolve, а не на throwaway-тап.
     */
    public function testNoDeadInlineMapLiteralInActions(): void
    {
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                APPPATH . 'Controllers/Telegram/Commands/Actions',
                \FilesystemIterator::SKIP_DOTS
            )
        );

        $offenders = [];
        foreach ($dir as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (str_contains($contents, 'inlineMap')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Мёртвый callback_data «inlineMap» вернулся в: ' . implode(', ', $offenders)
            . '. Замени на «move» (exact-роут → MoveCharacterAction).'
        );
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

    /**
     * ADR-093 «Перемешать ресурсы» — exact-роут 'ShuffleResources'; все хвосты
     * (rarity_/go_/restart) дают тот же первый сегмент → один обработчик. Тот же
     * class-of-bug, что npcAct_ (мёртвый prefix с `_`): фиксируем, что роут жив.
     */
    public function testShuffleResourcesCallbackRoutesResolve(): void
    {
        $expected = \App\Controllers\Telegram\Commands\Actions\Games\ShuffleResourcesAction::class;

        foreach ([
            'ShuffleResources',                 // вход (меню редкостей)
            'ShuffleResources_rarity_5',        // выбор редкости → меню количеств
            'ShuffleResources_go_5_25',         // выполнить перемешивание
            'ShuffleResources_restart',         // назад на выбор редкости
        ] as $callbackData) {
            $this->assertSame(
                $expected,
                $this->cbRoutes->resolve(explode('_', $callbackData)[0]),
                "callback_data «{$callbackData}» обязан резолвиться в ShuffleResourcesAction."
            );
        }
    }

    /**
     * ADR-096 «Оптовая продажа» — exact-роут 'bulkSell'; все хвосты (all_/rarity_/go_)
     * дают тот же первый сегмент → один обработчик. Тот же class-of-bug, что npcAct_
     * (мёртвый prefix с `_`): фиксируем, что роут жив для предпросмотра и выполнения.
     */
    public function testBulkSellCallbackRoutesResolve(): void
    {
        $expected = \App\Controllers\Telegram\Commands\Actions\Sell\BulkSellAction::class;

        foreach ([
            'bulkSell_all_10',        // предпросмотр: все ресурсы
            'bulkSell_all_50',
            'bulkSell_rarity_3_25',   // предпросмотр: редкость 3
            'bulkSell_go_all_50',     // выполнить: все
            'bulkSell_go_rarity_7_10', // выполнить: редкость 7
        ] as $callbackData) {
            $this->assertSame(
                $expected,
                $this->cbRoutes->resolve(explode('_', $callbackData)[0]),
                "callback_data «{$callbackData}» обязан резолвиться в BulkSellAction."
            );
        }
    }
}
