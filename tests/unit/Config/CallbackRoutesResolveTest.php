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
    /**
     * Топ игроков (2026-07-10): обе вкладки и кнопка возврата обязаны резолвиться,
     * иначе экран отвечает «кнопка устарела» (класс-бага мёртвых `npcAct_`).
     */
    public function testLeaderboardTabsResolve(): void
    {
        foreach (['leaderboard', 'leaderboardLegends', 'progressHub'] as $action) {
            $this->assertNotNull(
                $this->cbRoutes->resolve($action),
                "Callback «{$action}» не резолвится — кнопка экрана «Топ игроков» будет мёртвой."
            );
        }
    }

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
     * E28 Ф2 — переключатель сортировки складов: callback `resourcesGathered_sort_<mode>` и
     * `resourcesCrafting_sort_<mode>` резолвятся по ПЕРВОМУ сегменту (`explode('_')[0]`) в
     * exact-роут, а хвост `_sort_<mode>` разбирает сам action. Гейтит, что оба экрана
     * остаются достижимы при добавлении/смене сорт-режимов.
     */
    public function testInventorySortCallbacksResolveByFirstSegment(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\ResourcesGatheredAction::class,
            $this->cbRoutes->resolve(explode('_', 'resourcesGathered_sort_rarity')[0]),
            'resourcesGathered_sort_* обязан резолвиться в ResourcesGatheredAction.'
        );
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\CraftedResourcesAction::class,
            $this->cbRoutes->resolve(explode('_', 'resourcesCrafting_sort_value')[0]),
            'resourcesCrafting_sort_* обязан резолвиться в CraftedResourcesAction.'
        );
    }

    /**
     * ADR-132 Ф2 — кнопка «🔥 Серия выживания» (хаб «📊 Прогресс») шлёт callback `streakScreen`.
     * Гейтит, что он резолвится в StreakScreenAction (иначе мёртвая кнопка — урок npcAct_/inlineMap).
     */
    public function testStreakScreenCallbackRouteResolves(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Streak\StreakScreenAction::class,
            $this->cbRoutes->resolve('streakScreen'),
            'callback_data «streakScreen» обязан резолвиться в StreakScreenAction (кнопка «🔥 Серия выживания»).'
        );
    }

    /**
     * E28 / ADR-137 «Узлы» — тумблеры «Сводка с пустоши» (`nodeAnnounceOn`/`nodeAnnounceOff`,
     * SettingsAction) обязаны резолвиться в SettingsAction, как соседние тумблеры
     * (mediaOn/Off, notifySoundOn/Off). Латентный мёртвый-баг: тумблер рендерится только
     * при `world.nodes.announce_enabled` (dormant), поэтому его deadness не вскрылась —
     * без роута клик отдал бы «кнопка устарела». Гейтим на resolve (не throwaway-тап).
     */
    public function testNodeAnnounceToggleCallbackRoutesResolve(): void
    {
        foreach (['nodeAnnounceOn', 'nodeAnnounceOff'] as $cb) {
            $this->assertSame(
                \App\Controllers\Telegram\Commands\Actions\SettingsAction::class,
                $this->cbRoutes->resolve($cb),
                "callback_data «{$cb}» обязан резолвиться в SettingsAction (тумблер «Сводка с пустоши»)."
            );
        }
    }

    /**
     * Slice 1 (ресурс-грамотность) — exact-роут 'resourceOverview' (экран «📊 Все мои
     * ресурсы», кнопка на хабе «Инвентарь» при killswitch inventory.overview.enabled).
     * Урок control-tap: новый callback-ключ фиксируем resolve-гейтом на unit-уровне —
     * баг регистрации роута пережил бы throwaway-вызов (как npcAct_).
     */
    public function testResourceOverviewCallbackRouteResolves(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\ResourceOverviewAction::class,
            $this->cbRoutes->resolve('resourceOverview'),
            'callback_data «resourceOverview» обязан резолвиться в ResourceOverviewAction (кнопка «📊 Все мои ресурсы»).'
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
     * WB6 (ADR-137 «Узлы») — бой с узлом: callback `nodeAct_<verb>_<bossPointId>` → первый сегмент
     * 'nodeAct' → BossEncounterAction. Тот же class-of-bug, что npcAct_ (мёртвый prefix с `_`):
     * фиксируем, что ВСЕ боевые ветки резолвятся в правильный обработчик (живой роутинг до активации).
     */
    public function testNodeBossCallbackRoutesResolve(): void
    {
        $expected = \App\Controllers\Telegram\Commands\Actions\NPC\BossEncounterAction::class;
        foreach (['look', 'start', 'force', 'atk', 'def', 'spec', 'item', 'flee'] as $verb) {
            $this->assertSame(
                $expected,
                $this->cbRoutes->resolve(explode('_', "nodeAct_{$verb}_42")[0]),
                "nodeAct_{$verb}_* должен резолвиться в BossEncounterAction"
            );
        }
        // не коллизирует с npcAct (другой обработчик)
        $this->assertNotSame(
            $expected,
            $this->cbRoutes->resolve(explode('_', 'npcAct_talk_1')[0])
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
     * ADR-101 поселения — exact-роуты 'settleHub' (экран-хаб) и 'ruinLoot' (обыск руин, Ф4).
     * Фиксируем, что услуги хаба ведут на живые роуты (не мёртвый callback, как npcAct_/inlineMap).
     */
    public function testSettlementCallbackRoutesResolve(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Settlement\SettlementHubAction::class,
            $this->cbRoutes->resolve('settleHub'),
            'callback_data «settleHub» обязан резолвиться в SettlementHubAction (вход в поселение).'
        );

        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Settlement\RuinLootAction::class,
            $this->cbRoutes->resolve('ruinLoot'),
            'callback_data «ruinLoot» обязан резолвиться в RuinLootAction (услуга «Обыскать руины», Ф4).'
        );

        // ADR-101 Ф3 рефайн — фракционная лавка: список 'settleShop' + покупка 'settleShopBuy_id={id}'
        // (хвост `_id=`/`_go` → тот же первый сегмент). Class-of-bug npcAct_ — фиксируем, что роуты живы.
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Settlement\SettlementShopAction::class,
            $this->cbRoutes->resolve('settleShop'),
            'callback_data «settleShop» обязан резолвиться в SettlementShopAction (список лавки оплота).'
        );
        foreach (['settleShopBuy_id=5', 'settleShopBuy_id=5_go'] as $callbackData) {
            $this->assertSame(
                \App\Controllers\Telegram\Commands\Actions\Settlement\SettlementShopBuyAction::class,
                $this->cbRoutes->resolve(explode('_', $callbackData)[0]),
                "callback_data «{$callbackData}» обязан резолвиться в SettlementShopBuyAction (покупка)."
            );
        }
    }

    /**
     * E19 (ADR-119) музей — exact 'museum'/'museumLocked' + prefix 'collOpen_<id>'/'collDonate_<slotId>'.
     * Class-of-bug npcAct_ (мёртвый prefix с `_`): фиксируем, что хвосты резолвятся по первому сегменту.
     */
    public function testCollectionCallbackRoutesResolve(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Collections\CollectionsAction::class,
            $this->cbRoutes->resolve('museum'),
            'callback_data «museum» обязан резолвиться в CollectionsAction (вход в музей).'
        );
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Collections\CollectionsAction::class,
            $this->cbRoutes->resolve('museumLocked'),
            'callback_data «museumLocked» (lock < L50) обязан резолвиться в CollectionsAction.'
        );
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Collections\CollectionOpenAction::class,
            $this->cbRoutes->resolve(explode('_', 'collOpen_1')[0]),
            'callback_data «collOpen_<id>» обязан резолвиться в CollectionOpenAction.'
        );
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Collections\CollectionDonateAction::class,
            $this->cbRoutes->resolve(explode('_', 'collDonate_5')[0]),
            'callback_data «collDonate_<slotId>» обязан резолвиться в CollectionDonateAction.'
        );
    }

    /**
     * E20 (ADR-120) «Ангар» — exact-роут 'hangar' (хаб автоматизации на экране базы).
     * Урок control-tap: новый callback-ключ фиксируем resolve-гейтом на unit-уровне.
     */
    public function testHangarCallbackRouteResolves(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Camp\HangarAction::class,
            $this->cbRoutes->resolve('hangar'),
            'callback_data «hangar» обязан резолвиться в HangarAction (хаб автоматизации).'
        );
    }

    /**
     * ADR-127 «Путь новичка» — exact-роут 'guide'; и оглавление (`guide`), и разделы
     * (`guide_<key>`) дают первый сегмент 'guide' → один обработчик GuideAction. Тот же
     * class-of-bug, что npcAct_ (мёртвый prefix с `_`): фиксируем, что роут жив для
     * оглавления и для каждого раздела.
     */
    public function testGuideCallbackRoutesResolve(): void
    {
        $expected = \App\Controllers\Telegram\Commands\Actions\Guide\GuideAction::class;

        $callbacks = ['guide'];
        foreach (\App\Services\Onboarding\GuideCatalog::sections() as $section) {
            $callbacks[] = 'guide_' . $section['key'];
        }

        foreach ($callbacks as $callbackData) {
            $this->assertSame(
                $expected,
                $this->cbRoutes->resolve(explode('_', $callbackData)[0]),
                "callback_data «{$callbackData}» обязан резолвиться в GuideAction."
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

    /**
     * ADR-129 «Обыскать стратег-объект» — exact-роут 'strategicSearch' (кнопка на экране
     * движения, когда на клетке active strategic-объект; радиосигнал ADR-098 ведёт сюда).
     * Урок control-tap: новый callback-ключ фиксируем resolve-гейтом на unit-уровне —
     * баг регистрации роута пережил бы throwaway-вызов (как npcAct_).
     */
    public function testStrategicSearchCallbackRouteResolves(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\World\StrategicSearchAction::class,
            $this->cbRoutes->resolve('strategicSearch'),
            'callback_data «strategicSearch» обязан резолвиться в StrategicSearchAction (кнопка «🔍 Обыскать»).'
        );
    }

    /**
     * S4 (ADR-139, слайс 2) «Прогрессивное раскрытие» — lock-кнопка уровневой постройки:
     * callback `buildLocked_<Key>` → первый сегмент 'buildLocked' → BuildLockedAction. Тот же
     * class-of-bug, что npcAct_ (мёртвый prefix с `_`): фиксируем resolve, не коллизирует с
     * соседними 'building'/'genericBuildInfo'. Кнопка рендерится при onboarding.cold_open_v2.build_locks.
     */
    public function testBuildLockedCallbackRouteResolves(): void
    {
        $expected = \App\Controllers\Telegram\Commands\Actions\Camp\BuildLockedAction::class;
        foreach (['buildLocked_Arsenal', 'buildLocked_RoboticsWorkshop', 'buildLocked_Gym'] as $cb) {
            $this->assertSame(
                $expected,
                $this->cbRoutes->resolve(explode('_', $cb)[0]),
                "callback_data «{$cb}» обязан резолвиться в BuildLockedAction (lock-кнопка постройки)."
            );
        }
        // не коллизирует с соседними build-роутами (другие обработчики)
        $this->assertNotSame($expected, $this->cbRoutes->resolve('building'));
        $this->assertNotSame($expected, $this->cbRoutes->resolve(explode('_', 'genericBuildInfo_Arsenal')[0]));
    }

    /**
     * ADR-150 Слайс 3 — хаб «📋 Дела»: callback `tasksHub` → TasksHubAction. Соседний
     * `finishAllTasks_<id>` (прерывание задачи с экрана) обязан по-прежнему уходить в
     * FinishTaskAction — префиксы не должны перехватывать друг друга.
     */
    public function testTasksHubCallbackRouteResolves(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\TasksHubAction::class,
            $this->cbRoutes->resolve('tasksHub'),
            'callback_data «tasksHub» обязан резолвиться в TasksHubAction (хаб «Дела»).'
        );

        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\FinishTaskAction::class,
            $this->cbRoutes->resolve(explode('_', 'finishAllTasks_42')[0]),
            'Кнопка «⛔️ Прервать» с экрана «Дела» обязана резолвиться в FinishTaskAction.'
        );
    }

    /**
     * ADR-150 Слайс 4 — хаб «⚙️ Ещё»: callback `moreHub` → MoreHubAction. Плюс КАЖДАЯ кнопка,
     * которую рендерит экран «Ещё», обязана резолвиться — иначе получим мёртвую кнопку
     * (class-of-bug `npcAct_`, пойманный только живым тапом).
     */
    public function testMoreHubCallbackRoutesResolve(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\MoreHubAction::class,
            $this->cbRoutes->resolve('moreHub'),
            'callback_data «moreHub» обязан резолвиться в MoreHubAction (хаб «Ещё»).'
        );

        // Все выходы экрана «Ещё» (первый сегмент до `_`, как это делает CallbackqueryCommand).
        foreach ([
            'shop', 'arena', 'entertainment', 'chooseFaction_info', 'chooseFactionLocked',
            'factionProject', 'referral', 'tributeStatus', 'guide', 'whatsNewCatalog',
            'settings', 'move',
        ] as $cb) {
            $this->assertNotNull(
                $this->cbRoutes->resolve(explode('_', $cb)[0]),
                "Кнопка «{$cb}» с экрана «Ещё» не резолвится — мёртвая кнопка."
            );
        }
    }

    /**
     * ADR-150 Слайс 5 — «🔨 Крафт / 🏠 База»: сущности возвращаются в свои группы.
     * Ремонт ИНСТРУМЕНТОВ появляется в Крафте, склад — на Базе, снос/переезд схлопнуты
     * в существующую дверь `DeleteBase`. Все кнопки обязаны резолвиться.
     */
    public function testCraftBaseSliceButtonsResolve(): void
    {
        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Craft\Repair\RepairToolsListAction::class,
            $this->cbRoutes->resolve('repairToolsList'),
            'Кнопка «🪛 Ремонт инструментов» на экране Крафта обязана вести в RepairToolsListAction.'
        );

        $this->assertSame(
            \App\Controllers\Telegram\Commands\Actions\Storage\BaseStorageListAction::class,
            $this->cbRoutes->resolve('baseStorageList'),
            'Кнопка «📦 Склад базы» на экране Базы обязана вести в BaseStorageListAction.'
        );

        // «⚠️ Снести / переехать» ведёт в существующую дверь DeleteBase (три варианта с числами).
        // Её же суффикс `DeleteBase_FullRelocation` обязан резолвиться в тот же handler.
        $deleteBase = \App\Controllers\Telegram\Commands\Actions\Camp\DeleteBaseAction::class;
        $this->assertSame($deleteBase, $this->cbRoutes->resolve('DeleteBase'));
        $this->assertSame($deleteBase, $this->cbRoutes->resolve(explode('_', 'DeleteBase_FullRelocation')[0]));
        $this->assertSame($deleteBase, $this->cbRoutes->resolve(explode('_', 'DeleteBase_InstantDemolition')[0]));
    }
}
