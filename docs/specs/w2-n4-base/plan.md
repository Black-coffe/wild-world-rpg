# W2.N4 — база и постройки на нейтральном ядре: обзор, стройка, апгрейд (plan)

**Tier:** 2 · **Spec slug:** `w2-n4-base` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-189 (мост как фолбэк), ADR-181 (условные записи), ADR-171 (пул рюкзак+склад), ADR-102 (база-цель стройки в `task_settings.base_cell`), ADR-122 (налог per-base), ADR-187 (маяки), ADR-062, ADR-024, ADR-020
**Depends on:** w2-n3-craft-flags (v0.51.680); `WebNativeScreenService` VIEW_*/OP_*, `intentKey()`, `op=bridge`, `site/_play/{hud,dock}.php`, дедуп `web_play_intents`; multibase-picker (v0.51.669): `BaseCallbackSuffix`, `BaseScopeResolver::resolveForBase`

## Goal
Экраны базы перестают собираться внутри Telegram-handler'ов. Появляется ядро без `chat_id`:
обзор базы и выбор базы (из `BaseService`), список «что можно построить» и карточка постройки (из
`BuildListAction`/`GenericBuildingInfoAction`), старт стройки (из `GenericBuildingAction`), апгрейд
(обёртка над `BuildingUpgradeValidator`/`Applier`). Handler'ы бота становятся рендерерами с прежним
текстом. Ядро закрывает гонки старта и апгрейда и принимает базу явно. В вебе появляется нативная
«🏠 База» в доке: пикер, обзор, стройка, карточка, апгрейд, таймер стройки в HUD, а остальные
действия зданий доступны кнопками через мост. Плюс два хвоста W2.N3.

## Assumptions
- **Tier 2 на верхней границе**, как N1–N3: три review-единицы. Handler'ы, которые переписываются,
  по разведке: `BaseService` (~520 строк), `DetailedBaseInfoAction` (365), `BuildListAction` (121),
  `GenericBuildingInfoAction` (583), `GenericBuildingAction` (426), `UpgradeBuildingAction` (273).
  Если не влезает, делим story, а не расширяем.
- **Паритет снимками.** Текст и кнопки бота на экранах ядра — байт-в-байт прежние (снимки до и
  после, как в N2/N3). Исключение — ask 5: кнопки «🏗 Строить», карточка, апгрейд и «назад» на
  экранах ядра получают суффикс `_b<id>`, если база известна. Это меняет `callback_data`, но не
  текст. Старые кнопки без суффикса в уже отправленных сообщениях работают, как сейчас.
- **Не трогаем** (ask 3, мост): 14 карточек зданий `Camp/Buildings/*Handler.php` и их собственные
  действия (роботы, теплица, маяки, дрон, насос, спортзал), снос (`Demolish*`, `DeleteBaseAction`),
  ремонт, склад базы, ангар, декор, развитие (`BaseDevelopmentAction`), создание лагеря. Из веб-обзора
  на них ведут кнопки `op=bridge` с той же `callback_data`, что у бота.
- **Легаси `StartBuild*`/`Build*Construction`** не трогаем: живой путь стройки —
  `genericBuildInfo_<Key>` → `GenericBuildingAction`. Если разведка story 02 найдёт в живом UI кнопку
  на легаси-путь, её отмечаем в Findings и не переписываем.
- **Атомарность (ask 4).** Старт стройки: ресурсы (пул рюкзак+склад) и предметы — условной записью
  по образцу `CraftOrderService`. Проверки «уже строится», лимит зданий и лимит баз идут под
  `FOR UPDATE` строки персонажа. Апгрейд: золото — `decrementIfAtLeast` (сейчас
  `CharacterStatsService::adjust` с полом 0, то есть при гонке апгрейд обходится без оплаты), уровень —
  `UPDATE … WHERE level = <текущий>`. При отказе вся транзакция откатывается. Обычный путь не меняется.
- **Визит (ask 6).** `touchVisit` и `recordBaseOpened` переезжают в ядро (`open()`), и их зовут оба
  клиента. Одноразовые подсказки веб-игроку уходят через `WebDelivery`-захват, как хуки шага и Похода в
  N2, и попадают во входящие `/play`. Если окажется, что онбординг-подсказка шлётся мимо захвата,
  story 01 чинит доставку по `character_id`.
- Новых чисел баланса не ожидается: длительности — `BuildDurationService`, гейты — `Config\Buildings`
  и `BuildingGateService`, стоимость апгрейда — `Config\BuildingUpgrades`. Если по ходу найдётся
  зашитое число баланса на пути ядра, его переносим в GameSettings (ADR-024) и пишем в Plan deltas.
