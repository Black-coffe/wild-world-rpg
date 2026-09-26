# W2.N1 — HUD, персонаж, инвентарь, снаряжение на нейтральном ядре (plan)

**Tier:** 2 · **Spec slug:** `w2-n1-hud-character` · **Brief:** [brief.md](brief.md)
**Governed by:** ADR-190 (нейтральное ядро), ADR-189 (мост как фолбэк), ADR-188, ADR-062, ADR-020, ADR-024
**Depends on:** web-bridge-p1 (v0.51.673) — `Play`, `WebActService`, `_play/state.php`, `wildworld-play.js`

## Goal
Первые три экрана, которые перестают быть «Telegram в браузере»: персонаж (с постоянным HUD),
инвентарь и снаряжение. Для каждого появляется сервис с моделью экрана — данные, действия,
lock-состояния, без `Request`/`chat_id`/Markdown. Бот и веб становятся двумя рендерерами поверх него:
handler'ы бота переписываются под модель, веб рисует серверный HTML в `#play-state`. Экипировка
получает единый атомарный сервис надевания/снятия.

## Assumptions
- Tier 2 — на верхней границе: три крупных review-единицы, каждая переписывает handler'ы бота
  (≈1 900 строк: `CharacterService`, `ResourcesGatheredAction`, `CraftedResourcesAction`, `Gear*`,
  `ToggleEquip*`). Если story не укладывается — делим, а не расширяем.
- Нерадикальные изменения бота допустимы (ADR-190 §4): формулировки, порядок кнопок, lock-кнопка
  вместо сообщения-ошибки у экипировки. Числа и механика не меняются. Картинки бота — как были.
- Нативные экраны идут новым маршрутом `POST /play/view` за теми же гейтами (`web.play_enabled`,
  сессия, CSRF, `accountThrottle:play`). Мутации (надеть/снять) дедуплицируются по `intent_id` в
  `web_play_intents`, как `/play/act`.
- Хаб-меню инвентаря в боте (`InventoryAction`) остаётся хабом: общая модель заменяет данные его
  списков, а не навигацию бота.
- Склад базы, продажа экипировки (ADR-165), страховка — вне N1, в вебе через мост.
- `wave-check` даёт `verify-gap` на все три story: токен `vendor/bin/phpunit` читается как путь вне `## Files`. Проверка — полный набор (ячейка `## Commands`), он включает тесты story, названные в `## Files`; single-file ячейка с `<Path>` не сопоставляется буквально.
- HUD обновляется из ответов `/play/act`, `/play/view` и `/play/inbox`; таймер тикает в браузере от
  `ends_at`, без лишних запросов.

## Stories

- `w2-n1-hud-character-01` — модель персонажа + HUD + нативный «Я»; карточка бота рисуется из модели; маршрут `/play/view`.
- `w2-n1-hud-character-02` — модель инвентаря + нативный единый список с вкладками и поиском; списки ресурсов бота из модели.
- `w2-n1-hud-character-03` — модель снаряжения + атомарный сервис надевания; нативный экран с надеть/снять и lock-замком; `Gear*`/`ToggleEquip*` бота — рендереры.

## Contracts
- `POST /play/view` — поля `view` (`me|inventory|gear`), опционально `op` (`equip|unequip`),
  `kind` (`weapon|armor`), `item` (id строки персонажа), `intent_id` для мутаций. Ответ JSON
  `{html, hud, alert, csrf}`, без JS — PRG на `/play`. Идентификаторы персонажа — только из сессии.
- HUD — партиал `site/_play/hud.php`, данные — из модели персонажа (story 01), переиспользуется 02/03.

## Integration gate
`vendor/bin/phpunit --no-coverage --no-progress`

## Descoped

*(empty)*

## Plan deltas

**Approved:** Andrei Andrievskii, 2026-09-27 — «да» (стадия 02)
**Briefed:** <written by scripts/cycle.sh briefed>
**Branch:** <written by /vulyk-build before wave 1>
**Checked:** <written by scripts/human-check.sh>
**Council:** <written by scripts/cycle.sh judge/escalate>
**Shipped:** <written by scripts/ship-check.sh --record>
