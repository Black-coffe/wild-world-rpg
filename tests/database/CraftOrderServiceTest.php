<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\Craft\GenericCraftActionStart;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Request as LongmanRequest;
use Longman\TelegramBot\Telegram;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\RequestInterface;

/**
 * W2.N3-01 (ADR-190) — старт крафта как сервис: {@see \App\Services\Craft\CraftOrderService} даёт те же
 * отказы, строки `character_tasks` и списания, что прежний `GenericCraftActionStart`; бот-рендерер шлёт
 * те же сообщения. Атомарность (ADR-181): золото и компоненты — условной записью, лимиты очереди и
 * слотов — под блокировкой строки персонажа.
 *
 * Паритет бота: {@see self::BOT_BEFORE} — запросы Bot API, адреса фото и состояние БД прежнего handler'а
 * на этой фикстуре (сняты с кода ДО переноса логики в ядро). Схема — из миграций, как в MarchServiceTest;
 * `resources`/`character_resources` — DDL-копией (их миграция не идёт на MySQL 8).
 *
 * @internal
 */
final class CraftOrderServiceTest extends CIUnitTestCase
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
        '2024-05-15-131853_CreateFactionsTable',
        '2024-05-15-132233_CreateCharacterFactionsTable',
        '2026-05-25-170000_V20CreateFactionProjects',
        '2026-11-24-100000_CreateCharacterDebuffs',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'resources', 'character_resources', 'tasks',
        'character_tasks', 'crafted_items', 'crafted_items_log', 'claimed_cells', 'buildings', 'character_buildings',
        'base_storage', 'game_settings', 'factions', 'character_factions', 'faction_projects', 'character_debuffs',
    ];

    private const ENV = ['telegram.API_KEY' => '123456:TEST_TOKEN', 'telegram.BOT_USERNAME' => 'wildworldtest_bot'];

    private const CHAR = 1;
    private const TG   = 555002;

    /** Ресурсы фикстуры: id → имя (Повязка: Травы/Кора/Водоросли; Аптечка: Грибы/Мед/Алоэ/Вода + 5 Повязок). */
    private const RESOURCES = [1 => 'Травы', 2 => 'Кора деревьев', 3 => 'Водоросли', 4 => 'Грибы', 5 => 'Мед', 6 => 'Алоэ', 7 => 'Вода'];

    /** Нажатия бота по случаям паритета: [подготовка, callback]. */
    private const CASES = [
        'start'          => ['', 'genericCraft_Bandage_2'],
        'start_items'    => ['', 'genericCraft_BasicMedKit_1'],
        'queue'          => ['bandage_in_work', 'genericCraft_Bandage_1'],
        'shortage'       => ['', 'genericCraft_Bandage_50'],
        'shortage_plain' => ['hint_off', 'genericCraft_Bandage_50'],
        'recipe_limit'   => ['bandage_full', 'genericCraft_Bandage_1'],
        'slot_limit'     => ['slots_full', 'genericCraft_Bandage_1'],
        'busy'           => ['busy', 'genericCraft_BasicMedKit_1'],
        'no_gold'        => ['base', 'genericCraft_WorkbenchOne_1'],
        'unknown'        => ['', 'genericCraft_NoSuchRecipe_1'],
    ];

    private const BOT_BEFORE = <<<'JSON'
        {
            "start": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555002",
                        "message_id": "77",
                        "text": "*Процесс крафта запущен*\n\nТы создаёшь: 🩹 *Повязку* x2 шт.\n\n🌍 Где угодно · ⏳ Идёт в фоне\n_Можешь идти добывать, в Поход и двигаться по карте — крафт идёт сам. Сообщу, когда будет готово._\n\nВремя крафта: *1 час* ⏱️\n\n💸 *Списано:* Травы ×4 · Кора деревьев ×4 · Водоросли ×6\n\nПосле завершения будет добавлено *2* шт. в твой инвентарь.\n\n❗Прерывание задачи = потеря ресурсов!\n\n_О готовности узнаешь в сообщении._ 🎁",
                        "parse_mode": "Markdown"
                    }
                ],
                "photos": [
                    "http://example.com/uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg"
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": "60",
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 2
                        }
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "16"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "16"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "24"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": []
            },
            "start_items": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555002",
                        "message_id": "77",
                        "text": "*Процесс крафта запущен*\n\nТы создаёшь: 🚑 *Базовая аптечка* x1 шт.\n\n🌍 Где угодно · 🔒 Займёт целиком\n_Пока идёт — нельзя двигаться, добывать, идти в Поход и начинать второе такое же дело. Дождись окончания._\n\nВремя крафта: *30 минут* ⏱️\n\n💸 *Списано:* Грибы ×4 · Мед ×2 · Алоэ ×4 · Вода ×11 · Повязка ×5\n\nПосле завершения будет добавлено *1* шт. в твой инвентарь.\n\n❗Прерывание задачи = потеря ресурсов!\n\n_О готовности узнаешь в сообщении._ 🎁",
                        "parse_mode": "Markdown"
                    }
                ],
                "photos": [
                    "http://example.com/uploads/telegram/craft/simple_craft_kit.jpg"
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftBasicMedKit",
                        "due": "30",
                        "task_settings": {
                            "recipe": "BasicMedKit",
                            "quantity": 1
                        }
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "2"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "11"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "2"
                    }
                ],
                "gold": "150",
                "log": []
            },
            "queue": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555002",
                        "message_id": "77",
                        "text": "*В очередь поставлено:* 🩹 *Повязку* x1 шт.\n\n🌍 Где угодно · ⏳ Идёт в фоне\n\n📋 Позиция в очереди: *#2*\nНачнётся автоматически после завершения активного крафта.\n\n💸 *Списано:* Травы ×2 · Кора деревьев ×2 · Водоросли ×3\n\n❗Ресурсы уже списаны. Отмена очереди вернёт их.",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u274c \\u041e\\u0442\\u043c\\u0435\\u043d\\u0438\\u0442\\u044c \\u0438\\u0437 \\u043e\\u0447\\u0435\\u0440\\u0435\\u0434\\u0438\",\"callback_data\":\"cancelQueued_2\"},{\"text\":\"\\ud83d\\udccb \\u041e\\u0447\\u0435\\u0440\\u0435\\u0434\\u044c \\u043a\\u0440\\u0430\\u0444\\u0442\\u0430\",\"callback_data\":\"craftQueue\"}]]}"
                    }
                ],
                "photos": [
                    "http://example.com/uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg"
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": "60",
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "18"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "18"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "27"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": []
            },
            "shortage": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555002",
                        "text": "🔨 *Повязка* — не хватает материалов на *50 шт.*.\n\n*Не хватает:*\n• *Травы* — нужно 100, есть 20\n   🛒 у торговца не купить — только добыть\n• *Кора деревьев* — нужно 100, есть 20\n   🛒 у торговца не купить — только добыть\n• *Водоросли* — нужно 150, есть 30\n   🛒 у торговца не купить — только добыть\n\n_Добыть почти всегда дешевле, чем купить._",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u26cf \\u0414\\u043e\\u0431\\u044b\\u0442\\u044c\",\"callback_data\":\"gather\"},{\"text\":\"\\ud83c\\udf92 \\u0418\\u043d\\u0432\\u0435\\u043d\\u0442\\u0430\\u0440\\u044c\",\"callback_data\":\"inventory\"},{\"text\":\"\\u2b05\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434\",\"callback_data\":\"bandage\"}]]}"
                    }
                ],
                "photos": [],
                "rows": [],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": [
                    {
                        "action_name": "CRAFT_Bandage",
                        "action_status": "",
                        "description": "missing_materials | {\"missing_resources\":{\"Травы\":{\"need\":100,\"have\":20,\"name\":\"Травы\",\"storage\":0,\"pooled\":false},\"Кора деревьев\":{\"need\":100,\"have\":20,\"name\":\"Кора деревьев\",\"storage\":0,\"pooled\":false},\"Водоросли\":{\"need\":150,\"have\":30,\"name\":\"Водоросли\",\"storage\":0,\"pooled\":false}},\"missing_items\":[],\"qty\":50}"
                    }
                ]
            },
            "shortage_plain": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555002",
                        "text": "Недостаточно ресурсов для крафта 50 шт.",
                        "parse_mode": "Markdown"
                    }
                ],
                "photos": [],
                "rows": [],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": [
                    {
                        "action_name": "CRAFT_Bandage",
                        "action_status": "",
                        "description": "missing_materials | {\"missing_resources\":{\"Травы\":{\"need\":100,\"have\":20,\"name\":\"Травы\",\"storage\":0,\"pooled\":false},\"Кора деревьев\":{\"need\":100,\"have\":20,\"name\":\"Кора деревьев\",\"storage\":0,\"pooled\":false},\"Водоросли\":{\"need\":150,\"have\":30,\"name\":\"Водоросли\",\"storage\":0,\"pooled\":false}},\"missing_items\":[],\"qty\":50}"
                    }
                ]
            },
            "recipe_limit": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555002",
                        "text": "Очередь крафта *Повязка* заполнена (10 макс.). Дождись завершения или отмени один.",
                        "parse_mode": "Markdown"
                    }
                ],
                "photos": [],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": "60",
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBandage",
                        "due": null,
                        "task_settings": {
                            "recipe": "Bandage",
                            "quantity": 1
                        }
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": []
            },
            "slot_limit": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555002",
                        "text": "Все *3* слота крафта заняты. Дождись завершения одного из активных или отмени запас.",
                        "parse_mode": "Markdown"
                    }
                ],
                "photos": [],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftAntiseptic",
                        "due": "60",
                        "task_settings": {
                            "recipe": "Antiseptic",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "craftRegenerator",
                        "due": "60",
                        "task_settings": {
                            "recipe": "Regenerator",
                            "quantity": 1
                        }
                    },
                    {
                        "status": "queued",
                        "telegram_user_id": "7",
                        "task": "craftBasicMedKit",
                        "due": null,
                        "task_settings": {
                            "recipe": "BasicMedKit",
                            "quantity": 1
                        }
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": []
            },
            "busy": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555002",
                        "text": "🔒 Два таких дела сразу не идут.\n\nСейчас занят: Добыча (блокирующая)\n⌛️ Осталось примерно: 1 ч\n\nХочешь начать: Изготовление аптечки\n\n_Это дело тоже займёт тебя целиком — как добыча, движение по карте и Поход. Дождись окончания текущего или отмени его: /tasks_",
                        "parse_mode": "Markdown"
                    }
                ],
                "photos": [],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "gatherBlocking",
                        "due": "60",
                        "task_settings": null
                    }
                ],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": [
                    {
                        "action_name": "CRAFT_BasicMedKit",
                        "action_status": "",
                        "description": "exclusive_task_busy"
                    }
                ]
            },
            "no_gold": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555002",
                        "text": "Недостаточно золота. Нужно *20000* ед., есть *150* ед.",
                        "parse_mode": "Markdown"
                    }
                ],
                "photos": [],
                "rows": [],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": [
                    {
                        "action_name": "CRAFT_WorkbenchOne",
                        "action_status": "",
                        "description": "insufficient_gold | {\"need\":20000,\"have\":150}"
                    }
                ]
            },
            "unknown": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555002",
                        "text": "Неизвестный рецепт: NoSuchRecipe",
                        "parse_mode": "Markdown"
                    }
                ],
                "photos": [],
                "rows": [],
                "resources": [
                    {
                        "id_resources": "1",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "2",
                        "quantity": "20"
                    },
                    {
                        "id_resources": "3",
                        "quantity": "30"
                    },
                    {
                        "id_resources": "4",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "5",
                        "quantity": "4"
                    },
                    {
                        "id_resources": "6",
                        "quantity": "8"
                    },
                    {
                        "id_resources": "7",
                        "quantity": "22"
                    }
                ],
                "items": [
                    {
                        "crafted_item_id": "1",
                        "quantity": "7"
                    }
                ],
                "gold": "150",
                "log": []
            }
        }
        JSON;

    private BaseConnection $conn;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = Database::connect();
        $this->dropTables();
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $forge = Database::forge();
            foreach (self::MIGRATIONS as $file) {
                $this->migration($file, $forge instanceof Forge ? $forge : null)->up();
            }
            $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
            // CreateResourcesTable не идёт на MySQL 8 (TEXT UNSIGNED) — DDL-копия нужных колонок, как в CharacterProvisioningServiceTest.
            $this->conn->query(
                'CREATE TABLE resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_en VARCHAR(255) NULL,'
                . ' biome_id TEXT NULL, is_tradeable TINYINT(1) NOT NULL DEFAULT 1, type VARCHAR(255) NULL, price INT NULL, buy_price INT NULL,'
                . ' sell_price INT NULL, rarity INT NULL, level_required INT NULL, icon_text VARCHAR(255) NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->conn->query(
                'CREATE TABLE character_resources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_characters INT UNSIGNED NOT NULL,'
                . ' id_resources INT UNSIGNED NOT NULL, quantity INT NOT NULL DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)'
            );
            $this->seed();
        } catch (\Throwable $e) {
            $this->dropTables();

            throw $e;
        } finally {
            $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach (self::ENV as $k => $v) {
            $this->envBackup[$k] = getenv($k);
            putenv("{$k}={$v}");
        }
        $this->mockCache();
        $this->installRecorder();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            putenv($v === false ? $k : "{$k}={$v}");
        }
        $this->dropTables();
        parent::tearDown();
    }

    /**
     * Паритет бота: старт, старт с компонентами, постановка в очередь, нехватка (экран и строка),
     * упор в лимит рецепта и в слоты, эксклюзив, нехватка золота, неизвестный рецепт — те же
     * запросы Bot API, те же фото, те же строки `character_tasks` (`recipe`/`quantity`; новый ключ
     * `consumed` снимок не видит), остатки и action_log, что у прежнего handler'а.
     * Отдельный процесс: соседние тесты определяют `PHPUNIT_TESTSUITE`, и Longman под ним отвечает
     * фейком, не доходя до HTTP-клиента; плюс здесь подменяется обёртка потока `http`.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBotSendsTheSameMessagesAndWritesTheSameRowsAsBefore(): void
    {
        $before = json_decode(self::BOT_BEFORE, true);
        $this->assertIsArray($before);
        $this->assertSame(array_keys(self::CASES), array_keys($before));

        $this->photoWrapper();
        try {
            foreach (self::CASES as $case => [$prep, $data]) {
                $this->assertSame($before[$case], $this->runCase($prep, $data), "паритет бота: {$case}");
            }
        } finally {
            stream_wrapper_restore('http');
        }
    }

    /** Нехватка золота — отказ до транзакции: ни строки задачи, ни списания сырья и золота. */
    public function testNotEnoughGoldRefusesWithoutWriting(): void
    {
        $this->resetCase();
        $this->seedWorkbenchOne(gold: 19999);
        $before = $this->state();

        $out = (new \App\Services\Craft\CraftOrderService())->start(self::CHAR, 'WorkbenchOne', 1);

        $this->assertSame([false, \App\Services\Craft\CraftOrderService::NO_GOLD], [$out['ok'], $out['code']]);
        $this->assertSame('Недостаточно золота. Нужно *20000* ед., есть *19999* ед.', $out['message']);
        $this->assertSame($before, $this->state());
    }

    /**
     * Два старта подряд на остаток золота: и когда гейт видит свежий остаток, и когда второй старт
     * прошёл гейт по устаревшему снимку (параллельный запрос) — золото не уходит в минус, второй
     * старт не пишет строку и не списывает сырьё (условная запись откатывает всю транзакцию).
     */
    public function testGoldNeverGoesNegativeOnTwoStartsInARow(): void
    {
        foreach (['gate' => new \App\Services\Craft\CraftOrderService(), 'stale_gate' => $this->serviceWithoutGates()] as $label => $svc) {
            $this->resetCase();
            $this->seedWorkbenchOne(gold: 30000);

            $first = $svc->start(self::CHAR, 'WorkbenchOne', 1);
            $this->assertTrue($first['ok'], "{$label}: первый старт");
            $afterFirst = $this->state();

            $second = $svc->start(self::CHAR, 'WorkbenchOne', 1);
            $this->assertSame([false, \App\Services\Craft\CraftOrderService::NO_GOLD], [$second['ok'], $second['code']], $label);
            $this->assertSame('10000', (string) $this->conn->query('SELECT gold FROM characters WHERE id = 1')->getRow('gold'), $label);
            $this->assertSame($afterFirst, $this->state(), "{$label}: второй старт ничего не записал");
        }
    }

    /**
     * Лимиты очереди рецепта и слотов перепроверяются под блокировкой строки персонажа в
     * транзакции: второй старт, прошедший гейт по устаревшему снимку, всё равно упирается в лимит.
     */
    public function testQueueAndSlotLimitsHoldOnTwoStartsInARow(): void
    {
        $svc = $this->serviceWithoutGates();

        $this->resetCase();
        $this->setting(\App\Services\Craft\CraftOrderService::KEY_MAX_PER_RECIPE, 1);
        $this->assertTrue($svc->start(self::CHAR, 'Bandage', 1)['ok']);
        $afterFirst = $this->state();
        $second     = $svc->start(self::CHAR, 'Bandage', 1);
        $this->assertSame(\App\Services\Craft\CraftOrderService::QUEUE_FULL, $second['code']);
        $this->assertSame('Очередь крафта *Повязка* заполнена (1 макс.). Дождись завершения или отмени один.', $second['message']);
        $this->assertSame($afterFirst, $this->state());

        $this->resetCase();
        $this->setting(\App\Services\Craft\CraftOrderService::KEY_MAX_SLOTS, 1);
        $this->assertTrue($svc->start(self::CHAR, 'Bandage', 1)['ok']);
        $afterFirst = $this->state();
        $second     = $svc->start(self::CHAR, 'BasicMedKit', 1);
        $this->assertSame(\App\Services\Craft\CraftOrderService::SLOTS_FULL, $second['code']);
        $this->assertSame('Все *1* слота крафта заняты. Дождись завершения одного из активных или отмени запас.', $second['message']);
        $this->assertSame($afterFirst, $this->state());
    }

    /** `task_settings` нового старта несёт разбивку списания рюкзак/склад, компоненты и золото. */
    public function testStartRecordsWhereEverythingWasTakenFrom(): void
    {
        $this->resetCase();
        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (1, 5, 'active')");
        $this->conn->query('UPDATE characters SET cell_number = 5 WHERE id = 1');
        $this->conn->query('UPDATE character_resources SET quantity = 1 WHERE id_resources = 1');
        $this->conn->query('INSERT INTO base_storage (character_id, resource_id, quantity) VALUES (1, 1, 100)');

        $out = (new \App\Services\Craft\CraftOrderService())->start(self::CHAR, 'Bandage', 2);
        $this->assertTrue($out['ok']);
        $this->assertSame(\App\Services\Craft\CraftOrderService::STARTED, $out['code']);

        $settings = json_decode((string) $this->conn->query('SELECT task_settings FROM character_tasks WHERE id = ?', [$out['char_task_id']])->getRow('task_settings'), true);
        $this->assertSame([
            'recipe'   => 'Bandage',
            'quantity' => 2,
            'consumed' => [
                'resources'     => [
                    'Травы'         => ['backpack' => 1, 'storage' => 3],
                    'Кора деревьев' => ['backpack' => 4, 'storage' => 0],
                    'Водоросли'     => ['backpack' => 6, 'storage' => 0],
                ],
                'crafted_items' => [],
                'gold'          => 0,
            ],
        ], $settings);

        $this->resetCase();
        $medkit   = (new \App\Services\Craft\CraftOrderService())->start(self::CHAR, 'BasicMedKit', 1);
        $settings = json_decode((string) $this->conn->query('SELECT task_settings FROM character_tasks WHERE id = ?', [$medkit['char_task_id']])->getRow('task_settings'), true);
        $this->assertIsArray($settings);
        $this->assertSame(['Bandage' => 5], $settings['consumed']['crafted_items']);
    }

    /**
     * craft-batch-price-confirm-01: ответ старта несёт итог списания за партию для строки «Списано» —
     * рюкзак и склад сложены, компоненты русскими именами, золото за всю партию.
     */
    public function testStartReturnsWhatTheBatchCostForTheSpentLine(): void
    {
        $this->resetCase();
        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (1, 5, 'active')");
        $this->conn->query('UPDATE characters SET cell_number = 5 WHERE id = 1');
        $this->conn->query('UPDATE character_resources SET quantity = 1 WHERE id_resources = 1');
        $this->conn->query('INSERT INTO base_storage (character_id, resource_id, quantity) VALUES (1, 1, 100)');

        $out = (new \App\Services\Craft\CraftOrderService())->start(self::CHAR, 'Bandage', 2);
        $this->assertTrue($out['ok']);
        $this->assertSame([
            'gold'          => 0,
            'resources'     => ['Травы' => 4, 'Кора деревьев' => 4, 'Водоросли' => 6],
            'crafted_items' => [],
        ], $out['consumed']);

        $this->resetCase();
        $medkit = (new \App\Services\Craft\CraftOrderService())->start(self::CHAR, 'BasicMedKit', 1);
        $this->assertSame(['Повязка' => 5], $medkit['consumed']['crafted_items']);

        $this->resetCase();
        $this->seedWorkbenchOne(gold: 20000);
        $paid = (new \App\Services\Craft\CraftOrderService())->start(self::CHAR, 'WorkbenchOne', 1);
        $this->assertTrue($paid['ok']);
        $this->assertSame(20000, $paid['consumed']['gold']);

        $this->resetCase();
        $this->seedWorkbenchOne(gold: 19999);
        $refused = (new \App\Services\Craft\CraftOrderService())->start(self::CHAR, 'WorkbenchOne', 1);
        $this->assertSame(['gold' => 0, 'resources' => [], 'crafted_items' => []], $refused['consumed']);
    }

    /** Превью: без записи; `max_qty` — сколько хватает сырья, 0 при упоре в очередь. */
    public function testPreviewCountsMaxQuantityAndWritesNothing(): void
    {
        $this->resetCase();
        $before = $this->state();
        $p      = (new \App\Services\Craft\CraftOrderService())->preview(self::CHAR, 'Bandage', 3);
        $this->assertTrue($p['ok']);
        $this->assertSame(10, $p['max_qty'], 'Травы 20/2, Кора 20/2, Водоросли 30/3');
        $this->assertSame(['name' => 'Травы', 'need' => 6, 'have' => 20], $p['resources'][0]);
        $this->assertSame(1, $p['queue_pos']);
        $this->assertSame($p['minutes_one'] * 3, $p['minutes_total']);
        $this->assertSame($before, $this->state());

        $this->fill($this->taskId('craftBandage'), 10);
        $full = (new \App\Services\Craft\CraftOrderService())->preview(self::CHAR, 'Bandage', 1);
        $this->assertSame([false, \App\Services\Craft\CraftOrderService::QUEUE_FULL, 0], [$full['ok'], $full['code'], $full['max_qty']]);
    }

    /** Лимиты очереди — в GameSettings со значениями 10 и 3; повторный прогон seed-миграции ничего не меняет. */
    public function testQueueLimitSeedIsIdempotentAndCarriesOldValues(): void
    {
        $m = $this->migration('2026-12-14-100000_SeedCraftQueueLimitSettings', null);
        $m->up();
        $this->conn->query("UPDATE game_settings SET value_int = 7 WHERE setting_key = 'craft.queue.max_slots'");
        $m->up();

        $rows = $this->conn->query(
            "SELECT setting_key, value_int, default_value_text, category FROM game_settings WHERE setting_key LIKE 'craft.queue.%' ORDER BY setting_key"
        )->getResultArray();
        $this->assertSame([
            ['setting_key' => 'craft.queue.max_per_recipe', 'value_int' => '10', 'default_value_text' => '10', 'category' => 'craft'],
            ['setting_key' => 'craft.queue.max_slots', 'value_int' => '7', 'default_value_text' => '3', 'category' => 'craft'],
        ], $rows, 'второй прогон не дублирует и не перетирает значение из админки');
    }

    /** Ядро, у которого гейты прошли по устаревшему снимку — как у второго из двух параллельных запросов. */
    private function serviceWithoutGates(): \App\Services\Craft\CraftOrderService
    {
        return new class () extends \App\Services\Craft\CraftOrderService {
            public function gateError(string $recipeKey, array $recipe, array|\App\Entities\CharacterEntity $character, array $taskRow, int $quantity): ?array
            {
                return null;
            }
        };
    }

    private function setting(string $key, int $value): void
    {
        $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_int, category) VALUES (?, 'int', ?, 'craft')", [$key, $value]);
    }

    /** Верстак 1: база, 20000 золота, 4 сырья и 3 компонента — на две сборки. */
    private function seedWorkbenchOne(int $gold): void
    {
        $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (1, 5, 'active')");
        $this->conn->query('UPDATE characters SET gold = ? WHERE id = 1', [$gold]);
        foreach ([8 => 480, 9 => 80, 10 => 40, 11 => 20] as $id => $qty) {
            $this->conn->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, ?, ?)', [$id, $qty]);
        }
        foreach ([2 => 28, 3 => 48, 4 => 40] as $id => $qty) {
            $this->conn->query("INSERT INTO crafted_items_log (character_id, crafted_item_id, type, quantity) VALUES (1, ?, 'component', ?)", [$id, $qty]);
        }
    }

    /** @return array<string, mixed> */
    private function runCase(string $prep, string $data): array
    {
        $this->resetCase();
        $craft = $this->taskId('craftBandage');
        match ($prep) {
            'bandage_in_work' => $this->charTask($craft, 'in_work', 'Bandage'),
            'bandage_full'    => $this->fill($craft, 10),
            'slots_full'      => [$this->charTask($this->taskId('craftAntiseptic'), 'in_work', 'Antiseptic'), $this->charTask($this->taskId('craftRegenerator'), 'in_work', 'Regenerator'), $this->charTask($this->taskId('craftBasicMedKit'), 'queued', 'BasicMedKit')],
            'busy'            => $this->charTask($this->taskId('gatherBlocking'), 'in_work', null),
            'hint_off'        => $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_bool, category) VALUES ('craft.shortage_hint.enabled', 'bool', 0, 'craft')"),
            'base'            => $this->conn->query("INSERT INTO claimed_cells (character_id, map_cell_id, status) VALUES (1, 5, 'active')"),
            default           => null,
        };
        self::$photos = [];
        $this->sent   = [];
        $this->press($data);

        return ['sent' => $this->sent, 'photos' => self::$photos] + $this->state();
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $rows = $this->conn->query(
            'SELECT ct.status, ct.telegram_user_id, t.name AS task, TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time) AS due, ct.task_settings'
            . ' FROM character_tasks ct LEFT JOIN tasks t ON t.id = ct.task_id ORDER BY ct.id'
        )->getResultArray();
        foreach ($rows as &$row) {
            $settings = json_decode((string) ($row['task_settings'] ?? 'null'), true);
            $row['task_settings'] = is_array($settings)
                ? array_intersect_key($settings, ['recipe' => 1, 'quantity' => 1])
                : $settings;
        }
        unset($row);

        return [
            'rows'      => $rows,
            'resources' => $this->conn->query('SELECT id_resources, quantity FROM character_resources ORDER BY id_resources')->getResultArray(),
            'items'     => $this->conn->query('SELECT crafted_item_id, quantity FROM crafted_items_log ORDER BY id')->getResultArray(),
            'gold'      => $this->conn->query('SELECT gold FROM characters WHERE id = 1')->getRow('gold'),
            'log'       => $this->conn->query('SELECT action_name, action_status, description FROM action_log ORDER BY id')->getResultArray(),
        ];
    }

    private function resetCase(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['character_tasks', 'action_log', 'character_resources', 'crafted_items_log', 'claimed_cells', 'game_settings'] as $t) {
            $this->conn->query("TRUNCATE TABLE {$t}");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        foreach ([1 => 20, 2 => 20, 3 => 30, 4 => 8, 5 => 4, 6 => 8, 7 => 22] as $id => $qty) {
            $this->conn->query('INSERT INTO character_resources (id_characters, id_resources, quantity) VALUES (1, ?, ?)', [$id, $qty]);
        }
        $this->conn->query("INSERT INTO crafted_items_log (character_id, crafted_item_id, type, quantity) VALUES (1, 1, 'drug', 7)");
        $this->conn->query('UPDATE characters SET gold = 150 WHERE id = 1');
        service('cache')->clean();
    }

    private function fill(int $taskId, int $n): void
    {
        $this->charTask($taskId, 'in_work', 'Bandage');
        for ($i = 1; $i < $n; $i++) {
            $this->charTask($taskId, 'queued', 'Bandage');
        }
    }

    private function charTask(int $taskId, string $status, ?string $recipe): void
    {
        $this->conn->query(
            'INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings) VALUES (1, 7, ?, ?, ?, ?, ?)',
            [$taskId, date('Y-m-d H:i:s', time() - 30), $status === 'in_work' ? date('Y-m-d H:i:s', time() + 3570) : null, $status, $recipe === null ? null : json_encode(['recipe' => $recipe, 'quantity' => 1])]
        );
    }

    private function taskId(string $name): int
    {
        $id = $this->conn->query('SELECT id FROM tasks WHERE name = ?', [$name])->getRow('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    private function press(string $data): void
    {
        $cb = new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 77, 'date' => 0, 'chat' => ['id' => self::TG, 'type' => 'private']],
            'data'    => $data,
        ]);
        (new GenericCraftActionStart($cb))->handle();
    }

    /** @var list<string> */
    public static array $photos = [];

    /** Фото в media-off не уходит, но handler открывает поток по URL — пишем адрес вместо сети. */
    private function photoWrapper(): void
    {
        stream_wrapper_unregister('http');
        stream_wrapper_register('http', CraftOrderPhotoStream::class);
    }

    private function installRecorder(): void
    {
        $this->sent = [];
        new Telegram(self::ENV['telegram.API_KEY'], self::ENV['telegram.BOT_USERNAME']);
        $client = new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path         = explode('/', $request->getUri()->getPath());
            $this->sent[] = ['method' => (string) end($path)] + $params;

            return Create::promiseFor(new Response(200, [], '{"ok":true,"result":true}'));
        }]);
        LongmanRequest::setClient($client);
    }

    private function seed(): void
    {
        $this->conn->query("INSERT INTO biomes (id, name, danger_level) VALUES (1, 'b1', 1)");
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES (5, 5, 4, 0, 1), (6, 6, 5, 0, 1)');
        $this->conn->query("INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, 'Тест')", [self::TG]);
        $this->conn->query(
            "INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, disable_media)"
            . " VALUES (1, 7, 'Тест', 5, 1.5, 90, 10, 0.5, 0.01, 0.01, 150, 6, 1)"
        );
        foreach (self::RESOURCES as $id => $name) {
            $this->conn->query("INSERT INTO resources (id, name, name_en, type, rarity) VALUES (?, ?, ?, 'plant', 1)", [$id, $name, 'r' . $id]);
        }
        foreach ([8 => 'Древесина', 9 => 'Смола деревьев', 10 => 'Базальт', 11 => 'Лавовый камень'] as $id => $name) {
            $this->conn->query("INSERT INTO resources (id, name, name_en, type, rarity) VALUES (?, ?, ?, 'plant', 1)", [$id, $name, 'r' . $id]);
        }
        $this->conn->query(
            "INSERT INTO crafted_items (id, name_rus, name_eng, type) VALUES (1, 'Повязка', 'Bandage', 'drug'),"
            . " (2, 'Каменные блоки', 'stoneBlocks', 'component'), (3, 'Деревянные материалы', 'WoodMaterials', 'component'),"
            . " (4, 'Металлические фрагменты', 'metalFragments', 'component')"
        );
        foreach ([
            ['craftBandage', 'Изготовление повязки', 1],
            ['craftBasicMedKit', 'Изготовление аптечки', 0],
            ['craftAntiseptic', 'Изготовление антисептика', 1],
            ['craftRegenerator', 'Изготовление регенератора', 1],
            ['craftWorkbenchOne', 'Сборка верстака', 0],
        ] as [$name, $rus, $parallel]) {
            $this->conn->query("INSERT INTO tasks (name, name_rus, min_duration, max_duration, type, parallel_execution_allowed) VALUES (?, ?, 10, 30, 'craft', ?)", [$name, $rus, $parallel]);
        }
        $this->conn->query("INSERT INTO tasks (name, name_rus, min_duration, max_duration, type, parallel_execution_allowed) VALUES ('gatherBlocking', 'Добыча (блокирующая)', 10, 30, 'gather', 0)");
    }

    private function migration(string $file, ?Forge $forge): Migration
    {
        require_once APPPATH . 'Database/Migrations/' . $file . '.php';
        $class = 'App\\Database\\Migrations\\' . substr($file, 18);
        $m     = new $class($forge);
        $this->assertInstanceOf(Migration::class, $m);

        return $m;
    }

    private function dropTables(): void
    {
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(self::TABLES) as $t) {
            $this->conn->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->conn->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->conn->resetDataCache();
    }
}

/** Поток-заглушка для `http://`: запоминает адрес фото и отдаёт пустое тело. */
final class CraftOrderPhotoStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        CraftOrderServiceTest::$photos[] = $path;

        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
