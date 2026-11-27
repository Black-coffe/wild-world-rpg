<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Migrations\FixEndgameCraftTipNoT4;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * H6.2 (h6-canon-1127, ask 8, ревью раунд 1) — миграция совета №47 `EndgameCraftT4` прогоняется на строке
 * `game_tips`: up() дважды пишет новый заголовок и текст (повтор — те же значения, ключ не меняется, соседние
 * советы не трогаются), down() возвращает текст прода дословно.
 *
 * @internal
 */
final class FixEndgameCraftTipNoT4MigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2027-01-02-100000_FixEndgameCraftTipNoT4.php';
        $db = Database::connect('tests');
        $t  = $db->prefixTable('game_tips');
        $db->query("DROP TABLE IF EXISTS {$t}");
        $db->query("CREATE TABLE {$t} (id INT AUTO_INCREMENT PRIMARY KEY, title_ru VARCHAR(255) NULL, title_en VARCHAR(255) NULL, tip_type VARCHAR(32) NULL, content TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4");
        $db->table('game_tips')->insertBatch([
            ['id' => 47, 'title_ru' => FixEndgameCraftTipNoT4::OLD_TITLE_RU, 'title_en' => 'EndgameCraftT4', 'tip_type' => 'эндгейм', 'content' => FixEndgameCraftTipNoT4::OLD_CONTENT],
            ['id' => 48, 'title_ru' => 'Соседний совет', 'title_en' => 'OtherTip', 'tip_type' => 'эндгейм', 'content' => 'Не трогать (T4).'],
        ]);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable('game_tips'));
        parent::tearDown();
    }

    public function testUpTwiceRewritesTipAndDownRestoresProdText(): void
    {
        $forge = Database::forge('tests');

        (new FixEndgameCraftTipNoT4($forge))->up();
        (new FixEndgameCraftTipNoT4($forge))->up(); // повтор — те же значения

        $tip = $this->row(47);
        $this->assertSame('EndgameCraftT4', $tip['title_en'], 'ключ совета не меняется');
        $this->assertSame('🏆 Профессиональный крафт', $tip['title_ru']);
        $this->assertSame(FixEndgameCraftTipNoT4::NEW_CONTENT, $tip['content']);
        $this->assertStringContainsString('Для них', (string) $tip['content'], 'гейт — у фракционных вещей, не у всего уровня');
        $this->assertStringNotContainsString('T4', (string) $tip['title_ru'] . $tip['content']);
        $this->assertSame('эндгейм', $tip['tip_type'], 'категория не меняется');
        $this->assertCount(2, Database::connect('tests')->table('game_tips')->get()->getResultArray());
        $this->assertSame('Не трогать (T4).', $this->row(48)['content'], 'соседний совет не тронут');

        (new FixEndgameCraftTipNoT4($forge))->down();

        $tip = $this->row(47);
        $this->assertSame('🏆 Эндгейм-крафт (T4)', $tip['title_ru']);
        $this->assertSame(FixEndgameCraftTipNoT4::OLD_CONTENT, $tip['content']);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $row = Database::connect('tests')->table('game_tips')->where('id', $id)->get()->getRowArray();
        $this->assertIsArray($row);

        return $row;
    }
}
