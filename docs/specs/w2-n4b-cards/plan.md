# W2.N4b — 14 карточек построек на одном ядре, нативно в /play (plan)

**Tier:** 2 · **Spec slug:** `w2-n4b-cards` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро: сервис + два рендерера), ADR-189 (мост как фолбэк), ADR-095/multibase-picker (суффикс `_b<id>`, `BaseScopeResolver`), ADR-041 (оборона), ADR-142 (Навес), ADR-155 (`ProductionEffectService`), ADR-033 (грядки), ADR-020, ADR-062
**Depends on:** w2-n4-base / w2-n4-tails / w2-n4-tails2 (v0.51.681–683): `BaseScreenService::overview` (`bridge_callback` строки постройки), `WebNativeScreenService` `VIEW_BASE` + `BASE_SECTIONS`, нативный апгрейд `section=upgrade`, `$bridgeBtn` в `native_base.php`

## Goal
Карточка «моей» постройки перестаёт собираться в 14 копиях Telegram-handler'а. Появляется ядро
`BuildingCardService` без `chat_id`: по персонажу, базе и ключу здания оно отдаёт модель экрана — поля
карточки, строку эффекта, картинку и список кнопок (апгрейд, база, собственные действия здания с их
`callback_data`). Различия зданий (эффект, Грядки, Роботы, Маяки, Ремонт, Навес без апгрейда, оборона с
прочностью и стопкой) — таблица ядра, а не классы. В боте `BuildingHandlerAction` становится единственным
отрисовщиком, 14 классов удаляются, текст и кнопки бота байт-в-байт прежние. В `/play` строка постройки в
обзоре базы открывает нативную карточку (`section=card`); апгрейд — в нативный экран, собственные действия —
кнопками моста.

