---
story: pvp-detection-clarity-21
spec: pvp-detection-clarity
status: done
tier: 1
worker: drone-docs
tracer: false
wave: 7
blocked_by: [pvp-detection-clarity-19, pvp-detection-clarity-20]
---

# Бумага догоняет волну фиксов, а не описывает разобранную конструкцию

## Goal

После story ноты и срез карты описывают код, который есть сейчас, а не тот, что был два коммита
назад. Следующий, кто придёт по карте к боевому пути, начинает с верной модели порядка гейтов.

## Requirements

> BLOCK-2, major #C: ноты `PvpStandoffService.md:30,59-60` и `StandoffNotifier.md:70` описывают код до волны фиксов — указывают на `AttackPlayerAction::resolveStandoffOutcome()` (метода больше нет, он разделён на `resolveStandoffPreGate()` / `resolveStandoffOpen()`), формулируют кулдаун как «после прошлого закрытого окна» без исключения `cancelled`, и числят `GameSettingsService` в зависимостях `StandoffNotifier`, откуда он удалён.

> BLOCK-2, major #D: свежепосеянный срез `memory/map/pve-pvp.md:35` называет гейт `resolveStandoffOutcome()` «до анти-спам-кулдауна» — то есть описывает ровно ту конструкцию, которую `-13` через два коммита разобрал как критичную дыру.

## Files
- memory/map/pve-pvp.md

## Файлы вне репозитория

Правятся тоже, но в `## Files` их нет намеренно: vault — соседний репозиторий, а `scope-check.sh`
мерит дифф этого. Перечислены здесь и проверяются ревью:

- `C:\Projects\mmorpg-vault\tech-writing\services\PvpStandoffService.md`
- `C:\Projects\mmorpg-vault\tech-writing\services\StandoffNotifier.md`
- `C:\Projects\mmorpg-vault\tech-writing\handlers\pvp\AttackPlayerAction.md`
- `C:\Projects\mmorpg-vault\tech-writing\services\PlayerDetectionService.md`
- `C:\Projects\mmorpg-vault\decisions\ADR-186-Base-standoff-window-before-field-pvp.md` — раздел об отклонениях дополняется тем, что вскрыли два прохода ревью

## Non-goals
- Не править код. Расхождение кода с решением — отчётом Queen, а не правкой.
- Не пересказывать в `memory/map/` то, что лежит в vault'е: срез — это назначение, точки входа, ловушки и ссылка.
- Не переписывать ноты целиком: правятся места, разошедшиеся с кодом.
- Не делать `git stash` / `git checkout`; полный набор не запускать.

## Map slice

Волна фиксов — коммиты `67721b76`, `936c7b69`, `a4373031`, `7ef55b00`, `6066cdf6`, `ab18155e`
плюс то, что добавят story `-19` и `-20` (дождись их — они правят те же методы, о которых пишешь).
Дифф: `git diff fe359554..HEAD`. Story-файлы `-13` … `-20` несут в `## Findings` решения воркеров.

## Acceptance criteria
- [ ] Ноты описывают фактические имена методов после волны: двухфазный гейт `resolveStandoffPreGate()` / `resolveStandoffOpen()` вместо `resolveStandoffOutcome()`.
- [ ] Кулдаун защитника в нотах описан с исключением: `cancelled` (отмена самим нападавшим) его НЕ армирует, армируют `held` / `fled` / `countered` / `expired`, и сказано, почему — иначе следующий «унифицирует» константу обратно.
- [ ] Зависимости `StandoffNotifier` в ноте актуальны: `GameSettingsService` оттуда удалён.
- [ ] `memory/map/pve-pvp.md` называет фактический порядок гейтов: pre-gate → чтение анти-спам-кулдауна → смежность → ограничения → открытие окна, и ловушку «открытие окна пишет строку и шлёт тревогу, поэтому стоит после всех запретов».
- [ ] Ноты `last_reviewed` обновлены сегодняшней датой; ADR-186 в разделе об отклонениях дополнен тем, что вскрыли ДВА прохода ревью, включая регресс от сочетания двух независимо верных фиксов.
- [ ] Секретов в бумаге нет.

