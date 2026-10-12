---
story: oct-pvp-clarity-02
spec: oct-pvp-clarity
status: todo
returned:
tier: 2
worker: worker-code
model: sonnet
wave: 2
blocked_by: [oct-pvp-clarity-01]
---

# Блок «почему так вышло» в разборе боя: бот, `/play` и публичная страница боя

## Goal
Карточка боя в боте (`battleLog_<id>`) и на `/play` (`view=battle`) показывает блок «🧩 Почему так вышло»
из `battle.why`, когда список не пуст. Публичная `/battles/view` показывает тот же блок двухуровнево:
вошедшему участнику — `PvpOutcomeExplainer::explain(..., true)`, остальным — `explain(..., false)`.
Рендеры ничего не считают.

## Requirements
> Затем добавить в разбор боя строку, почему победил защитник.
> 2. В разборе боя в боте и на сайте есть блок «почему так вышло»: стена −X%, ограда −N HP, удачные удары слабого, смягчённые удары насмерть, пояснение про потолки Силы и уровней.
> 7. Блок «почему» читается в media-off и в legacy Markdown без поломки (парные `*`, без неэкранированных `_`), сообщение бота укладывается в лимит Telegram; старые бои без записи обороны открываются как раньше, без блока про оборону.
> 8. Публичная страница боя `/battles/view` показывает блок «почему так вышло»: любому зрителю — удачные удары, смягчённые удары насмерть, потолки; вошедшему на сайт участнику боя — ещё стена −X%, ограда −N HP, инициатива. Постороннему проценты стены, ограды и инициативы не видны ни в разметке, ни в исходнике страницы — только строка без чисел, что у базы работала оборона.
> «Блок «почему так вышло» не появится на публичной странице боя на сайте, а Torch0010, похоже, прислал скриншот как раз с неё.»

## Files
- app/Controllers/Telegram/Commands/Actions/PVP/BattleJournalAction.php
- app/Views/site/_play/native_battle.php
- tests/unit/Views/PlayViewsTest.php
- tests/database/BattleJournalServiceTest.php
- app/Controllers/BattlesController.php
- app/Views/site/battles/view.php
- tests/database/BattlesPublicViewTest.php

## Non-goals
- Не считать факторы в рендерах — только вывод `battle.why`.
- Не трогать старую `app/Views/battles/view.php`.
- Постороннему зрителю `/battles/view` не отдавать в контроллер/вьюху никаких чисел обороны: строки собираются в контроллере через `explain(..., false)`, сырой `log_data.defense` во вьюху не передаётся.
- Не перекраивать остальную карточку и кнопки.
- Новых CSS-компонентов не заводить: на `/play` — существующие классы `play-native-*`.

## Map slice
`memory/map/pve-pvp.md` → «Журнал и арена»; `memory/map/website.md` → `/play`.

## Acceptance criteria
- [ ] Бот: у боя с непустым `why` в тексте карточки есть заголовок блока и все строки; у боя с пустым `why` текст байт-в-байт прежний.
- [ ] Бот: строки экранированы для legacy Markdown (имя игрока с `_` или `*` в строке не ломает сообщение); длина самого длинного варианта карточки с блоком проверена тестом против лимита Telegram.
- [ ] `/play`: блок выводится через `esc()`, при пустом `why` разметка прежняя.
- [ ] Tier-3 на testbot: настоящий PvP-бой у базы с обороной → «📜 Разбор боя» в боте показывает блок; тот же бой на `/play` — тот же блок. Доказательство (скрин/текст) — в Implementation notes.
- [ ] `/battles/view`: вошедший участник боя (`myCharId` — attacker или defender) видит полный блок со стеной/оградой; не вошедший и вошедший посторонний — блок без процентов и HP обороны, но со строкой «у базы работала оборона»; старый бой без `defense` и бой без факторов — разметка страницы прежняя. Тест на оба уровня + проверка, что `wall_reduction_percent` не попадает в HTML постороннего.
- [ ] `/battles/view`: только классы `wildworld-ui.css` (ADR-062), `esc()`; Tier-2 curl по маршруту `200`.
- [ ] Tech-writing: ноты `BattleJournalAction` и `BattlesController` обновлены.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
