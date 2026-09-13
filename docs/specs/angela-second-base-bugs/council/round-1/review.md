<!-- seat: review · model: claude-opus-5 · round: 1 · head: 96f1d219 · pack: edbb6544e15a · attempt: 2 · recorded: 2026-09-13T17:30:00Z · verdict: PASS -->
VERDICT: PASS
LEAD-REVIEW: angela-second-base-bugs · round 1
MODEL: claude-opus-5
SCOPE: git diff develop...vulyk/angela-second-base-bugs, основное дерево (не COURT)

ПРОВЕРЕНО САМОСТОЯТЕЛЬНО, НЕ НА СЛОВО
1. Утверждение воркера 03 «аппликатор чинить не надо» - ВЕРНО. UpgradeBuildingAction::confirmUpgrade() берёт ctx charBuilding из шага 2 валидатора и передаёт в BuildingUpgradeApplier::apply(), который делает update(charBuilding id) (BuildingUpgradeApplier.php:117). Других источников строки у аппликатора нет. Починка шага 2 закрывает и запись. Тест BuildingUpgradeBaseScopeTest.php:253 доказывает поведением: строка первой базы остаётся level=10.
2. Регресс для игрока с ОДНОЙ базой - измерен на ПРОДЕ (read-only SELECT): character_buildings 256 строк, строк с map_cell_id без соответствующей активной claimed_cells - 0; дублей по (character_id, building_id, map_cell_id) - 0; персонажей с двумя и более активными базами - 2. Новый фильтр по map_cell_id никого не отрезает от карточки и апгрейда.
3. Полнота класса (grep -rn по app/): в трёх заявленных поверхностях дыр не осталось. Незакрытыми остаются Robots/, TeleportBeacon, StartrobotexplorerCommand.php:175 - названы в Открытых хвостах; плюс шесть мест, которые там НЕ названы (minor 11).
4. Соседняя дверь RepairBuildingAction - чистая: строку берёт по cb_id из callback, не по типу. Не находка.
5. Гейты: phpstan прогнал сам - No errors. Полный набор не гонял (три слепых места работали параллельно, правило проекта запрещает параллельный прогон). Точечно: BuildingUpgradeBaseScopeTest 7/7 зелёных, TextMapMultiBaseTest 6/6 зелёных, BuildingCardBaseScopeTest 4 ошибки из 4 (major 1).
6. Правила проекта: миграций в диффе нет, WipeManifest не при чём; хардкода чисел баланса нет; тексты карточек не менялись; media-off не страдает.
7. Правки вне Files - безвредны. Снятые записи baseline соответствуют исчезнувшему коду; снятие записей может только ужесточить phpstan. Послабление одно: счётчики coordinate_x/coordinate_y подняты 2 в 4.

MAJOR
1. tests/unit/Camp/BuildingCardBaseScopeTest.php:201-207 (createTableIfMissing) - тест принимает уже существующие общие таблицы за свои: все 4 теста упали (Unknown column locale в characters, Unknown column name_ru в buildings). Зелёное 17 passed зависит от того, кто создал таблицы раньше. Условие: тест обязан исполнять проверяемую ветку на схеме, которую гарантирует сам.
2. tests/unit/Camp/BuildingCardBaseScopeTest.php:5-30 - шим Longman\TelegramBot\fopen() объявлен на уровне файла и действует на ВЕСЬ процесс PHPUnit. Условие: эффект тестового шима должен быть ограничен этим тестом либо доказанно безразличен для остальных.
3. app/Models/ClaimedCellModel.php:137 + tests/unit/Services/World/TextMapMultiBaseTest.php:43 - единственный новый метод модели в тесте подменён целиком, собственного теста у него нет нигде. Ни фильтр status=active, ни orderBy(id) не проверяются ничем. Условие: выборка всех активных баз должна быть проверена на реальной таблице, включая отсечение abandoned.
4. tests/unit/Player/BuildingUpgradeBaseScopeTest.php:70-92 - комментарий утверждает схему 1:1 с миграциями, но живая character_buildings.building_type это ENUM с defensive и NOT NULL, а в тесте старый 5-значный nullable ENUM. Ветку апгрейда оборонной структуры этим тестом посеять нельзя.

MINOR
5. Текст отказа при resolveTargetBaseCell NULL: null возвращается и при двух и более базах вне базы, и при НУЛЕ активных баз, а ответ один - Баз у тебя несколько. Игроку без баз это враньё.
6. Отказ уходит новым сообщением (Request::sendMessage в 14 хендлерах), тогда как карточки редактируются на месте через MediaSender.
7. story 02 ссылается на ShowBaseInfoAction в Открытых хвостах плана, но там его нет.
8. BaseDevelopmentAction.php:70-75 - экран Развитие базы показывает MAX(level) по типу ПО ВСЕМ базам, а карточка теперь уровень текущей базы: два разных числа про одну Теплицу на соседних экранах.
9. reply-draft.md:21-26 объясняет Навес только через нет ни одной постройки и молчит про гейт уровня.
10. Ask 7: вердикт нет записан только в Implementation notes истории 02 и только про карточки; по изменению карты вердикта нет нигде.
11. Открытые хвосты неполны: постройку по персонажу резолвят ещё BuildingEffectsService.php:299, CommunicationTowerCoverageService.php:106, CraftShopGate.php:101, AutomationGateService.php:83, WarehouseSellBonusService.php:87, WeightCapacityService.php:133 и три production-handler (GymProductionHandler:68, HandPumpProductionHandler:83, GreenhouseProductionHandler:125).

ЗА ДИФФОМ (по допущениям плана, не находки): ask 5 вторая половина (ответ Анжеле не отправлен), ask 8 вторая половина (tech-writing), ask 9 (живой Tier-3), ask 10 (тег и прод-смоук).
