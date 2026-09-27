<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\ShowBaseInfoAction;
use App\Controllers\Telegram\Commands\Actions\Camp\DetailedBaseInfoAction;
use App\Services\Web\WebDelivery;
use App\Services\Web\WebInboxService;
use App\Services\Web\WebScreenStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Telegram;

/**
 * w2-n4-base-01 — паритет бота на экранах, которые переехали на ядро {@see \App\Services\Bases\BaseScreenService}:
 * «🏠 База» (`Base`[`_b<id>`]) и «🏘 Постройки» (`construction`[`_b<id>`]).
 *
 * {@see SNAPSHOT_BEFORE} снят с кода ДО переезда (develop 20fbc63b) этим же тестом. Ожидание после —
 * тот же снимок с единственной правкой ask 5: «🏗 Строить» на экране базы несёт `_b<id>` этой базы
 * ({@see expectedAfter()}). Текст, parse_mode и остальные кнопки — байт-в-байт.
 *
 * Фото экранов базы идут через `Request::encodeFile(base_url(...))`, в тест-стенде это http-адрес,
 * который не открыть. Тест подменяет обёртку потока `http` пустышкой ({@see ParityFakeHttpStream}),
 * а вызовы Bot API собирает захватом {@see WebDelivery} (актор = чат игрока), так что фото-экраны
 * снимаются целиком: подпись и кнопки.
 *
 * @internal
 */
