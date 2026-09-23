<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Controllers\Telegram\Commands\Actions\Quest\QuestsInfo;
use App\Database\Migrations\CreateQuestsTable;
use App\Database\Migrations\V11AddQuestPrerequisite;
use App\Models\QuestModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * bugs-info-0923-03 — карточка ЛЮБОГО квеста из строки `quests`.
 *
 * Схема `quests` строится реальными миграциями (CreateQuestsTable + V11AddQuestPrerequisite),
 * а не рукописным DDL; тест чистит за собой только то, что создал сам.
 */
final class QuestInfoCardTest extends CIUnitTestCase
{
    private BaseConnection $conn;
    private QuestModel $model;
    private bool $createdTable = false;
    private bool $addedPrereqColumn = false;

    /** @var list<int> */
    private array $insertedIds = [];

    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn = Database::connect('tests');
        $this->conn->resetDataCache();
        $forge = Database::forge('tests');

        require_once APPPATH . 'Database/Migrations/2024-04-26-121416_CreateQuestsTable.php';
        require_once APPPATH . 'Database/Migrations/2026-05-21-210000_V11AddQuestPrerequisite.php';

        if (! $this->conn->tableExists('quests', false)) {
            (new CreateQuestsTable($forge))->up();
            $this->createdTable = true;
        }
        if (! $this->conn->fieldExists('prerequisite_quest', 'quests')) {
            (new V11AddQuestPrerequisite($forge))->up();
            $this->addedPrereqColumn = ! $this->createdTable;
        }

