---
story: w-reg-polish-02
spec: w-reg-polish
status: done
returned: DONE
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
- Fallback «не понял» (`GenericmessageCommand`): «_Кнопки пропали? Нажми_ /menu _или_ /start _— они вернутся внизу экрана. В Telegram все команды ещё и под значком «☰» у поля ввода._». Ответ на /menu (`BotMenuService::sendMainMenu`): «…— в Telegram нажми значок «☰»…».
- `/guide` раздел `nav` (`GuideCatalog`): кроме «☰» и «нижней панели» там была «постоянная панель из шести кнопок… шесть *мест*» (в доке `/play` их восемь) — та же соседняя форма, правлено здесь же: «постоянные кнопки… *места*», «Если кнопок внизу экрана не видно», «Команду можно просто набрать в поле ввода. В Telegram они ещё и под значком «☰»…», «возврат к кнопкам внизу экрана».
- Проверка: шаблоны «нижн* панел*», «☰» в литерале без «Telegram» (`/^(?![\s\S]*Telegram)[\s\S]*☰/u`), счёт кнопок теперь ловит и «шести/семи/восьми кноп». Дерево — ok (1091 файл); прежние версии трёх файлов дают 6 срабатываний; фикстура соседней формы дописана (`fallbackHint()`: оба новых случая), обе фикстуры падают; `defects-check.sh` GREEN.
- `tests/_support/Community/provenance-corpus.php` НЕ правился: это замороженный срез корпуса на 2026-08-26 (шапка файла: обновление — отдельная story), проверка его не сканирует. `CommunityGuardTest` зелёный.

## Findings
