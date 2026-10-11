# Recon: web-onboarding (2026-10-11)

Два отчёта drone-scout, сжато. Пометка «не проверено» — разведчик туда не дошёл.

## Веб-доставка
- `Play::index()` `app/Controllers/Play.php:100` → `WebActService::bootstrap()` `app/Services/Web/WebActService.php:130`:
  если в `web_play_state` пусто (`screen` и `history`) — `act()` с `intent_id=bootstrap-<charId>`, `kind=command`,
  `data=/start` (`BOOTSTRAP_COMMAND` :44). Весь пайплайн бота синхронно в HTTP-запросе → первый `/play` >10 с.
- Сбой первого бутстрапа: `Play.php:118-122` → пустой экран; ключ намерения уже занят — повтор не диспатчит,
  экран остаётся пустым навсегда.
- Состояние `{screen: list<Msg>, history: list<list<Msg>>, dock, input}` — `WebScreenStore` (`:25`, `applyCapture` :101,
  history cap `WebPlay::historySize`=10, `:216-227`). Msg = `{message_id, text, caption, parse_mode, photo_url, inline_keyboard}`.
- Входящие — `WebInboxService` (`append`, `upsertEdit`, `unreadCount`, `latest`, `markAllRead`, `findMessage`), keep 200.
- Отправки вне запроса (крон): `WebDelivery::route()` `app/Services/Web/WebDelivery.php:118` — виртуальный чат
  (`VirtualChat::is`, :134) → только входящие (`SOURCE_VIRTUAL`, при `web.play_enabled`); привязанный TG-игрок (:143) →
  Telegram + зеркало во входящие. Экран меняет только фоновая ПРАВКА уже существующего синтетического id
  (`backgroundSynthetic` :127 → `WebScreenStore::patchMessage`). Новая отправка экран не трогает никогда → залипший
  «Сбор ресурсов начался! / ❌ Прервать добычу».
- HUD живой: `CharacterSheetService::hud` `app/Services/Player/CharacterSheetService.php:170`, задачи `in_work` :229-238 →
  «Свободен…» `app/Views/site/_play/hud.php:63`.
- Опрос `/play/inbox` — `Play::inbox()` :637 (`{unread, html, hud, csrf}`), `WebPlay::inboxPollSeconds`=30 (min 10).
  JS и вьюха входящих — не проверено.
- Flash `FLASH_ALERT/FLASH_EVENTS/FLASH_PREVIEW` (`Play.php:84-90`) — только сессия, крон их не пишет.
- Прецедент «входящее → экран»: `WebDelivery::captureEdit` :259-266.
- Нативный экран: `WebNativeScreenService` — `VIEW_*` (:139-151), `VIEWS` (:154), ветка `render()` (:440) →
  `site/_play/native_<x>.php`; `NATIVE_CALLBACKS` (:163), `DOCK_VIEWS` (:195). Ядро на два клиента: `ResourceShopScreenService`,
  `ArenaScreenService`, `BattleJournalService`, `TasksSurfaceService` (бот-сторона не проверена).
- `AccountRegister::createCharacter()` `app/Controllers/AccountRegister.php:60-94` → `redirect('/account')` :93.
  `AccountSession::rememberReturnTarget` :168 принимает только `/play`.
- Тесты — не проверено.

## Cold open бота и цепочка
- `StartCommand.php:88-263` (новичок), по порядку: носитель reply-клавиатуры (:216-220, `menuAttachText()`, флаг
  `cold_open_v2.menu_defer`); паёк (:225), сигнал (:236), встречающий (:247) — пропускаются при удавшемся одном окне;
  финальный welcome (:257). Варианты: легаси-текст 121 слово инлайном (:134-144, флаг `start_greeting` OFF);
  `greeting()` + «🤔 Задать имя персонажа»; **одно окно** (`single_screen` ON, на проде ON): `composeWelcome([greetingSingleScreen,
  kit, signal, greeter, closingSingleScreen])`, ≤3900 симв., кнопки «🧭 Сделать первый шаг» = `move~cold`, «✏️ Назвать героя»
  = `setCharacterName~cold`. Нет спавна → строка ошибки.
- `ColdOpenGreetingService` — чистые построители: `greetingEnabled/greeting/singleScreenEnabled/greetingSingleScreen/
  closingSingleScreen/menuDeferEnabled/menuAttachText/composeWelcome(list<?string>)/fitsSingleMessage`. Клавиатуры и
  легаси-текст живут только в `StartCommand`.
- `CharacterProvisioningService::create()` `app/Services/Player/CharacterProvisioningService.php:62`, `lastTexts()` :163
  → `{spawned, singleScreen, starterKit, signal, greeter}`. Сервисы пайка/сигнала/встречающего **ничего не отправляют**,
  только возвращают текст (3-й аргумент — лишь хвост навигации). Веб-персонаж: виртуальный `telegram_users`,
  `chatId = VirtualChat::idForAccount()` < 0. Тексты не сохраняются — после `create()` их не восстановить, кроме как
  пересобрать.
- Цепочка: `OnboardingChainCatalog` 8 шагов (`OnbStepMove, Gather, Craft, ClaimBase, OpenBase, Build, Sell, Level5`),
  `completion($titleEn)` → `{text, reply_markup}`. Завершение — крон `QuestObjectiveHandler` (everyMinute) проверяет
  состояние (`objectiveMet` :193; `collect_resource` = сумма инвентаря → паёк закрывает «воду» сам), затем `notifyOnboarding`
  (:179) → `safeSendMessage` → для веба во входящие.
- `ActionOrigin::FROM_COLDOPEN='cold'` (`ActionOrigin.php:57`), `~cold` срезается в `BotController::webhook`. Работа
  `move~cold` через веб-мост (`op=bridge`) — не проверено; firehose запишет action `move~cold`.
- Веб-имя задаётся при регистрации — кнопка «✏️ Назвать героя» в вебе не нужна.
