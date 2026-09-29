<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\UpgradeBuildingAction;
use App\Controllers\Telegram\Commands\Actions\Camp\BuildListAction;
use App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingAction;
use App\Controllers\Telegram\Commands\Actions\Camp\GenericBuildingInfoAction;
use App\Services\Web\WebDelivery;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Telegram;

// Пустышка `http://`, хранилища экрана без БД — общие с паритетом экранов базы (story 01).
require_once __DIR__ . '/BaseScreenBotParityTest.php';

/**
 * w2-n4-base-02 — паритет бота на экранах, переехавших на ядро {@see \App\Services\Buildings\BuildOrderService}
 * и {@see \App\Services\Buildings\BuildingUpgradeService}: «🏗 Строить», карточка постройки, старт стройки,
 * апгрейд (запрос и подтверждение).
 *
 * {@see SNAPSHOT_BEFORE} снят с кода ДО переезда (vulyk/w2-n4-base после story 01) этим же тестом: сообщения
 * (текст, parse_mode, кнопки) и след в БД (задачи, остатки, золото, уровни, отказы в `action_log`). Ожидание
 * после — тот же снимок, кроме ask 5: сценарии с суффиксом базы (`_b<id>` на входе) несут его в кнопках
 * карточки/старта ({@see expectedAfter()}); без суффикса — байт-в-байт.
 *
 * Фото — через подменённую обёртку `http` ({@see ParityFakeHttpStream}), вызовы Bot API — захватом
 * {@see WebDelivery} (актор — чат игрока). Схема — из миграций.
 *
 * @internal
 */
