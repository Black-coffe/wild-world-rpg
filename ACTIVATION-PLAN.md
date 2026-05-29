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
  ✅ **АКТИВИРОВАН на проде 2026-05-29 16:11** (admin-UI, audit-trail). Pre-flight покрыт same-session W28 Tier-3 (toggle
  gated-visibility + flip обе стороны + dormant-gate + e2e DB 3-state); код не трогался. Prod `value_bool=1` + cache:clear,
  0 чаров opted sound-on → все получают редизайн-дефолт (рутинные тихо). **⚠️ Поведенческое изменение** (не косметика):
  task-done уведомления больше не звенят. **Анонс-батч ОБЯЗАН объяснить:** «завершения задач теперь тихие по умолчанию;
  вернуть звук — Настройки → 🔔 Звук о завершении задач». Draft в inbox.

### Stage B — read-only отчёты (низкий риск)
- **A3 — `economy.player_report.enabled` + `economy.comparison.enabled`** (W24/W25, ADR-079/080). Read-only, 0 мутаций gold.
  ✅ **АКТИВИРОВАН на проде 2026-05-29 16:17** (оба killswitch через admin-UI). **Perf-чек PASS:** comparison-батч на проде
  (365 чаров, 2 correlated subquery/чар) = **41.9мс** (план был ~55мс). Pre-flight покрыт W24/W25 Tier-3. Read-only →
  0 риска записи. Faction-блок покажется только фракциям с ≥5 членов (Нейтралы 8/Инженеры 6/Партизаны 5; Милитари 3/
  Фермеры 1 — нет); server-wide блок всегда. Кнопка «💰 Моя экономика» в карточке Перса live.

### Stage C — контент / engagement (средний риск)
- **A4 — `achievement.enabled`** (W9/W10). Event-driven награды. ✅ **АКТИВИРОВАН на проде 2026-05-29 16:28** (admin-UI).
  **Pre-flight testbot (throwaway cron-run):** RUN1 awarded 20 (char 491→14/489→6), RUN2 +0 (идемпотентно → rollout
  терминирует); батч-уведомление «🏅 Новые достижения!» (14 в одном msg) рендерится чисто в Telegram Web. **Prod rollout
  подтверждён:** 1-й тик = 25 наград 25 чарам (cap=25/tick работает). 21 ачивка, 0 pre-awards, 365 чаров → throttled
  rollout ~25/мин. Уведомления ЛОУД (не silenced A2 — корректно для engagement). Идемпотентность гарантирует терминацию.
- **A5 — `cooking.fish_dishes.enabled`** (W23, ADR-078). **БЛОКЕР: заменить placeholder-картинки 3 блюд на реальные
  «Найденная фотоплёнка» (ADR-022) ДО активации.** Heal-значения tunable.
- **A6 — `caravan.bargain.enabled` + `caravan.faction.enabled`** (W14b/W15, ADR-068/069). Скидки/наценки — balance-чувствительно.
  ✅ **АКТИВИРОВАН на проде 2026-05-29 23:21** (оба killswitch через admin-UI `/admin/game-settings?category=world`, audit-trail;
  prod cache:clear → live; throwaway `a6:confirm` подтвердил bot читает оба ON). **Balance pre-flight на ЖИВОЙ торговой экономике
  (prod-данные + testbot service-layer smoke):**
  - **trading_karma** (вход торга): 354/365 чаров на дефолте 100 → `intdiv(100,10)=10%`; только 4 чара >110 (max 210 → cap 15%);
    1 negative-karma → 0% (guard `max(0,…)`). Итог: торг ≈ **плоские −10%** почти всем. Мягко, предсказуемо.
  - **faction**: лишь **15 чаров** в ривалри-способных фракциях (Милитари 3/Партизаны 5/Инженеры 6/Фермеры 1); Нейтралы (8) всегда
    neutral (караваны роллят faction 1-4, не 5). Существующие 5 активных караванов faction_id=NULL → нейтральны до истечения (~2ч);
    affinity только у НОВЫХ спавнов (chance 0.5).
  - **Стэк-математика (base=70% рынка):** member+торг ~60.2% рынка (глубочайший); neutral+торг 63%; **rival+торг ~72.8% — всё ещё
    НИЖЕ рынка** (торг частично гасит наценку до +4% над base) → contract-safe, ривал не наказан, просто меньше скидка.
  - **Killswitch-ON path** проверен throwaway `a6:preflight` на testbot (char 491 Фермер/karma100): member 100→95→86, rival 100→115→104,
    neutral 100→100→90 — совпало с hand-calc. Buy-handler пересчитывает обе скидки server-side (secure, не доверяет callback).
  - Defaults не трогал (designed safe baseline, live-данные подтвердили мягкость). Код не менялся (W14b/W15 уже Tier-3'д визуально).
  - **NB:** `caravan.bundle_chance` (W14a «богатый караван») остаётся 0 — НЕ в scope A6; кандидат на отдельную активацию.

### Stage D — crafted modifiers (средне-высокий, balance)
- **A7 — `craft.modifier.enabled`** (W19/W20, ADR-074/075). +5%/тир оружие+броня, cap +25%, цена gold+Минералы ×тир.
  ✅ **АКТИВИРОВАН на проде 2026-05-29 23:30** (admin-UI `?category=craft`, audit-trail; cache:clear; throwaway `a7:confirm` → enabled=true).
  **Balance pre-flight на ЖИВЫХ prod-данных (power-creep чек):**
  - **Cap +25% умеренный:** оружие 4-45 dmg (avg 22) → max 45→56; броня 1-32 (avg 16) → max 32→40. **Симметрично в PvP**
    (обе стороны могут модернизировать) → не даёт нечестного преимущества, только поднимает потолок. PvP duels/ladder пока OFF
    (A8/A9) → эффект сейчас в основном PvE + base-raids.
  - **Tiny blast radius:** лишь **6 экипированных оружий + 6 outfit'ов** на весь сервер → минимальная экспозиция power-creep.
  - **Gold-sink здоровый:** 30k gold за полный max одного предмета (5 тиров: 2000×tier). Avg gold 286k, один хордер 67M →
    модернизация = осмысленный gold-sink (дренирует хорды).
  - **Минералы (id11, rarity-1, 10g):** держат лишь 16/365 чаров (был near-dead ресурс) → модернизация даёт ему смысл (как
    рыба в W23); 75 за max-предмет (~750g) = мягкий gather-gate, не реальная цена.
  - **Killswitch-ON path verified** throwaway `a7:preflight` на testbot (char 491 Гаусс-пистолет): 5 тиров 5%→25%, цена
    2000×tier gold + 5×tier Минералы (итого 30k+75), 6-й отклонён `max_tier`, множитель 1.25 (25→31.3 dmg), preview-цены верны.
  - Defaults НЕ трогал (designed safe baseline, live-данные подтвердили умеренность + почти нулевой addressable audience).
    Можно поднять gold_cost позже, если данные покажут тривиальный sink для китов. **Fence byte-equivalent подтверждён ранее (W19/W20).**

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