## Assumptions
- **Паритет — снимками, снятыми ДО переписывания.** Story 01 первым шагом прогоняет старые 14 handler'ов
  на матрице фикстур (личная/коллективная постройка, `usage_count` null/число, `disappearance_date` есть/нет,
  оборона целая/повреждённая и ×1/×3 при стаке вкл/выкл, теплица с грядками вкл/выкл) и коммитит снимки
  (текст + клавиатура + photo/text). Новый код обязан дать те же байты. Расхождения старых копий между собой
  (часть handler'ов пишет «коллективная», часть — «общая» для `collective`; разные формулы «осталось») не
  выравниваются молча: ядро несёт их флагом в таблице здания, Findings перечисляет, чистка — отдельной задачей.
- **Переезд (ask 4) — видимое изменение у двух экранов.** Сейчас блок переезда есть в 12 handler'ах, а у
  обороны и Навеса его нет. Ядро проверяет переезд для всех 14 — карточки обороны и Навеса во время переезда
  начнут отказывать тем же текстом, что остальные. Это единственное отличие от снимков, оно в отдельном тесте.
- **Веб без фото.** Веб-карточка рисует текст (как остальные экраны `native_base.php`), картинка — не нужна:
  все смыслы в полях (ADR-020). Подписи в вебе чистые: «Уровень 2», использования — только когда есть лимит,
  налог «0» без «$»; бот при этом не меняется (ответ 2).
- **Мост.** Кнопки моста — те же `callback_data`, что в боте: `plantSeedMenu`, `AllRobots`, `teleportBeacon`,
  `repairBuilding_<id>`, `baseDevelopment`, `guide_base`. «🏠 База» в вебе — возврат на нативный обзор, а не мост.
  «⚙️ Действия» в строке обзора заменяется ссылкой на нативную карточку; для здания, которого ядро не знает,
  остаётся мост (не тупик).
- **Ссылки на классы.** `NavigationMapService` (12 строк `'to' => *Handler::class`) переводится на
  `BuildingHandlerAction::class`; тесты, создающие `HandPumpHandler`/`WarehouseHandler`, — на отрисовщик через
  тот же `building_<id>_<Key>` callback. Комментарии `ImageRegistry` с именами старых классов не трогаем.
- Новых чисел баланса нет: эффекты — `ProductionEffectService`, `DefenseStructureService`, лимит грядок — `FarmingService`.
- Tech-writing (Queen, в той же задаче): нота `BuildingCardService` новая, ноты 14 handler'ов —
  `status: deprecated` со ссылкой на ядро, `BuildingHandlerAction` и `WebNativeScreenService` — обновить.
- Вердикты (ask 6): `/guide` — нет, совет — нет: механика не меняется, меняется только где рисуется экран.
- Ask 7 (живой проход на preprod) закрывает Queen после мерджа: бот — Колонка, Теплица, Мастерская роботов,
  Центр телепортации, повреждённая стена, Навес; веб — те же карточки и переход в апгрейд/мост. Перед смоуком —
  `scripts/preprod-parity.sh`.

- `trace-check` помечает три строки `## Request` непокрытыми: это цитаты-источники, их содержание несут
  `## Asks`. Asks 6 и 7 (вердикты, живой проход) story не цитирует — их закрывает Queen в конце сборки.

## Stories

**Wave 1**
- `w2-n4b-cards-01` — снимки старых карточек → ядро `BuildingCardService` (14 зданий, переезд, база) → `BuildingHandlerAction` единственный отрисовщик, 14 классов удалены, ссылки и тесты переведены.

**Wave 2**
- `w2-n4b-cards-02` — веб: `section=card` в `WebNativeScreenService`, карточка в `native_base.php` (поля, эффект, апгрейд нативно, действия мостом), строка обзора ведёт в карточку, компонент в `ui-kit.html`.

## Contracts
- `BuildingCardService::card(int $characterId, ?int $baseId, string $key, int $currentCell): array` —
  `{state: ok|refused, text (отказ, текст бота), key, building_id, char_building_id, name, emoji, image|null,
  level, amount, tax, built_at|null, disappears_at|null, uses{used,left|null}|null, usage_label|null,
  description|null, hp{cur,max}|null, effect|null, notes[] , actions[]{kind: upgrade|base|bridge, text, data}}`.
  Бот собирает из этого прежний Markdown через таблицу шаблона здания; веб — свою разметку.
- `WebNativeScreenService`: `BASE_SECTIONS` += `'card'`, nav `key` = `name_en` (`[A-Za-z]{1,40}`); модель `card_owned`.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

- 2026-11-02, story 02 (записка бокового агента, «проверка байт-в-байт видит только дефолты настроек»): в `## Files` story 02 добавлены `tests/unit/Camp/BuildingCardParityTest.php` и снимки — второй прогон с флагами `defense.stack.enabled=1`, `farming.enabled=0`; снимки сняты со старых классов из `e8a70c10`. Отвергнуто: включить флаги, которые сейчас на проде, — не сверял прод, а обе ветки флагов покрывают любую комбинацию этих двух.

- 2026-11-02, story 01 (отчёт сборки): `## Files` story 01 расширен двумя путями, без которых удаление 14 классов не собирается. `phpstan-baseline.neon` — 36 блоков baseline ссылались на удалённые файлы, phpstan падал «path is neither a directory nor a file»; блоки вычищены скриптом по несуществующему `path:`, baseline не перегенерирован (иначе спрятал бы новые ошибки). `tests/unit/Camp/DetailedBaseInfoBareConstructionTest.php` — пользовался статическим флагом шима `fopen` из `BuildingCardBaseScopeTest`, флаг стал общим `$GLOBALS`. Отвергнуто: оставить статический флаг для совместимости — две правды о том, включён ли шим.

**Approved:** Andrei (владелец), 2026-11-02 — запуском /vulyk-build w2-n4b-cards
**Briefed:**
**Branch:** vulyk/w2-n4b-cards
**Checked:**
**Council:**
**Council:** RED round 1, 2026-11-02, at e6bf0949, pack 762348ceedbe
**Shipped:**
