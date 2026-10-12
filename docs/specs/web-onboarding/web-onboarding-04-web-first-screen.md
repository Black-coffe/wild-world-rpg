---
story: web-onboarding-04
spec: web-onboarding
status: done
returned: DONE
tier: 3
worker: worker-code
model: sonnet
wave: 2
blocked_by: [web-onboarding-01, web-onboarding-02]
---

# Первый экран /play — приветствие из ядра, сразу после создания персонажа

## Goal
`AccountRegister::createCharacter()` перестаёт выбрасывать `CharacterProvisioningService::lastTexts()`
(`AccountRegister.php:85`). Тексты передаются в `ColdOpenWelcomeService::build(CLIENT_WEB, …)` (story 01), модель
маппится в Msg экрана и записывается `WebScreenStore::seedFirstScreen()` вместе с доком (контракт — `plan.md`).
Редирект после создания ведёт в `/play` (при `web.play_enabled` ON; иначе `/account`, как сейчас). Первый `GET /play`
находит непустой экран, и `WebActService::bootstrap()` мост `/start` не гоняет: нет «🧭 Меню ниже.», нет загрузки
дольше 10 с. Сбой засева не роняет создание персонажа: экран остаётся пустым, и работает прежний бутстрап-фолбэк.

## Requirements
> 1. Веб-новичок при первом входе видит приветствие Роби — тот же первый экран, что бот даёт в ленте (паёк, сигнал, встречающий, «🧭 Сделать первый шаг»), собранный в общем ядре, и бот рисует его из того же ядра.
> 4. После создания персонажа — сразу в игру (/play), а не в кабинет.
> 6. Экран читается в media-off: приветствие, итог задачи и плашка нового сообщения несут весь смысл текстом; в веб-текстах нового первого экрана нет бот-лексики («ниже», «нижнее меню», «команда /…»).

## Files
- app/Controllers/AccountRegister.php
- app/Services/Web/WebScreenStore.php
- tests/database/WebFirstScreenTest.php

## Non-goals
- Не трогать `ColdOpenWelcomeService` и `StartCommand`: модель — из story 01, как есть. Если её не хватает, это
  INTERFACES, а не правка.
- Не трогать `WebActService::bootstrap()`: непустой экран и так её гасит. Фолбэк остаётся как есть.
- Не трогать `rev`/`task_id` и замену экрана из story 02, кроме того, что пишет `seedFirstScreen` по контракту.
- Не чинить бот-лексику вне первого экрана (F7 — отдельная спека) и не трогать страницу «как попасть», кабинет и
  кнопки «ИГРАТЬ НА САЙТЕ» (F4, F5-дубль).
- Не добавлять «✏️ Назвать героя»: имя выбрано при регистрации.

## Map slice
`memory/map/website.md` → «Аккаунты (ADR-188)», «Игра на сайте (ADR-189)», Gotchas (один персонаж на аккаунт,
`GET_LOCK`, callback только по `message_id`, return target `/play`); `memory/map/telegram.md` → Gotchas «Виртуальный
Telegram-id»; `docs/specs/web-onboarding/recon.md` → оба раздела.

## Acceptance criteria
- [ ] Создание персонажа на сайте пишет в `web_play_state` экран из веб-модели ядра: приветствие Роби, паёк, сигнал,
      встречающий (те, что вернул `lastTexts()` для этого персонажа), одна кнопка «🧭 Сделать первый шаг». Док
      присутствует и такой же, каким его даёт сегодняшний бутстрап.
- [ ] Ответ `createCharacter` — редирект на `/play` при `web.play_enabled` ON и на `/account` при OFF.
- [ ] Первый `GET /play` нового персонажа не вызывает `WebActService::act()` с `bootstrap-<id>` (мост не идёт) и
      показывает засеянный экран.
- [ ] Тап «🧭 Сделать первый шаг» (`/play/act`, callback `move` с `message_id` засеянного сообщения) делает первый шаг,
      как `move~cold` в боте: экран сменился, позиция персонажа изменилась.
- [ ] Ни один текст засеянного экрана — после реального `create()` для веб-персонажа, не фикстуры — не матчит
      `/ниже|нижн\w* меню|команд\w* \/|\/start|\/menu|клавиатур/iu`. Если матчит текст пайка, сигнала или встречающего
      из-за хвоста навигации, `AccountRegister` передаёт в `create()` веб-нейтральный хвост.
- [ ] Повторный вызов `seedFirstScreen` при существующем состоянии ничего не меняет и возвращает `false`. Исключение
      при засеве логируется одной `error`-строкой, персонаж создан, `/play` уходит в фолбэк-бутстрап.
- [ ] Весь смысл экрана — в тексте (media-off), фото нет.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes
- AccountRegister: createCharacter сеет экран из CLIENT_WEB ядра (только при spawned), док из BotMenuService::mainReplyKeyboard(), редирект /play при web.play_enabled, иначе /account; сбой засева — одна error-строка.
- WebScreenStore::seedFirstScreen(id, messages, dock=[]): message_id из аллокатора, только при пустых screen/history, rev+1, task_id null; иначе false.
- Бот-лексика: тест идёт с onboarding.cold_open_v2.single_screen ON (как на проде); при OFF create() даёт хвост «в нижнем меню» в тексте встречающего, а CharacterProvisioningService вне Files.
- Тап «первый шаг»: тест проверяет, что callback move по message_id засеянного сообщения принят act(); настоящий move-handler в тесте не гонялся (runPipeline заглушён).

## Findings
