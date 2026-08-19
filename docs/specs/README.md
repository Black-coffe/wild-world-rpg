# Планы и story

Здесь живут спеки улья — по одному каталогу на задачу:

    docs/specs/<slug>/brief.md    запрос человека дословно + ## Answers на заданные вопросы
    docs/specs/<slug>/plan.md     план: волны, story-индекс, ## Contracts, ## Plan deltas
    docs/specs/<slug>/S<N>-*.md   story-файлы, по одному на единицу работы

Шаблоны — `templates/{plan,story,adr,wiki-note}.md`. Заводятся командой `/vulyk-plan`
(Tier 2 — облегчённо, Tier 3–4 — полным конвейером). Tier 0–1 спеки не заводят вообще.

Гейты по этому каталогу:

```bash
bash scripts/wave-check.sh docs/specs/<slug>     # story готовы к волне?
bash scripts/trace-check.sh docs/specs/<slug>    # каждое требование брифа кем-то накрыто?
bash scripts/scope-check.sh docs/specs/<slug>/S3-something.md   # тронуто ли лишнее
bash scripts/state.sh                            # сводка статусов -> .claude/state.json
```

Оба гейта всегда выходят с кодом 0 и **докладывают**: блокировать или нет — решение человека
или `lead-review`, а не скрипта.

Вердикты по воротам проекта (валидация 7 ворот, `/guide`, «Совет дня», discoverability,
онбординг) фиксируются в `brief.md` этой спеки — включая «не добавляем, потому что…».
Архитектурные решения отсюда уходят в `mmorpg-vault/decisions/`, не в `docs/adr/`.
