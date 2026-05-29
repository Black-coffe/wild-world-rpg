# ACTIVATION-PLAN — активация dormant vNext2 + закрытие хвостов

> **Статус:** активный план после 🏁 ROADMAP-CRAFT-vNext2 (W1–W30). Решение W30 (user, 2026-05-29, **гибрид**):
> НЕ генерировать vNext3 сейчас — сначала **активировать построенное** (13 dormant killswitch) + **закрыть
> накопленные хвосты** (DRIFT-BACKLOG, Asana-2022). Лёгкий vNext3 генерируется ПОЗЖЕ, уже на реальных player-данных
> от активированных фич. Ретроспектива vNext2 — `mmorpg-vault/inbox/2026-05-29-roadmap-vnext2-retrospective.md`.

## Зачем (обоснование пивота)

3 роадмепа (v1 → vNext → vNext2) построили десятки фич. vNext2 шипнут весь DORMANT (de-risk). Часть уже активирована
(drone-family W1-W5, robots-T2, faction-project, onboarding, seasonal — `*.enabled=1`). Но **13 killswitch остаются OFF**
(прод-`game_settings`, 2026-05-29) → построено, протестировано на testbot, но игроки НЕ видят. Активация = highest-ROI:
0 нового кода, прямой player-value. Генерировать vNext3 поверх неактивированного долга — наращивать build-debt.

## Методика активации (gate на каждый killswitch)

Каждая активация = отдельная сессия с воротами:
1. **Re-read** ADR фичи + tech-writing нота (актуальность).
2. **Balance-tune** GameSettings под LIVE (dormant-дефолты — «safe baseline», не финальный баланс; rationale per ADR-024).
3. **Tier-3 на ЖИВОМ testbot** (реальный чар, killswitch ON) — полный cold-smoke (UX-discoverability чек).
4. **Enable на проде** через `/admin/game-settings/<category>` (НЕ raw SQL — audit-trail ADR-024).
5. **Observe** — daily log review N дней (`feedback_daily_log_review`); rollback = killswitch OFF мгновенно.
6. **Анонс** — НЕ по ходу; копить в `inbox/`, один консолидированный батч в конце (`feedback_announce_after_roadmap_done`).

## Инвентарь: 13 dormant killswitch (прод 2026-05-29)

Порядок активации — по возрастанию риска (cosmetic → read-only → engagement → balance → live-PvP).

### Stage A — косметика / UX (нулевой balance-риск)
- **A1 — `housing.decoration.enabled`** (W21/W22, ADR-076/077). Чистая косметика базы, 0 механики. ✅ **АКТИВИРОВАН на проде
  2026-05-29 16:08** (admin-UI `/admin/game-settings`, audit-trail; не raw SQL). Pre-flight Tier-3 на testbot (char 491,
  killswitch ON): база-экран → кнопка «🎨 Декор» видна, caption чист, 0 регрессии (housing-код не трогался с W22). Prod
  `value_bool=1` + cache:clear → live для всех. Observe: прод-логи чисты. Анонс — в батч.
- **A2 — `notifications.silent_threshold.enabled`** (W28, ADR-083). UX: рутинные завершения тихие; per-char opt-out на звук. 0 баланса.

### Stage B — read-only отчёты (низкий риск)
- **A3 — `economy.player_report.enabled` + `economy.comparison.enabled`** (W24/W25, ADR-079/080). Read-only, 0 мутаций gold.
  Проверить производительность comparison-батча на живых ~365 чарах (~55мс на testbot).

### Stage C — контент / engagement (средний риск)
- **A4 — `achievement.enabled`** (W9/W10). Event-driven награды. Проверить: нет спама, идемпотентность (max_awards_per_tick).
- **A5 — `cooking.fish_dishes.enabled`** (W23, ADR-078). **БЛОКЕР: заменить placeholder-картинки 3 блюд на реальные
  «Найденная фотоплёнка» (ADR-022) ДО активации.** Heal-значения tunable.
