---
story: w2-n7-combat-01
spec: w2-n7-combat
status: done
returned: DONE
tier: 2
worker: worker-code
model: sonnet
wave: 1
blocked_by: []
---

# Ядро журнала боёв, бот «📜 Мои бои» и «📜 Разбор боя» под итогом

## Goal
Есть `App\Services\PVE\BattleJournalService` — нейтральное ядро журнала: модель списка «мои бои» (PvE, PvP, дуэли,
новые сверху, с лимитом) и модель карточки боя (кто с кем, итог, пометка дуэли, раунды из `log_data` v2), отказ
`not_found` на чужой или несуществующий бой. Колонка `battle_logs.battle_type` вмещает `DUEL`. Бот рисует из ядра
экран «📜 Мои бои» (`battles`) и карточку (`battleLog_<id>`), а под итогом PvE и PvP (у обоих бойцов) стоит кнопка
«📜 Разбор боя».

## Requirements
> 1. В /play есть «⚔️ Бои»: журнал моих боёв (PvE, PvP и дуэли) списком и карточка боя с итогом и разбором по раундам. Чужие бои не видны.
> 4. В боте есть «📜 Мои бои» из той же модели, сжато в лимит Telegram. Под итогом каждого боя есть кнопка «📜 Разбор боя», а на арене и в рейтинге — вход в журнал. Арену, дуэль и рейтинг бот рисует из тех же моделей, их тексты и кнопки не меняются (паритет проверяется тестом).
> 5. Всё читается без картинок. Если дуэли или рейтинг выключены, виден замок с объяснением. Вердикты: в /guide — да, строка про журнал в разделе «⚔️ Бой и PvE»; совет — да, про разбор боя.

## Files
- app/Services/PVE/BattleJournalService.php
- app/Controllers/Telegram/Commands/Actions/PVP/BattleJournalAction.php
- app/Config/CallbackRoutes.php
- app/Database/Migrations/2026-12-17-100000_WidenBattleLogsType.php
- app/Services/PVE/PveBattleLogWriter.php
- app/Services/Player/PvEService.php
- app/Services/PVE/PveNotificationSender.php
- app/Controllers/Telegram/Commands/Actions/PVP/AttackPlayerAction.php
- tests/database/BattleJournalServiceTest.php
- tests/unit/Services/PVE/PveNotificationSenderTest.php
- tests/unit/Services/Player/PvEServiceNotifyFailureTest.php
- tests/database/AttackPlayerPostBattleKeyboardTest.php
- phpstan-baseline.neon

## Non-goals
- Не переписывать `AttackPlayerAction` под ядро (это N7b): только добавить кнопку в клавиатуру итога обоим бойцам.
- Не трогать публичные `/battles`, `/battles/view/{id}` и их вьюхи; ссылку «Посмотреть детали боя» в PvP не убирать.
- Не писать дуэли в журнал и не трогать арену/рейтинг — это story 02 (здесь только ядро умеет читать `DUEL`).
- Не менять текст итога PvE/PvP — только добавить кнопку.

## Map slice
`memory/map/pve-pvp.md` — Entry points (лог), Gotchas (награда только победителю, авто-PvE доставка через
`TelegramBridge::ensure()`); `memory/map/telegram.md` — маршрутизация колбэков (`Config\CallbackRoutes`,
ключ = первый сегмент `explode('_')`).

## Acceptance criteria
- [ ] Список содержит мои PvE (по `player1_id`) и PvP/DUEL (я — `player1_id` или `player2_id`), новые сверху, не больше лимита; `npc_id` в `player2_id` у PvE не делает бой «чужим своим».
- [ ] Карточка чужого или несуществующего боя → `not_found`; бот отвечает понятным алертом, ничего не ломая.
- [ ] Карточка содержит итог («победа/поражение», противник) и раунды; запись без раундов или с битым JSON → итог без раундов.
- [ ] Текст карточки в боте ≤ 4096 символов на бою с большим числом раундов (тест с длинным логом); markdown/HTML-эскейп имён.
- [ ] Миграция расширяет `battle_type` до `varchar(8)` и откатывается; `php -l` чист.
- [ ] Итог PvE (авто-бой и «Напасть» на NPC) несёт кнопку `battleLog_<id>` с id записанного лога; итог PvP — у атакующего и у защитника.
- [ ] Экран «📜 Мои бои» и карточка читаются без картинок (текст самодостаточен), кнопки 2–3 в ряд.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `app/Services/PVE/BattleJournalService.php` (новый): `listFor()` / `card()` / `isMine()`; модель — имена, итог, раунды из обеих форм `log_data` (PvE и PvP v2); полный дамп персонажа PvE наружу не выходит (тест). Победа PvE — `winner_id == я`; при совпадении id спауна с id персонажа итог берётся из последнего удара.
- `PVP/BattleJournalAction.php` (новый) + `CallbackRoutes` (`battles`, `battleLog`): HTML, экранирование имён, `fit()` режет раунды в бюджет 3500 символов; из уведомления карточка — новым сообщением (итог с наградами не затирается), из списка (`battleLog_<id>_j`) — на месте.
- Миграция `2026-12-17-100000_WidenBattleLogsType`: `battle_type` VARCHAR(3)→(8); у таблицы нет createTable-миграции, поэтому на пустой базе — no-op.
- `PveBattleLogWriter::write()` → `?int`, `PvEService` передаёт id в `PveNotificationSender::send(..., ?int $battleId)` и в ответ `battle_id`; `PveNotificationSender::keyboard()` — одна кнопка «📜 Разбор боя».
- `AttackPlayerAction::postBattleKeyboard($id, $battleId = 0)` — третья кнопка в ряд у обоих бойцов; остальной handler не тронут.
- Найдено: `tests/unit/Services/PVE/PveNotificationSenderNoKeyTest::testWithKeyTheSamePathReachesSend` падает при отдельном запуске файла и на исходном коде (порядок набора) — в полном наборе зелёный; не трогал.

## Findings
