<!-- Срез-указатель, а не копия территории. Подробность — в mmorpg-vault; здесь только то,
     что нужно, чтобы понять, куда идти, и не вляпаться. Посеян обследованием дерева репозитория
     и конституцией проекта 2026-08-19; углубляется /vulyk-map <path> через drone-scout. -->
last-verified: 2026-09-08

# Scout report: Онбординг, обучение, советы

## Purpose
Довести новичка от `/start` до самостоятельной игры и держать механики находимыми: холодный
старт, just-in-time подсказки, обучающая цепочка, справочник `/guide`, «Совет дня».

## Entry points
- `app/Services/Onboarding/` — `ColdOpenGreetingService`, `ColdOpenSignalService`, `PolarStarService`,
  `NewbieGreeterService`, `NewbieAtmosphereService`, `StarterKitService`, `FirstShelterService`,
  `LuckyFindService`, `WinBeatService`, `BuildLockService`,
  `OnboardingChainService` + `OnboardingChainCatalog`,
  `OnboardingHintService` + `OnboardingHintCatalog`,
  `GuideService` + **`GuideCatalog`** (источник истины `/guide`).
- Советы: `app/Services/Player/TipService.php`, таблица `game_tips`, модель `GameTipsModel`,
  рассылка `app/TaskHandlers/Tips/DailyTipBroadcastHandler.php`.
- Команды: `GuideCommand`, `TipsCommand`, `StartCommand`.

## Key types / contracts
`/guide` — **read-only**: никаких наград, выдач, телепортов и мутаций (это проверяет source-scan
тест `GuideCatalogTest`). Ключ раздела — только `[a-z]`, без `_`.
`tip_type` — ровно 14 значений ENUM; чужое значение отклоняет валидатор модели.

## Dependencies
inbound: `/start`, первые действия игрока, крон рассылки.
outbound: почти все доменные сервисы (подсказки контекстные).

## Gotchas
- Совет добавляется идемпотентной seed-миграцией `*Seed<Что>Tip.php`, идемпотентность — по `title_en`.
- Эмодзи требуют `utf8mb4` у колонки; ловится только Tier-3 рендером.
- Ни один tip/guide-текст не должен нести хрупкие числа баланса — они дрейфуют.
- Reply-меню само не обновляется: только `/start` и `/menu` его пере-аттачат.
- Обучающие тексты могут устареть от навигационных правок в другом домене: JIT-хинт
  `beacon_crafted` и раздел `/guide` `teleport` до 08.09.2026 обещали кнопку маяков «на экране
  «🧑 Я»» — её там нет с 09.07.2026 (`navigation.final_grid.enabled=1`, финал ADR-150). Правь
  путь, который называет текст, вместе с любой правкой навигации, а не только сам текст.

## Vault
`mmorpg-vault/decisions/ADR-103-Onboarding-system-and-navigation-resilience.md` · ADR-127 (`/guide`)
