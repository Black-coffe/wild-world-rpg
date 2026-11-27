<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\H6RenameRelicsAndBracketWeapons;
use App\Services\Player\CollectionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * H6.3 (h6-relics-1127, S1 asks 1, 1a, 2, 3, 5; S2 asks 4, 5) — реликвии 69/70/71 переименованы на месте, английский в скобках убран.
 *
 * На схеме с прод-коллациями (`resources.name` unicode_ci, `collection_slots.resource_name` general_ci) и старыми
 * строками: держатели, слоты «Сокровищ глубин» с пожертвованием и пост сайта о пещерах. up() дважды: строки
 * держателей и количества те же, имена и описания новые, слоты называют новые `resources.name`, CollectionService
 * видит запас держателя, коллекция у пожертвовавшего остаётся полной, пост сайта несёт новые имена. down()
 * возвращает старые имена.
 *
 * @internal
 */
final class H6RenameRelicsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const TABLES = [
        'resources', 'character_resources', 'collections', 'collection_slots', 'character_collection_slots',
        'site_posts', 'game_settings', 'weapons', 'tasks',
    ];

    private const SLUG = 'syre-biom-peshhery-i-podzemelja-vot-spisok-syrja-kotoroe-mozhet-byt-najdeno-i-ispolzovano';

    private const OLD = [
        69 => 'Сердце Дракона',
        70 => 'Древняя Книга',
        71 => 'Корона подземного короля',
    ];

    private const NEW = [
        69 => 'Изотопное ядро „Дракон-4“',
        70 => 'Архивный носитель Предтеч',
        71 => 'Релейный венец Глубины',
    ];

    /** `rationale_text` настройки редкого события, как его посеяла S10SeedRareEventGameSettings (локальная копия testbot 2026-11-27). */
    private const RARE_RATIONALE_OLD = '10% за tick — компромисс между «редкое событие, ощутимо когда срабатывает» (П1/П3 game-stories) и «не настолько частое, чтобы T3-ресурсы стали тривиальными» (П8 economy, П10 onboarding). Событие «Открытие скрытых подземелий» спавнит Сердце Дракона/Древняя Книга/Корона (keyword=RevealingHiddenCameras) в биоме Пещеры (id=7).';

    private const SETTING_TEXT_COLUMNS = ['rationale_text', 'effect_text', 'above_effect_text', 'below_effect_text'];

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2027-01-03-100000_H6RenameRelicsAndBracketWeapons.php';
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($t));
        }
        $db->query("CREATE TABLE {$db->prefixTable('resources')} (id INT PRIMARY KEY, name VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL, description TEXT NULL, rarity INT DEFAULT 5, updated_at DATETIME NULL, UNIQUE KEY uq_name (name)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE {$db->prefixTable('character_resources')} (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT, quantity INT DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE {$db->prefixTable('collections')} (id INT AUTO_INCREMENT PRIMARY KEY, collection_key VARCHAR(64), name VARCHAR(128), description VARCHAR(512) NULL, theme VARCHAR(64) NULL, min_level INT DEFAULT 50, reward_title_key VARCHAR(64) NULL, icon VARCHAR(16) NULL, sort_order INT DEFAULT 0, enabled TINYINT DEFAULT 1) DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE {$db->prefixTable('collection_slots')} (id INT AUTO_INCREMENT PRIMARY KEY, collection_id INT, resource_name VARCHAR(128) COLLATE utf8mb4_general_ci NOT NULL, required_qty INT DEFAULT 1, sort_order INT DEFAULT 0) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $db->query("CREATE TABLE {$db->prefixTable('character_collection_slots')} (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, collection_slot_id INT, donated_at DATETIME NULL, UNIQUE KEY uniq_ccs (character_id, collection_slot_id)) DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE {$db->prefixTable('site_posts')} (id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(255), title VARCHAR(255) NULL, content_html MEDIUMTEXT NULL, excerpt TEXT NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE {$db->prefixTable('game_settings')} (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(64) NOT NULL, value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_string TEXT NULL, default_value_text TEXT NULL, rationale_text TEXT NULL, effect_text TEXT NULL, above_effect_text TEXT NULL, below_effect_text TEXT NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE {$db->prefixTable('weapons')} (id INT PRIMARY KEY, name VARCHAR(150) NOT NULL, name_en VARCHAR(150) NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb3");
        $db->query("CREATE TABLE {$db->prefixTable('tasks')} (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL, description MEDIUMTEXT NOT NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->table('weapons')->insertBatch([
            ['id' => 3, 'name' => 'Трубчатый пистолет (Pipe Gun)', 'name_en' => 'PipeGun'],
            ['id' => 6, 'name' => 'Обрез (усиленный)', 'name_en' => 'ShortenedShotgun'],
            ['id' => 10, 'name' => 'Ракетница (Firebomb)', 'name_en' => 'FirebombLauncher'],
            ['id' => 1, 'name' => 'Металлическое копьё', 'name_en' => 'MetalSpear'],
        ]);
        $db->table('tasks')->insertBatch([
            ['id' => 56, 'name' => 'craftPipeGun', 'description' => 'Создание самодельного Pipe Gun'],
            ['id' => 57, 'name' => 'craftMetalSpear', 'description' => 'Не трогать.'],
        ]);
        $db->table('game_settings')->insert([
            'setting_key'       => 'economy.shop.buy_level_gate_enabled',
            'value_type'        => 'bool',
            'value_bool'        => 1,
            'below_effect_text' => 'false — прежнее поведение: любой ресурс покупается на любом уровне, если хватает золота (персонаж 1 уровня берёт «Корону подземного короля» за 5000).',
        ]);
        $db->table('game_settings')->insert([
            'setting_key'    => 'rare_event.RevealingHiddenCameras.chance_per_tick',
            'value_type'     => 'float',
            'rationale_text' => self::RARE_RATIONALE_OLD,
        ]);
        $db->table('game_settings')->insert([
            'setting_key'       => 'test.relics.all_columns',
            'value_type'        => 'int',
            'effect_text'       => 'Выдаёт Древняя Книга раз в сутки.',
            'above_effect_text' => 'Выше — Сердце Дракона и Корона подземного короля становятся обычными.',
        ]);

        $db->table('resources')->insertBatch([
            ['id' => 69, 'name' => self::OLD[69], 'description' => 'Огненный камень, пульсирующий энергией. Говорят, что он обладает огромной силой. Завладеть таким считается настоящим событием и достижением.', 'rarity' => 1],
            ['id' => 70, 'name' => self::OLD[70], 'description' => 'Потрепанная книга, написанная на неизвестном языке. Говорят, что она содержит ценные знания.', 'rarity' => 1],
            ['id' => 71, 'name' => self::OLD[71], 'description' => 'Корона, принадлежавшая легендарному подземному королю. Дает +10 ко всем характеристикам персонажа раз в 10 дней!', 'rarity' => 1],
            ['id' => 68, 'name' => 'Древние реликвии', 'description' => 'Не трогать.', 'rarity' => 1],
        ]);
        $db->table('character_resources')->insertBatch([
            ['id_characters' => 501, 'id_resources' => 69, 'quantity' => 700],
            ['id_characters' => 501, 'id_resources' => 70, 'quantity' => 3],
            ['id_characters' => 502, 'id_resources' => 71, 'quantity' => 1],
            ['id_characters' => 502, 'id_resources' => 68, 'quantity' => 9],
        ]);
        $db->table('collections')->insertBatch([
            ['id' => 2, 'collection_key' => 'deep_treasures', 'name' => 'Сокровища глубин', 'min_level' => 50, 'enabled' => 1],
            ['id' => 1, 'collection_key' => 'other', 'name' => 'Другая', 'min_level' => 50, 'enabled' => 1],
        ]);
        $db->table('collection_slots')->insertBatch([
            ['id' => 4, 'collection_id' => 2, 'resource_name' => self::OLD[69], 'required_qty' => 1, 'sort_order' => 1],
            ['id' => 5, 'collection_id' => 2, 'resource_name' => self::OLD[70], 'required_qty' => 1, 'sort_order' => 2],
            ['id' => 6, 'collection_id' => 2, 'resource_name' => self::OLD[71], 'required_qty' => 1, 'sort_order' => 3],
            ['id' => 1, 'collection_id' => 1, 'resource_name' => 'Древние реликвии', 'required_qty' => 1, 'sort_order' => 1],
        ]);
        $db->table('character_collection_slots')->insertBatch([
            ['character_id' => 502, 'collection_slot_id' => 4, 'donated_at' => '2026-11-01 10:00:00'],
            ['character_id' => 502, 'collection_slot_id' => 5, 'donated_at' => '2026-11-01 10:00:00'],
            ['character_id' => 502, 'collection_slot_id' => 6, 'donated_at' => '2026-11-01 10:00:00'],
        ]);
        $db->table('site_posts')->insertBatch([
            ['slug' => self::SLUG, 'title' => 'Сырье | биом «Пещеры и подземелья»', 'content_html' => $this->oldSitePost()],
            ['slug' => 'other-post', 'title' => 'Другой', 'content_html' => '<p>Сердце Дракона в чужом посте.</p>'],
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($t));
        }
        $this->cleanCache();
        parent::tearDown();
    }

    public function testUpTwiceRenamesRelicsInPlaceAndKeepsHoldingsAndCollections(): void
    {
        $db       = Database::connect('tests');
        $holdings = $db->table('character_resources')->orderBy('id')->get()->getResultArray();
        $forge    = Database::forge('tests');

        (new H6RenameRelicsAndBracketWeapons($forge))->up();
        (new H6RenameRelicsAndBracketWeapons($forge))->up(); // повтор — те же значения

        foreach (self::NEW as $id => $name) {
            $row = $db->table('resources')->where('id', $id)->get()->getRowArray();
            $this->assertIsArray($row);
            $this->assertSame($name, $row['name'], "ресурс {$id} переименован на месте");
            $desc = (string) $row['description'];
            $this->assertNotSame('', $desc);
            $this->assertStringNotContainsString('+10', $desc, 'описание не обещает эффекта, которого нет');
            $this->assertStringNotContainsString('характеристик', $desc);
            $this->assertStringNotContainsString('Говорят', $desc);
            $this->assertStringNotContainsString('корол', mb_strtolower($desc));
            $this->assertStringNotContainsString('книг', mb_strtolower($desc));
        }
        $this->assertStringContainsString('индекс', (string) $db->table('resources')->where('id', 69)->get()->getRowArray()['description'], '«Дракон-4» читается как заводской индекс');
        $this->assertSame('Не трогать.', $db->table('resources')->where('id', 68)->get()->getRowArray()['description'], 'соседний ресурс не тронут');
        $this->assertSame(4, $db->table('resources')->countAllResults());

        $this->assertSame($holdings, $db->table('character_resources')->orderBy('id')->get()->getResultArray(), 'строки и количества держателей те же');

        $slots = $db->table('collection_slots')->orderBy('id')->get()->getResultArray();
        $this->assertSame('Древние реликвии', $slots[0]['resource_name'], 'чужой слот не тронут');
        foreach ([4 => 69, 5 => 70, 6 => 71] as $slotId => $resId) {
            $slot = $db->table('collection_slots')->where('id', $slotId)->get()->getRowArray();
            $this->assertIsArray($slot);
            $this->assertSame(self::NEW[$resId], $slot['resource_name'], "слот {$slotId} называет новое имя ресурса");
        }

        $collections = new CollectionService();
        $this->assertSame(700, $collections->heldQty(501, self::NEW[69]), 'держатель видит свой запас под новым именем');
        $this->assertSame(3, $collections->heldQty(501, self::NEW[70]));
        $this->assertSame(1, $collections->heldQty(502, self::NEW[71]));
        $this->assertTrue($collections->isComplete(502, 2), 'коллекция у пожертвовавшего остаётся полной');

        $post = (string) $db->table('site_posts')->where('slug', self::SLUG)->get()->getRowArray()['content_html'];
        foreach (self::OLD as $id => $old) {
            $this->assertStringNotContainsString($old, $post, "пост сайта не называет «{$old}»");
            $this->assertSame(2, substr_count($post, self::NEW[$id]), "пост сайта называет «" . self::NEW[$id] . "» в заголовке и в итоге");
        }
        $this->assertStringNotContainsString('героям', $post);
        $this->assertSame(
            $this->normalize((string) file_get_contents(APPPATH . 'Data/site_canon/' . self::SLUG . '.html')),
            $this->normalize($post),
            'пост в БД совпадает с канон-файлом сайта',
        );
        $this->assertSame('<p>Сердце Дракона в чужом посте.</p>', $db->table('site_posts')->where('slug', 'other-post')->get()->getRowArray()['content_html'], 'чужой пост не тронут');

        (new H6RenameRelicsAndBracketWeapons($forge))->down();

        foreach (self::OLD as $id => $old) {
            $this->assertSame($old, $db->table('resources')->where('id', $id)->get()->getRowArray()['name']);
        }
        $this->assertSame(self::OLD[69], $db->table('collection_slots')->where('id', 4)->get()->getRowArray()['resource_name']);
        $this->assertSame($this->normalize($this->oldSitePost()), $this->normalize((string) $db->table('site_posts')->where('slug', self::SLUG)->get()->getRowArray()['content_html']));
        $this->assertSame($holdings, $db->table('character_resources')->orderBy('id')->get()->getResultArray());
    }

    /**
     * S2 (ask 4, 5): английский в скобках уходит из имён оружия 3 и 10, из описания задачи 56 и из текста экрана
     * крафта; «Обрез (усиленный)» — русский и остаётся; повтор up() ничего не меняет, down() возвращает старое.
     */
    public function testUpTwiceDropsEnglishInBracketsFromWeaponsTaskAndCraftScreen(): void
    {
        $db    = Database::connect('tests');
        $forge = Database::forge('tests');

        (new H6RenameRelicsAndBracketWeapons($forge))->up();
        (new H6RenameRelicsAndBracketWeapons($forge))->up();

        foreach ($db->table('weapons')->orderBy('id')->get()->getResultArray() as $w) {
            $this->assertDoesNotMatchRegularExpression('~\([A-Za-z]~', (string) $w['name'], "оружие {$w['id']} без английского в скобках");
        }
        $names = array_column($db->table('weapons')->orderBy('id')->get()->getResultArray(), 'name', 'id');
        $this->assertSame([1 => 'Металлическое копьё', 3 => 'Трубчатый пистолет', 6 => 'Обрез (усиленный)', 10 => 'Ракетница'], $names);
        $this->assertSame('PipeGun', $db->table('weapons')->where('id', 3)->get()->getRowArray()['name_en'], 'name_en (ключ картинки) не тронут');

        $task = (string) $db->table('tasks')->where('id', 56)->get()->getRowArray()['description'];
        $this->assertStringNotContainsString('Pipe Gun', $task);
        $this->assertDoesNotMatchRegularExpression('~[A-Za-z]~', $task, 'описание задачи 56 по-русски');
        $this->assertSame('Не трогать.', $db->table('tasks')->where('id', 57)->get()->getRowArray()['description']);

        $gate = (string) $db->table('game_settings')->where('setting_key', 'economy.shop.buy_level_gate_enabled')->get()->getRowArray()['below_effect_text'];
        $this->assertStringNotContainsString('подземного короля', $gate, 'описание настройки называет новое имя');
        $this->assertStringContainsString('«Релейный венец Глубины»', $gate);

        $source = (string) file_get_contents(APPPATH . 'Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Weapons/WeaponsCraft2Select.php');
        $code   = implode("\n", array_filter(explode("\n", $source), static fn (string $l): bool => ! str_starts_with(ltrim($l), '//')));
        $this->assertDoesNotMatchRegularExpression('~\([A-Za-z][^)]*\)\*~', $code, 'экран крафта без английского в скобках');
        $this->assertStringContainsString('2) 🔫 *Трубчатый пистолет*', $code);

        (new H6RenameRelicsAndBracketWeapons($forge))->down();

        $this->assertSame('Трубчатый пистолет (Pipe Gun)', $db->table('weapons')->where('id', 3)->get()->getRowArray()['name']);
        $this->assertSame('Ракетница (Firebomb)', $db->table('weapons')->where('id', 10)->get()->getRowArray()['name']);
        $this->assertSame('Создание самодельного Pipe Gun', $db->table('tasks')->where('id', 56)->get()->getRowArray()['description']);
        $this->assertStringContainsString('«Корону подземного короля»', (string) $db->table('game_settings')->where('setting_key', 'economy.shop.buy_level_gate_enabled')->get()->getRowArray()['below_effect_text']);
    }

    /**
     * Ask 5a (review round 1, minor 2): после up() ни одна текстовая колонка `game_settings` (rationale, effect,
     * above, below) не называет старые реликвии — в том числе обоснование редкого события «Открытие скрытых
     * подземелий»; повтор up() ничего не меняет, down() возвращает старый текст.
     */
    public function testUpTwiceLeavesNoOldRelicNameInAnyGameSettingsText(): void
    {
        $db    = Database::connect('tests');
        $forge = Database::forge('tests');

        (new H6RenameRelicsAndBracketWeapons($forge))->up();
        (new H6RenameRelicsAndBracketWeapons($forge))->up();

        foreach ($db->table('game_settings')->orderBy('id')->get()->getResultArray() as $row) {
            foreach (self::SETTING_TEXT_COLUMNS as $col) {
                $text = (string) ($row[$col] ?? '');
                foreach (['Сердце Дракона', 'Древняя Книга', 'подземного короля', '/Корона'] as $old) {
                    $this->assertStringNotContainsString($old, $text, "{$row['setting_key']}.{$col} не называет «{$old}»");
                }
            }
        }

        $rare = (string) $db->table('game_settings')->where('setting_key', 'rare_event.RevealingHiddenCameras.chance_per_tick')->get()->getRowArray()['rationale_text'];
        $this->assertStringContainsString('спавнит Изотопное ядро „Дракон-4“/Архивный носитель Предтеч/Релейный венец Глубины (keyword=RevealingHiddenCameras)', $rare);
        $all = $db->table('game_settings')->where('setting_key', 'test.relics.all_columns')->get()->getRowArray();
        $this->assertSame('Выдаёт Архивный носитель Предтеч раз в сутки.', $all['effect_text']);
        $this->assertSame('Выше — Изотопное ядро „Дракон-4“ и Релейный венец Глубины становятся обычными.', $all['above_effect_text']);

        (new H6RenameRelicsAndBracketWeapons($forge))->down();

        $this->assertSame(self::RARE_RATIONALE_OLD, $db->table('game_settings')->where('setting_key', 'rare_event.RevealingHiddenCameras.chance_per_tick')->get()->getRowArray()['rationale_text']);
        $this->assertSame('Выдаёт Древняя Книга раз в сутки.', $db->table('game_settings')->where('setting_key', 'test.relics.all_columns')->get()->getRowArray()['effect_text']);
    }

    /** Текст поста на сайте до H6.3: фрагмент о реликвиях, как он лежит на проде и в канон-файле до правки. */
    private function oldSitePost(): string
    {
        return "<h3>Древние реликвии (редкость 1, с 20 уровня)</h3>\n"
            . "<p>Уникальные артефакты подземелий — доступны лишь опытным выжившим.</p>\n\n"
            . "<h3>Сердце Дракона (редкость 1, с 55 уровня)</h3>\n"
            . "<p>Легендарная находка глубочайших залов, открывающаяся только героям высокого уровня.</p>\n\n"
            . "<h3>Древняя Книга (редкость 1, с 75 уровня)</h3>\n"
            . "<p>Редчайший артефакт знаний для эндгейм-контента.</p>\n\n"
            . "<h3>Корона подземного короля (редкость 1, со 100 уровня)</h3>\n"
            . "<p>Венец подземелий — самая престижная и труднодоступная находка на острове.</p>\n\n"
            . "<p>Пещеры — кладовая редкостей. А легендарные артефакты глубин (Сердце Дракона, Древняя Книга, Корона подземного короля) — цель самых упорных выживших.</p>\n";
    }

    /**
     * Пост в тесте — фрагмент; канон-файл — целая страница. Сравнивается фрагмент о реликвиях: от заголовка
     * «Древние реликвии» до конца абзаца с итогом, без различий в переводах строк.
     */
    private function normalize(string $html): string
    {
        $html  = str_replace("\r\n", "\n", $html);
        $start = mb_strpos($html, '<h3>Древние реликвии');
        $this->assertNotFalse($start);
        $html = mb_substr($html, $start);
        $h3s  = [];
        preg_match_all('~<h3>[^<]*</h3>\n<p>[^<]*</p>~u', $html, $m);
        foreach ($m[0] as $block) {
            $h3s[] = $block;
        }
        $this->assertGreaterThanOrEqual(4, count($h3s), 'в посте четыре блока о реликвиях');
        $this->assertSame(1, preg_match('~\(([^()]*,[^()]*,[^()]*)\) — цель самых упорных выживших~u', $html, $sum));

        return implode("\n", array_slice($h3s, 0, 4)) . "\n" . $sum[1];
    }

    private function cleanCache(): void
    {
        if (function_exists('cache')) {
            $c = cache();
            if (is_object($c) && method_exists($c, 'clean')) {
                $c->clean();
            }
        }
    }
}
