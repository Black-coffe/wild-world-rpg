---
story: w2-n7-combat-03
spec: w2-n7-combat
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 3
blocked_by: [w2-n7-combat-02]
---

# Веб /play: «⚔️ Бои» — журнал, карточка боя, арена, рейтинг; guide, совет, ROADMAP

## Goal
В `/play` есть нативный «⚔️ Бои» в доке: журнал моих боёв (`view=battles`), карточка боя со всеми раундами
(`view=battle`, параметр — id боя), арена (`view=arena`: ростер, вызов `op=duel`, итог с разбором, тумблер
`op=duels_open`) и рейтинг PvP (`view=ladder`, вкладки). Операции дедупятся по `intent_id`, без JS — PRG. Колбэки
`battles`, `battleLog_<id>`, `arena`, `pvpLadder` с других экранов и из входящих открываются нативно. В `/guide`
раздел «⚔️ Бой и PvE» упоминает журнал; есть совет про разбор боя; в журнал ROADMAP готова строка W2.N7.

## Requirements
> 1. В /play есть «⚔️ Бои»: журнал моих боёв (PvE, PvP и дуэли) списком и карточка боя с итогом и разбором по раундам. Чужие бои не видны.
> 2. Арена в /play нативно: кто открыт к дуэлям, вызов, итог с разбором, «открыться к дуэлям» и рейтинг PvP. Атака игрока на карте, встреча с NPC и Узлы пока идут мостом (N7b).
> 3. Дуэль пишется в журнал обоим с пометкой «дуэль, без потерь». Повтор формы или двойной тап не проводит вторую дуэль.
> 5. Всё читается без картинок. Если дуэли или рейтинг выключены, виден замок с объяснением. Вердикты: в /guide — да, строка про журнал в разделе «⚔️ Бой и PvE»; совет — да, про разбор боя.
> 6. Живой проход на preprod в вебе и в боте: дуэль видна в журнале обоим, PvE-бой разбирается по раундам. И строка W2.N7 в журнал ROADMAP.

## Files
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Views/site/_play/native_battles.php
- app/Views/site/_play/native_battle.php
- app/Views/site/_play/native_arena.php
- app/Views/site/_play/dock.php
- public/assets/css/wildworld-ui.css
- app/Views/site/_layout/meta.php
- public/ui-kit.html
- app/Services/Onboarding/GuideCatalog.php
- app/Database/Migrations/2026-12-17-100010_SeedBattleJournalTip.php
- tests/database/PlayViewControllerTest.php
- tests/unit/Views/PlayViewsTest.php
- tests/unit/Services/Onboarding/GuideCatalogTest.php
- ROADMAP.md
- phpstan-baseline.neon

## Non-goals
- Не делать нативными атаку игрока, окно противостояния, встречу с NPC и Узлы — их кнопки остаются мостом.
- Не трогать публичные `/battles` и их вьюхи.
- Новый CSS-компонент — только если существующих `play-*` не хватает; тогда сначала в `ui-kit.html` и бамп `?v=`.
- Строку W2.N7 в ROADMAP §5 не заполнять тегом заранее — тег вписывается при шипе.

## Map slice
`memory/map/website.md` — «Игра на сайте», Gotchas (`/play`: персонаж только из сессии, CSRF `regenerate`,
дедуп `web_play_intents`, `qty`), `memory/map/onboarding.md` — `/guide`, «Совет дня».

## Acceptance criteria
- [ ] `view=battles` и `view=battle` показывают только мои бои; чужой id — честный отказ без утечки имён.
- [ ] `op=duel` с одним `intent_id` дважды (повтор формы) — одна дуэль; итог показывает разбор и ведёт в карточку.
- [ ] Замки арены и рейтинга при выключенных флагах видны с объяснением; журнал доступен всегда.
- [ ] Вьюхи работают без JS, читаются без картинок, вёрстка на 375/768/1440 без горизонтального скролла (Tier-2 на preprod).
- [ ] `GuideCatalog` раздел `combat` упоминает «📜 Мои бои / ⚔️ Бои» без чисел баланса; seed совета идемпотентен (ключ `title_en`, категория `бой`).
- [ ] Колбэки `battles`, `battleLog_<id>`, `arena`, `pvpLadder` из входящих и с других нативных экранов открывают нативный вид, не мост.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `WebNativeScreenService`: виды `battles` / `battle` / `arena` / `ladder` (`BATTLE_VIEWS`), модели `battlesModel()` / `battleModel()` / `arenaModel()` поверх `BattleJournalService` и `ArenaScreenService` (инъекция через конструктор), операции `duel()` (`:duel`) и `duelsOpen()` (`:duels_open`) с дедупом `web_play_intents`. Кулдаун повторной дуэли — атомарный, в ядре (story 02); веб его не дублирует.
- `nativeRoute()` (поверх него — прежний `viewForCallback()`): `battles`, `arena`, `pvpLadder`, `pvpLadder_global` — точные; `battleLog_<id>` и `battleLog_<id>_j` → карточка, `pvpLadder_faction_<id>` → вкладка фракции.
- `Play::act()` перехватывает колбэк «⚔️ Боёв» (входящие, экран моста) и отдаёт нативный экран без диспетча в бота (JSON — экран, без JS — PRG на `/play?view=…`). Только боевые виды: `shop`/`baseStorageList` из входящих по-прежнему идут мостом — расширять это не просили, мост там работает.
- Итог дуэли: алерт с победителем и причиной (`ArenaScreenService::reasonLabel`), на арене — блок «🤺 Итог дуэли» из карточки журнала (`duel` = id боя, только свой и только `DUEL`) с кнопкой «📜 Разбор боя» в карточку. Без JS — PRG на `/play?view=arena&duel=<id>`.
- Чужой и несуществующий бой — один и тот же отказ «Этот бой не найден в твоём журнале.», без имён (ядро чужое не отдаёт).
- Замки: выключенные дуэли/рейтинг — `play-lock` с текстом ядра (`LOCK_ARENA`/`LOCK_LADDER`) и путём в журнал; на вкладках — «🔒 Арена (закрыта)» / «🔒 Рейтинг PvP (закрыт)», нажатие открывает объяснение. Журнал от флагов не зависит.
- Док: «⚔️ Бои» — постоянная кнопка после «🛒 Магазин».
- Новый CSS-компонент не понадобился: хватило `play-craft-reqs`, `play-craft-facts`, `play-lock`, `play-native-note`, `play-kb-grid`. Поэтому `wildworld-ui.css`, `meta.php` (`?v=`), `ui-kit.html` и `phpstan-baseline.neon` не тронуты (phpstan L9 чист).
- Подписи типа/итога боя веба — `BATTLE_TYPE_LABELS` / `BATTLE_RESULT_LABELS` (ядро отдаёт коды, у бота свои подписи того же смысла).
- Вердикты: `/guide` — да, раздел `combat` («⚔️ Бой и PvE») дополнен абзацем про «📜 Мои бои» / «📜 Разбор боя» / «⚔️ Бои», без цифр; совет — да, `2026-12-17-100010_SeedBattleJournalTip.php` (`title_en=BattleJournal`, категория `бой`).
- ROADMAP §5: строка W2.N7 с тегом «—» — тег вписывается при шипе.
- Тесты: `PlayViewControllerTest` +5 (журнал и чужой бой, дуэль из веба обоим один раз + кнопка из входящих, тумблер, рейтинг с вкладкой фракции, замки), `PlayViewsTest` +1 (текст, мутации с CSRF/intent, замки) и счёт форм дока, `GuideCatalogTest` +1.

## Findings
