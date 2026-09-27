<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Controllers\Telegram\Commands\Actions\MoveCharacterToDirectionAction;
use App\Services\World\MoveService;
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
 * W2.N2-02 (ADR-190) — шаг как сервис: {@see MoveService::step()} даёт те же отказы и те же дельты,
 * что прежний `MoveCharacterToDirectionAction::handle()`; прирост за шаг — из GameSettings.
 *
 * Паритет бота: {@see self::BOT_*} — запросы Bot API, которые прежний handler слал на этой фикстуре
 * (сняты с кода ДО переноса шага в сервис). Handler-рендерер обязан слать ровно их.
 *
 * Схема — из миграций; `character_tasks.task_settings` и `telegram_users.last_map_message_id`
 * создающей миграции не имеют (legacy-дамп) — добавляются ALTER'ом, `npc_spawns` — DDL-копией
 * прод-таблицы. Флаги чужих фич (караван, дрон-разведчик, поселения, узлы, NPC) выключены или
 * по умолчанию dormant; карго-дрон включён — его lock-кнопка «🔒 Карго-дрон» и есть «хвост» шага.
 *
 * Фикстура: персонаж на (0, 500) у западного края, лес (biome 1), клетка (1, 500) — пустыня (9).
 *
 * @internal
 */
final class MoveServiceTest extends CIUnitTestCase
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

    /** Запросы Bot API и записи прежнего handler'а на фикстуре — сняты с кода ДО переноса шага в сервис. */
    private const BOT_BEFORE = <<<'JSON'
        {
            "moved": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "message_id": "77",
                        "text": "Куда пойдём? Выберите направление:\n\nЛегенда:\n🙎‍♂️ — игрок\n🏕 — ваша база\n🚫 — чужая база\n🏚 — поселение\n☠ — узел (босс)\n⏳ — узел в кулдауне\n🥷 — NPC\n⬛️ — не изучено\n⬜ — за пределами мира\n\n1) 🌲 — Лес\n2) ⛰️ — Горы\n3) ❄️ — Тундра\n4) 🌊 — Реки\n5) 🌴 — Джунгли\n6) 🌾 — Поля\n7) 🕳️ — Пещеры\n8) 🌋 — Вулкан\n9) 🏜️ — Пустыни\n\n❤️ Здоровье: 88\n💤 Усталость: 9\n\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🌋🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🙎‍♂️🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🌲🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n\nИгрок по центру (X=1, Y=500)\n\nВы двинулись на: *восток*\n",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u2196\\ufe0f \\u0421\\u0435\\u0432-\\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_northwest\"},{\"text\":\"\\u2b06\\ufe0f \\u0421\\u0435\\u0432\\u0435\\u0440\",\"callback_data\":\"move_dir_north\"},{\"text\":\"\\u2197\\ufe0f \\u0421\\u0435\\u0432-\\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_northeast\"}],[{\"text\":\"\\u2b05\\ufe0f \\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_west\"},{\"text\":\"\\ud83c\\udfe0 \\u0411\\u0430\\u0437\\u0430\",\"callback_data\":\"Base\"},{\"text\":\"\\ud83e\\uddd1\\u200d\\ud83c\\udf3e \\ud83d\\udee0\\ufe0f\",\"callback_data\":\"characterActions\"},{\"text\":\"\\u27a1\\ufe0f \\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_east\"}],[{\"text\":\"\\u2199\\ufe0f \\u042e\\u0433\\u043e-\\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_southwest\"},{\"text\":\"\\u2b07\\ufe0f \\u042e\\u0433\",\"callback_data\":\"move_dir_south\"},{\"text\":\"\\u2198\\ufe0f \\u042e\\u0433\\u043e-\\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_southeast\"}],[{\"text\":\"\\ud83d\\uddfa\\ufe0f \\u041f\\u043e\\u0445\\u043e\\u0434\",\"callback_data\":\"march\"},{\"text\":\"\\ud83d\\udd12 \\u041a\\u0430\\u0440\\u0433\\u043e-\\u0434\\u0440\\u043e\\u043d\",\"callback_data\":\"cargoDroneLocked\"}]]}"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "💡 *Подсказка новичку*\n\nЧтобы что-то строить (Склад, Теплицу, Мастерскую и др.) — нужна *своя база*. Далеко идти не надо: лагерь ставится прямо там, где стоишь, на любой свободной клетке.\n\n1️⃣ Открой *«База»* в нижнем меню (или команда /base)\n2️⃣ Нажми *«🏕 Разбить лагерь»* и подтверди на следующем экране\n3️⃣ Затем *«🏗 Строить»* — там и Склад, и Теплица, и всё остальное\n\n_Постройки возводятся на базе, а не покупаются в магазине. А если всё же идёшь далеко — жми «🗺️ Поход» вместо шагов по одной клетке: так выносливости тратится заметно меньше._",
                        "parse_mode": "Markdown"
                    }
                ],
                "row": {
                    "health": "88",
                    "tired": "9",
                    "strength": "0.52",
                    "experience": "1.53",
                    "cell_number": "500002",
                    "biome_id": "9"
                },
                "explored": 9
            },
            "fallback": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "editMessageText",
                        "chat_id": "555001",
                        "message_id": "77",
                        "text": "Куда пойдём? Выберите направление:\n\nЛегенда:\n🙎‍♂️ — игрок\n🏕 — ваша база\n🚫 — чужая база\n🏚 — поселение\n☠ — узел (босс)\n⏳ — узел в кулдауне\n🥷 — NPC\n⬛️ — не изучено\n⬜ — за пределами мира\n\n1) 🌲 — Лес\n2) ⛰️ — Горы\n3) ❄️ — Тундра\n4) 🌊 — Реки\n5) 🌴 — Джунгли\n6) 🌾 — Поля\n7) 🕳️ — Пещеры\n8) 🌋 — Вулкан\n9) 🏜️ — Пустыни\n\n❤️ Здоровье: 88\n💤 Усталость: 9\n\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🌋🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🙎‍♂️🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🌲🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n\nИгрок по центру (X=1, Y=500)\n\nВы двинулись на: *восток*\n",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u2196\\ufe0f \\u0421\\u0435\\u0432-\\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_northwest\"},{\"text\":\"\\u2b06\\ufe0f \\u0421\\u0435\\u0432\\u0435\\u0440\",\"callback_data\":\"move_dir_north\"},{\"text\":\"\\u2197\\ufe0f \\u0421\\u0435\\u0432-\\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_northeast\"}],[{\"text\":\"\\u2b05\\ufe0f \\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_west\"},{\"text\":\"\\ud83c\\udfe0 \\u0411\\u0430\\u0437\\u0430\",\"callback_data\":\"Base\"},{\"text\":\"\\ud83e\\uddd1\\u200d\\ud83c\\udf3e \\ud83d\\udee0\\ufe0f\",\"callback_data\":\"characterActions\"},{\"text\":\"\\u27a1\\ufe0f \\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_east\"}],[{\"text\":\"\\u2199\\ufe0f \\u042e\\u0433\\u043e-\\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_southwest\"},{\"text\":\"\\u2b07\\ufe0f \\u042e\\u0433\",\"callback_data\":\"move_dir_south\"},{\"text\":\"\\u2198\\ufe0f \\u042e\\u0433\\u043e-\\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_southeast\"}],[{\"text\":\"\\ud83d\\uddfa\\ufe0f \\u041f\\u043e\\u0445\\u043e\\u0434\",\"callback_data\":\"march\"},{\"text\":\"\\ud83d\\udd12 \\u041a\\u0430\\u0440\\u0433\\u043e-\\u0434\\u0440\\u043e\\u043d\",\"callback_data\":\"cargoDroneLocked\"}]]}"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "Куда пойдём? Выберите направление:\n\nЛегенда:\n🙎‍♂️ — игрок\n🏕 — ваша база\n🚫 — чужая база\n🏚 — поселение\n☠ — узел (босс)\n⏳ — узел в кулдауне\n🥷 — NPC\n⬛️ — не изучено\n⬜ — за пределами мира\n\n1) 🌲 — Лес\n2) ⛰️ — Горы\n3) ❄️ — Тундра\n4) 🌊 — Реки\n5) 🌴 — Джунгли\n6) 🌾 — Поля\n7) 🕳️ — Пещеры\n8) 🌋 — Вулкан\n9) 🏜️ — Пустыни\n\n❤️ Здоровье: 88\n💤 Усталость: 9\n\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🌋🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🙎‍♂️🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜🌲🌲🌲⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n⬜⬜⬜⬜⬜⬛️⬛️⬛️⬛️⬛️⬛️⬛️\n\nИгрок по центру (X=1, Y=500)\n\nВы двинулись на: *восток*\n",
                        "parse_mode": "Markdown",
                        "reply_markup": "{\"inline_keyboard\":[[{\"text\":\"\\u2196\\ufe0f \\u0421\\u0435\\u0432-\\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_northwest\"},{\"text\":\"\\u2b06\\ufe0f \\u0421\\u0435\\u0432\\u0435\\u0440\",\"callback_data\":\"move_dir_north\"},{\"text\":\"\\u2197\\ufe0f \\u0421\\u0435\\u0432-\\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_northeast\"}],[{\"text\":\"\\u2b05\\ufe0f \\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_west\"},{\"text\":\"\\ud83c\\udfe0 \\u0411\\u0430\\u0437\\u0430\",\"callback_data\":\"Base\"},{\"text\":\"\\ud83e\\uddd1\\u200d\\ud83c\\udf3e \\ud83d\\udee0\\ufe0f\",\"callback_data\":\"characterActions\"},{\"text\":\"\\u27a1\\ufe0f \\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_east\"}],[{\"text\":\"\\u2199\\ufe0f \\u042e\\u0433\\u043e-\\u0417\\u0430\\u043f\\u0430\\u0434\",\"callback_data\":\"move_dir_southwest\"},{\"text\":\"\\u2b07\\ufe0f \\u042e\\u0433\",\"callback_data\":\"move_dir_south\"},{\"text\":\"\\u2198\\ufe0f \\u042e\\u0433\\u043e-\\u0412\\u043e\\u0441\\u0442\\u043e\\u043a\",\"callback_data\":\"move_dir_southeast\"}],[{\"text\":\"\\ud83d\\uddfa\\ufe0f \\u041f\\u043e\\u0445\\u043e\\u0434\",\"callback_data\":\"march\"},{\"text\":\"\\ud83d\\udd12 \\u041a\\u0430\\u0440\\u0433\\u043e-\\u0434\\u0440\\u043e\\u043d\",\"callback_data\":\"cargoDroneLocked\"}]]}"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "💡 *Подсказка новичку*\n\nЧтобы что-то строить (Склад, Теплицу, Мастерскую и др.) — нужна *своя база*. Далеко идти не надо: лагерь ставится прямо там, где стоишь, на любой свободной клетке.\n\n1️⃣ Открой *«База»* в нижнем меню (или команда /base)\n2️⃣ Нажми *«🏕 Разбить лагерь»* и подтверди на следующем экране\n3️⃣ Затем *«🏗 Строить»* — там и Склад, и Теплица, и всё остальное\n\n_Постройки возводятся на базе, а не покупаются в магазине. А если всё же идёшь далеко — жми «🗺️ Поход» вместо шагов по одной клетке: так выносливости тратится заметно меньше._",
                        "parse_mode": "Markdown"
                    }
                ],
                "row": {
                    "health": "88",
                    "tired": "9",
                    "strength": "0.52",
                    "experience": "1.53",
                    "cell_number": "500002",
                    "biome_id": "9"
                },
                "explored": 9
            },
            "edge": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1",
                        "text": "🧭 Там край острова — дальше на запад пути нет.\nМожно пойти: север, юг, восток, северо-восток, юго-восток",
                        "show_alert": "1"
                    }
                ],
                "row": {
                    "health": "88",
                    "tired": "12",
                    "strength": "0.50",
                    "experience": "1.50",
                    "cell_number": "500001",
                    "biome_id": "1"
                },
                "explored": 0
            },
            "busy": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "🚫 Невозможно переместиться!\nУ вас идёт задача: Крафт\nСначала дождитесь окончания.",
                        "parse_mode": "Markdown"
                    }
                ],
                "row": {
                    "health": "88",
                    "tired": "12",
                    "strength": "0.50",
                    "experience": "1.50",
                    "cell_number": "500001",
                    "biome_id": "1"
                },
                "explored": 0
            },
            "exhausted": {
                "sent": [
                    {
                        "method": "answerCallbackQuery",
                        "callback_query_id": "cbq-1"
                    },
                    {
                        "method": "sendMessage",
                        "chat_id": "555001",
                        "text": "Недостаточно ресурсов на перемещение!\nЗдоровье после перехода: 87.9\nУсталость после перехода: -2.35"
                    }
                ],
                "row": {
                    "health": "88",
                    "tired": "1",
                    "strength": "0.50",
                    "experience": "1.50",
                    "cell_number": "500001",
                    "biome_id": "1"
                },
                "explored": 0
            },
            "relocation": {
                "sent": [
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
                "row": {
                    "health": "88",
                    "tired": "12",
                    "strength": "0.50",
                    "experience": "1.50",
                    "cell_number": "500001",
                    "biome_id": "1"
                },
                "explored": 0
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
            // AddBiomeIdToCharacters оставляет в forge висящий FK, AddActiveVehicleLogId ставит колонку AFTER поля чужой миграции — обе колонки ALTER'ом.
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
     * Паритет бота: шесть исходов шага (успех с правкой, успех через новое сообщение, край мира,
     * эксклюзивная задача, нехватка сил, переезд) — те же запросы Bot API и те же записи, что у
     * прежнего handler'а.
     *
     * Отдельный процесс: другие тесты набора определяют `PHPUNIT_TESTSUITE`, и Longman под ним
     * отвечает фейком, не доходя до HTTP-клиента, — запись запросов ослепла бы.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBotSendsTheSameMessagesAndWritesTheSameDeltasAsBefore(): void
    {
        $before = json_decode(self::BOT_BEFORE, true);
        $this->assertIsArray($before);
        $this->assertSame(['moved', 'fallback', 'edge', 'busy', 'exhausted', 'relocation'], array_keys($before));

        foreach ($before as $case => $expected) {
            $this->assertIsArray($expected);
            $this->resetCase();
            match ($case) {
                'fallback'   => $this->editFails = true,
                'busy'       => $this->task('Craft', 'Крафт', 0),
                'relocation' => $this->task('BaseRelocation', 'Переезд', 1),
                'exhausted'  => $this->conn->query('UPDATE characters SET tired = 1 WHERE id = 1'),
                default      => null,
            };
            $this->sent = [];
            $this->press($case === 'edge' ? 'move_dir_west' : 'move_dir_east');

            $this->assertSame($expected['sent'], $this->sent, "запросы Bot API: {$case}");
            $this->assertSame($expected['row'], $this->row(), "персонаж после шага: {$case}");
            $this->assertSame($expected['explored'], $this->conn->table('explored_cells')->countAllResults(), "туман: {$case}");
        }
    }

    public function testRefusalsKeepTheOldMeaningAndWriteNothing(): void
    {
        $move   = new MoveService();
        $before = $this->row();

        $edge = $move->step(self::CHAR, 'west');
        $this->assertFalse($edge['ok']);
        $this->assertSame(MoveService::EDGE, $edge['code']);
        $this->assertStringContainsString('край острова — дальше на запад пути нет', (string) $edge['message']);
        $this->assertStringContainsString('Можно пойти: север, юг, восток, северо-восток, юго-восток', (string) $edge['message']);

        $this->conn->query('UPDATE characters SET tired = 1 WHERE id = 1');
        $tired = $move->step(self::CHAR, 'east');
        $this->assertSame(MoveService::EXHAUSTED, $tired['code']);
        $this->assertStringStartsWith('Недостаточно ресурсов на перемещение!', (string) $tired['message']);
        $this->conn->query('UPDATE characters SET tired = 12, health = 0 WHERE id = 1');
        $this->assertSame(MoveService::EXHAUSTED, $move->step(self::CHAR, 'east')['code'], 'нет здоровья');
        $this->conn->query('UPDATE characters SET health = 87.5 WHERE id = 1');

        $this->task('Craft', 'Крафт', 0);
        $busy = $move->step(self::CHAR, 'east');
        $this->assertSame(MoveService::BUSY, $busy['code']);
        $this->assertStringContainsString('У вас идёт задача: Крафт', (string) $busy['message']);
        $this->resetCase();

        $this->task('BaseRelocation', 'Переезд', 1);
        $this->assertSame(MoveService::RELOCATION, $move->step(self::CHAR, 'east')['code']);
        $this->resetCase();

        $this->assertSame(MoveService::BAD_DIR, $move->step(self::CHAR, 'up')['code']);
        $this->assertSame(MoveService::NO_CHARACTER, $move->step(999, 'east')['code']);

        $this->assertSame($before, $this->row(), 'отказ ничего не пишет');
        $this->assertSame(0, $this->conn->table('explored_cells')->countAllResults());
    }

    public function testStepReturnsPositionsCostAndCellTailAsEvents(): void
    {
        $out = (new MoveService())->step(self::CHAR, 'east');

        $this->assertTrue($out['ok']);
        $this->assertSame(MoveService::MOVED, $out['code']);
        $this->assertSame(['x' => 0, 'y' => 500, 'cell' => self::cell(0, 500)], $out['from']);
        $this->assertSame(['x' => 1, 'y' => 500, 'cell' => self::cell(1, 500)], $out['to']);
        $this->assertNotNull($out['cost']);
        $this->assertEqualsWithDelta(0.1, $out['cost']['health'], 1e-9);
        $this->assertEqualsWithDelta(3.35, $out['cost']['tired'], 1e-9);
        $this->assertSame([[
            'type' => 'cargo', 'text' => 'Карго-дрона пока нет.',
            'buttons' => [['text' => '🔒 Карго-дрон', 'callback_data' => 'cargoDroneLocked']],
        ]], $out['events']);
    }

    public function testGainPerStepComesFromGameSettings(): void
    {
        $move = new MoveService();
        $this->assertSame(0.02, $move->statPerStep(), 'дефолт = прежнее число');
        $this->assertSame(0.03, $move->xpPerStep(), 'дефолт = прежнее число');

        $this->conn->query("INSERT INTO game_settings (setting_key, value_type, value_float) VALUES ('world.move.stat_per_step', 'float', 0.1), ('world.move.xp_per_step', 'float', 0.2)");
        $this->mockCache();
        $move->step(self::CHAR, 'east');
        $row = $this->row();
        $this->assertSame('0.60', $row['strength']);
        $this->assertSame('1.70', $row['experience']);
    }

    public function testSeedMigrationIsIdempotentAndCarriesRationale(): void
    {
        $forge = Database::forge();
        $m     = $this->migration('2026-12-13-100000_SeedMoveStepGainSettings', $forge instanceof Forge ? $forge : null);
        $m->up();
        $m->up();

        $rows = $this->conn->table('game_settings')->whereIn('setting_key', ['world.move.stat_per_step', 'world.move.xp_per_step'])
            ->orderBy('setting_key')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $this->assertSame(['0.02', '0.03'], array_column($rows, 'default_value_text'));
        foreach ($rows as $row) {
            $this->assertSame('world', $row['category']);
            foreach (['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text', 'recommended_min', 'recommended_max', 'hard_min', 'hard_max'] as $col) {
                $this->assertNotEmpty($row[$col], "{$row['setting_key']}.{$col}");
            }
        }
        $this->mockCache();
        $this->assertEqualsWithDelta(0.02, (new MoveService())->statPerStep(), 1e-9);
        $this->assertEqualsWithDelta(0.03, (new MoveService())->xpPerStep(), 1e-9);
    }

    public function testDebuffEventIsTextCompleteWithCureButtons(): void
    {
        $event = MoveService::debuffEvent(\Config\Debuffs::keys()[0]);
        $this->assertNotNull($event);
        $this->assertSame(MoveService::EVENT_DEBUFF, $event['type']);
        $this->assertStringContainsString('Чем снять:', $event['text']);
        $this->assertSame(['pharmacy', 'medicinesCraft1'], array_column($event['buttons'], 'callback_data'));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->conn->query('SELECT health, tired, strength, experience, cell_number, biome_id FROM characters WHERE id = 1')->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }

    private bool $editFails = false;

    private function resetCase(): void
    {
        $this->editFails = false;
        $this->conn->query('DELETE FROM character_tasks');
        $this->conn->query('DELETE FROM tasks');
        $this->conn->query('DELETE FROM explored_cells');
        $this->conn->query('DELETE FROM action_log');
        $this->conn->query('UPDATE characters SET health = 87.5, tired = 12, strength = 0.5, experience = 1.5, cell_number = ?, biome_id = 1 WHERE id = 1', [self::cell(0, 500)]);
    }

    private function task(string $name, string $nameRus, int $parallel): void
    {
        $this->conn->query('INSERT INTO tasks (name, name_rus, parallel_execution_allowed) VALUES (?, ?, ?)', [$name, $nameRus, $parallel]);
        $taskId = (int) $this->conn->insertID();
        $this->conn->query("INSERT INTO character_tasks (character_id, telegram_user_id, task_id, start_time, end_time, status) VALUES (1, 7, ?, NOW(), NOW() + INTERVAL 1 HOUR, 'in_work')", [$taskId]);
    }

    // ── фикстура ─────────────────────────────────────────────────────────

    private function press(string $data): void
    {
        $cb = new CallbackQuery([
            'id'      => 'cbq-1',
            'from'    => ['id' => self::TG, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => ['message_id' => 77, 'date' => 0, 'chat' => ['id' => self::TG, 'type' => 'private']],
            'data'    => $data,
        ]);
        (new MoveCharacterToDirectionAction($cb))->handle();
    }

    private function installRecorder(): void
    {
        $this->sent = [];
        new Telegram(self::ENV['telegram.API_KEY'], self::ENV['telegram.BOT_USERNAME']);
        $client = new Client(['handler' => function (RequestInterface $request): PromiseInterface {
            parse_str((string) $request->getBody(), $params);
            $path         = explode('/', $request->getUri()->getPath());
            $this->sent[] = ['method' => (string) end($path)] + $params;

            $fail = $this->editFails && str_starts_with((string) end($path), 'edit');

            return Create::promiseFor($fail
                ? new Response(400, [], '{"ok":false,"error_code":400,"description":"Bad Request: message to edit not found"}')
                : new Response(200, [], '{"ok":true,"result":true}'));
        }]);
        LongmanRequest::setClient($client);
    }

    private function seed(): void
    {
        foreach ([[1, 3], [9, 2], [8, 9]] as [$id, $danger]) {
            $this->conn->query('INSERT INTO biomes (id, name, danger_level) VALUES (?, ?, ?)', [$id, 'b' . $id, $danger]);
        }
        $rows = [];
        for ($y = 498; $y <= 502; $y++) {
            for ($x = 0; $x <= 3; $x++) {
                $biome  = $x === 1 && $y === 500 ? 9 : ($x === 1 && $y === 499 ? 8 : 1);
                $rows[] = sprintf('(%d, %d, %d, %d, %d)', self::cell($x, $y), self::cell($x, $y), $x, $y, $biome);
            }
        }
        $this->conn->query('INSERT INTO map (id, cell_number, coordinate_x, coordinate_y, biome_id) VALUES ' . implode(', ', $rows));
        $this->conn->query('INSERT INTO telegram_users (id, telegram_id, first_name) VALUES (7, ?, ?)', [self::TG, 'Тест']);
        $this->conn->query(
            "INSERT INTO characters (id, telegram_user_id, name, level, experience, health, tired, strength, agility, intellect, gold, cell_number, biome_id)"
            . " VALUES (?, 7, 'Тест', 5, 1.5, 87.5, 12, 0.5, 0.01, 0.01, 0, ?, 1)",
            [self::CHAR, self::cell(0, 500)]
        );
        foreach ([['drone.scout.enabled', 0], ['caravan.enabled', 0], ['drone.cargo.enabled', 1]] as [$key, $on]) {
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
