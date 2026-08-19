<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-08-19

# Scout report: Админка и баланс

## Purpose
Управление контентом и балансом, аналитика, воронка, карта навигации, полный вайп сервера.

## Entry points
- `app/Controllers/Admin/` — 22 контроллера; маршруты — группа `admin` с фильтром `login`
  в `app/Config/Routes.php:36`.
- `app/Services/GameSettings/GameSettingsService.php` + `GameSettingsReaderTrait.php` —
  live-tunable баланс.
- `app/Services/Admin/` — `DashboardAnalyticsService`, `FunnelAnalyticsService`,
  `DashboardCsvExporter`, `TrendRange`, **`WipeService`**.
- `app/Config/WipeManifest.php` — классификация КАЖДОЙ таблицы БД (ADR-087).
- Вёрстка: `app/Views/admin/`, `public/assets/css/admin-ui.css`, UI-kit —
  `public/admin-redesign-preview.html`.

## Key types / contracts
Каждый tunable-ключ обязан нести `rationale_text`, `above_effect_text`, `below_effect_text`,
soft- и hard-границы и кнопку «Сбросить к default». Без этих полей запись не сохраняется.

## Dependencies
inbound: браузер админа.
outbound: почти все модели; `GameSettings` читают все доменные сервисы.

## Gotchas
- Кэш `GameSettings` — 60 секунд; на проде значения флипаются через admin UI, не SQL-ом
  (SQL мимо UI обходит audit-trail).
- Новая таблица или player-колонка без записи в `WipeManifest` роняет
  `tests/unit/Config/WipeManifestCoverageTest` и блокирует деплой.
- Дизайн-система админки — «Quiet Premium» (`.aui-*`), не публичный flat-стиль.
- Админка под правило UX-Discoverability **не подпадает** — это не player-interaction.

## Vault
`mmorpg-vault/apps/admin/index.md` · `docs/admin-design/ADMIN_UI_CONSTITUTION.md`
