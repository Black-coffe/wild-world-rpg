<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\CancelMarchAction;
use App\Controllers\Telegram\Commands\Actions\MarchAction;
use App\Services\World\MarchService;
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
 * W2.N2-03 (ADR-190) — Поход как сервис: {@see MarchService} даёт те же отказы и ту же строку
 * `character_tasks`, что прежние `MarchAction`/`CancelMarchAction`; бот-рендерер шлёт те же сообщения.
 *
 * Паритет бота: {@see self::BOT_BEFORE} — запросы Bot API и строки Похода прежних handler'ов на этой
 * фикстуре (сняты с кода ДО переноса логики в сервис). Схема — из миграций, как в MoveServiceTest.
 *
 * Фикстура: персонаж на (0, 500) у западного края; (1, 500) — биом 9, (1..2, 500) разведаны.
 *
 * @internal
 */
final class MarchServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const MIGRATIONS = [
        '2024-03-17-222643_CreateBiomesTable',
        '2024-03-18-105708_CreateMapTable',
        '2024-03-20-153728_CreateTelegramUsersTable',
        '2024-03-20-154155_CreateCharactersTable',
        '2024-03-18-134951_CreateActionLogTable',
        '2024-03-22-111828_CreateTasksTable',
        '2024-03-22-132411_CreateCharacterTasksTable',
        '2026-05-10-190000_AddPausedStatusToCharacterTasks',
        '2024-03-24-212921_CreateExploredCellsTable',
        '2024-05-23-061031_CreateClaimedCellsTable',
        '2024-04-16-100640_CreateCraftedItemsTable',
        '2024-04-16-122053_CreateCraftedItemsLogTable',
        '2026-05-29-500000_W3aCreateBaseStorage',
        '2026-05-19-100000_CreateGameSettingsTable',
    ];

    private const TABLES = [
        'biomes', 'map', 'telegram_users', 'characters', 'action_log', 'tasks', 'character_tasks', 'explored_cells',
        'claimed_cells', 'crafted_items', 'crafted_items_log', 'base_storage', 'game_settings', 'npc_spawns',
    ];

    private const ENV = ['telegram.API_KEY' => '123456:TEST_TOKEN', 'telegram.BOT_USERNAME' => 'wildworldtest_bot'];

    private const CHAR = 1;
    private const TG   = 555001;

    /** Нажатия бота по случаям паритета: [подготовка, callback]. */
    private const CASES = [
        'picker'      => ['', 'march'],
        'setup'       => ['', 'march_east_3'],
        'setup_cap'   => ['', 'march_east_999'],
        'setup_edge'  => ['', 'march_west_2'],
        'go'          => ['', 'march_go_east_3'],
        'go_fallback' => ['edit_fails', 'march_go_east_12'],
        'go_no_task'  => ['no_marching', 'march_go_east_3'],
        'go_busy'     => ['busy', 'march_go_east_3'],
        'go_reloc'    => ['relocation', 'march_go_east_3'],
        'more'        => ['in_work', 'march_more_5'],
        'more_none'   => ['', 'march_more_5'],
        'resume'      => ['paused', 'march_resume'],
        'resume_none' => ['', 'march_resume'],
        'cancel'      => ['in_work', 'cancelMarch'],
        'cancel_pause'=> ['paused', 'cancelMarch'],
        'cancel_none' => ['', 'cancelMarch'],
    ];

    private const BOT_BEFORE = <<<'JSON'
        {
            "picker": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "text": "🚜 *Куда выступаем?* Выбери направление.\n\n_В походе ты идёшь, пока что-то не потребует решения: встреча с игроком, тяжёлая рана, чужой лагерь на пути, край мира, привал по усталости._",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u2196\\ufe0f \\u0421\\u0435\\u0432-\\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"march_northwest_1\"},{\"text\":\"\\u2b06\\ufe0f \\u0421\\u0435\\u0432\\u0435\\u0440\",\"callback_data\":\"march_north_1\"},{\"text\":\"\\u2197\\ufe0f \\u0421\\u0435\\u0432-\\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"march_northeast_1\"}],[{\"text\":\"\\u2b05\\ufe0f \\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"march_west_1\"},{\"text\":\"\\u21a9\\ufe0f \\u041a \\u043a\\u0430\\u0440\\u0442\\u0435\",\"callback_data\":\"move\"},{\"text\":\"\\u27a1\\ufe0f \\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"march_east_1\"}],[{\"text\":\"\\u2199\\ufe0f \\u042e\\u0433\\u043e-\\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"march_southwest_1\"},{\"text\":\"\\u2b07\\ufe0f \\u042e\\u0433\",\"callback_data\":\"march_south_1\"},{\"text\":\"\\u2198\\ufe0f \\u042e\\u0433\\u043e-\\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"march_southeast_1\"}]]}",
                        "message_id": "77"
                    }
                ],
                "rows": []
            },
            "setup": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "text": "🚜 *Поход:* ➡️ Восток ×3\n\nВидишь впереди (1 клетка): b9. Дальше — туман.\nВ пути возможно (поход тогда прервётся, ты решишь):\n  • встреча с игроком → бой / бегство / пройти мимо\n  • рейдеры → авто-стычка; тяжёлая рана → привал\n  • объект/событие со 100% триггером → отчёт и выбор\n  • чужой лагерь на пути → остановишься не доходя\n  • кончится выносливость → привал раньше срока\n_Прочее (находки, биомы, мелочи) — разгребётся само, отчёт по прибытии._\n\nРасход ≈ ❤️0.06  💤1.5  ·  в пути ~1 мин\nРазведано 2 по 3 · целина 1 по 3 — 1 мин (пешком 1)\n_Отряд идёт сам и довольно шустро — темп от ❤️/💤 не зависит (они лишь топливо в пути), но зависит от активного транспорта._\n\nЗаказать можно не больше 60 клеток за раз (зависит от транспорта).\n\n🚚 Транспорт — в разработке, на темп похода пока не влияет.",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u2796\",\"callback_data\":\"march_east_2\"},{\"text\":\"\\u00d73\",\"callback_data\":\"march_east_3\"}],[{\"text\":\"\\u27955\",\"callback_data\":\"march_east_8\"},{\"text\":\"\\ud83d\\ude9c \\u0412\\u044b\\u0441\\u0442\\u0443\\u043f\\u0438\\u0442\\u044c\",\"callback_data\":\"march_go_east_3\"}],[{\"text\":\"\\ud83e\\udded \\u0414\\u0440\\u0443\\u0433\\u043e\\u0435 \\u043d\\u0430\\u043f\\u0440\\u0430\\u0432\\u043b\\u0435\\u043d\\u0438\\u0435\",\"callback_data\":\"march\"},{\"text\":\"\\u21a9\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434\",\"callback_data\":\"move\"},{\"text\":\"\\ud83d\\ude9a \\u0422\\u0440\\u0430\\u043d\\u0441\\u043f\\u043e\\u0440\\u0442\",\"callback_data\":\"vehicleScreen\"}]]}",
                        "message_id": "77"
                    }
                ],
                "rows": []
            },
            "setup_cap": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "text": "🚜 *Поход:* ➡️ Восток ×60\n\nВидишь впереди (1 клетка): b9. Дальше — туман.\nВ пути возможно (поход тогда прервётся, ты решишь):\n  • встреча с игроком → бой / бегство / пройти мимо\n  • рейдеры → авто-стычка; тяжёлая рана → привал\n  • объект/событие со 100% триггером → отчёт и выбор\n  • чужой лагерь на пути → остановишься не доходя\n  • кончится выносливость → привал раньше срока\n_Прочее (находки, биомы, мелочи) — разгребётся само, отчёт по прибытии._\n\nРасход ≈ ❤️1.2  💤30  ·  в пути ~5 мин\nРазведано 2 по 3 · целина 12 по 3 — 5 мин (пешком 5)\n_Отряд идёт сам и довольно шустро — темп от ❤️/💤 не зависит (они лишь топливо в пути), но зависит от активного транспорта._\n\nЗаказать можно не больше 60 клеток за раз (зависит от транспорта).\n\n🚚 Транспорт — в разработке, на темп похода пока не влияет.",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u2796\",\"callback_data\":\"march_east_59\"},{\"text\":\"\\u00d760\",\"callback_data\":\"march_east_60\"}],[{\"text\":\"\\u27955\",\"callback_data\":\"march_east_60\"},{\"text\":\"\\ud83d\\ude9c \\u0412\\u044b\\u0441\\u0442\\u0443\\u043f\\u0438\\u0442\\u044c\",\"callback_data\":\"march_go_east_60\"}],[{\"text\":\"\\ud83e\\udded \\u0414\\u0440\\u0443\\u0433\\u043e\\u0435 \\u043d\\u0430\\u043f\\u0440\\u0430\\u0432\\u043b\\u0435\\u043d\\u0438\\u0435\",\"callback_data\":\"march\"},{\"text\":\"\\u21a9\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434\",\"callback_data\":\"move\"},{\"text\":\"\\ud83d\\ude9a \\u0422\\u0440\\u0430\\u043d\\u0441\\u043f\\u043e\\u0440\\u0442\",\"callback_data\":\"vehicleScreen\"}]]}",
                        "message_id": "77"
                    }
                ],
                "rows": []
            },
            "setup_edge": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "text": "🚜 *Поход:* ⬅️ Запад ×2\n\nВидишь впереди (1 клетка): край мира. Дальше — туман.\nВ пути возможно (поход тогда прервётся, ты решишь):\n  • встреча с игроком → бой / бегство / пройти мимо\n  • рейдеры → авто-стычка; тяжёлая рана → привал\n  • объект/событие со 100% триггером → отчёт и выбор\n  • чужой лагерь на пути → остановишься не доходя\n  • кончится выносливость → привал раньше срока\n_Прочее (находки, биомы, мелочи) — разгребётся само, отчёт по прибытии._\n\nРасход ≈ ❤️0.04  💤1  ·  в пути ~0 мин\n_Отряд идёт сам и довольно шустро — темп от ❤️/💤 не зависит (они лишь топливо в пути), но зависит от активного транспорта._\n\nЗаказать можно не больше 60 клеток за раз (зависит от транспорта).\n\n🚚 Транспорт — в разработке, на темп похода пока не влияет.",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u2796\",\"callback_data\":\"march_west_1\"},{\"text\":\"\\u00d72\",\"callback_data\":\"march_west_2\"}],[{\"text\":\"\\u27955\",\"callback_data\":\"march_west_7\"},{\"text\":\"\\ud83d\\ude9c \\u0412\\u044b\\u0441\\u0442\\u0443\\u043f\\u0438\\u0442\\u044c\",\"callback_data\":\"march_go_west_2\"}],[{\"text\":\"\\ud83e\\udded \\u0414\\u0440\\u0443\\u0433\\u043e\\u0435 \\u043d\\u0430\\u043f\\u0440\\u0430\\u0432\\u043b\\u0435\\u043d\\u0438\\u0435\",\"callback_data\":\"march\"},{\"text\":\"\\u21a9\\ufe0f \\u041d\\u0430\\u0437\\u0430\\u0434\",\"callback_data\":\"move\"},{\"text\":\"\\ud83d\\ude9a \\u0422\\u0440\\u0430\\u043d\\u0441\\u043f\\u043e\\u0440\\u0442\",\"callback_data\":\"vehicleScreen\"}]]}",
                        "message_id": "77"
                    }
                ],
                "rows": []
            },
            "go": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "🚜 *Как работает Поход*\n\nОтряд теперь идёт *сам, в фоне* — можешь заниматься другими делами, карта обновляется по мере движения.\n\nПро скорость (частый вопрос):\n• Отряд идёт *ровным шагом и довольно быстро*. Темп *постоянный* — он не ускоряется и не замедляется от того, далеко ли цель.\n• Скорость *не зависит* от здоровья и выносливости. ❤️ и 💤 — это *топливо*: они тратятся за каждую клетку, а не разгоняют отряд. Даже с полными показателями Поход идёт тем же темпом.\n• Кончится выносливость — отряд встанет на привал раньше срока.\n\n_Кнопка «➕ Продлить» добавит клеток на ходу, «❌ Остановиться» — прервёт поход в любой момент._",
                        "parse_mode": "Markdown"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "text": "🚜 *Поход начат:* ➡️ Восток ×3\n\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜🙎‍♂️🏜️🌲⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n\n_Карта обновляется по мере движения — отряд идёт сам и довольно шустро (темп ровный, от ❤️/💤 не зависит)._",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u274c \\u041e\\u0441\\u0442\\u0430\\u043d\\u043e\\u0432\\u0438\\u0442\\u044c\\u0441\\u044f\",\"callback_data\":\"cancelMarch\"}]]}",
                        "message_id": "77"
                    }
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "Marching",
                        "due": "0",
                        "task_settings": {
                            "acc": [],
                            "heading": "east",
                            "log": [],
                            "msg_chat_id": 555001,
                            "msg_id": 77,
                            "started_cell": 500001,
                            "steps_done": 0,
                            "steps_planned": 3
                        }
                    }
                ]
            },
            "go_fallback": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "🚜 *Как работает Поход*\n\nОтряд теперь идёт *сам, в фоне* — можешь заниматься другими делами, карта обновляется по мере движения.\n\nПро скорость (частый вопрос):\n• Отряд идёт *ровным шагом и довольно быстро*. Темп *постоянный* — он не ускоряется и не замедляется от того, далеко ли цель.\n• Скорость *не зависит* от здоровья и выносливости. ❤️ и 💤 — это *топливо*: они тратятся за каждую клетку, а не разгоняют отряд. Даже с полными показателями Поход идёт тем же темпом.\n• Кончится выносливость — отряд встанет на привал раньше срока.\n\n_Кнопка «➕ Продлить» добавит клеток на ходу, «❌ Остановиться» — прервёт поход в любой момент._",
                        "parse_mode": "Markdown"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "text": "🚜 *Поход начат:* ➡️ Восток ×12\n\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜🙎‍♂️🏜️🌲⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n\n_Карта обновляется по мере движения — отряд идёт сам и довольно шустро (темп ровный, от ❤️/💤 не зависит)._",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u274c \\u041e\\u0441\\u0442\\u0430\\u043d\\u043e\\u0432\\u0438\\u0442\\u044c\\u0441\\u044f\",\"callback_data\":\"cancelMarch\"}]]}",
                        "message_id": "77"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "🚜 *Поход начат:* ➡️ Восток ×12\n\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜🙎‍♂️🏜️🌲⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️\n\n_Карта обновляется по мере движения — отряд идёт сам и довольно шустро (темп ровный, от ❤️/💤 не зависит)._",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u274c \\u041e\\u0441\\u0442\\u0430\\u043d\\u043e\\u0432\\u0438\\u0442\\u044c\\u0441\\u044f\",\"callback_data\":\"cancelMarch\"}]]}"
                    }
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "Marching",
                        "due": "0",
                        "task_settings": {
                            "acc": [],
                            "heading": "east",
                            "log": [],
                            "msg_chat_id": 555001,
                            "msg_id": 77,
                            "started_cell": 500001,
                            "steps_done": 0,
                            "steps_planned": 12
                        }
                    }
                ]
            },
            "go_no_task": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "Задача \"Поход\" не найдена в системе."
                    }
                ],
                "rows": []
            },
            "go_busy": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "*Вы не можете начать действие!* 😥\n\n*Вы уже заняты выполнением задачи:*\n\n👉 *Крафт* 👈\n⌛️ До конца еще: *59* минут!\n\n**😔 Пожалуйста, завершите текущую задачу или дождитесь окончания, прежде чем начинать новую.**\n\n*P.S.*\n\n💡 Вы можете посмотреть список активных задач, используя команду➡️ /tasks\n\n",
                        "reply_markup": "{\"inline_keyboard\":[]}",
                        "parse_mode": "Markdown"
                    }
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "Craft",
                        "due": "3600",
                        "task_settings": null
                    }
                ]
            },
            "go_reloc": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "Сейчас идёт *Планируемый переезд базы*.\nПока эта задача активна, это действие недоступно!",
                        "parse_mode": "Markdown"
                    }
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "BaseRelocation",
                        "due": "3600",
                        "task_settings": null
                    }
                ]
            },
            "more": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1",
                        "text": "Поход продлён на 5 клеток. Всего: 11."
                    }
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "Marching",
                        "due": "0",
                        "task_settings": {
                            "acc": [],
                            "heading": "east",
                            "log": [],
                            "msg_chat_id": 555001,
                            "msg_id": 70,
                            "started_cell": 500001,
                            "steps_done": 2,
                            "steps_planned": 11
                        }
                    }
                ]
            },
            "more_none": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1",
                        "text": "Поход уже завершён — продлевать нечего."
                    }
                ],
                "rows": []
            },
            "resume": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1",
                        "text": "Поход возобновлён."
                    }
                ],
                "rows": [
                    {
                        "status": "in_work",
                        "telegram_user_id": "7",
                        "task": "Marching",
                        "due": "0",
                        "task_settings": {
                            "acc": [],
                            "heading": "east",
                            "log": [],
                            "msg_chat_id": 555001,
                            "msg_id": 70,
                            "paused_reason": "player_detected",
                            "started_cell": 500001,
                            "steps_done": 4,
                            "steps_planned": 6,
                            "steps_remaining": 2
                        }
                    }
                ]
            },
            "resume_none": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1",
                        "text": "Походов на паузе нет."
                    }
                ],
                "rows": []
            },
            "cancel": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "text": "🚜 *Поход прерван.* Пройдено `2` клетки.\n_Раскрытые клетки остаются на карте._",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\ud83e\\uddd1\\u200d\\ud83c\\udf3e \\u0414\\u0435\\u0439\\u0441\\u0442\\u0432\\u0438\\u044f \\ud83d\\udee0\\ufe0f\",\"callback_data\":\"characterActions\"}]]}",
                        "message_id": "77"
                    }
                ],
                "rows": [
                    {
                        "status": "completed",
                        "telegram_user_id": "7",
                        "task": "Marching",
                        "due": "0",
                        "task_settings": {
                            "acc": [],
                            "heading": "east",
                            "log": [],
                            "msg_chat_id": 555001,
                            "msg_id": 70,
                            "started_cell": 500001,
                            "steps_done": 2,
                            "steps_planned": 6
                        }
                    }
                ]
            },
            "cancel_pause": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "text": "🚜 *Поход прерван.* Пройдено `4` клетки.\n_Раскрытые клетки остаются на карте._",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\ud83e\\uddd1\\u200d\\ud83c\\udf3e \\u0414\\u0435\\u0439\\u0441\\u0442\\u0432\\u0438\\u044f \\ud83d\\udee0\\ufe0f\",\"callback_data\":\"characterActions\"}]]}",
                        "message_id": "77"
                    }
                ],
                "rows": [
                    {
                        "status": "completed",
                        "telegram_user_id": "7",
                        "task": "Marching",
                        "due": "0",
                        "task_settings": {
                            "acc": [],
                            "heading": "east",
                            "log": [],
                            "msg_chat_id": 555001,
                            "msg_id": 70,
                            "paused_reason": "player_detected",
                            "started_cell": 500001,
                            "steps_done": 4,
                            "steps_planned": 6,
                            "steps_remaining": 2
                        }
                    }
                ]
            },
            "cancel_none": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1",
                        "text": "Активного похода нет."
                    }
                ],
                "rows": []
            }
        }
        JSON;

    private BaseConnection $conn;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private bool $editFails = false;

    private bool $captureRowsOnSend = false;

    /** @var list<list<array<string, mixed>>> строки Похода на момент каждого запроса к Bot API */
    private array $rowsOnSend = [];

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
            // Как в MoveServiceTest: две колонки characters и legacy-колонки — ALTER'ом, npc_spawns — DDL-копией.
            $this->conn->query('ALTER TABLE characters ADD biome_id INT UNSIGNED NULL AFTER cell_number');
            $this->conn->query('ALTER TABLE characters ADD active_vehicle_log_id INT UNSIGNED NULL');
            $this->conn->query('ALTER TABLE character_tasks ADD task_settings TEXT NULL');
            $this->conn->query('ALTER TABLE telegram_users ADD last_map_message_id BIGINT NULL');
            $this->conn->query(
                'CREATE TABLE `npc_spawns` ('
                . ' `id` int unsigned NOT NULL AUTO_INCREMENT, `npc_id` int unsigned NOT NULL, `cell_number` int NOT NULL,'
                . ' `coordinate_x` int NOT NULL, `coordinate_y` int NOT NULL,'
                . " `current_health` decimal(7,2) NOT NULL DEFAULT '100.00', `spawned_at` datetime DEFAULT CURRENT_TIMESTAMP,"
                . " `status` varchar(20) NOT NULL DEFAULT 'alive',"
                . ' `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
                . ' PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
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
     * Паритет бота: пикер, экран маршрута (с потолком и у края), старт (правка и новое сообщение),
     * отказы старта, продление, возобновление, остановка — те же запросы Bot API и те же строки
     * `character_tasks`, что у прежних handler'ов. `task_settings` сравниваются по ключам
     * (сервис дописывает `msg_*` после отправки — порядок ключей другой, значения те же).
     *
     * Отдельный процесс: другие тесты набора определяют `PHPUNIT_TESTSUITE`, и Longman под ним
     * отвечает фейком, не доходя до HTTP-клиента, — запись запросов ослепла бы.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBotSendsTheSameMessagesAndWritesTheSameRowsAsBefore(): void
    {
        $before = json_decode(self::BOT_BEFORE, true);
        $this->assertIsArray($before);
        $this->assertSame(array_keys(self::CASES), array_keys($before));

        foreach (self::CASES as $case => [$prep, $data]) {
            $this->assertSame($before[$case], $this->runCase($prep, $data), "паритет бота: {$case}");
        }
    }

    public function testWebStartWritesTheBotRowWithoutMessageIds(): void
    {
        $this->resetCase();
        $out = (new MarchService())->start(self::CHAR, 'east', 3);
        $this->assertTrue($out['ok']);
        $this->assertSame(MarchService::STARTED, $out['code']);
        $this->assertSame(3, $out['n']);

        $rows = $this->marchRows();
        $this->assertCount(1, $rows);
        $bot = json_decode(self::BOT_BEFORE, true)['go']['rows'][0];
        unset($bot['task_settings']['msg_chat_id'], $bot['task_settings']['msg_id']);
        $this->assertSame($bot, $rows[0], 'та же строка, что у бота, без msg_*');
    }

    /**
     * Ревью раунда 1, minor 1: бот-Поход несёт `msg_chat_id`/`msg_id` с момента вставки строки —
     * на каждом запросе к Bot API, при котором строка уже есть (подсказки, правка экрана), она с ними.
     * Отдельный процесс — как у снимка паритета: статическое состояние Bot API от соседних тестов.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBotStartRowCarriesMessageIdsFromTheInsert(): void
    {
        $this->resetCase();
        $this->captureRowsOnSend = true;
        $this->rowsOnSend        = [];
        $this->press('march_go_east_3');
        $this->captureRowsOnSend = false;

        $seen = array_values(array_filter($this->rowsOnSend, static fn (array $rows): bool => $rows !== []));
        $this->assertNotSame([], $seen, 'после вставки бот что-то отправил');
        foreach ($seen as $i => $rows) {
            $this->assertCount(1, $rows);
            $settings = $rows[0]['task_settings'];
            $this->assertSame([self::TG, 77], [$settings['msg_chat_id'] ?? null, $settings['msg_id'] ?? null], "запрос #{$i}");
        }

        $this->resetCase();
        (new MarchService())->start(self::CHAR, 'east', 3, self::TG, 91);
        $settings = $this->marchRows()[0]['task_settings'];
        $this->assertSame([self::TG, 91], [$settings['msg_chat_id'], $settings['msg_id']]);
    }

    /** Ревью раунда 1, minor 2: продление зажато в потолок заказа — и в сервисе, и через callback бота. */
    public function testExtendIsClampedToTheOrderCap(): void
    {
        $march = new MarchService();
        $row   = ['heading' => 'east', 'steps_planned' => 6, 'steps_done' => 2, 'started_cell' => self::cell(0, 500), 'acc' => [], 'log' => []];

        $this->resetCase();
        $this->marchRow('in_work', $row);
        $out = $march->extend(self::CHAR, 9999);
        $this->assertSame([true, 66], [$out['ok'], $out['total']], 'n зажат в потолок 60 нейтрального профиля');
        $this->assertSame('Поход продлён на 60 клеток. Всего: 66.', $out['message']);

        $this->resetCase();
        $this->marchRow('in_work', $row);
        $this->press('march_more_9999');
        $this->assertSame(66, $this->marchRows()[0]['task_settings']['steps_planned']);
    }

    public function testRefusalsCarryCodesAndWriteNothing(): void
    {
        $march = new MarchService();

        $this->resetCase();
        $this->assertSame(MarchService::BAD_DIR, $march->start(self::CHAR, 'up', 3)['code']);
        $this->assertSame(MarchService::NO_CHARACTER, $march->preview(999, 'east', 3)['code']);

        $this->task('Craft', 'Крафт', 0);
        $busy = $march->start(self::CHAR, 'east', 3);
        $this->assertSame(MarchService::BUSY, $busy['code']);
        $this->assertStringContainsString('Крафт', $busy['message']);

        $this->resetCase();
        $this->task('BaseRelocation', 'Переезд', 1);
        $this->assertSame(MarchService::RELOCATION, $march->preview(self::CHAR, 'east', 3)['code']);

        $this->resetCase();
        $this->conn->query("DELETE FROM tasks WHERE name = 'Marching'");
        $this->assertSame(MarchService::NO_TASK, $march->start(self::CHAR, 'east', 3)['code']);
        $this->assertSame(MarchService::NOT_ACTIVE, $march->extend(self::CHAR, 5)['code']);
        $this->assertSame(MarchService::NOT_PAUSED, $march->resume(self::CHAR)['code']);
        $this->assertSame(MarchService::NO_MARCH, $march->stop(self::CHAR)['code']);
        $this->assertSame([], array_values(array_filter($this->marchRows(), static fn (array $r): bool => $r['task'] === 'Marching')));
    }

    public function testPreviewCarriesCellsEtaAndCostAndClampsToCap(): void
    {
        $this->resetCase();
        $p = (new MarchService())->preview(self::CHAR, 'east', 999);
        $this->assertTrue($p['ok']);
        $this->assertSame(60, $p['n'], 'потолок пешком — max_steps_per_order нейтрального профиля');
        $this->assertSame(60, $p['cap']);
        $this->assertSame('b9', $p['ahead']);
        $this->assertGreaterThan(0, $p['eta_minutes']);
        $this->assertGreaterThan(0.0, $p['tired']);
        $this->assertNotNull($p['hook']);
    }

    public function testStatusShowsProgressEtaAndPauseThenStopClearsIt(): void
    {
        $march = new MarchService();
        $this->resetCase();
        $this->assertNull($march->status(self::CHAR));

        $this->marchRow('in_work', ['heading' => 'east', 'steps_planned' => 6, 'steps_done' => 2, 'started_cell' => self::cell(0, 500), 'acc' => [], 'log' => []]);
        $st = $march->status(self::CHAR);
        $this->assertNotNull($st);
        $this->assertSame(['in_work', 'east', 2, 6, null], [$st['status'], $st['heading'], $st['steps_done'], $st['steps_planned'], $st['paused_reason']]);
        $this->assertIsInt($st['eta']);
        $this->assertGreaterThanOrEqual(time(), $st['eta']);

        $this->assertSame(MarchService::EXTENDED, $march->extend(self::CHAR, 5)['code']);
        $this->assertSame(11, $march->status(self::CHAR)['steps_planned'] ?? null);

        $stop = $march->stop(self::CHAR);
        $this->assertSame([true, 2], [$stop['ok'], $stop['steps_done']]);
        $this->assertNull($march->status(self::CHAR));

        $this->marchRow('paused', ['heading' => 'east', 'steps_planned' => 6, 'steps_done' => 4, 'paused_reason' => 'player_detected']);
        $paused = $march->status(self::CHAR);
        $this->assertNotNull($paused);
        $this->assertSame(['paused', 'player_detected', null], [$paused['status'], $paused['paused_reason'], $paused['eta']]);
        $this->assertSame(MarchService::RESUMED, $march->resume(self::CHAR)['code']);
        $this->assertSame('in_work', $march->status(self::CHAR)['status'] ?? null);
    }

    public function testRayMapsCellsToDirectionAndChebyshevDistance(): void
    {
        $this->assertSame(['dir' => 'east', 'n' => 4], MarchService::ray(4, 0));
        $this->assertSame(['dir' => 'northwest', 'n' => 3], MarchService::ray(-3, -3));
        $this->assertSame(['dir' => 'south', 'n' => 2], MarchService::ray(0, 2));
        $this->assertNull(MarchService::ray(2, 1));
        $this->assertNull(MarchService::ray(0, 0));
    }

    /** @return array{sent: list<array<string, mixed>>, rows: list<array<string, mixed>>} */
    private function runCase(string $prep, string $data): array
    {
        $this->resetCase();
        match ($prep) {
            'edit_fails'  => $this->editFails = true,
            'no_marching' => $this->conn->query("DELETE FROM tasks WHERE name = 'Marching'"),
            'busy'        => $this->task('Craft', 'Крафт', 0),
            'relocation'  => $this->task('BaseRelocation', 'Переезд', 1),
            'in_work'     => $this->marchRow('in_work', ['heading' => 'east', 'steps_planned' => 6, 'steps_done' => 2, 'started_cell' => self::cell(0, 500), 'msg_chat_id' => self::TG, 'msg_id' => 70, 'acc' => [], 'log' => []]),
            'paused'      => $this->marchRow('paused', ['heading' => 'east', 'steps_planned' => 6, 'steps_done' => 4, 'started_cell' => self::cell(0, 500), 'msg_chat_id' => self::TG, 'msg_id' => 70, 'acc' => [], 'log' => [], 'steps_remaining' => 2, 'paused_reason' => 'player_detected']),
            default       => null,
        };
        $this->sent = [];
        $this->press($data);

        return ['sent' => $this->sent, 'rows' => $this->marchRows()];
    }

    /** @return list<array<string, mixed>> */
    private function marchRows(): array
    {
        $rows = $this->conn->query(
            'SELECT ct.status, ct.telegram_user_id, t.name AS task, TIMESTAMPDIFF(SECOND, ct.start_time, ct.end_time) AS due, ct.task_settings'
            . ' FROM character_tasks ct LEFT JOIN tasks t ON t.id = ct.task_id ORDER BY ct.id'
        )->getResultArray();
        $out = [];
        foreach ($rows as $row) {
            $settings = json_decode((string) ($row['task_settings'] ?? 'null'), true);
            if (is_array($settings)) {
                ksort($settings);
            }
            $row['task_settings'] = $settings;
            $out[]                = $row;
        }

        return $out;
    }

    private function resetCase(): void
    {
        $this->editFails = false;
        $this->conn->query('DELETE FROM character_tasks');
        $this->conn->query('DELETE FROM action_log');
        $this->conn->query('DELETE FROM tasks');
        $this->conn->query("INSERT INTO tasks (name, name_rus, parallel_execution_allowed) VALUES ('Marching', 'Поход', 0)");
        $this->conn->query('UPDATE characters SET health = 87.5, tired = 12, cell_number = ?, biome_id = 1 WHERE id = 1', [self::cell(0, 500)]);
    }

    private function task(string $name, string $nameRus, int $parallel): void
    {
        $this->conn->query('INSERT INTO tasks (name, name_rus, parallel_execution_allowed) VALUES (?, ?, ?)', [$name, $nameRus, $parallel]);
        $taskId = (int) $this->conn->insertID();
        $this->conn->query("INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status) VALUES (1, 7, ?, NOW(), NOW() + INTERVAL 1 HOUR, 'in_work')", [$taskId]);
    }

    /** @param array<string, mixed> $settings */
    private function marchRow(string $status, array $settings): void
    {
        $this->conn->query(
            "INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status, task_settings)"
            . " SELECT 1, 7, id, '2026-01-01 00:00:00', '2026-01-01 00:00:00', ?, ? FROM tasks WHERE name = 'Marching'",
            [$status, json_encode($settings)]
        );
    }

    private function press(string $data): void
    {
        $cb = new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 77, 'date' => 0, 'chat' => ['id' => self::TG, 'type' => 'private']],
            'data'    => $data,
        ]);
        if ($data === 'cancelMarch') {
            (new CancelMarchAction($cb))->handle();
        } else {
            (new MarchAction($cb))->handle();
        }
    }

    private function installRecorder(): void
    {
        $this->sent = [];
        new Telegram(self::ENV['telegram.API_KEY'], self::ENV['telegram.BOT_USERNAME']);
        $client = new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path         = explode('/', $request->getUri()->getPath());
            $this->sent[] = ['method' => (string) end($path)] + $params;
            if ($this->captureRowsOnSend) {
                $this->rowsOnSend[] = $this->marchRows();
            }

            $fail = $this->editFails && str_starts_with((string) end($path), 'edit');

            return Create::promiseFor($fail
                ? new Response(400, [], '{"ok":false,"error_code":400,"description":"Bad Request: message to edit not found"}')
                : new Response(200, [], '{"ok":true,"result":true}'));
        }]);
        LongmanRequest::setClient($client);
    }

    private function seed(): void
    {
        foreach ([[1, 3], [9, 2]] as [$id, $danger]) {
            $this->conn->query('INSERT INTO biomes (id, name, danger_level) VALUES (?, ?, ?)', [$id, 'b' . $id, $danger]);
        }
        $rows = [];
        for ($y = 498; $y <= 502; $y++) {
            for ($x = 0; $x <= 14; $x++) {
                $biome  = $x === 1 && $y === 500 ? 9 : 1;
                $rows[] = sprintf('(%d, %d, %d, %d, %d)', self::cell($x, $y), self::cell($x, $y), $x, $y, $biome);
            }
        }
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES ' . implode(', ', $rows));
        foreach ([[1, 500], [2, 500]] as [$x, $y]) {
            $this->conn->query('INSERT INTO explored_cells (character_id, map_cell_id) VALUES (1, ?)', [self::cell($x, $y)]);
        }
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, ?)', [self::TG, 'Тест']);
        $this->conn->query(
            "INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, biome_id)"
            . " VALUES (?, 7, 'Тест', 5, 1.5, 87.5, 12, 0.5, 0.01, 0.01, 0, ?, 1)",
            [self::CHAR, self::cell(0, 500)]
        );
        foreach ([['drone.scout.enabled', 0], ['caravan.enabled', 0]] as [$key, $on]) {
            $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_bool) VALUES (?, 'bool', ?)", [$key, $on]);
        }
    }

    private static function cell(int $x, int $y): int
    {
        return $y * 1000 + $x + 1;
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