        $this->model  = new QuestModel($this->conn);
        $this->suffix = (string) random_int(100000, 999999);
    }

    protected function tearDown(): void
    {
        if ($this->insertedIds !== []) {
            $this->conn->table('quests')->whereIn('id', $this->insertedIds)->delete();
        }
        if ($this->createdTable) {
            $this->conn->query('DROP TABLE IF EXISTS quests');
        } elseif ($this->addedPrereqColumn) {
            (new V11AddQuestPrerequisite(Database::forge('tests')))->down();
        }
        $this->conn->resetDataCache();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertQuest(array $row): int
    {
        $this->conn->table('quests')->insert($row + [
            'status'      => 'available',
            'min_level'   => 1,
            'reward_type' => 'gold',
            'reward'      => 100,
        ]);
        $id                  = (int) $this->conn->insertID();
        $this->insertedIds[] = $id;

        return $id;
    }

    /**
     * @param array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} $keyboard
     * @return list<string>
     */
    private static function callbacks(array $keyboard): array
    {
        $out = [];
        foreach ($keyboard['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                $out[] = $button['callback_data'];
            }
        }

        return $out;
    }

    /** @return array{0: int, 1: string} id и title_en квеста с предшественником */
    private function seedChain(): array
    {
        $prevEn = 'CardPrev_' . $this->suffix;
        $this->insertQuest([
            'title_ru'    => 'Первый шаг цепочки',
            'title_en'    => $prevEn,
            'description' => 'Предыдущий квест',
        ]);
        $titleEn = 'Card_Test_Quest_' . $this->suffix;
        $id      = $this->insertQuest([
            'title_ru'           => 'Карточный квест',
            'title_en'           => $titleEn,
            'description'        => 'Соберите три ящика у старой фермы',
            'min_level'          => 7,
            'reward_type'        => 'gold',
            'reward'             => 2500,
            'prerequisite_quest' => $prevEn,
        ]);

        return [$id, $titleEn];
    }

    public function testCardByIdButtonShowsAllFieldsAndOneRowOfNavButtons(): void
    {
        [$id] = $this->seedChain();

        $tail = QuestsInfo::cardTail('questInfo_id' . $id);
        $this->assertSame('id' . $id, $tail);
        $card = QuestsInfo::buildCard((string) $tail, $this->model);

        $this->assertStringContainsString('Карточный квест', $card['text']);
        $this->assertStringContainsString('Соберите три ящика у старой фермы', $card['text']);
        $this->assertStringContainsString('7-го уровня', $card['text']);
        $this->assertStringContainsString('2 500 золото', $card['text']);
        $this->assertStringContainsString('Первый шаг цепочки', $card['text']);

        $this->assertCount(1, $card['keyboard']['inline_keyboard']);
        $this->assertSame(['questInfo', 'character'], self::callbacks($card['keyboard']));
        $this->assertSame('◀️ Я', $card['keyboard']['inline_keyboard'][0][1]['text']);
    }

    public function testLegacyTitleEnButtonWithUnderscoresOpensSameCard(): void
    {
        [$id, $titleEn] = $this->seedChain();

        $byId     = QuestsInfo::buildCard('id' . $id, $this->model);
        $legacy   = QuestsInfo::cardTail('questInfo_' . $titleEn);
        $this->assertSame($titleEn, $legacy);
        $byLegacy = QuestsInfo::buildCard((string) $legacy, $this->model);

        $this->assertSame($byId, $byLegacy);
    }

    public function testUnknownIdAndUnknownTitleEnGiveRefusalNotList(): void
    {
        foreach (['id999999999', 'No_Such_Quest_' . $this->suffix] as $tail) {
            $card = QuestsInfo::buildCard($tail, $this->model);
            $this->assertStringContainsString('не найден', $card['text']);
            $this->assertStringNotContainsString('Список всех квестов', $card['text']);
            $this->assertContains('questInfo', self::callbacks($card['keyboard']));
        }
    }

    public function testBareQuestInfoIsListNotCard(): void
    {
        $this->assertNull(QuestsInfo::cardTail('questInfo'));
        $this->assertNull(QuestsInfo::cardTail('questInfo_'));
    }

    public function testEveryButtonCallbackFitsTelegramLimit(): void
    {
        [$id] = $this->seedChain();
        $longId = $this->insertQuest([
            'title_ru'    => str_repeat('Очень длинное название ', 8),
            'title_en'    => 'Long_' . str_repeat('x', 200) . $this->suffix,
            'description' => 'Длинный',
        ]);

        $list = QuestsInfo::generateQuestKeyboard($this->model->findAll());
        $card = QuestsInfo::buildCard('id' . $id, $this->model);
        $miss = QuestsInfo::buildCard('id0', $this->model);

        $all = array_merge(self::callbacks($list), self::callbacks($card['keyboard']), self::callbacks($miss['keyboard']));
        $this->assertContains('questInfo_id' . $longId, $all);
        foreach ($all as $cb) {
            $this->assertLessThanOrEqual(64, strlen($cb), $cb);
        }
    }

    public function testMarkdownBreakingCharsFromDbAreNeutralised(): void
    {
        $id = $this->insertQuest([
            'title_ru'    => 'Квест_с *звёздами* и `кодом`',
            'title_en'    => 'Md_Break_' . $this->suffix,
            'description' => 'Опис_ание с *непарной звездой и `бэктиком',
        ]);

        $card = QuestsInfo::buildCard('id' . $id, $this->model);

        $this->assertStringContainsString('Квестс звёздами и кодом', $card['text']);
        $this->assertStringContainsString('Описание с непарной звездой и бэктиком', $card['text']);
        $this->assertStringNotContainsString('`', $card['text']);
        $this->assertStringNotContainsString('_', $card['text']);
        $this->assertSame(0, substr_count($card['text'], '*') % 2, 'звёздочки разметки парные');
    }

    public function testNoPrerequisiteLineWhenQuestHasNone(): void
    {
        $id = $this->insertQuest([
            'title_ru'    => 'Одиночный квест',
            'title_en'    => 'Solo_' . $this->suffix,
            'description' => 'Без предшественника',
        ]);

        $card = QuestsInfo::buildCard('id' . $id, $this->model);

        $this->assertStringNotContainsString('Сначала завершите', $card['text']);
    }

    public function testFormerFourHardcodedQuestsUseGenericCard(): void
    {
        foreach (['Explore30Cells', 'ExploreAllBiomes', 'Explore300Cells', 'FirstAidkitBasic'] as $en) {
            $existing = $this->model->where('title_en', $en)->first();
            if (! is_array($existing)) {
                $this->insertQuest([
                    'title_ru'    => 'Прежний ' . $en,
                    'title_en'    => $en,
                    'description' => 'Описание ' . $en,
                ]);
                $existing = $this->model->where('title_en', $en)->orderBy('id', 'ASC')->first();
            }
            $this->assertIsArray($existing);

            $card = QuestsInfo::buildCard((string) QuestsInfo::cardTail('questInfo_' . $en), $this->model);
            $this->assertStringContainsString(
                \App\Services\Display\MarkdownSafe::name((string) $existing['title_ru'], 'Квест'),
                $card['text']
            );
            $this->assertStringContainsString('Награда', $card['text']);
            $this->assertStringNotContainsString('не найден', $card['text']);
        }
    }
}
