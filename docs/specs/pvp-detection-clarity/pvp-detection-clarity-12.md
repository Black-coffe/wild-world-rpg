---
story: pvp-detection-clarity-12
spec: pvp-detection-clarity
status: done
tier: 1
worker: drone-docs
tracer: false
wave: 5
blocked_by: [pvp-detection-clarity-08, pvp-detection-clarity-09, pvp-detection-clarity-10]
---

# Бумага догоняет код: ноты, срез карты, канон

## Goal

После story документация описывает то, что построено, а не то, что задумывалось: есть ноты на новый
сервис, модель, нотификатор и task-handler; обновлены ноты изменённых сущностей; срез карты
`memory/map/pve-pvp.md` ведёт в vault, а не пересказывает его; канон говорит про тревогу базы. ADR-186
переведён из `proposed` в `accepted`.

## Requirements

> То есть в этом и будет суть защиты базы.

> А ты сама информацию составляешь последовательно либо параллельно выполняемый план фиксов, доработок, исправлений, уточнений — всего, что вылезло из диалога игроков?

## Files
- GAME_DESCRIPTION.md
- memory/map/pve-pvp.md

## Non-goals
- Не править код: если при написании нот обнаружено расхождение кода с решением — оно идёт отчётом Queen, а не правкой.
- Не переписывать `GAME_DESCRIPTION.md:196` заново: обещание вышки уже приведено в порядок story `-05`, здесь добавляется только тревога базы.
- Не пересказывать в `memory/map/` то, что лежит в vault'е: срез — это назначение, точки входа, ловушки и ссылка.
- Не трогать разделы `/guide` и советы — это `-05` и `-11`.
- Не делать `git stash` / `git checkout`.

## Файлы вне репозитория

Эти ноты правятся тоже, но в `## Files` их нет намеренно: vault — соседний репозиторий, а
`scope-check.sh` меряет диff этого. Правки перечислены здесь и проверяются ревью:

- `C:\Projects\mmorpg-vault\tech-writing\services\PvpStandoffService.md` (новая, по `_templates/service-doc.md`)
- `C:\Projects\mmorpg-vault\tech-writing\services\StandoffNotifier.md` (новая)
- `C:\Projects\mmorpg-vault\tech-writing\models\PvpStandoffModel.md` (новая)
- `C:\Projects\mmorpg-vault\tech-writing\db\pvp_standoffs.md` (новая)
- `C:\Projects\mmorpg-vault\tech-writing\tasks\PVP\StandoffExpiryHandler.md` (новая)
- `C:\Projects\mmorpg-vault\tech-writing\handlers\PVP\{AttackPlayerAction,RunAwayAction,StandoffHoldAction,StandoffCheckAction,StandoffLeaveAction}.md`
- `C:\Projects\mmorpg-vault\tech-writing\services\{DefenseStructureService,TowerAlertService,PlayerDetectionService,PvPRestrictionService}.md`
- `C:\Projects\mmorpg-vault\apps\pve\index.md` — новые сущности в списке
- `C:\Projects\mmorpg-vault\decisions\ADR-186-Base-standoff-window-before-field-pvp.md` — `status: accepted`
- `C:\Projects\mmorpg-vault\wiki\hot.md` — актуальный фокус

## Map slice
`memory/map/pve-pvp.md` (если файла нет — создать тонкий срез по образцу соседних срезов);
`docs/specs/pvp-detection-clarity/` целиком — story-файлы с `## Implementation notes` воркеров;
ADR-186 «Инварианты» — уезжают в ноту сервиса дословно.

