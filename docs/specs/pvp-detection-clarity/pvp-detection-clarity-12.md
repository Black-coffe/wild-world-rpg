---
story: pvp-detection-clarity-12
spec: pvp-detection-clarity
status: todo
tier: 1
worker: drone-docs
tracer: false
wave: 4
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

## Findings
