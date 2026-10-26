---
story: w-reg-polish-02
spec: w-reg-polish
status: todo
returned:
tier: 2
worker: worker-code
wave: 1
blocked_by: []
---

# «☰» с оговоркой «в Telegram», «нижняя панель» → «внизу экрана», проверка класса

## Goal
Тексты, которые уходят и в `/play`, не обещают там значок «☰» и «нижнюю панель»: «☰» упоминается только с
оговоркой «в Telegram» в той же фразе, «нижняя панель вернётся» → «кнопки вернутся внизу экрана». Места: fallback
«не понял» (`GenericmessageCommand.php:229-230`), раздел `/guide` (`GuideCatalog.php:987`), ответ на /menu
(`BotMenuService.php:325`) и всё, что найдёт расширенная проверка. `scripts/defects-bot-lexicon-check.php` получает два
шаблона: «нижн* панел*» и «☰» в литерале без «Telegram»; карточка `docs/defects/bot-lexicon-in-web.md` дописана
соседней формой, фикстура соседней формы — случаем «☰» без оговорки.

## Requirements
> 6. «☰» — с оговоркой «в Telegram», «нижняя панель» → «внизу экрана»; проверка класса ловит оба.
> соседняя форма бот-лексики вне проверки — «нижняя панель» и «значок «☰» рядом с полем ввода» в fallback «не понял»

## Files
- app/Controllers/Telegram/Commands/SystemCommands/GenericmessageCommand.php
- app/Services/Onboarding/GuideCatalog.php
- app/Services/Telegram/BotMenuService.php
- scripts/defects-bot-lexicon-check.php
- docs/defects/bot-lexicon-in-web.md
- docs/defects/INDEX.md
- docs/defects/fixtures/bot-lexicon-in-web-neighbour.php
- tests/_support/Community/provenance-corpus.php

## Non-goals
- Не писать две версии текста по `VirtualChat::is()` — одна фраза на оба клиента (решение W-REG.2 и владельца).
- Не трогать описания команд `setMyCommands` и `MenuCommand` из `ALLOWED` — они только в меню Telegram.
- Не ловить «☰» в комментариях; не трогать старые миграции.

## Map slice
`memory/map/onboarding.md` (`GuideCatalog`), `memory/map/scripts.md` (проверка бот-лексики), `docs/defects/bot-lexicon-in-web.md`.

## Acceptance criteria
- [ ] `php scripts/defects-bot-lexicon-check.php` на дереве выходит 0; на фикстуре соседней формы — не 0, и она держит оба новых случая («нижняя панель» и «☰» без «Telegram»).
- [ ] Литерал «В Telegram … «☰» …» проходит; литерал «… значок «☰» рядом с полем ввода» без «Telegram» — падает.
- [ ] Fallback «не понял», раздел `/guide` и ответ на /menu говорят о «☰» только с «в Telegram», «нижней панели» в игроцком тексте нет; Markdown парный.
- [ ] `bash scripts/defects-check.sh` зелёный, карточка в INDEX.

## Verification
`bash scripts/defects-check.sh`
`vendor/bin/phpunit --no-coverage --no-progress tests/unit/Services/CommunityGuardTest.php`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
