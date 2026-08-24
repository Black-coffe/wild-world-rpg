---
story: chat-requests-batch-02
spec: chat-requests-batch
status: done
tier: 1
worker: worker-code
tracer: false
wave: 1
blocked_by: []
---

# Экран робота называет реальный охват

## Goal
Перед запуском робота игрок видит, сколько клеток тот обойдёт при его уровне
Мастерской, и как это число вырастет на следующем уровне. Сейчас число не названо
нигде, и двое игроков независимо посчитали его квадратом уровня.

## Requirements
> [19.08.2026] Max Syskov: «мой после запуска обработал 8)) так что присоединяюсь тоже к вопросу»

## Files
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php
- app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/AllRobotsHandler.php
- tests/unit/Camp/RobotReachTextTest.php

## Notes
Охват считается в `CompleteRobotGatheringHandler` как
`max(1, workshopLevel + extraCells)` — линейно, не квадратом. Число для текста брать
той же формулой, не переписывая её копией: разъехавшиеся близнецы уже были причиной
жалоб. У Max L7 + T2-бонус = 8, это совпадает с его наблюдением.

## Non-goals
- Не менять формулу охвата и не делать её квадратичной.
- Не трогать длительность работы (`2 ч × уровень`) и износ прочности.
- Не заводить новых ключей `GameSettings`: число вычисляемое, а не настраиваемое.

## Acceptance criteria
- [x] Создан и зелёный `tests/unit/Camp/RobotReachTextTest.php` — он обязан падать на доправочном поведении,
      иначе гейт зелёный впустую (урок «скан исходника ≠ покрытие»). *(story 09 удалила этот файл —
      он тестировал приватные `reachCellsText()`, которые story 09 убрала при централизации в
      `RobotService`; поведение теперь покрыто `tests/unit/Camp/RobotReachSingleSourceTest.php`.)*
- [x] `vendor/bin/phpunit --no-coverage --no-progress` целиком зелёный.

## Verification
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress app/Controllers/Telegram/Commands/Actions/Camp/Buildings/Robots/StartRobotGatheringAction.php`

## Findings

Не смог найти в двух перечисленных файлах ни одного места, где охват уже назывался бы
текстом — реальный экран «перед запуском» (`RobotGathererActivator.php`, кнопка «Запуск
робота») тоже врёт (`$cellsCount = $workshopLevel`, без `extraCells`), но он не в `## Files`
и не тронут (Law 3). Вместо этого число добавлено в:

- **`AllRobotsHandler.php`** (список роботов до выбора) — под каждым роботом строка
  охвата и прироста на след. уровне, отдельно на тип робота (`name_eng` → `RobotService`).
- **`StartRobotGatheringAction.php`** (подтверждение после нажатия «Запуск робота») —
  строка охвата в тексте «Ты запустил робота…», это и есть экран, где Max увидел «8» и
  спросил про формулу.

Формула `max(1, workshopLevel + extraCells)` не читается из `CompleteRobotGatheringHandler`
(та не в `## Files`, seam туда добавлять не стал — вне scope) — вместо этого `extraCells`
берётся из того же канонического `RobotService::gatheringExtraCellsFor()`, которым
пользуется и хендлер завершения; сама тривиальная арифметика `max(1, a+b)` продублирована
как приватный `private static function reachCellsText()` в обоих файлах (идентичный код,
задокументирован комментарием на оба места — риск дрейфа только в этой одной строке).

phpstan потребовал типизировать новые свойства (`RobotService $robotService`,
`BuildingModel`/`CharacterBuildingModel` в `AllRobotsHandler`) и убрать `array`-офсеты на
смешанных типах моделей (`is_array()`/`is_numeric()`-гварды) — без баз-лайна, по правилу
проекта «нет (int)$mixed».
