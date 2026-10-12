---
story: oct-pvp-clarity-02
spec: oct-pvp-clarity
status: done
returned: DONE
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

- **Бот** (`BattleJournalAction`): `whyBlock(list<string>)` — «🧩 <b>Почему так вышло</b>» и строки ядра между итогом
  и раундами. Карточка бота — **HTML** (`parse_mode: HTML`), не legacy Markdown: строки идут через `esc()`
  (`htmlspecialchars`), поэтому `_`, `*`, `<`, `&` в имени ничего не ломают (тест на имя `Kill_er*<b>&Bob</b>`).
  Блок уменьшает бюджет раундов (`fit()`), а не лимит сообщения: 150 раундов + блок ≤ 4096 и в символах, и в UTF-16.
  Пустой `why` — `whyBlock` даёт `''`, текст прежний.
- **`/play`** (`native_battle.php`): секция `data-battle-why` из `card.why` на существующих `play-native-section` /
  `play-craft-reqs`, вывод через `esc()`; нет `why` (или пусто) — разметки нет. Новых CSS-классов нет.
- **Публичная `/battles/view`** (поправка владельца, ask 8): `BattlesController::whyFor($log, $viewerCharId)` выбирает
  уровень — участник боя (`authContext()['characterId']` = attacker/defender id) получает числа обороны, остальные
  `explain(..., false)`. Во вьюху идёт только `$why` (готовые строки), `log_data.defense` — нет (тест: `wall_reduction`
  в HTML гостя отсутствует). Вьюха — классы `card`/`stack`/`kicker`/`mb-0` из `wildworld-ui.css`, без инлайн-стилей,
  компонент не новый (карточка с текстом), `ui-kit.html` не трогаю.
- Вход участника в feature-тесте потребовал бы таблицы аккаунтов (ADR-188), поэтому уровень проверен напрямую на
  `whyFor` (участник / гость 0 / посторонний 99), а GET гостя — по HTML.
- **Проверки:** `BattlesPublicViewTest` (+3), `BattleJournalServiceTest` (+1), `PlayViewsTest` (+1) зелёные; phpstan чистый.
  Локальный Apache не поднят (`curl` → 000), рендер страницы покрыт feature-тестом (200).
- **Ещё не сделано — Tier-3 и Tier-2 на preprod:** настоящий PvP-бой у базы с обороной на testbot → «📜 Разбор боя»
  в боте, тот же бой на `/play` и `/battles/view` (гостем и участником), 1440/768/375. Это возможно только после
  мерджа в `develop` и деплоя на preprod; результат — в smoke-отчёт перед тегом.
- Tech-writing: `handlers/pvp/BattleJournalAction`, `controllers/BattlesController` обновлены.

## Findings