- **A6 — `caravan.bargain.enabled` + `caravan.faction.enabled`** (W14b/W15, ADR-068/069). Скидки/наценки — balance-чувствительно
  (проверить, что торг не ломает экономику; faction-affinity справедлив).

### Stage D — crafted modifiers (средне-высокий, balance)
- **A7 — `craft.modifier.enabled`** (W19/W20, ADR-074/075). +5%/тир оружие+броня, cap +25%, цена gold+Минералы ×тир.
  **Balance-tune ДО live:** power-creep риск; выверить bonus_pct/cost/cap против PvE/PvP. Fence byte-equivalent уже подтверждён.

### Stage E — live PvP (наивысший риск)
- **A8 — `pvp.duel.enabled`** (W17/W18.5, ADR-071/073). Opt-in, equalized, 0 DB-записей, decisive tiebreak. Относительно
  безопасно (спортивный поединок), но первый live-PvP UX — наблюдать.
- **A9 — `pvp.ladder.enabled`** затем `pvp.ladder.broadcast_enabled` (W18, ADR-072). Сначала scoring (накопить данные),
  ПОТОМ еженедельный broadcast. **Broadcast = mass-message: проверить once/week guard** (урок `feedback_once_per_day_guard_db_not_cache`
  — ladder-handler пока cache-маркер, кандидат на DB-claim перед активацией broadcast).

### Отложено (блокировано зависимостями — НЕ активировать сейчас)
- **`i18n.locale_switch.enabled`** (W26/W27) — PREMATURE: локализована лишь «Моя экономика». Активация = half-localized UI
  для игрока. **Блокер:** масс-конверсия handler-поверхностей на `lang()` + DB-контент перевод (колонка `*_en`). Это
  объём отдельной серии — кандидат в будущий vNext3 ИЛИ тейл-трек, не в near-term активацию.
- **`inventory.weight_cap.enabled`** (W3a) — RESTRICTS игроков (soft-cap веса); `weight_capacity` default 9999=off.
  **Блокер:** balance-pass W3b (реальные l1_base/per_level) НЕ сделан. Player-hostile без выверенного баланса —
  активировать ТОЛЬКО после дизайн-решения; возможно никогда без явного game-design обоснования.

## Параллельный тейл-трек (не-активационный долг)

- **T1 — DRIFT-BACKLOG.md** (`mmorpg-vault/tech-writing/DRIFT-BACKLOG.md`) — ~45 pre-vNext2 (V*/S*/F*) tech-writing нот.
  Закрывать инкрементально по касанию ИЛИ выделенным sweep'ом.
- **T2 — Asana-бэклог (с 2022)** — накопленные таски, не трогавшиеся за 3 роадмепа. **Нужен экспорт/доступ от user'а**
  (Claude не имеет доступа к Asana). Триаж: дедуп против реализованного (многое могло закрыться по ходу v1/vNext/vNext2),
  классификация 🔴/🟠/🟡/🟢 фреймворком валидации, слияние выживших в активный план.
- **T3 — W28-backlog** — batching/digest + throttle/anti-flood уведомлений (отложено из W28).
- **T4 — gold-ledger foundation** — разблокирует time-series economy reports (W24 отложено).

## Консолидированный анонс (после активационного батча)

После Stage A–E — один анонс игрокам по всем активированным фичам (drone-family уже live + новый батч). Драфты копить в
`inbox/` по ходу активаций. `feedback_announce_after_roadmap_done`.

## vNext3 — отложен (генерируется ПОЗЖЕ)

После активации + наблюдения реальных player-данных (что зашло, что нет) — лёгкий 5-осевой прогон уже на ФАКТАХ
вовлечённости, а не гипотезах. Кандидаты-зародыши: i18n полная (если en-аудитория растёт), weight-cap balance (если
нужен gold-sink), Quest T2 расширение, fishing-as-gather, festival events, gold-ledger. Источник тейлов — ретроспектива
vNext2 §Открытые tail'ы + данные активации.