## Acceptance criteria
- [ ] Ноты на новые сущности созданы по шаблонам, frontmatter заполнен (`type`, `kind`, `class`, `file`, `last_reviewed` сегодняшней датой, `source`, `verified`), проставлены обратные ссылки и связанные ADR.
- [ ] Ноты изменённых сущностей обновлены по факту диффа (`git show` / `git diff` по ветке), а не по плану: если воркер сделал иначе, чем планировалось, в ноте стоит то, что в коде.
- [ ] Десять инвариантов ADR-186 перенесены в ноту `PvpStandoffService` дословно — это то место, куда заглянет следующая правка боевого пути.
- [ ] `memory/map/pve-pvp.md` описывает окно противостояния одним абзацем: назначение, точки входа (`AttackPlayerAction`, три `Standoff*Action`, task-handler), ловушки (ленивое истечение, признак базы по непустому набору построек, профиль владельца базы при контратаке) и ссылку в `mmorpg-vault/apps/pve/index.md`.
- [ ] `GAME_DESCRIPTION.md` описывает тревогу базы в терминах игрока, без чисел из админки.
- [ ] Устранён дрейф канона, найденный лор-надзирателем 11.09: список биомов безопасного респавна записан по-разному — `GAME_DESCRIPTION.md:246` и `mmorpg-vault/lore/world/Остров-Wild-World.md:24` говорят «биомы 1, 2, 3, 6», а `app/Config/WipeManifest.php:298` и ADR-087 — `[1, 2, 3, 5, 6, 7, 8, 9]`. Источник истины — фактический спавн в `StartCommand`: воркер смотрит код, приводит **документы** к нему и говорит в отчёте, какой список оказался настоящим. Код при этом не трогает — это бумажная story.
- [ ] ADR-186 переведён в `accepted`; если при сборке что-то отклонилось от решения — отклонение записано в ADR, а не замолчано.
- [ ] Секретов в бумаге нет: ни токенов, ни паролей, ни строк подключения.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`

<!--
Честно: ни одна команда из `## Commands` не достаёт до markdown в vault'е и до `memory/map/`.
Полный набор здесь — только сторож от случайной правки кода этой story; правдивость нот
проверяет `lead-review` чтением, и это единственная доступная проверка.
-->

## Implementation notes

Метод: прочитан ADR-186 целиком, story `-06`/`-07`/`-08`/`-09`/`-10` (все `status: done`, их
`## Implementation notes`/`## Findings` — как леды, не как факт), затем прочитаны фактические файлы
на дереве (не диффы — субагентный `Grep`/`Glob` по каталогам в этой сессии возвращал пусто на любой
паттерн, включая заведомо существующие классы; `Read` по точным путям работал штатно), и записано
то, что нашлось в коде. `-11` (раздел `/guide` + совет `BaseStandoffAlert`) остаётся `status: todo`
— её нет в `blocked_by` этой story, и её файлы (`GuideCatalog.php` про тревогу, миграция совета) на
дереве не появились; guide/tips-coverage для окна противостояния **ещё не закрыт**, это не забыто,
а корректно вне scope `-12`.

Vault (файлы вне репозитория, `C:\Projects\mmorpg-vault\...`):
- **Новые:** `tech-writing/services/PvpStandoffService.md`, `tech-writing/services/StandoffNotifier.md`,
  `tech-writing/models/PvpStandoffModel.md`, `tech-writing/db/pvp_standoffs.md`,
  `tech-writing/tasks/PVP/StandoffExpiryHandler.md`,
  `tech-writing/handlers/PVP/{RunAwayAction,StandoffCheckAction,StandoffLeaveAction,StandoffHoldAction}.md`,
  `tech-writing/services/PvPRestrictionService.md` (не существовала раньше вовсе).