## Verification

`git status --porcelain` и `git diff --name-only` не содержат ни одного файла из `app/` и `tests/` —
story бумажная, и это её единственный доступный сторож: ни одна команда из `## Commands` до
markdown не достаёт.

## Implementation notes

Источник — код и `git diff fe359554..HEAD`, не прежние ноты (они и были предметом дыры). Обошёл все
десять коммитов волны, подтвердил каждое расхождение прямым `Grep`/`Read` по коду перед правкой
бумаги (`resolveStandoffOutcome()` больше не существует, `resolveStandoffPreGate()`/
`resolveStandoffOpen()` есть; `COOLDOWN_ARMING_STATUSES` и его состав; `GameSettingsService`
действительно удалена из `StandoffNotifier.php`; тест-мост `testLockButtonReasonsMatchCooldownExemptReasons`
существует и сверяет `lockLabel()`↔`restrictionReasonHasLockButton()`; `TypeError`-фикс в
`PlayerDetectionService::detectNearbyPlayers()` подтверждён комментарием story `-22` в самом коде).

Правки:

- `memory/map/pve-pvp.md` — раздел «Окно противостояния»: порядок гейтов переписан на двухфазную
  модель (pre-gate → кулдаун → смежность → ограничения → open), добавлены ловушки «открытие после
  всех запретов», «не все закрытия армируют кулдаун», «не все тапы платят анти-спам-кулдаун» (с
  указанием на тест-мост), «экран обнаружения был мёртв» (TypeError).
- `PvpStandoffService.md` — `shouldOpen()` описан через `COOLDOWN_ARMING_STATUSES`, Инвариант 6
  переписан на двухфазный гейт, добавлена отдельная ловушка про `cancelled`, обновлены «Где
  используется» (новые имена методов) и «Тестовое покрытие» (новые тесты волны).
- `StandoffNotifier.md` — `GameSettingsService` убран из «Зависимости» с пояснением почему.
- `AttackPlayerAction.md` — раздел «Окно противостояния» переписан целиком на двухфазную модель с
  разбором обоих BLOCK (критично #1 и BLOCK-2 критично #A/major #B) и тест-моста; «Поток handle()»
  и «Тесты (окно противостояния)» обновлены под новые методы/тесты.
- `PlayerDetectionService.md` — добавлен раздел «🔴 Ловушка: экран обнаружения падал TypeError,
  тесты этого не видели» со ссылками на смежные памяти класса дефекта; обновлено «Тестовое
  покрытие» (запись истории по реальному пути, различимость меток при коллизии).
- `ADR-186-Base-standoff-window-before-field-pvp.md` — новый раздел «Дополнено при сборке (два
  прохода ревью…)» перед «Связанные ADR»: пять находок (BLOCK #1, BLOCK #2, регресс от сочетания
  двух верных фиксов, скрытый мост без проверки, тестовое покрытие врало) с итоговым абзацем, что
  Инварианты 1–10 не изменились по существу — изменился порядок вызовов и состав множеств.

Не найдено расхождений, которые story `-21` предписывала бы чинить, но которые я бы не смог
подтвердить кодом — все пять пунктов задания подтверждены прямым чтением `AttackPlayerAction.php`,
`PvpStandoffService.php`, `StandoffNotifier.php`, `PlayerDetectionService.php` и
`StandoffAttackGateTest.php`.

## Findings

Расхождение кода с ADR-186, не входящее в список задания, но всплывшее при чтении диффа: ADR-186
§6 (раздел «Что уходит в GameSettings») и его же «Ворота проекта» → ADMIN-TUNABLE BALANCE говорят
про шесть ключей `pvp.standoff.*` — этот список код не нарушает, не трогал. Отдельно: в
`AttackPlayerAction::resolveStandoffOpen()` чтение `pvp.standoff.cooldown_sec` и
`pvp.standoff.hold_damage_reduction_percent` идёт напрямую через `new GameSettingsService()` внутри
метода (не инжектится) — это уже было так до волны фиксов, не новый дрейф, и не входило в задание;
называю на случай, если это не замечено ранее.