- **Стопки.** `BaseBuildingsList::buildSummary` считает строки, а не `amount`. Веб-обзор показывает
  «×N» из `amount`. Налог в боте не пересчитываем, его формулу держит `TaxCollectionHandler`: если
  окажется, что строка налога на экране расходится с кроном, это Finding, а не правка.
- Завершение стройки остаётся за `GenericBuildingCompletionHandler`/`BuiltCompletion*` — ядро их не
  трогает. Веб видит завершение опросом HUD и во входящих. Если у web-only завершение молча
  теряется, как было у крафта, чиним в story 02 по образцу N3.
- Мутации веба (`build_start`, `upgrade`) дедуплицируются через `intent_id` с суффиксом экрана, ≤ 64.
- Хвост W2.N3 про `FISH_RECIPES`: экран костра бота читает список из `CraftOrderService` (одна
  публичная константа или метод). Кнопка «🔒 Профессиональный крафт» на 375 переносится по словам,
  без разрыва внутри слова.
- `wave-check` даст `verify-gap` (`vendor/bin/phpunit` читается как путь) и `empty-glob` на новые
  файлы, как в N1–N3. `trace-check` пометит строки `## Request` непокрытыми: это цитаты-источники, их
  содержание несут `## Asks`. Asks 8 и 10 закрывает Queen в конце сборки (вердикты и живой проход).

## Stories

**Wave 1**
- `w2-n4-base-01` — ядро чтения: `BaseScreenService` (выбор базы, обзор, список построек со стопками, `open()` = визит + онбординг), `BaseService`/`DetailedBaseInfoAction` — рендереры, суффикс базы на кнопках ядра.

**Wave 2**
- `w2-n4-base-02` — ядро записи: `BuildOrderService` (каталог «что можно построить» с замками, карточка, атомарный старт), `BuildingUpgradeService` (превью + атомарный апгрейд); `BuildListAction`, `GenericBuildingInfoAction`, `GenericBuildingAction`, `UpgradeBuildingAction` — рендереры.

**Wave 3**
- `w2-n4-base-03` — веб: `view=base` (пикер, обзор, каталог, карточка, старт, апгрейд, кнопки моста), «🏠 База» в доке, стройка в HUD с таймером, ui-kit; хвосты N3 (`FISH_RECIPES`, кнопка на 375).

## Contracts
- `BaseScreenService::resolve(int $characterId, ?int $baseId): array` —
  `{state: picker|base|no_base|unavailable, bases[]{id,cell,x,y,biome,covered,distance}, base_id}`;
  `overview(int $characterId, int $baseId): array` —
  `{base{id,cell,x,y,biome,on_base,days_left,tax_total,decor}, coverage{covered,tower_level,distance,max},
  buildings[]{char_building_id,building_id,key,name,icon,level,amount,type,bridge_callback}}`;
  `open(int $characterId, int $baseId): void` — визит и онбординг, без вывода.
- `BuildOrderService::catalog(int $characterId, int $baseId): array` —
  `{items[]{key,name,icon,locked,lock_reason,lock_path,built_count}}`;
  `preview(int $characterId, int $baseId, string $key): array` —
  `{ok,code,building{key,name,icon,effect},resources[]{name,need,have},items[]{name,need,have},minutes,gates[],can_start}`;
  `start(int $characterId, int $baseId, string $key): array` —
  `{ok,code,message,char_task_id,ends_at}`; коды отказа — константы.
- `BuildingUpgradeService::preview(int $characterId, int $baseId, int $charBuildingId): array` —
  `{ok,code,level,next_level,gold,resources[]{name,need,have},effect_now,effect_next,can_upgrade}`;
  `apply(int $characterId, int $baseId, int $charBuildingId): array` — `{ok,code,message,level}`.
- `POST /play/view` с `view=base`: `op=pick` (`b`), `op=catalog` (`b`), `op=building` (`b`, `key`),
  `op=build_start` (`b`, `key`, `intent_id`), `op=upgrade_preview` (`b`, `id`), `op=upgrade` (`b`, `id`,
  `intent_id`). Ответ, как у N1–N3: `{html, hud, alert, csrf}`; без JS — PRG на `/play?view=base`. Id
  персонажа — только из сессии, `b` каждый раз перепроверяется ядром.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** Andrei Andrievskii, 2026-09-27 — «ДА» (стадия 02)
**Briefed:** <written by scripts/cycle.sh briefed>
**Branch:** vulyk/w2-n4-base
**Checked:** <written by scripts/human-check.sh>
**Council:** RED round 1, 2026-09-27, at 639618d4, pack 10bcc90aa632
**Shipped:** <written by scripts/ship-check.sh --record>
