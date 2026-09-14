---
paths: ["app/Models/**", "app/Services/**", "app/Controllers/**", "app/TaskHandlers/**", "app/Database/Migrations/**"]
---

# Tech-writing contract

Конституционное правило из `CLAUDE.md` №1: полный текст ниже.

---

<!-- Полный текст, перенесён дословно из CLAUDE.md 2026-09-14 (сокращение контекста субагентов). Инвариант остался в CLAUDE.md. -->

## 📚 КОНСТИТУЦИОННОЕ ПРАВИЛО TECH-WRITING (зафиксировано 2026-05-04, ADR-009)

**ЛЮБОЕ изменение в коде, затрагивающее модель / сервис / Telegram action-handler / task-handler / контроллер — ОБЯЗАНО сопровождаться синхронным обновлением соответствующей ноты в `mmorpg-vault/tech-writing/`.**

### Где какая нота

| Категория | Путь | Шаблон |
|---|---|---|
| CI4-модели | `tech-writing/models/<ModelName>.md` | `_templates/model-doc.md` |
| Сервисы | `tech-writing/services/<ServiceName>.md` | `_templates/service-doc.md` |
| Telegram action-handler'ы | `tech-writing/handlers/<group>/<HandlerName>.md` | `_templates/handler-doc.md` |
| Task-handler'ы | `tech-writing/tasks/<group>/<HandlerName>.md` | `_templates/handler-doc.md` |
| Web/Admin контроллеры | `tech-writing/controllers/<ControllerName>.md` | `_templates/service-doc.md` |
| Миграции (карта таблиц) | `tech-writing/db/<table>.md` | (структура аналогична model-doc) |

### Что включает обязательное обновление

**При создании нового кода:**
- Создать ноту по соответствующему шаблону.
- Заполнить frontmatter: `type`, `kind`, `class`, `file`, `last_reviewed: <today>`, `source: human` (или `mixed` если генерил Claude), `verified: true`.
- Проставить ссылки на смежные ноты (`apps/<подсистема>/index.md` уже знает про эту сущность? — обновить и там).
- Указать связанные ADR (если новое решение — создать ADR в `decisions/`).
- В блок «Где используется» — добавить актуальные callers.

**При изменении кода:**
- API изменился (новый метод / параметр / поле модели) → обновить раздел «Публичный API» или «Поля».
- Триггер task-handler'а сменился → обновить раздел «Триггер».
- Добавили новый action_name в `ActionLogModel` → обновить «Audit-коды».
- Зависимости изменились → обновить «Зависимости».
- Обновить `last_reviewed: <today>` в frontmatter.

**При удалении кода:**
- Не удалять ноту, а пометить frontmatter `status: deprecated` + причину.
- В шапке — ссылка на замещающую ноту (если есть).

### Зачем это правило

🔍 **Навигируемость на масштабе.** На сегодня — 49 моделей, ~30 сервисов, ~80 action-handler'ов, ~70 task-handler'ов. Без wiki будет невозможно найти «где это лежит, что делает, кто вызывает».

🧠 **Selective reading для Claude.** Claude адресным `Read` тянет нужную ноту (`apps/pve/index.md`, `tech-writing/services/BattleService.md`) вместо вкачивания целой подсистемы в контекст.

🔗 **Граф связей.** Wiki-links между моделями/сервисами/handler'ами строят граф. Obsidian Graph View показывает кластеры. Видно сирот.

⏰ **Anti-drift.** Без правила доки разойдутся с кодом за 3-4 месяца. Правило заставляет обновлять синхронно.

### Что считается «завершённой задачей»

- ✅ Идея прошла фреймворк валидации (`GAME_RULES_AND_VALIDATION_FRAMEWORK.md` — 7 ворот, классифицирована в 🔴/🟠/🟡/🟢; для 🟠 — ADR создан)
- ✅ Код написан
- ✅ PHPUnit-тесты зелёные (`composer test`)
- ✅ Миграция применена и протестирована (если меняли схему)
- ✅ Документация в репо обновлена (`CLAUDE.md`, `GAME_DESCRIPTION.md` если задели лор)
- ✅ **Tech-writing нота обновлена в vault'е**
- ✅ **Если значимое решение — ADR создан в `mmorpg-vault/decisions/`**
- ✅ **`mmorpg-vault/wiki/hot.md` обновлён** (если контекст сменился)
- ✅ **Если новый контент требует картинки** (крафт / здание / событие / оружие / NPC / фракция) — LEXICON-запись + строка в `Config\ImageRegistry` + сгенерённая картинка (`php spark images:generate`), стиль = «Найденная фотоплёнка» (ADR-022, `mmorpg-vault/reference/Image-Style-Bible.md`, runbook `image-generation.md`). См. CONTRIBUTING §Image contract.
- ✅ Коммит с осмысленным русским сообщением

### Workflow в конце сессии Claude

Перед `/clear` или завершением работы — Claude обязан:

1. **Обновить `mmorpg-vault/daily/<сегодня>.md`** с разделами «Сделано», «Решения», «Открытые вопросы», «Завтра».
2. **Обновить `mmorpg-vault/wiki/hot.md`** под актуальный фокус (если изменился).
3. **Создать/обновить tech-writing ноту** для каждой затронутой сущности.
4. **Создать ADR** если приняли архитектурное решение.

---
