# HONEST-OFF — не обещать выключенное (plan)
**Brief:** [brief.md](brief.md) · **Branch:** `vulyk/honest-off-1127` · **Approved:** «Одобряю (Рекомендую)», 2026-11-27

## Goal
Справочник и советы перестают описывать как живое то, что выключено. Раздел `/guide` «Трофейная подать» и совет
`TributeDominance` показываются, только когда `tribute.enabled` включён: у раздела появляется необязательный ключ
`flag`, у советов — карта «`title_en` → флаг» в `TipService`, обе проверки в одном месте общего кода, поэтому бот и
`/play` (тот же `GuideCommand`/`GuideAction` через мост) меняются вместе. Раздел «💬 Общий чат» и совет
`Adr176CommunityChatExists` переписываются без обещания, что Роби отвечает в чате.

## Assumptions
- Сайт и канон уже чисты (ask 4): в `app/Views/site`, `app/Views/play` и `GAME_DESCRIPTION.md` нет текстов о подати и
  ответах Роби в чате; на проде в опубликованных `site_posts` и в `site_pages` совпадений нет (SELECT 27.11; пост №122
  упоминает Роби-проводника у высадки, не чат). Лор `Роби.md` уже требует не выдавать выключенное за живое.
- Кнопки подати уже гейтятся флагом (`CharacterSheetService.php:340`, `MoreSurfaceService.php:107,224`,
  `EntertaimentAction.php:50`) — не трогаем.
- `GuideCatalog::sections()` остаётся нефильтрованным: из него строит корпус community-бот (`CommunityGuard.php:1098`),
  там подать уже стоп-тема. Фильтр — новый `visibleSections()` для `indexPayload`, `find`, `nextKey` (иначе «Дальше» из
  `standoff` вело бы в скрытую подать). Скрытый ключ по прямой ссылке падает в оглавление существующим путём.
- Совет №81 в базе не меняем — только не выдаём, пока флаг выключен; при включении он вернётся сам.
- Новые тексты (на ваше одобрение вместе с планом):
  - раздел «💬 Общий чат»: пункт «🤖 Роби читает чат…» и строка «…либо от Роби…» заменяются на
    «• 🙋 *Кто отвечает.* Живые выжившие — и владелец игры, когда заглядывает. Роби в чате не пишет: его подсказки —
    здесь, в справочнике, и в */tips*.»; «📍 Где он» и «🤝 Личные споры и жалобы» без изменений;
  - совет №143: «💬 Знал, что у сообщества есть общий чат? Там живые выжившие помогают друг другу. Подробнее (где
    искать, чего ждать и куда с личным вопросом) — команда */guide* → раздел *«💬 Общий чат»*.»
- Когда включат `community.enabled` (пункт COMM), тексты чата возвращаются той же спекой включения — строка в COMM.
- Имя миграции совета сортируется после последней (`2026-12-30-100000_…`), по образцу
  `2026-12-15-100000_WebLinkTipWithoutEmail.php` (`TITLE_EN`, `NEW_CONTENT`, `OLD_CONTENT`, `up`/`down`).
- Goes live: мердж ничего не выкатывает; push в `develop` → preprod; тег → прод, миграция совета применяется
  `post-deploy.sh`.

## Stories
- [x] S1 Раздел `/guide` за флагом и честный раздел чата - asks: 1, 3, 5, 7
  - files: `app/Services/Onboarding/GuideCatalog.php`, `app/Services/Onboarding/GuideService.php`,
    `tests/unit/Services/Onboarding/GuideCatalogTest.php`, `tests/unit/Services/Onboarding/GuideServiceFlagTest.php` (новый),
    tech-writing: `services/GuideCatalog.md`, `services/GuideService.md`, `handlers/guide/GuideAction.md`
  - test first: при `tribute.enabled`=0 оглавление не содержит кнопки `guide_tribute`, `guide_tribute` открывает
    оглавление, «Дальше» из `standoff` ведёт не в `tribute`; при 1 — всё как раньше; тело `chat` не содержит обещания
    ответа Роби. Падает до кода: раздел виден всегда. `testChatSectionExplainsWhatItIsAndOwnerRoute` закрепляет
    старое обещание («Роби», «суток») — переписать под новый текст, сохранив проверку «владельцу» и «без цифр».
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding tests/unit/Content/NavigationLabelsInTextsTest.php tests/unit/Web/WebPlayTextsTest.php && php scripts/defects-bot-lexicon-check.php`
- [x] S2 Совет подати за флагом и честный совет о чате - asks: 2, 3, 5, 6, 7
  - files: `app/Services/Player/TipService.php`, новая миграция `app/Database/Migrations/2026-12-31-100000_CommunityChatTipWithoutRobiAnswers.php`,
    `tests/database/TipServiceTest.php`, `tests/database/CommunityChatTipWithoutRobiAnswersTest.php` (новый),
    tech-writing: `services/TipService.md`, `tasks/tips/DailyTipBroadcastHandler.md`
  - test first: `pickForCharacter` при `tribute.enabled`=0 никогда не выбирает `TributeDominance` (единственный
    кандидат → null), при 1 — выбирает; миграция меняет текст №143 по `title_en`, повторный `up` ничего не ломает,
    `down` возвращает старый. Падает до кода: выбор без фильтра.
  - check: `vendor/bin/phpunit --no-coverage --no-progress tests/database/TipServiceTest.php tests/database/DailyTipBroadcastHandlerTest.php tests/database/CommunityChatTipWithoutRobiAnswersTest.php && git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

Вердикты (ask 7): `/guide` — правка двух существующих разделов, нового нет; совет — правка №143 и условие показа №81,
нового нет; онбординг и discoverability — не затронуты (вход в подать и так скрыт флагом).

## Test command
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/Onboarding tests/unit/Content/NavigationLabelsInTextsTest.php tests/unit/Web/WebPlayTextsTest.php tests/database/TipServiceTest.php tests/database/DailyTipBroadcastHandlerTest.php tests/database/CommunityChatTipWithoutRobiAnswersTest.php`
плюс phpstan и `php -l` миграций; до push — весь набор одним процессом без ключа (`.claude/rules/tests.md`).

## Descoped
(none)