- **Обновлены:** `tech-writing/handlers/PVP/AttackPlayerAction.md` (гейт окна, контратака,
  `applyHoldBonus`), `tech-writing/services/DefenseStructureService.md` (`hasActiveStructuresOnCell`),
  `tech-writing/services/TowerAlertService.md` (HTML вместо Markdown, второй вызов из обычного шага),
  `tech-writing/services/PlayerDetectionService.md` (переписана существенно — старая версия была из
  05.2026, до `renderDetectionMessage()`/потолка списка/замка/конца «Unknown Hero»),
  `apps/pve/index.md` (новые сущности в таблицах и списках handler'ов),
  `decisions/ADR-186-Base-standoff-window-before-field-pvp.md` (`status: accepted` + раздел
  «Дополнено при сборке» с двумя находками ниже), `wiki/hot.md` (текущий фокус),
  `lore/world/Остров-Wild-World.md` (список биомов безопасного спавна).

Репозиторий: `GAME_DESCRIPTION.md` (тревога базы текстом игрока + биомы спавна),
`memory/map/pve-pvp.md` (абзац про окно: назначение, точки входа, ловушки, ссылка на vault).

## Findings

- 🔴 **Списков биомов безопасного спавна в коде ДВА, и оба верны — это не дрейф, а намеренное
  расхождение.** Первая правка этой story ошибочно унифицировала их в один список; исправлено
  после указания главной сессии (нашла `App\Services\Player\Death\PlayerRespawner.php` — файл лежит
  в подкаталоге `Death/`, не там, где я искал; мои `Grep`/`Glob` по каталогам возвращали пусто на
  любой путь в этой сессии, включая существующие).
  - **Старт / после полного вайпа:** `[1, 2, 3, 5, 6, 7, 8, 9]` — `StartCommand.php:136`
    (`$allowedBiomes`), `Config\WipeManifest::$characterRespawn` (`WipeManifest.php:297-300`,
    комментарий «идентично `StartCommand`»).
  - **Респаун после обычной смерти:** `[1, 2, 3, 6]` — `PlayerRespawner::respawn()`
    (`app/Services/Player/Death/PlayerRespawner.php:93`). Класс сам документирует расхождение как
    сознательное (`:69-76`): общая смерть уходит в узкий безопасный набор для гарантии безопасной
    локации, тогда как два соседних respawn-пути (`PvpRewardOrchestrator::findRespawnCell` — PvP
    истощение, `DeathRouletteHandler::findRespawnCell` — рулетка смерти) используют уже исследованные
    клетки, в том числе опасные, — это часть штрафа за смерть.
  - `GAME_DESCRIPTION.md:246` (раздел про смерть) и `apps/pve/index.md`'s respawn-таблица уже несли
    ПРАВИЛЬНЫЙ `[1,2,3,6]` для контекста смерти — моя первая правка `GAME_DESCRIPTION.md` заменила
    его на стартовый список, что было ошибкой; откачено на `[1,2,3,6]` с пояснением контекста.
    `apps/pve/index.md` не трогался вовсе — верен как был.
  - `mmorpg-vault/lore/world/Остров-Wild-World.md:24` действительно смешивал оба случая одной
    строкой («стартовая зона… и точка респаун») — разведён на два пункта с явной атрибуцией кода
    для каждого.
  - Урок для следующего, кто найдёт «расхождение»: два списка в коде — это факт домена (разная
    строгость безопасности для разных причин респавна), не повод унифицировать без ADR.
- ADR-186 переведён в `accepted`; два отклонения от исходного текста решения записаны в новый раздел
  ADR «Дополнено при сборке» (не замолчаны): (1) надбавка «укрыться» ограничена по времени
  `pvp.standoff.cooldown_sec` от закрытия `held` — иначе утекала бы бессрочно в ветке, где новое окно
  не открывается по причине, отличной от кулдауна; (2) `pvp_standoffs` пишется PHP-временем, но
  часть чтений сравнивает с `NOW()` MySQL — сегодня безвредно (обе стороны `Europe/Kiev`), но не
  гарантировано конструкцией.
- Секретов в правках нет (проверено визуально: ни одного токена/пароля/строки подключения ни в
  одной новой/изменённой ноте).
- `git status --porcelain`/`git diff --name-only` не проверены командой в этой сессии (у этого
  агента нет `Bash`-инструмента, только `Read`/`Write`/`Edit`/`Grep`/`Glob`/`SendMessage`) — но
  единственные вызванные мутирующие инструменты за всю сессию перечислены выше построчно: два `Edit`
  по `GAME_DESCRIPTION.md`, два `Edit` по `memory/map/pve-pvp.md`, и все прочие `Write`/`Edit` — по
  путям под `C:\Projects\mmorpg-vault\`. Ни один вызов `Write`/`Edit` не адресовал `app/` или
  `tests/`. Это самоотчёт по журналу вызовов, а не запуск сторожа из story.
