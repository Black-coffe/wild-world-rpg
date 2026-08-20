<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Craft;

use App\Entities\CharacterEntity;
use App\Entities\ResourceEntity;
use App\Services\Craft\CraftShortageService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ADR-158 — экран «чего не хватает».
 *
 * Прод за 60 дней: 144 отказа крафта, из них 137 — нехватка сырья и 127 — попытка
 * собрать ОДНУ штуку. Раньше игрок в ответ получал «Недостаточно ресурсов для крафта
 * N шт.» без списка и кнопок, хотя точный список сервер уже считал.
 *
 * @internal
 */
final class CraftShortageServiceTest extends CIUnitTestCase
{
    /**
     * @param array<string,array{id:int,is_tradeable:int,buy_price:float,biome_id:string}> $resources
     * @param array<int,string> $biomes
     * @param array<int,string> $hints
     */
    private function service(array $resources, array $biomes = [], array $hints = []): CraftShortageService
    {
        return new class ($resources, $biomes, $hints) extends CraftShortageService {
            /**
             * @param array<string,array{id:int,is_tradeable:int,buy_price:float,biome_id:string}> $resources
             * @param array<int,string> $biomes
             * @param array<int,string> $hints
             */
            public function __construct(
                private array $resources,
                private array $biomes,
                private array $hints
            ) {
                parent::__construct();
            }

            protected function findResource(string $name): ?ResourceEntity
            {
                if (! isset($this->resources[$name])) {
                    return null;
                }

                return new ResourceEntity($this->resources[$name]);
            }

            protected function loadBiomeNames(): array
            {
                return $this->biomes;
            }

            protected function compassHints(array|CharacterEntity $character): array
            {
                return $this->hints;
            }
        };
    }

    /** @return array<string,array{need:int,have:int,name:string}> */
    private function missing(string $name, int $need, int $have): array
    {
        return [$name => ['need' => $need, 'have' => $have, 'name' => $name]];
    }

    public function testNamesExactlyWhatIsMissing(): void
    {
        $svc = $this->service(['Древесина' => ['id' => 7, 'is_tradeable' => 1, 'buy_price' => 1.12, 'biome_id' => '1']]);

        $screen = $svc->describe([], $this->missing('Древесина', 240, 40), [], 1);

        $this->assertStringContainsString('Древесина', $screen['text']);
        $this->assertStringContainsString('нужно 240, есть 40', $screen['text']);
    }

    /** Главная ценность экрана: не «нет ресурсов», а «вот где это добывается». */
    public function testTellsWhereToGatherWithBiomeAndCompass(): void
    {
        $svc = $this->service(
            ['Базальт' => ['id' => 12, 'is_tradeable' => 0, 'buy_price' => 0.0, 'biome_id' => '5,9']],
            [5 => 'Вулканы', 9 => 'Пустыня'],
            [5 => 'редкий · северо-восток, далеко']
        );

        $text = $svc->describe([], $this->missing('Базальт', 20, 0), [], 1)['text'];

        $this->assertStringContainsString('Вулканы', $text);
        $this->assertStringContainsString('северо-восток', $text);
    }

