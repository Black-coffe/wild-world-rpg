<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-30

# Scout report: Онбординг, обучение, советы

## Purpose
Довести новичка от `/start` до самостоятельной игры и держать механики находимыми: холодный
старт, just-in-time подсказки, обучающая цепочка, справочник `/guide`, «Совет дня».

## Entry points
- `app/Services/Onboarding/` — `ColdOpenGreetingService`, `ColdOpenSignalService`, `PolarStarService`,
  `NewbieGreeterService`, `NewbieAtmosphereService`, `StarterKitService`, `FirstShelterService`,
  `LuckyFindService`, `WinBeatService`, `BuildLockService`,
  `OnboardingChainService` + `OnboardingChainCatalog`,
  `OnboardingHintService` + `OnboardingHintCatalog` (13 ключей: first_base, first_move, first_build,
  first_craft, daily_tasks, automation, greenhouse, first_boss_sighting, first_storage_open,
  armor_no_arsenal, first_march, beacon_crafted, first_long_march),
  `GuideService` + **`GuideCatalog`** (источник истины `/guide`; API: `sections()`, `find()`,
  `nextKey()`, `GROUPS` = start/mid/end/meta; 35 разделов на 2026-09-30).
- Советы: `app/Services/Player/TipService.php` (`pickForCharacter` → `recordView` → `serveTip` →
  `renderTip`), таблица `game_tips`, модель `app/Models/GameTipsModel.php`,
  рассылка `app/TaskHandlers/Tips/DailyTipBroadcastHandler.php`.
- Команды: `app/Controllers/Telegram/Commands/{GuideCommand,TipsCommand,StartCommand}.php`,
  `Commands/Actions/Guide/GuideAction.php` (callback `guide_<key>`).
- Тест-гейт: `tests/unit/Services/Onboarding/GuideCatalogTest.php` (source-scan).

## Key types / contracts
`/guide` — **read-only**: никаких наград, выдач, телепортов и мутаций (source-scan тест
`GuideCatalogTest`). Раздел = `{key, group, button, title, body}`; ключ — только `[a-z]`, без `_`
(callback `guide_<key>`, хвост = key).
`tip_type` — ровно 14 значений ENUM (`in_list` в `GameTipsModel`); чужое значение отклоняет валидатор.
Хинт: `OnboardingHintCatalog::get($key)` → `{text, reply_markup?}`; лимитер в
`OnboardingHintService::maybeSend` (one-shot + killswitch + opt-out).
Рассылка: killswitch `tips.daily_enabled`, час `tips.daily_hour`, once/day-claim
`tips.daily_last_broadcast` в `game_settings`.

## Dependencies
inbound: `/start`, первые действия игрока, крон рассылки (`DailyTipBroadcastHandler`, everyMinute).
outbound: почти все доменные сервисы (подсказки контекстные). `GuideCatalog` НЕ чистые данные
(хотя его docblock так говорит): в рантайме зовёт `App\Services\Telegram\BotMenuService`
(`menuLabel`, `actionLabel`, `gatherOnCompassEnabled`) и `Player\Progression\LevelProgressService::isEnabled()`.

## Gotchas
- Совет добавляется идемпотентной seed-миграцией `*Seed<Что>Tip.php` (77 штук на 2026-09-30; бывает
  префикс `Adr<N>Seed…`), идемпотентность — по `title_en`.
- Эмодзи требуют `utf8mb4` у колонки; ловится только Tier-3 рендером.
- Ни один tip/guide-текст не должен нести хрупкие числа баланса — они дрейфуют.
- Reply-меню само не обновляется: только `/start` и `/menu` его пере-аттачат.
- Подписи кнопок в `/guide` и хинтах берутся из `BotMenuService` в рантайме (инцидент 2026-07-24),
  а не хардкодом. Хардкод уже раз врал («Настройки» в меню нет с ADR-150, они внутри «⚙️ Ещё»).
- Часть текста зависит от killswitch: путь к добыче (`gatherOnCompassEnabled`) и строка прогресса
  уровня (`LevelProgressService::isEnabled`) меняются по флагу — обещай только фактическую раскладку.
- Путь к маякам: раздел `teleport` и хинт `beacon_crafted` ведут «🏠 База» → «📡 Маяки» (старый дрейф
  «маяки на экране 🧑 Я» исправлен). Правь путь в тексте вместе с любой правкой навигации.
  `beacon_crafted` и `first_long_march` — без level-ceiling, в отличие от newbie-funnel хинтов.
- Раздел `nav` называет «🏠 База» с иконкой, а `base`/`craft` берут подпись через `{$base}`.

## Vault
`mmorpg-vault/decisions/ADR-103-Onboarding-system-and-navigation-resilience.md` · ADR-127 (`/guide`)
· ADR-174 §3 (хинт транспорта)
