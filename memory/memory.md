# Hive memory index

<!-- Pointer index: <= 60 lines, always loaded. Pointers are hints - verify against code before acting.
     Maintained by drone-docs (pointers) and librarian (hygiene). Humans welcome too. -->

## Три памяти — не перепутать
- `memory/` — рабочая память улья: карта, уроки, статистика. Едет в git вместе с кодом.
- `mmorpg-vault/` — знание о продукте: канон (`lore/`), tech-writing, 169 ADR (`decisions/`), daily.
- `mmorpg-vault/claude-memory/` — уроки о том, как со мной работать (`autoMemoryDirectory`).

## Что в работе сейчас
- `mmorpg-vault/wiki/hot.md` — читать в начале сессии, до планирования.

## Codebase map
- `memory/map/telegram.md` — webhook, команды, ~54 action-handler'а, MediaSender
- `memory/map/tasks-worker.md` — cron → `Worker::processTasks` → 74 TaskHandler'а
- `memory/map/player.md` — `Services/Player`: статы, инвентарь, смерть, телепорты, дроны
- `memory/map/world.md` — карта, биомы, туман войны, объекты, Поход
- `memory/map/craft.md` — рецепты, длительность, износ, дерево крафта, калькулятор
- `memory/map/bases.md` — лагерь, постройки, налог, мульти-база
- `memory/map/pve-pvp.md` — бой, лут, боссы, дуэли, лестница, лог боя
- `memory/map/quests-events-npc.md` — квесты, ежедневки, мировые события, диалоги NPC
- `memory/map/onboarding.md` — холодный старт, подсказки, `/guide`, «Совет дня»
- `memory/map/admin.md` — админка, `GameSettings`, `WipeManifest`, вайп
- `memory/map/website.md` — публичный сайт wildworld.fun, SEO, CMS в БД
- `memory/map/data-layer.md` — 80 моделей, 524 миграции, Entity, репозиторий

## Unmapped territory
Срезы посеяны обследованием дерева и конституцией, а не чтением кода: они верны на уровне
«где что лежит и обо что спотыкаются», но не на уровне сигнатур. Углублять адресно —
`/vulyk-map <path>`, когда задача заходит в область. Не покрыты отдельными срезами:
`Services/{Analytics,Economy,Endgame,Farming,Food,Images,Localization,Navigation,Oracle,
Settlement,Social,Display,Duration,More}`, `app/Commands/` (14 spark-команд), `app/Filters/`,
`app/Language/`, `deploy/`, `tests/`.

## Аудиты
- `docs/specs/exploit-audit/REPORT.md` — 34 находки эксплойт-аудита (4 🔴 / 4 🟠 / 18 🟡 / 8 ⚪),
  бэклог правок `FIX-BACKLOG.md` (F0–F30). `tests/exploit-poc/` — 29 PoC-тестов (RED = доказанный
  эксплойт), testsuite `exploit-poc` исключён из дефолтного `--testsuite App` (`phpunit.xml.dist`),
  гонять по одному файлу.

## Wiki domains
Доменные ноты живут в vault'е, не в `docs/wiki/`:
- `mmorpg-vault/apps/<подсистема>/index.md` — участники подсистемы
- `mmorpg-vault/tech-writing/{models,services,handlers,tasks,controllers,db}/` — по сущностям
- `mmorpg-vault/glossary/` — термины канона
- `mmorpg-vault/lore/` — канон геймплея

## Verification
- test: `vendor/bin/phpunit --no-coverage --no-progress`
- lint: `vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
- migrations: `git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`
- build: none — PHP ничего не собирает
- Зелёный Tier 1 ≠ «работает»: Telegram-рендер и админка проверяются Tier 2/3, см.
  `CLAUDE.vulyk.md` → `## Project bindings`.

## Learnings
- Consolidated: memory/learnings/CONSOLIDATED.md (run /vulyk-gc to refresh)