    public function testShowsBuyCostAndDeepLinkForTradeableResource(): void
    {
        $svc = $this->service(['Смола деревьев' => ['id' => 31, 'is_tradeable' => 1, 'buy_price' => 2.5, 'biome_id' => '1']]);

        $screen = $svc->describe([], $this->missing('Смола деревьев', 40, 10), [], 1);

        // Докупить нужно 30 по 2.5 → 75.
        $this->assertStringContainsString('докупить 30', $screen['text']);
        $this->assertStringContainsString('75', $screen['text']);
        // Дефицит-ссылка несёт и ресурс, и недостачу: 30 — это то, что кнопка купит одним тапом.
        $this->assertStringContainsString('buy_need_31_30', json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** Нетоварное сырьё: честно говорим, что купить нельзя, и не рисуем кнопку. */
    public function testSaysWhenResourceCannotBeBought(): void
    {
        $svc = $this->service(['Обсидиан' => ['id' => 44, 'is_tradeable' => 0, 'buy_price' => 0.0, 'biome_id' => '5']]);

        $screen = $svc->describe([], $this->missing('Обсидиан', 5, 0), [], 1);

        $this->assertStringContainsString('не купить', $screen['text']);
        $this->assertStringNotContainsString('buy_need_44', json_encode($screen['keyboard'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * Порядок рядов — сообщение о ценностях: добыть раньше, чем купить. Обратный
     * порядок сообщал бы новичку, что платить — нормальный первый ход.
     */
    public function testGatherRowComesBeforeBuyRow(): void
    {
        $svc = $this->service(['Древесина' => ['id' => 7, 'is_tradeable' => 1, 'buy_price' => 1.12, 'biome_id' => '1']]);

        $rows = $svc->describe([], $this->missing('Древесина', 100, 0), [], 1)['keyboard']['inline_keyboard'];

        $gatherRow = null;
        $buyRow    = null;
        foreach ($rows as $i => $row) {
            foreach ($row as $btn) {
                if (($btn['callback_data'] ?? '') === 'gather') {
                    $gatherRow = $i;
                }
                if (str_starts_with($btn['callback_data'] ?? '', 'buy_need_')) {
                    $buyRow = $i;
                }
            }
        }

        $this->assertNotNull($gatherRow, 'кнопка добычи обязана быть всегда');
        $this->assertNotNull($buyRow);
        $this->assertLessThan($buyRow, $gatherRow);
    }

    /** Экран отказа не должен быть тупиком — выход есть даже без единого ресурса в базе. */
    public function testAlwaysOffersAWayForward(): void
    {
        $rows = $this->service([])->describe([], $this->missing('Неизвестный', 1, 0), [], 1)['keyboard']['inline_keyboard'];

        $callbacks = [];
        foreach ($rows as $row) {
            foreach ($row as $btn) {
                $callbacks[] = $btn['callback_data'] ?? '';
            }
        }

        $this->assertContains('gather', $callbacks);
        $this->assertContains('inventory', $callbacks);
    }

    public function testComponentIsMarkedAsCraftable(): void
    {
        $svc  = $this->service([]);
        $text = $svc->describe(
            [],
            [],
            ['metalFragments' => ['need' => 20, 'have' => 3, 'name' => 'Металл фрагменты']],
            1
        )['text'];

        $this->assertStringContainsString('Металл фрагменты', $text);
        $this->assertStringContainsString('верстак', $text);
    }

    /** Легаси Markdown не экранирует служебные символы — звёздочки обязаны быть парными. */
    public function testMarkdownStaysBalancedEvenWithDirtyNames(): void
    {
        $svc = $this->service(['Ржавый *лом*' => ['id' => 3, 'is_tradeable' => 1, 'buy_price' => 1.0, 'biome_id' => '1']]);

        $text = $svc->describe(
            [],
            $this->missing('Ржавый *лом*', 5, 1),
            [],
            2,
            ['item_name_rus' => 'Щит_прототип']
        )['text'];

        $this->assertSame(0, substr_count($text, '*') % 2, 'непарная * рвёт разметку и Telegram молча не доставит сообщение');
        $this->assertSame(0, substr_count($text, '_') % 2);
    }

    /** Длинный список не раздувает сообщение: подробно — первые несколько, дальше счётчик. */
    public function testLongListIsTruncatedHonestly(): void
    {
        $missing = [];
        for ($i = 1; $i <= 9; $i++) {
            $missing['Ресурс ' . $i] = ['need' => 10, 'have' => 0, 'name' => 'Ресурс ' . $i];
        }

        $text = $this->service([])->describe([], $missing, [], 1)['text'];

        $this->assertStringContainsString('и ещё 3 позиц', $text);
    }
}