final class BuildBotParityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2026-05-08-220000_AddDisableMediaFlag',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-04-16-122053_CreateCraftedItemsLogTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-05-23-090819_CreateBuildingsTable',
        '2024-05-27-105534_CreateCharacterBuildingsTable',
        '2026-05-29-500000_W3aCreateBaseStorage',
        '2026-05-19-100000_CreateGameSettingsTable',
        '2026-05-08-190000_AddEndgameSystem',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources', 'character_resources', 'tasks',
        'character_tasks', 'crafted_items', 'crafted_items_log', 'claimed_cells', 'buildings', 'character_buildings',
        'base_storage', 'game_settings', 'faction_endgame_scores',
    ];

    private const TG = 771_000_002;

    /** Сценарий → [подготовка, класс, callback_data, метод]. */
    private const CASES = [
        'list_novice'        => ['novice', BuildListAction::class, 'Build', 'handle'],
        'list_veteran'       => ['veteran', BuildListAction::class, 'Build', 'handle'],
        'list_suffix'        => ['veteran', BuildListAction::class, 'Build_b1', 'handle'],
        'card_enough'        => ['stocked', GenericBuildingInfoAction::class, 'genericBuildInfo_Workshop', 'handle'],
        'card_short'         => ['half', GenericBuildingInfoAction::class, 'genericBuildInfo_Workshop', 'handle'],
        'card_deps'          => ['stocked', GenericBuildingInfoAction::class, 'genericBuildInfo_Arsenal', 'handle'],
        'card_low_level'     => ['low', GenericBuildingInfoAction::class, 'genericBuildInfo_WoodenWall', 'handle'],
        'card_not_on_base'   => ['away', GenericBuildingInfoAction::class, 'genericBuildInfo_Workshop', 'handle'],
        'card_no_camp'       => ['no_camp', GenericBuildingInfoAction::class, 'genericBuildInfo_Workshop', 'handle'],
        'card_duplicate'     => ['stocked_owned', GenericBuildingInfoAction::class, 'genericBuildInfo_Workshop', 'handle'],
        'card_suffix'        => ['stocked', GenericBuildingInfoAction::class, 'genericBuildInfo_Workshop_b1', 'handle'],
        'start_ok'           => ['stocked', GenericBuildingAction::class, 'genericStartBuild_Workshop', 'handle'],
        'start_duplicate'    => ['stocked_owned', GenericBuildingAction::class, 'genericStartBuild_Workshop', 'handle'],
        'start_short'        => ['half', GenericBuildingAction::class, 'genericStartBuild_Workshop', 'handle'],
        'start_deps'         => ['stocked', GenericBuildingAction::class, 'genericStartBuild_Arsenal', 'handle'],
        'start_low_level'    => ['low', GenericBuildingAction::class, 'genericStartBuild_WoodenWall', 'handle'],
        'start_not_on_base'  => ['away', GenericBuildingAction::class, 'genericStartBuild_Workshop', 'handle'],
        'start_cell_full'    => ['full', GenericBuildingAction::class, 'genericStartBuild_Workshop', 'handle'],
        'start_suffix'       => ['stocked', GenericBuildingAction::class, 'genericStartBuild_Workshop_b1', 'handle'],
        'up_ask_ok'          => ['owned', UpgradeBuildingAction::class, 'upgrade_building_2', 'askForUpgrade'],
        'up_ask_no_gold'     => ['owned_poor', UpgradeBuildingAction::class, 'upgrade_building_2', 'askForUpgrade'],
        'up_ask_missing'     => ['owned_l3', UpgradeBuildingAction::class, 'upgrade_building_2', 'askForUpgrade'],
        'up_ask_away'        => ['owned_away', UpgradeBuildingAction::class, 'upgrade_building_2', 'askForUpgrade'],
        'up_ask_suffix'      => ['owned', UpgradeBuildingAction::class, 'upgrade_building_2_b1', 'askForUpgrade'],
        'up_confirm_ok'      => ['owned', UpgradeBuildingAction::class, 'confirm_upgrade_building_2_l1', 'confirmUpgrade'],
        'up_confirm_no_gold' => ['owned_poor', UpgradeBuildingAction::class, 'confirm_upgrade_building_2_l1', 'confirmUpgrade'],
        'up_confirm_suffix'  => ['owned', UpgradeBuildingAction::class, 'confirm_upgrade_building_2_l1_b1', 'confirmUpgrade'],
        // w2-n4-tails-01: кнопка старых сообщений (без `_l`) ничего не списывает — заново показывает запрос.
        'up_confirm_legacy'        => ['owned', UpgradeBuildingAction::class, 'confirm_upgrade_building_2', 'confirmUpgrade'],
        'up_confirm_legacy_suffix' => ['owned', UpgradeBuildingAction::class, 'confirm_upgrade_building_2_b1', 'confirmUpgrade'],
    ];

    /**
     * До переезда карточка и старт не знали суффикса базы («Неизвестное здание: Workshop_b1»). После —
     * нажатие с суффиксом обязано дать то же, что без него (плюс суффикс в кнопках): ожидание берётся
     * у близнеца без суффикса.
     */
    private const SUFFIX_TWIN = [
        'card_suffix'  => 'card_enough',
        'start_suffix' => 'start_ok',
        // w2-n4-tails-01: старая кнопка подтверждения = свежий запрос апгрейда.
        'up_confirm_legacy'        => 'up_ask_ok',
        'up_confirm_legacy_suffix' => 'up_ask_suffix',
    ];

    private const SNAPSHOT_BEFORE = <<<'JSON'
        {
         "list_novice": {
          "sent": [
           {
            "text": "🤖 Это я – *Роби*!\n\n_🏠 Строить можно только стоя на своей базе. ⏳ Сама стройка идёт в фоне — можешь уходить и заниматься своим._\n\nВот список доступных построек с указанием суточного налога:\n\n*⛺ Навес — первое укрытие* | *Налог: 0* 💰\n*🚰 Ручная скважина* | *Налог: 300* 💰\n*🔥 Доменная печь* | *Налог: 450* 💰\n*🏚️ Склад* | *Налог: 900* 💰\n*🔧 Мастерская* | *Налог: 500* 💰\n*🌱 Теплица* | *Налог: 840* 💰\n*☀️ Солнечная станция* | *Налог: 760* 💰\n🔒 *🥊 Спортзал* | _нужен lvl 5_\n🔒 *🥼 Лаборатория* | _нужен lvl 5_\n🔒 *🤖 Мастерская робототехники* | _нужен lvl 10_\n🔒 *🌀 Центр телепортации* | _нужен lvl 12_\n🔒 *⚔️ Арсенал* | _нужен lvl 15_\n*📢 Вышка связи* | *Налог: 1300* 💰\n🔒 *🪵 Деревянная стена* | _нужен lvl 8_\n🔒 *🌵 Колючая ограда* | _нужен lvl 10_\n🔒 *🗼 Дозорная вышка* | _нужен lvl 12_\n\n_Выбери желаемое здание для строительства._",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "⛺ Навес — первое укрытие",
               "genericBuildInfo_LeanTo"
              ],
              [
               "🚰 Ручная скважина",
               "genericBuildInfo_HandPump"
              ]
             ],
             [
              [
               "🔥 Доменная печь",
               "genericBuildInfo_BlastFurnace"
              ],
              [
               "🏚️ Склад",
               "genericBuildInfo_Warehouse"
              ]
             ],
             [
              [
               "🔧 Мастерская",
               "genericBuildInfo_Workshop"
              ],
              [
               "🌱 Теплица",
               "genericBuildInfo_Greenhouse"
              ]
             ],
             [
              [
               "☀️ Солнечная станция",
               "genericBuildInfo_SolarStation"
              ],
              [
               "🔒 🥊 Спортзал (с lvl 5)",
               "buildLocked_Gym"
              ]
             ],
             [
              [
               "🔒 🥼 Лаборатория (с lvl 5)",
               "buildLocked_Laboratory"
              ],
              [
               "🔒 🤖 Мастерская робототехники (с lvl 10)",
               "buildLocked_RoboticsWorkshop"
              ]
             ],
             [
              [
               "🔒 🌀 Центр телепортации (с lvl 12)",
               "buildLocked_TeleportationCenter"
              ],
              [
               "🔒 ⚔️ Арсенал (с lvl 15)",
               "buildLocked_Arsenal"
              ]
             ],
             [
              [
               "📢 Вышка связи",
               "genericBuildInfo_CommunicationTower"
              ],
              [
               "🔒 🪵 Деревянная стена (с lvl 8)",
               "buildLocked_WoodenWall"
              ]
             ],
             [
              [
               "🔒 🌵 Колючая ограда (с lvl 10)",
               "buildLocked_BarbedFence"
              ],
              [
               "🔒 🗼 Дозорная вышка (с lvl 12)",
               "buildLocked_WatchTower"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "list_veteran": {
          "sent": [
           {
            "text": "🤖 Это я – *Роби*!\n\n_🏠 Строить можно только стоя на своей базе. ⏳ Сама стройка идёт в фоне — можешь уходить и заниматься своим._\n\nВот список доступных построек с указанием суточного налога:\n\n*🚰 Ручная скважина* | *Налог: 300* 💰\n*🔥 Доменная печь* | *Налог: 450* 💰\n*🏚️ Склад* | *Налог: 900* 💰\n*🔧 Мастерская* | *Налог: 500* 💰\n*🌱 Теплица* | *Налог: 840* 💰\n*☀️ Солнечная станция* | *Налог: 760* 💰\n*🥊 Спортзал* | *Налог: 900* 💰\n*🥼 Лаборатория* | *Налог: 860* 💰\n*🤖 Мастерская робототехники* | *Налог: 1400* 💰\n*🌀 Центр телепортации* | *Налог: 820* 💰\n*⚔️ Арсенал* | *Налог: 2000* 💰\n*📢 Вышка связи* | *Налог: 1300* 💰\n*🪵 Деревянная стена* | *Налог: 200* 💰\n*🌵 Колючая ограда* | *Налог: 350* 💰\n*🗼 Дозорная вышка* | *Налог: 700* 💰\n\n_Выбери желаемое здание для строительства._",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "🚰 Ручная скважина",
               "genericBuildInfo_HandPump"
              ],
              [
               "🔥 Доменная печь",
               "genericBuildInfo_BlastFurnace"
              ]
             ],
             [
              [
               "🏚️ Склад",
               "genericBuildInfo_Warehouse"
              ],
              [
               "🔧 Мастерская",
               "genericBuildInfo_Workshop"
              ]
             ],
             [
              [
               "🌱 Теплица",
               "genericBuildInfo_Greenhouse"
              ],
              [
               "☀️ Солнечная станция",
               "genericBuildInfo_SolarStation"
              ]
             ],
             [
              [
               "🥊 Спортзал",
               "genericBuildInfo_Gym"
              ],
              [
               "🥼 Лаборатория",
               "genericBuildInfo_Laboratory"
              ]
             ],
             [
              [
               "🤖 Мастерская робототехники",
               "genericBuildInfo_RoboticsWorkshop"
              ],
              [
               "🌀 Центр телепортации",
               "genericBuildInfo_TeleportationCenter"
              ]
             ],
             [
              [
               "⚔️ Арсенал",
               "genericBuildInfo_Arsenal"
              ],
              [
               "📢 Вышка связи",
               "genericBuildInfo_CommunicationTower"
              ]
             ],
             [
              [
               "🪵 Деревянная стена",
               "genericBuildInfo_WoodenWall"
              ],
              [
               "🌵 Колючая ограда",
               "genericBuildInfo_BarbedFence"
              ],
              [
               "🗼 Дозорная вышка",
               "genericBuildInfo_WatchTower"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "list_suffix": {
          "sent": [
           {
            "text": "🤖 Это я – *Роби*!\n\n_🏠 Строить можно только стоя на своей базе. ⏳ Сама стройка идёт в фоне — можешь уходить и заниматься своим._\n\nВот список доступных построек с указанием суточного налога:\n\n*🚰 Ручная скважина* | *Налог: 300* 💰\n*🔥 Доменная печь* | *Налог: 450* 💰\n*🏚️ Склад* | *Налог: 900* 💰\n*🔧 Мастерская* | *Налог: 500* 💰\n*🌱 Теплица* | *Налог: 840* 💰\n*☀️ Солнечная станция* | *Налог: 760* 💰\n*🥊 Спортзал* | *Налог: 900* 💰\n*🥼 Лаборатория* | *Налог: 860* 💰\n*🤖 Мастерская робототехники* | *Налог: 1400* 💰\n*🌀 Центр телепортации* | *Налог: 820* 💰\n*⚔️ Арсенал* | *Налог: 2000* 💰\n*📢 Вышка связи* | *Налог: 1300* 💰\n*🪵 Деревянная стена* | *Налог: 200* 💰\n*🌵 Колючая ограда* | *Налог: 350* 💰\n*🗼 Дозорная вышка* | *Налог: 700* 💰\n\n_Выбери желаемое здание для строительства._",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "🚰 Ручная скважина",
               "genericBuildInfo_HandPump"
              ],
              [
               "🔥 Доменная печь",
               "genericBuildInfo_BlastFurnace"
              ]
             ],
             [
              [
               "🏚️ Склад",
               "genericBuildInfo_Warehouse"
              ],
              [
               "🔧 Мастерская",
               "genericBuildInfo_Workshop"
              ]
             ],
             [
              [
               "🌱 Теплица",
               "genericBuildInfo_Greenhouse"
              ],
              [
               "☀️ Солнечная станция",
               "genericBuildInfo_SolarStation"
              ]
             ],
             [
              [
               "🥊 Спортзал",
               "genericBuildInfo_Gym"
              ],
              [
               "🥼 Лаборатория",
               "genericBuildInfo_Laboratory"
              ]
             ],
             [
              [
               "🤖 Мастерская робототехники",
               "genericBuildInfo_RoboticsWorkshop"
              ],
              [
               "🌀 Центр телепортации",
               "genericBuildInfo_TeleportationCenter"
              ]
             ],
             [
              [
               "⚔️ Арсенал",
               "genericBuildInfo_Arsenal"
              ],
              [
               "📢 Вышка связи",
               "genericBuildInfo_CommunicationTower"
              ]
             ],
             [
              [
               "🪵 Деревянная стена",
               "genericBuildInfo_WoodenWall"
              ],
              [
               "🌵 Колючая ограда",
               "genericBuildInfo_BarbedFence"
              ],
              [
               "🗼 Дозорная вышка",
               "genericBuildInfo_WatchTower"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "card_enough": {
          "sent": [
           {
            "text": "*🔧 Мастерская!*\n🏠 Только на базе · ⏳ Идёт в фоне\n\nДля строительства тебе нужны:\n\n📦 Древесина - 1500 ед. (в наличии 1500 ед.)\n📦 Вода - 800 ед. (в наличии 900 ед.)\n📦 Глина - 400 ед. (в наличии 400 ед.)\n📦 Металлические фрагменты - 15 ед. (в наличии 15 ед.)\n📦 Деревянные материалы - 14 ед. (в наличии 20 ед.)\n📦 Каменные блоки - 10 ед. (в наличии 10 ед.)\n\n*Строительство займёт:* ~90 минут\n\n*Описание:* Ускоряет изготовление всех предметов (со 2-го уровня; на 3-м — заметно сильнее). Нужна для постройки инженерных сооружений.\n\n",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "⛏️ Добыть ресурсы",
               "gather"
              ],
              [
               "🛍️ Купить",
               "buy"
              ]
             ],
             [
              [
               "🧑‍🌾 Действия 🛠️",
               "characterActions"
              ],
              [
               "🎒 Инвентарь",
               "inventory"
              ],
              [
               "🛠️ Строить 🔧 Мастерская",
               "genericStartBuild_Workshop"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "card_short": {
          "sent": [
           {
            "text": "*🔧 Мастерская!*\n🏠 Только на базе · ⏳ Идёт в фоне\n\nДля строительства тебе нужны:\n\n📦 Древесина - 1500 ед. (в наличии 700 ед.)\n📦 Вода - 800 ед. (в наличии 900 ед.)\n📦 Глина - 400 ед. (в наличии 0 ед.)\n📦 Металлические фрагменты - 15 ед. (в наличии 5 ед.)\n📦 Деревянные материалы - 14 ед. (в наличии 0 ед.)\n📦 Каменные блоки - 10 ед. (в наличии 0 ед.)\n\n*Строительство займёт:* ~90 минут\n\n*Описание:* Ускоряет изготовление всех предметов (со 2-го уровня; на 3-м — заметно сильнее). Нужна для постройки инженерных сооружений.\n\n\nНедостающие ресурсы:\n- Древесина: требуется 1500, в наличии 700\n- Глина: требуется 400, в наличии 0\n\nНедостающие предметы:\n- Металлические фрагменты: требуется 15, в наличии 5\n- Деревянные материалы: требуется 14, в наличии 0\n- Каменные блоки: требуется 10, в наличии 0\n",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "🛒 Древесина ×800",
               "buy_need_1_800"
              ],
              [
               "🛒 Глина ×400",
               "buy_need_3_400"
              ]
             ],
             [
              [
               "⛏️ Добыть ресурсы",
               "gather"
              ],
              [
               "🛍️ Купить",
               "buy"
              ]
             ],
             [
              [
               "🧑‍🌾 Действия 🛠️",
               "characterActions"
              ],
              [
               "🎒 Инвентарь",
               "inventory"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "700"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "5"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "card_deps": {
          "sent": [
           {
            "text": "*⚔️ Арсенал!*\n🏠 Только на базе · ⏳ Идёт в фоне\n\nДля строительства тебе нужны:\n\n- Ironstone (нет в DB) - 200\n- RareMetals (нет в DB) - 60\n- Oil (нет в DB) - 70\n- Sulfur (нет в DB) - 50\n📦 Металлические фрагменты - 120 ед. (в наличии 15 ед.)\n- wiring (нет в DB) - 15\n- electronicComponents (нет в DB) - 8\n\n*Строительство займёт:* ~90 минут\n\n*Описание:* Здание для хранения и экипировки оружия и брони. Чтобы надеть снаряжение, на базе нужен Арсенал.\n\n\n*Сначала постройте:* Мастерская, BlastFurnace, SolarStation, Laboratory\n\nНедостающие ресурсы:\n- Ironstone (нет в DB): требуется 200, в наличии 0\n- RareMetals (нет в DB): требуется 60, в наличии 0\n- Oil (нет в DB): требуется 70, в наличии 0\n- Sulfur (нет в DB): требуется 50, в наличии 0\n\nНедостающие предметы:\n- Металлические фрагменты: требуется 120, в наличии 15\n- wiring (нет в DB): требуется 15, в наличии 0\n- electronicComponents (нет в DB): требуется 8, в наличии 0\n",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "⛏️ Добыть ресурсы",
               "gather"
              ],
              [
               "🛍️ Купить",
               "buy"
              ]
             ],
             [
              [
               "🧑‍🌾 Действия 🛠️",
               "characterActions"
              ],
              [
               "🎒 Инвентарь",
               "inventory"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "card_low_level": {
          "sent": [
           {
            "text": "Ваш уровень слишком низкий для строительства *🪵 Деревянная стена*. Нужно хотя бы уровень: *8*.",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "📡 Телепорт",
               "TeleportToCamp"
              ],
              [
               "🧭 Двигаться",
               "move"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "card_not_on_base": {
          "sent": [
           {
            "text": "Вы находитесь не в лагере. Переместитесь в лагерь, чтобы продолжить строительство.",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "📡 Телепорт",
               "TeleportToCamp"
              ],
              [
               "🧭 Двигаться",
               "move"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "card_no_camp": {
          "sent": [
           {
            "text": "У вас нет лагеря. Разбейте лагерь, чтобы продолжить.",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "🏕 Разбить лагерь",
               "Camp"
              ],
              [
               "🧑‍🌾 Действия 🛠️",
               "characterActions"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "card_duplicate": {
          "sent": [
           {
            "text": "*🔧 Мастерская!*\n🏠 Только на базе · ⏳ Идёт в фоне\n\nДля строительства тебе нужны:\n\n📦 Древесина - 1500 ед. (в наличии 1500 ед.)\n📦 Вода - 800 ед. (в наличии 900 ед.)\n📦 Глина - 400 ед. (в наличии 400 ед.)\n📦 Металлические фрагменты - 15 ед. (в наличии 15 ед.)\n📦 Деревянные материалы - 14 ед. (в наличии 20 ед.)\n📦 Каменные блоки - 10 ед. (в наличии 10 ед.)\n\n*Строительство займёт:* ~90 минут\n\n*Описание:* Ускоряет изготовление всех предметов (со 2-го уровня; на 3-м — заметно сильнее). Нужна для постройки инженерных сооружений.\n\n",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "⛏️ Добыть ресурсы",
               "gather"
              ],
              [
               "🛍️ Купить",
               "buy"
              ]
             ],
             [
              [
               "🧑‍🌾 Действия 🛠️",
               "characterActions"
              ],
              [
               "🎒 Инвентарь",
               "inventory"
              ],
              [
               "🛠️ Строить 🔧 Мастерская",
               "genericStartBuild_Workshop"
              ]
             ]
            ]
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "1"
           }
          ],
          "log": []
         },
         "card_suffix": {
          "sent": [
           {
            "text": "Неизвестное здание: Workshop_b1",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "start_ok": {
          "sent": [
           {
            "text": "*Строительство Мастерская начато!*\n\n🏠 Только на базе · ⏳ Идёт в фоне\n_Можешь уходить — стройка идёт сама, сообщу по готовности._\n\nДлительность: ~90 мин.\nПо завершении здание будет добавлено на базу.",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [
           {
            "status": "in_work",
            "telegram_user_id": "7",
            "task": "buildWorkshop",
            "task_settings": {
             "building": "Workshop",
             "base_cell": 5
            }
           }
          ],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "0"
           },
           {
            "id_resources": "2",
            "quantity": "100"
           },
           {
            "id_resources": "3",
            "quantity": "0"
           }
          ],
          "items": [
           {
            "crafted_item_id": "2",
            "quantity": "6"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "start_duplicate": {
          "sent": [
           {
            "text": "*Строительство Мастерская начато!*\n\n🏠 Только на базе · ⏳ Идёт в фоне\n_Можешь уходить — стройка идёт сама, сообщу по готовности._\n\nДлительность: ~90 мин.\nПо завершении здание будет добавлено на базу.\n\n⚠️ *У тебя уже есть 🔧 Мастерская.* Копия не даст новых бонусов — они начисляются один раз на тип здания на все базы. Налог с копии на этой базе не растёт, но с каждой базы берётся отдельно.\n",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [
           {
            "status": "in_work",
            "telegram_user_id": "7",
            "task": "buildWorkshop",
            "task_settings": {
             "building": "Workshop",
             "base_cell": 5
            }
           }
          ],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "0"
           },
           {
            "id_resources": "2",
            "quantity": "100"
           },
           {
            "id_resources": "3",
            "quantity": "0"
           }
          ],
          "items": [
           {
            "crafted_item_id": "2",
            "quantity": "6"
           }
          ],
          "gold": "60000",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "1"
           }
          ],
          "log": []
         },
         "start_short": {
          "sent": [
           {
            "text": "Не хватает материалов для Мастерская:\n\n• Древесина: нужно 1500, есть 700\n• Глина: нужно 400, есть 0\n• Металлические фрагменты: нужно 15, есть 5\n• Деревянные материалы: нужно 14, есть 0\n• Каменные блоки: нужно 10, есть 0",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "700"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "5"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": [
           {
            "action_name": "BUILD_Workshop",
            "action_status": "",
            "description": "missing_materials | {\"missing_resources\":{\"Wood\":{\"need\":1500,\"have\":\"700\",\"name\":\"Древесина\"},\"Clay\":{\"need\":400,\"have\":0,\"name\":\"Глина\"}},\"missing_items\":{\"metalFragments\":{\"need\":15,\"have\":\"5\",\"name\":\"Металлические фрагменты\"},\"WoodMaterials\":{\"need\":14,\"have\":0,\"name\":\"Деревянные материалы\"},\"stoneBlocks\":{\"need\":10,\"have\":0,\"name\":\"Каменные блоки\"}}}"
           }
          ]
         },
         "start_deps": {
          "sent": [
           {
            "text": "Сначала постройте: Мастерская, BlastFurnace, SolarStation, Laboratory",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": [
           {
            "action_name": "BUILD_Arsenal",
            "action_status": "",
            "description": "missing_deps | {\"missing\":[\"Мастерская\",\"BlastFurnace\",\"SolarStation\",\"Laboratory\"]}"
           }
          ]
         },
         "start_low_level": {
          "sent": [
           {
            "text": "Нужен уровень не ниже *8* для постройки Деревянная стена.",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": [
           {
            "action_name": "BUILD_WoodenWall",
            "action_status": "",
            "description": "low_level | {\"required\":8,\"have\":5}"
           }
          ]
         },
         "start_not_on_base": {
          "sent": [
           {
            "text": "Ты не на своей базе. Постройки возводятся только когда стоишь на базе — телепортируйся или дойди до неё.",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": [
           {
            "action_name": "BUILD_Workshop",
            "action_status": "",
            "description": "not_on_base"
           }
          ]
         },
         "start_cell_full": {
          "sent": [
           {
            "text": "На этой базе уже максимум построек (*1*): возведено 1, в работе 0. Снеси что-нибудь или развивай другую базу.",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [
           {
            "building_id": "3",
            "map_cell_id": "5",
            "level": "1"
           }
          ],
          "log": [
           {
            "action_name": "BUILD_Workshop",
            "action_status": "",
            "description": "cell_full | {\"built\":1,\"inflight\":0,\"max\":1}"
           }
          ]
         },
         "start_suffix": {
          "sent": [
           {
            "text": "Неизвестное здание: Workshop_b1",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [
           {
            "id_resources": "1",
            "quantity": "1500"
           },
           {
            "id_resources": "2",
            "quantity": "900"
           },
           {
            "id_resources": "3",
            "quantity": "400"
           }
          ],
          "items": [
           {
            "crafted_item_id": "1",
            "quantity": "15"
           },
           {
            "crafted_item_id": "2",
            "quantity": "20"
           },
           {
            "crafted_item_id": "3",
            "quantity": "10"
           }
          ],
          "gold": "60000",
          "levels": [],
          "log": []
         },
         "up_ask_ok": {
          "sent": [
           {
            "text": "Вы хотите поднять *Мастерская* с уровня 1 на уровень 2?\n\nТребуется:\n- Уровень персонажа >= 1 (у вас 20)\n- Золото: 50000 (у вас 60000)\n\nПодтвердите апгрейд?",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "✅ Подтвердить",
               "confirm_upgrade_building_2"
              ],
              [
               "❌ Отмена",
               "Base"
              ]
             ]
            ]
           }
          ],
          "alert": "Проверка завершена.",
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "60000",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "1"
           }
          ],
          "log": []
         },
         "up_ask_no_gold": {
          "sent": [
           {
            "text": "Нужно золото: 50000, у вас: 100.",
            "parse_mode": null,
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "100",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "1"
           }
          ],
          "log": []
         },
         "up_ask_missing": {
          "sent": [
           {
            "text": "Недостаточно ресурсов для апгрейда до уровня 4:\n- Вода (нужно: 15000, есть: 0)\n- Древесина (нужно: 10000, есть: 0)",
            "parse_mode": null,
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "200000",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "3"
           }
          ],
          "log": []
         },
         "up_ask_away": {
          "sent": [
           {
            "text": "Вы не на базе или база отсутствует. Нельзя улучшать постройки.",
            "parse_mode": null,
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "60000",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "1"
           }
          ],
          "log": []
         },
         "up_ask_suffix": {
          "sent": [
           {
            "text": "Вы хотите поднять *Мастерская* с уровня 1 на уровень 2?\n\nТребуется:\n- Уровень персонажа >= 1 (у вас 20)\n- Золото: 50000 (у вас 60000)\n\nПодтвердите апгрейд?",
            "parse_mode": "Markdown",
            "buttons": [
             [
              [
               "✅ Подтвердить",
               "confirm_upgrade_building_2_b1"
              ],
              [
               "❌ Отмена",
               "Base"
              ]
             ]
            ]
           }
          ],
          "alert": "Проверка завершена.",
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "60000",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "1"
           }
          ],
          "log": []
         },
         "up_confirm_ok": {
          "sent": [
           {
            "text": "Поздравляем! «Мастерская» поднялось с уровня 1 до уровня 2.\n",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": "Улучшение выполнено!",
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "10000",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "2"
           }
          ],
          "log": []
         },
         "up_confirm_no_gold": {
          "sent": [
           {
            "text": "Нужно золото: 50000, у вас: 100.",
            "parse_mode": null,
            "buttons": []
           }
          ],
          "alert": null,
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "100",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "1"
           }
          ],
          "log": []
         },
         "up_confirm_suffix": {
          "sent": [
           {
            "text": "Поздравляем! «Мастерская» поднялось с уровня 1 до уровня 2.\n",
            "parse_mode": "Markdown",
            "buttons": []
           }
          ],
          "alert": "Улучшение выполнено!",
          "tasks": [],
          "resources": [],
          "items": [],
          "gold": "10000",
          "levels": [
           {
            "building_id": "2",
            "map_cell_id": "5",
            "level": "2"
           }
          ],
          "log": []
         }
        }
        JSON;

    private const STASH = 'zz_bbp_';

    private BaseConnection $conn;
    private bool $wrapperSwapped = false;

    /** @var list<string> */
    private array $stashed = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->conn = Database::connect();
        $this->stashForeignTables();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . substr($file, 18);
                $m     = new $class($forge instanceof Forge ? $forge : null);
                $this->assertInstanceOf(Migration::class, $m);
                $m->up();
            }
            $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
            $this->conn->query('ALTER TABLE tasks ADD handler_key VARCHAR(64) NULL');
            // CreateResourcesTable не идёт на MySQL 8 (TEXT UNSIGNED) — DDL-копия нужных колонок, как в CraftOrderServiceTest.
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' biome_id TEXT NULL, is_tradeable TINYINT(1) NOT NULL DEFAULT 1, type VARCHAR(255) NULL, price INT NULL, buy_price INT NULL,'
                . ' sell_price INT NULL, rarity INT NULL, level_required INT NULL, icon_text VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        if (in_array('http', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('http');
        }
        stream_wrapper_register('http', ParityFakeHttpStream::class);
        $this->wrapperSwapped = true;

        WebDelivery::reset();
        WebDelivery::useServices(new ParityFakeStore(), new ParityFakeInbox());
    }

    protected function tearDown(): void
    {
        WebDelivery::reset();
        if ($this->wrapperSwapped) {
            stream_wrapper_restore('http');
        }
        service('cache')->clean();
        $this->dropTables();
        $this->restoreForeignTables();
        // `tableExists()` соседей читает кэш списка таблиц соединения — он помнит снесённые здесь таблицы.
        $this->conn->resetDataCache();
        parent::tearDown();
    }

    /**
     * Тест живёт в `tests/unit`, а соседи по набору читают общие таблицы тестовой БД (`crafted_items` и др.).
     * Чужие таблицы на время теста переименовываются и возвращаются в `tearDown()` — снос их ломал соседей.
     */
    private function stashForeignTables(): void
    {
        foreach (self::TABLES as $t) {
            $exists = $this->conn->query('SHOW TABLES LIKE ?', [$t])->getResultArray() !== [];
            $stash  = self::STASH . $t;
            $parked = $this->conn->query('SHOW TABLES LIKE ?', [$stash])->getResultArray() !== [];
            if ($exists && ! $parked) {
                $this->conn->query("RENAME TABLE `{$t}` TO `{$stash}`");
                $this->stashed[] = $t;
            }
        }
    }

    private function restoreForeignTables(): void
    {
        foreach ($this->stashed as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
            $this->conn->query('RENAME TABLE `' . self::STASH . $t . "` TO `{$t}`");
        }
        $this->stashed = [];
    }

    public function testBotScreensAndWritesMatchSnapshotExceptBaseSuffix(): void
    {
        $actual = [];
        foreach (self::CASES as $name => [$prep, $class, $data, $method]) {
            $actual[$name] = $this->runCase($prep, $class, $data, $method);
        }

        $dump = getenv('BUILD_PARITY_DUMP');
        if (is_string($dump) && $dump !== '') {
            file_put_contents($dump, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->markTestSkipped('снимок записан в ' . $dump);
        }

        $before = json_decode(self::SNAPSHOT_BEFORE, true);
        $this->assertIsArray($before, 'снимок ДО обязан быть валидным JSON');
        foreach (self::CASES as $name => [, , $data]) {
            $source = self::SUFFIX_TWIN[$name] ?? $name;
            $this->assertArrayHasKey($source, $before, "в снимке нет сценария {$source}");
            $this->assertSame(self::expectedAfter($data, $before[$source]), $actual[$name], "сценарий {$name}");
        }
    }

    /**
     * w2-n4-tails-01: подтверждение с чужого уровня (повторный тап после апгрейда) и подтверждение во время
     * переезда базы ничего не пишут — след в БД тот же, что у простого запроса; игрок видит причину.
     */
    public function testStaleOrRelocatingConfirmWritesNothing(): void
    {
        $untouched = $this->runCase('owned', UpgradeBuildingAction::class, 'upgrade_building_2', 'askForUpgrade');
        unset($untouched['sent'], $untouched['alert']);

        $stale = $this->runCase('owned', UpgradeBuildingAction::class, 'confirm_upgrade_building_2_l0', 'confirmUpgrade');
        $this->assertSame(\App\Services\Buildings\BuildingUpgradeService::TEXT_STALE, $stale['sent'][0]['text'] ?? null);
        unset($stale['sent'], $stale['alert']);
        $this->assertSame($untouched, $stale, 'устаревшее подтверждение не списывает и не поднимает уровень');

        $moving = $this->runCase('owned_relocating', UpgradeBuildingAction::class, 'confirm_upgrade_building_2_l1', 'confirmUpgrade');
        $this->assertSame(\App\Services\Tasks\ActiveTasksService::TEXT_RELOCATION, $moving['sent'][0]['text'] ?? null);
        $this->assertSame('Markdown', $moving['sent'][0]['parse_mode'] ?? null);
        $this->assertSame($untouched['gold'], $moving['gold']);
        $this->assertSame($untouched['levels'], $moving['levels']);
    }

    /**
     * Ask 5: нажатие с суффиксом базы (`…_b<id>`) несёт его в кнопки, ведущие к стройке этой базы:
     * карточки списка (`genericBuildInfo_<Key>`), «Строить» карточки (`genericStartBuild_<Key>`), «❌ Отмена» апгрейда (`Base`).
     *
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    private static function expectedAfter(string $data, array $case): array
    {
        // Числа в отказе `missing_materials` ядро пишет числами, а не строками из БД (`"have":"700"` → `700`).
        foreach ((array) ($case['log'] ?? []) as $i => $row) {
            $case['log'][$i]['description'] = preg_replace('/"have":"(\d+)"/', '"have":$1', (string) $row['description']);
        }
        // w2-n4-tails-01: «✅ Подтвердить» несёт уровень, с которого сделан запрос (`_l<N>` до суффикса базы).
        // w2-n4-tails-02 (ask 3): под вопросом — эффект уровня; множители Мастерской в этой схеме не заданы, поэтому
        // «базовый эффект» на обоих уровнях. Остальной текст запроса — байт-в-байт.
        foreach ((array) ($case['sent'] ?? []) as $i => $msg) {
            if (preg_match('/с уровня (\d+) на уровень/', (string) ($msg['text'] ?? ''), $lvl) !== 1) {
                continue;
            }
            $case['sent'][$i]['text'] = preg_replace('/(на уровень \d+\?)/u', "$1\n\n✨ Эффект: базовый эффект — от уровня не меняется", (string) $msg['text'], 1);
            foreach ((array) ($msg['buttons'] ?? []) as $r => $row) {
                foreach ((array) $row as $b => $button) {
                    $case['sent'][$i]['buttons'][$r][$b][1] = preg_replace('/^(confirm_upgrade_building_\d+)(_b\d+)?$/', '$1_l' . $lvl[1] . '$2', (string) $button[1]);
                }
            }
        }
        if (preg_match('/_b(\d+)$/', $data, $m) !== 1 || ! is_array($case['sent'] ?? null)) {
            return $case;
        }
        foreach ($case['sent'] as $i => $msg) {
            foreach ((array) ($msg['buttons'] ?? []) as $r => $row) {
                foreach ((array) $row as $b => $button) {
                    // Ремонт круга 1: «❌ Отмена» подтверждения апгрейда (`Base`) — тоже на ту же базу.
                    if (preg_match('/^(generic(BuildInfo|StartBuild)_[A-Za-z]+|Base)$/', (string) $button[1]) === 1) {
                        $case['sent'][$i]['buttons'][$r][$b][1] = $button[1] . '_b' . $m[1];
                    }
                }
            }
        }

        return $case;
    }

    /** @return array<string, mixed> */
    private function runCase(string $prep, string $class, string $data, string $method): array
    {
        $this->resetCase();
        $this->prepare($prep);

        WebDelivery::beginCapture(self::TG, 1);
        try {
            (new $class($this->cbq($data)))->{$method}();
        } finally {
            $capture = WebDelivery::endCapture();
        }

        $sent = [];
        foreach ([...$capture['sent'], ...array_values($capture['edited'])] as $msg) {
            $rows = [];
            foreach ($msg['inline_keyboard'] as $row) {
                $r = [];
                foreach ($row as $button) {
                    $r[] = [(string) ($button['text'] ?? ''), (string) ($button['callback_data'] ?? '')];
                }
                $rows[] = $r;
            }
            $sent[] = ['text' => $msg['caption'] ?? $msg['text'], 'parse_mode' => $msg['parse_mode'], 'buttons' => $rows];
        }

        return ['sent' => $sent, 'alert' => $capture['alert']] + $this->state();
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $tasks = $this->conn->query(
            'SELECT ct.status, ct.telegram_user_id, t.name AS task, ct.task_settings FROM character_tasks ct'
            . ' LEFT JOIN tasks t ON t.id = ct.task_id ORDER BY ct.id'
        )->getResultArray();
        foreach ($tasks as &$row) {
            $row['task_settings'] = json_decode((string) $row['task_settings'], true);
        }
        unset($row);

        return [
            'tasks'     => $tasks,
            'resources' => $this->conn->query('SELECT id_resources, quantity FROM character_resources ORDER BY id')->getResultArray(),
            'items'     => $this->conn->query('SELECT crafted_item_id, quantity FROM crafted_items_log ORDER BY id')->getResultArray(),
            'gold'      => $this->conn->query('SELECT gold FROM characters WHERE id = 1')->getRowArray()['gold'] ?? null,
            'levels'    => $this->conn->query('SELECT building_id, map_cell_id, level FROM character_buildings ORDER BY id')->getResultArray(),
            'log'       => $this->conn->query("SELECT action_name, action_status, description FROM action_log ORDER BY id")->getResultArray(),
        ];
    }

    private function prepare(string $prep): void
    {
        $level = match ($prep) {
            'novice' => 1,
            'low'    => 5,
            default  => 20,
        };
        $cell = in_array($prep, ['away', 'owned_away'], true) ? 6 : 5;
        $gold = match ($prep) {
            'owned_poor' => 100,
            'owned_l3'   => 200000,
            default      => 60000,
        };
        $this->conn->query('UPDATE characters SET level = ?, cell_number = ?, gold = ? WHERE id = 1', [$level, $cell, $gold]);

        if ($prep === 'novice') {
            // Замки уровня (S4) и Навес новичку (S5) — за своими killswitch'ами.
            foreach (['onboarding.cold_open_v2.build_locks', 'onboarding.first_build.enabled'] as $key) {
                $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_bool, category) VALUES (?, 'bool', 1, 'experimental')", [$key]);
            }
        }
        if ($prep !== 'no_camp') {
            $this->conn->query("INSERT INTO claimed_cells (id, character_id, map_cell_id, status) VALUES (1, 1, 5, 'active')");
        }

        // Workshop: Wood 1500, Water 800, Clay 400; metalFragments 15, WoodMaterials 14, stoneBlocks 10.
        if (in_array($prep, ['stocked', 'stocked_owned', 'full', 'away', 'low', 'no_camp'], true)) {
            $this->stock([1 => 1500, 2 => 900, 3 => 400], [1 => 15, 2 => 20, 3 => 10]);
        }
        if ($prep === 'half') {
            $this->stock([1 => 700, 2 => 900], [1 => 5]);
        }
        if ($prep === 'stocked_owned') {
            $this->building(2, 1, 5);
        }
        if ($prep === 'full') {
            $this->building(3, 1, 5); // лимит базы в 1 постройку — через кэш GameSettings ниже
            $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_int, category) VALUES ('buildings.cells.max_buildings_per_cell', 'int', 1, 'buildings')");
        }
        if (in_array($prep, ['owned', 'owned_poor', 'owned_away', 'owned_relocating'], true)) {
            $this->building(2, 1, 5);
        }
        if ($prep === 'owned_relocating') {
            $this->conn->query("INSERT INTO tasks (id, name, name_rus, min_duration, max_duration, type, parallel_execution_allowed, handler_key) VALUES (99, 'BaseRelocation', 'Переезд базы', 60, 60, 'base', 0, 'base_relocation')");
            $this->conn->query("INSERT INTO character_tasks (character_id, telegram_user_id, task_id, status, start_time, end_time) VALUES (1, 7, 99, 'in_work', NOW(), NOW() + INTERVAL 1 HOUR)");
        }
        if ($prep === 'owned_l3') {
            $this->building(2, 3, 5);
        }
    }

    /**
     * @param array<int, int> $resources id → qty
     * @param array<int, int> $items id → qty
     */
    private function stock(array $resources, array $items): void
    {
        foreach ($resources as $id => $qty) {
            $this->conn->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, ?, ?)', [$id, $qty]);
        }
        foreach ($items as $id => $qty) {
            $this->conn->query("INSERT INTO crafted_items_log (character_id, crafted_item_id, type, quantity) VALUES (1, ?, 'component', ?)", [$id, $qty]);
        }
    }

    private function building(int $buildingId, int $level, int $cell): void
    {
        $this->conn->query('INSERT INTO character_buildings (character_id, building_id, map_cell_id, level, amount) VALUES (1, ?, ?, ?, 1)', [$buildingId, $cell, $level]);
    }

    private function resetCase(): void
    {
        service('cache')->clean();
        foreach (['action_log', 'character_resources', 'character_tasks', 'crafted_items_log', 'claimed_cells', 'character_buildings', 'base_storage', 'game_settings', 'characters', 'telegram_users', 'buildings', 'tasks', 'resources', 'crafted_items', 'map', 'biomes'] as $t) {
            $this->conn->query("DELETE FROM `{$t}`");
        }
        $this->seed();
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'Лес', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (6, 6, 5, 0, 1)');
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, 'Тест')", [self::TG]);
        $this->conn->query(
            'INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)'
            . " VALUES (1, 7, 'Тест', 20, 1.5, 90, 10, 0.5, 0.01, 0.01, 60000, 5, 0)"
        );
        foreach ([1 => ['Древесина', 'Wood'], 2 => ['Вода', 'Water'], 3 => ['Глина', 'Clay']] as $id => [$name, $en]) {
            $this->conn->query("INSERT INTO resources (id, name, name_en, type, rarity) VALUES (?, ?, ?, 'plant', 1)", [$id, $name, $en]);
        }
        foreach ([1 => ['Металлические фрагменты', 'metalFragments'], 2 => ['Деревянные материалы', 'WoodMaterials'], 3 => ['Каменные блоки', 'stoneBlocks']] as $id => [$rus, $eng]) {
            $this->conn->query("INSERT INTO crafted_items (id, name_rus, name_eng, type) VALUES (?, ?, ?, 'component')", [$id, $rus, $eng]);
        }
        foreach ([1 => ['Вышка связи', 'CommunicationTower', 'utility'], 2 => ['Мастерская', 'Workshop', 'production'], 3 => ['Теплица', 'Greenhouse', 'farming']] as $id => [$ru, $en, $type]) {
            $this->conn->query('INSERT INTO buildings (id, name_ru, name_en, building_type) VALUES (?, ?, ?, ?)', [$id, $ru, $en, $type]);
        }
        foreach (['buildWorkshop', 'startBuildArsenal', 'buildWoodenWall', 'buildLeanTo'] as $name) {
            $this->conn->query(
                "INSERT INTO tasks (name, name_rus, min_duration, max_duration, type, parallel_execution_allowed, handler_key) VALUES (?, ?, 30, 90, 'build', 1, 'generic_building')",
                [$name, $name]
            );
        }
    }

    private function cbq(string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_build',
            'from' => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1, 'date' => time(),
                'chat' => ['id' => self::TG, 'type' => 'private'],
                'text' => 'placeholder',
            ],
            'chat_instance' => 'ci_' . self::TG,
            'data' => $data,
        ]);
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
