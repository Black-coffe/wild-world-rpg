---
paths: ["app/Services/**", "app/Config/GameBalance.php"]
---
# Баланс — только через админку

- Любой параметр, влияющий на баланс (цена, количество, вероятность, длительность, кулдаун,
  формула боя, лимит, порог, флаг фичи, прогрессия), регистрируется в `GameSettings` и правится
  из `/admin/game-settings/<category>`. Магическое число в коде — отказ в мердже.
- Каждый ключ обязан нести `rationale_text`, `above_effect_text`, `below_effect_text`,
  soft- и hard-границы. Без них запись не сохраняется.
- Не баланс, а инфраструктура (таймауты, retry, размеры буферов, cron-расписания, enum'ы,
  идентификаторы, тексты UI) — остаётся в `Config\*`.
- Кэш `GameSettings` — 60 секунд. На проде значение флипается через admin UI: прямой SQL обходит
  audit-trail.
- Дефолт в коде ≠ значение на проде. Перед выводом «параметр равен X» — SELECT на проде.
- Умножающий бонус на маленьком числе может быть невидим: `round(1 × 1.55) = 1`. Проверять на
  реальных величинах, а не на «в формуле всё правильно».

---

<!-- Полный текст, перенесён дословно из CLAUDE.md 2026-09-14 (сокращение контекста субагентов). Инвариант остался в CLAUDE.md. -->

## 🎛️ КОНСТИТУЦИОННОЕ ПРАВИЛО ADMIN-TUNABLE BALANCE (зафиксировано 2026-05-17, ADR-024 будущий)

**Любой параметр, влияющий на сеттинг / баланс / ребаланс игры — ОБЯЗАТЕЛЬНО выносится в админку через `GameSettings` live-tunable framework.** Не магическое число в коде, не константа в `Config\GameBalance`, не hardcoded ENV.

Полный текст и аргументация — `memory/feedback_admin_tunable_balance.md` (всегда загружено).

### Что обязано быть у каждого admin-tunable параметра

| Атрибут | Назначение |
|---|---|
| `setting_key` | `category.subcategory.name` (snake_case_dot_notation) |
| `value_*` | Текущее значение (типизированная колонка) |
| `default_value_text` | Default который выставил **Claude** при создании ключа — для Reset button |
| `category` | Логическая группа (craft / buildings / resources / combat / world / endgame / experimental) |
| `rationale_text` | **Почему сейчас стоит именно это значение** (1–3 предложения) |
| `effect_text` | **На что влияет** (механики, формулы, какие игроки заметят) |
| `above_effect_text` | **Что произойдёт, если выше** (конкретный сценарий: «при 0.70 ремонт почти бесплатный, П8 теряют sink, инфляция») |
| `below_effect_text` | **Что произойдёт, если ниже** (конкретный сценарий) |
| `recommended_min/max` | Soft-границы (admin UI окрасит warning жёлтым) |
| `hard_min/max` | Жёсткие пределы (сохранение запрещено вне их) |
| `updated_at/by` | Audit trail |

**Без `rationale_text` / `above_effect_text` / `below_effect_text` — запись не должна сохраняться.** Это invariant.

### Когда вынос обязателен

Любой параметр меняющий:
- 💰 **цену/стоимость/количество** ресурсов или валюты
- 🎲 **вероятности** (RNG, drop rates, spawn chances, crit chances)
- ⏱ **время** (durations, cooldowns, intervals)
- ⚔ **формулы боя** (damage reduction, multipliers, caps)
- 📊 **лимиты/пороги** (max inventory, slot counts, level gates)
- 🚩 **флаги фич** (seasonal enabled, etc.)
- 📈 **прогрессию** (XP needed, levels-per-tier)

→ Регистрируй в `GameSettings` с полным rationale, **не оставляй в коде**.

### Когда НЕ нужно

- Архитектурные timeouts / retry counts / buffer sizes (не игровая механика) → `Config\*` ok.
- Идентификаторы и enum'ы (`status='queued'`) → data shape, не баланс.
- Тексты UI / локализация → у них своя инфра (`tips`, descriptions).
- Cron schedules → инфраструктура.

### Admin sidebar — структурированный, НЕ хаотичный

`/admin/game-settings/<category>` — отдельный экран на категорию. Sidebar дерево:

```
⚙️ Параметры баланса
├── 🔧 Крафт и ремонт          (craft)
├── 🏗 Стройка и постройки     (buildings)
├── 💎 Ресурсы и редкость       (resources)
├── ⚔ Бой и PvP                 (combat)
├── 🌐 Мир и события            (world)
├── 🎯 Эндгейм                   (endgame)
└── 🧪 Экспериментальные        (experimental — A/B флаги)
```

### Reset-to-default — обязательно

Каждая настройка в admin UI имеет кнопку **«Сбросить к default»**. Default-ы выбираю Я (Claude) при создании ключа — на основе game-design анализа, 10-портретного чека, принципа «safe baseline». Корректировка default → новая seed-migration.

### Anti-patterns (не делай)

- ❌ `const REPAIR_COST = 0.5` в коде (магическое число)
- ❌ Tunable значение в `Config\GameBalance` но не в `GameSettings` (двойная правда)
- ❌ GameSettings ключ без `rationale_text` / `above` / `below`
- ❌ Admin UI без Reset-to-default
- ❌ Плоский список ключей без категоризации в sidebar
- ❌ SQL напрямую в `game_settings` минуя admin UI (обходит audit-trail)

### Foundation

`GameSettings` framework — выход **S5 ROADMAP-CRAFT v1** (`ADR-024`; сам роадмап закрыт и удалён — история в git). До его реализации новые tunable параметры можно временно держать в `Config\GameBalance` с TODO-комментарием «→ GameSettings после S5», но **с момента ship'а S5 — миграция обязательна**.

### Чек-лист «закрытой задачи» дополнен (новый пункт)

При завершении любой сессии — **проверь**, не появились ли в твоём коде hardcoded balance-числа. Если да — зарегистрируй в `GameSettings` с полной rationale-документацией. Без этого задача **не считается закрытой**.

---