final class BaseScreenBotParityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const PREFIX = 'bsp_';

    /** @var array<string,string> таблица => колонки. */
    private const TABLES = [
        'telegram_users'      => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_id BIGINT NULL',
        'characters'          => 'id INT AUTO_INCREMENT PRIMARY KEY, telegram_user_id INT NULL, name VARCHAR(64) NULL, cell_number INT NULL, level INT NOT NULL DEFAULT 99, locale VARCHAR(8) NULL, disable_media TINYINT NOT NULL DEFAULT 0',
        'claimed_cells'       => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NOT NULL, map_cell_id INT NOT NULL, claimed_at DATETIME NULL, last_visited_at DATETIME NULL, last_warned_at DATETIME NULL, status VARCHAR(16) NOT NULL, camp_name VARCHAR(64) NULL, camp_flag VARCHAR(16) NULL, camp_hearth VARCHAR(64) NULL, camp_furniture VARCHAR(64) NULL, camp_pet VARCHAR(64) NULL',
        'buildings'           => 'id INT AUTO_INCREMENT PRIMARY KEY, name_ru VARCHAR(255) NULL, name_en VARCHAR(255) NULL',
        'character_buildings' => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, building_id INT NULL, map_cell_id INT NULL, level INT NULL DEFAULT 1, tax INT NULL, amount INT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL',
        'map'                 => 'id INT PRIMARY KEY, cell_number INT NULL, coordinate_x INT NULL, coordinate_y INT NULL, biome_id INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'biomes'              => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NULL, description TEXT NULL, danger_level INT NULL, survival_difficulty INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
        'tasks'               => 'id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NULL, name_rus VARCHAR(64) NULL',
        'character_tasks'     => 'id INT AUTO_INCREMENT PRIMARY KEY, character_id INT NULL, telegram_user_id INT NULL, task_id INT NULL, status VARCHAR(16) NULL, start_time DATETIME NULL, end_time DATETIME NULL, task_settings TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL',
    ];

    /**
     * Снимок ДО переезда: сценарий → список сообщений `{text, parse_mode, buttons[[text, callback_data]]}`.
     * Id баз детерминированы (таблицы пересоздаются на каждый тест).
     */
    private const SNAPSHOT_BEFORE = <<<'JSON'
        {
         "base_on_base": [
          {
           "text": "🤖 Это я – *Роби*!\n\n🏕️ *Дом*\n📍 *Координаты базы*: x=10 y=10\n🌍 *Биом*: Лес\n\n*Твоя база содержит:*\n*Построек:* 3 шт.\n*Налог:* 19 ед. золота в сутки\n\n*Список построек:*\n- Вышка связи\n- Склад\n- Теплица\n",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🏗 Строить",
              "Build"
             ],
             [
              "🏘 Постройки",
              "construction_b1"
             ],
             [
              "📡 Маяки",
              "teleportBeacon"
             ]
            ],
            [
             [
              "🤖 Ангар",
              "hangar_b1"
             ],
             [
              "📡 Телепорт",
              "TeleportToCamp"
             ],
             [
              "🧭 Двигаться",
              "move"
             ]
            ],
            [
             [
              "❌ Удалить базу",
              "DeleteBase"
             ],
             [
              "🚚 Полноценный переезд",
              "DeleteBase_FullRelocation"
             ],
             [
              "🔨 Снести постройку",
              "demolishBuilding"
             ]
            ]
           ]
          }
         ],
         "base_no_bases": [
          {
           "text": "🤖 Это снова я – *Роби*!\n\nУ тебя *нет* ещё разбитого лагеря, а значит и нет базы.\n\n🏚 *База* — твой дом: на ней строятся *Склад*, *Теплица*, *Мастерская* и другие постройки. _В магазине построек нет — их возводят на базе._\n\nТы сейчас находишься в локации:\n• Координаты: X=10, Y=10\n• Биом: *Лес*\n• Опасность: 1\n• Сложность выживания: 2\n• _Описание_: Густой лес.\n\nВыбирай место с умом — биом базы влияет на доступные ресурсы. Чтобы разбить лагерь, жми кнопку ниже:",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🏕 Разбить лагерь",
              "Camp"
             ]
            ]
           ]
          }
         ],
         "base_one_far": [
          {
           "text": "🤖 Это снова я – *Роби*!\n\nТвоя база находится в другой ячейке, и сигнал вышки связи туда не дотягивается.\nЧтобы начать строительство или управлять базой, вернись физически:\n\n1️⃣ дойти пешком\n2️⃣ телепорт\n\n📍 *Координаты базы*: x=10 y=10\n🌍 *Биом*: Лес",
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
             ],
             [
              "📡 Маяки",
              "teleportBeacon"
             ]
            ]
           ]
          }
         ],
         "base_one_tower": [
          {
           "text": "🤖 Это я – *Роби*!\n\n🏕️ *Дом*\n\n_Вы сейчас не физически на базе,_\n_но сигнал *Вышки связи* (ур. 2) покрывает *1* ходов_\n_(лимит: 200 ходов)._\n**Управление базой доступно дистанционно!**\n\n📍 *Координаты базы*: x=10 y=10\n🌍 *Биом*: Лес\n\n*Твоя база содержит:*\n*Построек:* 2 шт.\n*Налог:* 15 ед. золота в сутки\n\n*Список построек:*\n- Вышка связи\n- Склад\n",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🏗 Строить",
              "Build"
             ],
             [
              "🏘 Постройки",
              "construction_b1"
             ],
             [
              "📡 Маяки",
              "teleportBeacon"
             ]
            ],
            [
             [
              "🤖 Ангар",
              "hangar_b1"
             ],
             [
              "📡 Телепорт",
              "TeleportToCamp"
             ],
             [
              "🧭 Двигаться",
              "move"
             ]
            ],
            [
             [
              "❌ Удалить базу",
              "DeleteBase"
             ],
             [
              "🚚 Полноценный переезд",
              "DeleteBase_FullRelocation"
             ],
             [
              "🔨 Снести постройку",
              "demolishBuilding"
             ]
            ]
           ]
          }
         ],
         "base_picker_none_covered": [
          {
           "text": "🤖 Это снова я – *Роби*!\n\nАктивных баз: *2*. Ни одна база сейчас не под сигналом своей Вышки связи:\n\n▫️ Первая (X=10, Y=10), расстояние: 890 ходов\n▫️ Вторая (X=20, Y=20), расстояние: 880 ходов\n",
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
         "base_picker_one_covered": [
          {
           "text": "🤖 Это я – *Роби*!\n\n🏕️ *Первая*\n\n_Вы сейчас не физически на базе,_\n_но сигнал *Вышки связи* (ур. 1) покрывает *1* ходов_\n_(лимит: 100 ходов)._\n**Управление базой доступно дистанционно!**\n\n📍 *Координаты базы*: x=10 y=10\n🌍 *Биом*: Лес\n\n*Твоя база содержит:*\n*Построек:* 1 шт.\n*Налог:* 10 ед. золота в сутки\n\n*Список построек:*\n- Вышка связи\n",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🏗 Строить",
              "Build"
             ],
             [
              "🏘 Постройки",
              "construction_b1"
             ],
             [
              "📡 Маяки",
              "teleportBeacon"
             ]
            ],
            [
             [
              "🤖 Ангар",
              "hangar_b1"
             ],
             [
              "📡 Телепорт",
              "TeleportToCamp"
             ],
             [
              "🧭 Двигаться",
              "move"
             ]
            ],
            [
             [
              "❌ Удалить базу",
              "DeleteBase"
             ],
             [
              "🚚 Полноценный переезд",
              "DeleteBase_FullRelocation"
             ],
             [
              "🔨 Снести постройку",
              "demolishBuilding"
             ]
            ]
           ]
          }
         ],
         "base_picker_two_covered": [
          {
           "text": "🤖 Это снова я – *Роби*!\n\nАктивных баз: *2*. Под сигналом Вышки связи сразу несколько баз — выбери, с какой работать:\n\n🏠 Первая (X=10, Y=10) — под сигналом Вышки\n🏠 Вторая (X=20, Y=20) — под сигналом Вышки\n",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🏠 Первая (10,10)",
              "Base_b1"
             ],
             [
              "🏠 Вторая (20,20)",
              "Base_b2"
             ]
            ],
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
         "base_selected_suffix": [
          {
           "text": "🤖 Это я – *Роби*!\n\n🏕️ *Вторая*\n\n_Вы сейчас не физически на базе,_\n_но сигнал *Вышки связи* (ур. 1) покрывает *5* ходов_\n_(лимит: 100 ходов)._\n**Управление базой доступно дистанционно!**\n\n📍 *Координаты базы*: x=20 y=20\n🌍 *Биом*: Лес\n\n*Твоя база содержит:*\n*Построек:* 2 шт.\n*Налог:* 17 ед. золота в сутки\n\n*Список построек:*\n- Вышка связи\n- Теплица\n",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🏗 Строить",
              "Build"
             ],
             [
              "🏘 Постройки",
              "construction_b2"
             ],
             [
              "📡 Маяки",
              "teleportBeacon"
             ]
            ],
            [
             [
              "🤖 Ангар",
              "hangar_b2"
             ],
             [
              "📡 Телепорт",
              "TeleportToCamp"
             ],
             [
              "🧭 Двигаться",
              "move"
             ]
            ],
            [
             [
              "❌ Удалить базу",
              "DeleteBase"
             ],
             [
              "🚚 Полноценный переезд",
              "DeleteBase_FullRelocation"
             ],
             [
              "🔨 Снести постройку",
              "demolishBuilding"
             ]
            ]
           ]
          }
         ],
         "base_unavailable_suffix": [
          {
           "text": "Эта база сейчас недоступна: встань на неё или подойди под сигнал её Вышки связи — и открой «🏠 База» снова.",
           "parse_mode": null,
           "buttons": []
          }
         ],
         "construction_on_base": [
          {
           "text": "Перед тобой территория твоей базы. Здесь можно подробнее изучить каждое сооружение!\n\n*Координаты базы*: x=10, y=10\n*Биом*: Лес\n",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🚰 Вышка связи L1",
              "building_1_CommunicationTower_b1"
             ],
             [
              "🔥 Склад L3",
              "building_2_Warehouse_b1"
             ],
             [
              "🏗 Развитие базы",
              "baseDevelopment_b1"
             ]
            ]
           ]
          }
         ],
         "construction_tower": [
          {
           "text": "_Вы не на базе физически,_ но сигнал *Вышки связи* (ур. 2) покрывает расстояние 1/200. **Можно управлять сооружениями удалённо!**\n\nПеред тобой территория твоей базы. Здесь можно подробнее изучить каждое сооружение!\n\n*Координаты базы*: x=10, y=10\n*Биом*: Лес\n",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🚰 Вышка связи L2",
              "building_1_CommunicationTower_b1"
             ],
             [
              "🏚️ Теплица L1",
              "building_3_Greenhouse_b1"
             ],
             [
              "🏗 Развитие базы",
              "baseDevelopment_b1"
             ]
            ]
           ]
          }
         ],
         "construction_no_base": [
          {
           "text": "🤖 Это снова я – *Роби*!\n\nУ тебя нет ещё разбитого лагеря, а значит и нет базы. Для разбивки лагеря используй кнопки ниже.",
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
         "construction_far": [
          {
           "text": "🤖 Это снова я – *Роби*!\n\nТвоя база находится в другой игровой ячейке, ты не дома! Чтобы начать строительство или изучить сооружения, вернись на базу:\n1️⃣ пешком\n2️⃣ телепорт.\n\n📍 *Координаты базы*: x=10 y=10\n🌍 *Биом*: Лес",
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
         "construction_suffix": [
          {
           "text": "_Вы не на базе физически,_ но сигнал *Вышки связи* (ур. 1) покрывает расстояние 5/100. **Можно управлять сооружениями удалённо!**\n\nПеред тобой территория твоей базы. Здесь можно подробнее изучить каждое сооружение!\n\n*Координаты базы*: x=20, y=20\n*Биом*: Лес\n",
           "parse_mode": "Markdown",
           "buttons": [
            [
             [
              "🚰 Вышка связи L1",
              "building_1_CommunicationTower_b2"
             ],
             [
              "🔥 Склад L1",
              "building_2_Warehouse_b2"
             ],
             [
              "🏗 Развитие базы",
              "baseDevelopment_b2"
             ]
            ]
           ]
          }
         ],
         "construction_empty": [
          {
           "text": "На вашей базе нет построек.",
           "parse_mode": "Markdown",
           "buttons": []
          }
         ]
        }
        JSON;

    private string $origPrefix = '';
    private int $towerBuildingId = 0;
    private int $biomeId = 0;
    private bool $wrapperSwapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('PHPUNIT_TESTSUITE')) {
            define('PHPUNIT_TESTSUITE', true);
        }
        new Telegram('123456:TEST-fake-token-for-tests', 'test_bot');

        $this->origPrefix = $this->db()->getPrefix();
        $this->db()->setPrefix(self::PREFIX);
        foreach (self::TABLES as $table => $cols) {
            $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            $this->db()->query('CREATE TABLE ' . self::PREFIX . $table . " ({$cols}) DEFAULT CHARSET=utf8mb4");
        }

        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'clean')) {
            $cache->clean();
        }

        $this->db()->table('buildings')->insert(['name_ru' => 'Вышка связи', 'name_en' => 'CommunicationTower']);
        $this->towerBuildingId = (int) $this->db()->insertID();
        $this->db()->table('buildings')->insert(['name_ru' => 'Склад', 'name_en' => 'Warehouse']);
        $this->db()->table('buildings')->insert(['name_ru' => 'Теплица', 'name_en' => 'Greenhouse']);

        $this->db()->table('biomes')->insert(['name' => 'Лес', 'danger_level' => 1, 'survival_difficulty' => 2, 'description' => 'Густой лес.']);
        $this->biomeId = (int) $this->db()->insertID();

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
        try {
            foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                $this->db()->query('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
            }
        } finally {
            $this->db()->setPrefix($this->origPrefix);
        }

        parent::tearDown();
    }

    // ── Сценарии ─────────────────────────────────────────────────────────────

    public function testBotScreensMatchSnapshotExceptBaseSuffixOnBuild(): void
    {
        $actual = [];
        foreach (self::scenarioNames() as $name) {
            $actual[$name] = $this->runScenario($name);
        }

        $dump = getenv('BASE_PARITY_DUMP');
        if (is_string($dump) && $dump !== '') {
            file_put_contents($dump, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->markTestSkipped('снимок записан в ' . $dump);
        }

        $before = json_decode(self::SNAPSHOT_BEFORE, true);
        $this->assertIsArray($before, 'снимок ДО обязан быть валидным JSON');

        foreach (self::scenarioNames() as $name) {
            $this->assertArrayHasKey($name, $before, "в снимке нет сценария {$name}");
            $this->assertSame(self::expectedAfter($name, $before[$name]), $actual[$name], "сценарий {$name}");
        }
    }

    /** @return list<string> */
    private static function scenarioNames(): array
    {
        return [
            'base_on_base', 'base_no_bases', 'base_one_far', 'base_one_tower', 'base_picker_none_covered',
            'base_picker_one_covered', 'base_picker_two_covered', 'base_selected_suffix', 'base_unavailable_suffix',
            'construction_on_base', 'construction_tower', 'construction_no_base', 'construction_far',
            'construction_suffix', 'construction_empty',
        ];
    }

    /**
     * Ask 5: на экране базы «🏗 Строить» (`Build`) получает суффикс `_b<id>` показанной базы. Id базы берём
     * из соседней кнопки «🏘 Постройки» (`construction_b<id>`) того же сообщения.
     *
     * @param mixed $messages
     * @return mixed
     */
    private static function expectedAfter(string $scenario, mixed $messages): mixed
    {
        if (! is_array($messages)) {
            return $messages;
        }
        foreach ($messages as $i => $msg) {
            if (! is_array($msg) || ! is_array($msg['buttons'] ?? null)) {
                continue;
            }
            $baseId = null;
            foreach ($msg['buttons'] as $row) {
                foreach ((array) $row as $button) {
                    if (is_array($button) && preg_match('/^construction_b(\d+)$/', (string) $button[1], $m) === 1) {
                        $baseId = $m[1];
                    }
                }
            }
            if ($baseId === null) {
                continue;
            }
            foreach ($msg['buttons'] as $r => $row) {
                foreach ((array) $row as $b => $button) {
                    if (is_array($button) && $button[1] === 'Build') {
                        $messages[$i]['buttons'][$r][$b][1] = 'Build_b' . $baseId;
                    }
                }
            }
        }

        return $messages;
    }

    /** @return list<array{text:?string, parse_mode:?string, buttons:list<list<array{0:string,1:string}>>}> */
    private function runScenario(string $name): array
    {
        foreach (self::TABLES as $table => $_) {
            if (! in_array($table, ['buildings', 'biomes'], true)) {
                $this->db()->query('TRUNCATE TABLE ' . self::PREFIX . $table);
            }
        }

        $this->addMapCell(100, 10, 10);
        $this->addMapCell(200, 20, 20);
        $this->addMapCell(300, 11, 11);
        $this->addMapCell(400, 15, 15);
        $this->addMapCell(900, 900, 900);

        switch ($name) {
            case 'base_on_base':
                [$tg, $c] = $this->seedCharacter(100);
                $b = $this->addBase($c, 100, 'Дом', 1);
                $this->addBuilding($c, 2, 100, 2, 5, 3);
                $this->addBuilding($c, 3, 100, 1, 4, 1);

                return $this->press(ShowBaseInfoAction::class, $tg, 'Base');
            case 'base_no_bases':
                [$tg] = $this->seedCharacter(100);

                return $this->press(ShowBaseInfoAction::class, $tg, 'Base');
            case 'base_one_far':
                [$tg, $c] = $this->seedCharacter(900);
                $this->addBase($c, 100, 'Дом', 1);

                return $this->press(ShowBaseInfoAction::class, $tg, 'Base');
            case 'base_one_tower':
                [$tg, $c] = $this->seedCharacter(300);
                $this->addBase($c, 100, 'Дом', 2);
                $this->addBuilding($c, 2, 100, 1, 5, 2);

                return $this->press(ShowBaseInfoAction::class, $tg, 'Base');
            case 'base_picker_none_covered':
                [$tg, $c] = $this->seedCharacter(900);
                $this->addBase($c, 100, 'Первая', 1);
                $this->addBase($c, 200, 'Вторая', null);

                return $this->press(ShowBaseInfoAction::class, $tg, 'Base');
            case 'base_picker_one_covered':
                [$tg, $c] = $this->seedCharacter(300);
                $this->addBase($c, 100, 'Первая', 1);
                $this->addBase($c, 200, 'Вторая', null);

                return $this->press(ShowBaseInfoAction::class, $tg, 'Base');
            case 'base_picker_two_covered':
                [$tg, $c] = $this->seedCharacter(400);
                $this->addBase($c, 100, 'Первая', 1);
                $this->addBase($c, 200, 'Вторая', 1);

                return $this->press(ShowBaseInfoAction::class, $tg, 'Base');
            case 'base_selected_suffix':
                [$tg, $c] = $this->seedCharacter(400);
                $this->addBase($c, 100, 'Первая', 1);
                $b2 = $this->addBase($c, 200, 'Вторая', 1);
                $this->addBuilding($c, 3, 200, 2, 7, 1);

                return $this->press(ShowBaseInfoAction::class, $tg, "Base_b{$b2}");
            case 'base_unavailable_suffix':
                [$tg, $c] = $this->seedCharacter(900);
                $b = $this->addBase($c, 100, 'Дом', null);

                return $this->press(ShowBaseInfoAction::class, $tg, "Base_b{$b}");
            case 'construction_on_base':
                [$tg, $c] = $this->seedCharacter(100);
                $this->addBase($c, 100, 'Дом', 1);
                $this->addBuilding($c, 2, 100, 3, 5, 2);

                return $this->press(DetailedBaseInfoAction::class, $tg, 'construction');
            case 'construction_tower':
                [$tg, $c] = $this->seedCharacter(300);
                $this->addBase($c, 100, 'Дом', 2);
                $this->addBuilding($c, 3, 100, 1, 4, 1);

                return $this->press(DetailedBaseInfoAction::class, $tg, 'construction');
            case 'construction_no_base':
                [$tg] = $this->seedCharacter(100);

                return $this->press(DetailedBaseInfoAction::class, $tg, 'construction');
            case 'construction_far':
                [$tg, $c] = $this->seedCharacter(900);
                $this->addBase($c, 100, 'Дом', 1);

                return $this->press(DetailedBaseInfoAction::class, $tg, 'construction');
            case 'construction_suffix':
                [$tg, $c] = $this->seedCharacter(400);
                $this->addBase($c, 100, 'Первая', 1);
                $b2 = $this->addBase($c, 200, 'Вторая', 1);
                $this->addBuilding($c, 2, 200, 1, 5, 1);

                return $this->press(DetailedBaseInfoAction::class, $tg, "construction_b{$b2}");
            case 'construction_empty':
                [$tg, $c] = $this->seedCharacter(100);
                $this->db()->table('claimed_cells')->insert([
                    'character_id' => $c, 'map_cell_id' => 100, 'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active',
                ]);

                return $this->press(DetailedBaseInfoAction::class, $tg, 'construction');
        }

        $this->fail("нет сценария {$name}");
    }

    // ── Помощники ────────────────────────────────────────────────────────────

    /**
     * @param class-string $action
     * @return list<array{text:?string, parse_mode:?string, buttons:list<list<array{0:string,1:string}>>}>
     */
    private function press(string $action, int $tgId, string $data): array
    {
        WebDelivery::beginCapture($tgId, 0);
        try {
            (new $action($this->cbq($tgId, $data)))->handle();
        } finally {
            $capture = WebDelivery::endCapture();
        }

        $out = [];
        foreach ([...$capture['sent'], ...array_values($capture['edited'])] as $msg) {
            $rows = [];
            foreach ($msg['inline_keyboard'] as $row) {
                $r = [];
                foreach ($row as $button) {
                    $r[] = [(string) ($button['text'] ?? ''), (string) ($button['callback_data'] ?? '')];
                }
                $rows[] = $r;
            }
            $out[] = [
                'text'       => $msg['caption'] ?? $msg['text'],
                'parse_mode' => $msg['parse_mode'],
                'buttons'    => $rows,
            ];
        }

        return $out;
    }

    private function db(): BaseConnection
    {
        return Database::connect('tests');
    }

    private function addMapCell(int $cellNumber, int $x, int $y): void
    {
        $this->db()->table('map')->insert([
            'id' => $cellNumber, 'cell_number' => $cellNumber,
            'coordinate_x' => $x, 'coordinate_y' => $y, 'biome_id' => $this->biomeId,
        ]);
    }

    /** @return array{0:int,1:int} [telegram_id, character_id] */
    private function seedCharacter(int $standingOnCell): array
    {
        $tgId = 771_000_001;
        $this->db()->table('telegram_users')->insert(['telegram_id' => $tgId]);
        $tgUid = (int) $this->db()->insertID();
        $this->db()->table('characters')->insert([
            'telegram_user_id' => $tgUid, 'cell_number' => $standingOnCell, 'locale' => 'ru', 'level' => 99,
        ]);

        return [$tgId, (int) $this->db()->insertID()];
    }

    private function addBase(int $charId, int $cellNumber, ?string $campName, ?int $towerLevel): int
    {
        $this->db()->table('claimed_cells')->insert([
            'character_id' => $charId, 'map_cell_id' => $cellNumber,
            'claimed_at' => date('Y-m-d H:i:s'), 'status' => 'active', 'camp_name' => $campName,
        ]);
        $baseId = (int) $this->db()->insertID();

        if ($towerLevel !== null) {
            $this->addBuilding($charId, $this->towerBuildingId, $cellNumber, $towerLevel, 10, 1);
        }

        return $baseId;
    }

    private function addBuilding(int $charId, int $buildingId, int $cell, int $level, int $tax, int $amount): void
    {
        $this->db()->table('character_buildings')->insert([
            'character_id' => $charId, 'building_id' => $buildingId, 'map_cell_id' => $cell,
            'level' => $level, 'tax' => $tax, 'amount' => $amount,
        ]);
    }

    private function cbq(int $tgId, string $data): CallbackQuery
    {
        return new CallbackQuery([
            'id'   => 'cbq_parity',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'Тест'],
            'message' => [
                'message_id' => 1, 'date' => time(),
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'placeholder',
            ],
            'chat_instance' => 'ci_' . $tgId,
            'data' => $data,
        ]);
    }
}

/** Пустышка `http://` для `Request::encodeFile(base_url(...))`: открывается, читается пустой. */
final class ParityFakeHttpStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /** @return array<string,int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}

/** Хранилище экрана без БД: синтетические id по порядку, ни одного сохранённого сообщения. */
final class ParityFakeStore extends WebScreenStore
{
    private int $next = 1_000_000;

    public function __construct()
    {
    }

    public function nextMessageId(int $characterId): int
    {
        return ++$this->next;
    }

    public function findMessage(int $characterId, int $messageId): ?array
    {
        return null;
    }
}

/** Входящие без БД. */
final class ParityFakeInbox extends WebInboxService
{
    public function __construct()
    {
    }

    public function findMessage(int $characterId, int $messageId): ?array
    {
        return null;
    }
}
