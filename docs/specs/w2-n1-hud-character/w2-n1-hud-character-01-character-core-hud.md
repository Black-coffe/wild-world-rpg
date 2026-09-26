---
story: w2-n1-hud-character-01
spec: w2-n1-hud-character
status: todo
returned:
tier: 2
worker: worker-code
model: opus
wave: 1
blocked_by: []
---

# Модель персонажа, HUD и нативный «Я»

## Goal
`CharacterSheetService::forCharacter(int $id)` отдаёт модель экрана персонажа (имя, уровень, опыт и
прогресс до следующего уровня, здоровье, выносливость, золото, клетка/координаты/биом, активная задача
с `ends_at`, надетые оружие и броня, доступные действия) без `Request`, `chat_id` и Markdown.
`CharacterService::showCharacterInfo` рисует карточку бота из этой модели. В `/play` постоянный HUD
(партиал) и нативный экран «Я» через новый `POST /play/view`; тап «🧑 Я» в доке открывает нативный экран.

## Requirements
> 1. В `/play` всегда виден HUD: здоровье, выносливость, золото, уровень и опыт, клетка и биом, активная задача с живым таймером; обновляется после каждого действия и при опросе входящих.
> 2. «Я» — одна модель экрана персонажа в сервисе; карточка бота и нативный экран «Я» в вебе рисуются из неё и показывают одни и те же данные.
> 6. Кнопки, которые нативный экран не покрывает (Страховка, «Куда ушло», Склад базы и т.п.), работают через мост, как сейчас.
> 7. Только `wildworld-ui.css`: HUD, слоты снаряжения и список инвентаря сначала в `ui-kit.html`; экраны без горизонтального скролла на 375 / 768 / 1440.
> Поэтому мы не пытаемся накостылять HTML поверх Telegram.

## Files
- app/Services/Player/CharacterSheetService.php
- app/Services/Player/CharacterService.php
- app/Services/Web/WebNativeScreenService.php
- app/Controllers/Play.php
- app/Config/Routes.php
- app/Views/site/play.php
- app/Views/site/_play/hud.php
- app/Views/site/_play/native_me.php
- public/assets/js/wildworld-play.js
- public/assets/css/wildworld-ui.css
- public/ui-kit.html
- app/Views/site/_layout/meta.php
- tests/unit/Services/Player/CharacterSheetServiceTest.php
- tests/database/PlayViewControllerTest.php

## Non-goals
- Не переписывать инвентарь и снаряжение (02, 03): на «Я» это кнопки, ведущие в `/play/view` или мост.
- Не менять числа и формулы уровня (`LevelProgressService`), только читать.
- Не удалять мост и не трогать `WebActService::act`, кроме переиспользования дедупа.

## Map slice
`memory/map/player.md` (статы, инвентарь); `memory/map/website.md` (дизайн-система, `?v=`).

## Acceptance criteria
- [ ] Карточка бота содержит те же поля и числа, что до рефакторинга (тест модели + сравнение текста на фикстуре).
- [ ] `/play` показывает HUD при загрузке; ответы `/play/act`, `/play/view`, `/play/inbox` несут обновлённый HUD; таймер задачи тикает в браузере без запросов.
- [ ] «🧑 Я» в доке и `view=me` рисуют нативный экран; кнопки без нативного экрана уходят в мост.
- [ ] `/play/view` закрыт флагом `web.play_enabled`, сессией, CSRF и троттлингом; id персонажа из запроса не принимается.
- [ ] HUD и карточка «Я» есть в `ui-kit.html`; `?v=` CSS и JS подняты; нет радиусов и теней.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`

## Implementation notes

## Findings
