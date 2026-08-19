<?php

declare(strict_types=1);

namespace Tests\Unit\TaskHandlers;

use App\Models\BaseStorageModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\CharacterResourceModel;
use App\Models\GameSettingsModel;
use App\Models\ResourceModel;
use App\Services\GameSettings\GameSettingsService;
use App\TaskHandlers\GreenhouseProductionHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * ADR-171 (story storage-craft-insurance-04) — теплица считает воду пулом
 * (рюкзак + склад базы), не только рюкзак. Баг: игрок с 7887 воды на складе
 * получал «осталось 3 единицы», потому что handler читал только
 * `character_resources`.
 *
 * Отклонение от `ResourcePoolService`: тот гейтит склад тем, стоит ли ПЕРСОНАЖ
 * на базе прямо сейчас (`BaseCheckService::checkBaseStatus`). Для крона теплицы
 * это неверно — теплица работает и пока хозяин гуляет в поле, склад её базы
 * доступен ей вне зависимости от текущей позиции игрока. Handler поэтому читает
 * `base_storage` напрямую (`BaseStorageModel::quantityFor/withdraw`), уважая
 * только killswitch `storage.pool_enabled`, а не гейт «на базе».
 *
 * `notifyWaterShortage` подменена seam'ом (без живого Telegram, CI без бот-ключа
 * — memory `feedback_taskhandler_telegram_init_in_tests`).
 *
 * Cooldown-дедуп уведомлений живёт в CI4-кэше (`service('cache')`), НЕ в
 * `character_resources` — ревью team-lead поймал, что нулевая строка-заглушка (заведённая
 * ради cooldown-метки, когда рюкзак пуст) всплывала бы игроку на экране инвентаря
 * `ResourcesGatheredAction` («📦 Вода | 0 шт», тот экран не фильтрует `quantity > 0`).
 * Ключ кэша `greenhouse_water_shortage_{characterId}` дублирует приватную константу
 * `GreenhouseProductionHandler::NOTIFY_CACHE_PREFIX` — если она поменяется, эти тесты
 * придётся поправить вместе с ней.
 *
 * Story storage-craft-insurance-10 (второй заход, после красной CI-сборки на реальном
 * дампе): первая версия этого файла безусловно дропала `game_settings`/`buildings`/
 * `character_buildings`/`resources`/`character_resources`/`base_storage` — общие имена,
 * которыми пользуются десятки других DB-тестов. Попытка «резервной копии» через
 * `RENAME TABLE` на CI (где эти таблицы реально мигрированы) сама всё сломала — see
 * git history. Правильное решение проще и надёжнее: вообще не трогать таблицы с общими
 * именами. `GreenhouseProductionHandler` теперь принимает все свои модели через
 * конструктор (тот же DI-паттерн, что уже был у `$cfg`/`$buildingEffects`), и тест
 * подставляет модели, указывающие через `Model::setTable()` на уникальные таблицы с
 * префиксом `ghwt_` — они не пересекаются ни с одним другим тестом ни при каких
 * обстоятельствах, поэтому safe drop+create без всякого backup/restore.
 *
 * @internal
 */
final class GreenhouseProductionWaterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    /** Уникальный префикс — гарантия, что тест никогда не заденет общие таблицы других тестов. */
    private const PREFIX = 'ghwt_';

    private const TABLE_GAME_SETTINGS       = self::PREFIX . 'game_settings';
    private const TABLE_BUILDINGS           = self::PREFIX . 'buildings';
    private const TABLE_CHARACTER_BUILDINGS = self::PREFIX . 'character_buildings';
    private const TABLE_RESOURCES           = self::PREFIX . 'resources';
    private const TABLE_CHARACTER_RESOURCES = self::PREFIX . 'character_resources';
    private const TABLE_BASE_STORAGE        = self::PREFIX . 'base_storage';

    private const TABLES = [
        self::TABLE_GAME_SETTINGS, self::TABLE_BUILDINGS, self::TABLE_CHARACTER_BUILDINGS,
        self::TABLE_RESOURCES, self::TABLE_CHARACTER_RESOURCES, self::TABLE_BASE_STORAGE,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanCache();
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $db->query('CREATE TABLE ' . self::TABLE_GAME_SETTINGS . ' (id INT AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(191), value_type VARCHAR(16) NULL, value_int INT NULL, value_bool TINYINT NULL, value_float DOUBLE NULL, value_string TEXT NULL)');
        $db->query('CREATE TABLE ' . self::TABLE_BUILDINGS . ' (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(191))');
        $db->query('CREATE TABLE ' . self::TABLE_CHARACTER_BUILDINGS . ' (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, building_id INT, level INT DEFAULT 1)');
        $db->query('CREATE TABLE ' . self::TABLE_RESOURCES . ' (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(191))');
        $db->query('CREATE TABLE ' . self::TABLE_CHARACTER_RESOURCES . ' (id INT AUTO_INCREMENT PRIMARY KEY, id_characters INT, id_resources INT, quantity INT DEFAULT 0, custom_data TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $db->query('CREATE TABLE ' . self::TABLE_BASE_STORAGE . ' (id INT AUTO_INCREMENT PRIMARY KEY, character_id INT, resource_id INT, quantity INT DEFAULT 0, arrived_from_cell INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');

        $db->table(self::TABLE_BUILDINGS)->insert(['id' => 1, 'name_en' => 'Greenhouse']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 1, 'name_en' => 'Water']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 2, 'name_en' => 'Fruit']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 3, 'name_en' => 'Berries']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 4, 'name_en' => 'Mushrooms']);
        $db->table(self::TABLE_RESOURCES)->insert(['id' => 5, 'name_en' => 'Crops']);
    }

    protected function tearDown(): void
    {
        $db = Database::connect('tests');
        foreach (self::TABLES as $t) {
            $db->query("DROP TABLE IF EXISTS {$t}");
        }
        $this->cleanCache();
        parent::tearDown();
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

    private function setPoolEnabled(bool $enabled): void
    {
        Database::connect('tests')->table(self::TABLE_GAME_SETTINGS)->insert([
            'setting_key' => 'storage.pool_enabled',
            'value_type'  => 'bool',
            'value_bool'  => $enabled ? 1 : 0,
        ]);
        $this->cleanCache();
    }

    /** @return int id вставленной строки character_buildings (конкретный экземпляр постройки) */
    private function makeGreenhouse(int $characterId, int $level = 1): int
    {
        $db = Database::connect('tests');
        $db->table(self::TABLE_CHARACTER_BUILDINGS)->insert([
            'character_id' => $characterId,
            'building_id'  => 1,
            'level'        => $level,
        ]);

        return (int) $db->insertID();
    }

    private function setBackpackWater(int $characterId, int $qty): void
    {
        Database::connect('tests')->table(self::TABLE_CHARACTER_RESOURCES)->insert([
            'id_characters' => $characterId,
            'id_resources'  => 1,
            'quantity'      => $qty,
        ]);
    }

    /**
     * Дублирует ключевую схему `GreenhouseProductionHandler::NOTIFY_CACHE_PREFIX` — ключ
     * по id конкретной постройки (`character_buildings.id`), не по персонажу.
     */
    private function markRecentlyNotified(int $buildingInstanceId): void
    {
        $cache = service('cache');
        if (is_object($cache) && method_exists($cache, 'save')) {
            $cache->save('greenhouse_water_shortage_' . $buildingInstanceId, time(), 1800);
        }
    }

    private function setStorageWater(int $characterId, int $qty): void
    {
        Database::connect('tests')->table(self::TABLE_BASE_STORAGE)->insert([
            'character_id' => $characterId,
            'resource_id'  => 1,
            'quantity'     => $qty,
        ]);
    }

    private function backpackQty(int $characterId): int
    {
        $row = $this->backpackRow($characterId);
        return $row ? (int) $row['quantity'] : 0;
    }

    /** @return array<string,mixed>|null сырая строка character_resources (Вода) — null, если строки нет вовсе */
    private function backpackRow(int $characterId): ?array
    {
        $row = Database::connect('tests')->table(self::TABLE_CHARACTER_RESOURCES)
            ->where('id_characters', $characterId)->where('id_resources', 1)->get()->getRowArray();
        return $row ?: null;
    }

    private function storageQty(int $characterId): int
    {
        $row = Database::connect('tests')->table(self::TABLE_BASE_STORAGE)
            ->where('character_id', $characterId)->where('resource_id', 1)->get()->getRowArray();
        return $row ? (int) $row['quantity'] : 0;
    }

    /**
     * Собирает handler со всеми моделями, указывающими на приватные `ghwt_*` таблицы —
     * ни разу не задевает `game_settings`/`buildings`/`character_buildings`/`resources`/
     * `character_resources`/`base_storage`, которыми пользуются другие DB-тесты. Модели
     * персонажа/telegram-пользователя не переопределены: `notifyWaterShortage` ниже
     * подменена seam'ом и до них дело не доходит.
     */
    private function handler(): GreenhouseProductionHandler
    {
        $characterBuildingModel = (new CharacterBuildingModel())->setTable(self::TABLE_CHARACTER_BUILDINGS);
        $characterResourceModel = (new CharacterResourceModel())->setTable(self::TABLE_CHARACTER_RESOURCES);
        $buildingModel          = (new BuildingModel())->setTable(self::TABLE_BUILDINGS);
        $resourceModel          = (new ResourceModel())->setTable(self::TABLE_RESOURCES);
        $baseStorageModel       = (new BaseStorageModel())->setTable(self::TABLE_BASE_STORAGE);
        $gameSettings           = new GameSettingsService((new GameSettingsModel())->setTable(self::TABLE_GAME_SETTINGS));

        $h = new class(
            null,
            null,
            $characterBuildingModel,
            $characterResourceModel,
            $buildingModel,
            $resourceModel,
            null,
            null,
            $baseStorageModel,
            $gameSettings
        ) extends GreenhouseProductionHandler {
            /** @var list<array{0:int,1:int}> */
            public array $captured = [];

            protected function notifyWaterShortage(int $characterId, int $waterQuantity): void
            {
                $this->captured[] = [$characterId, $waterQuantity];
            }
        };

        return $h;
    }

    // ----------------------------------------------------------------

    public function testWaterPoolCombinesBackpackAndStorage(): void
    {
        // L1 теплица требует 1 воды (GameBalance::greenhouseLevels[1]). Рюкзак пуст,
        // всё на складе — раньше это давало «нет воды», теперь пул должен покрыть.
        $c = 1;
        $this->makeGreenhouse($c, 1);
        $this->setStorageWater($c, 7887);

        $this->handler()->handle();

        $this->assertSame(7886, $this->storageQty($c), 'списание (1) ушло со склада');
        $this->assertSame(0, $this->backpackQty($c), 'в рюкзаке по-прежнему пусто');
    }

    public function testConsumptionDrainsBackpackBeforeStorage(): void
    {
        $c = 2;
        $this->makeGreenhouse($c, 1);
        $this->setBackpackWater($c, 100);
        $this->setStorageWater($c, 1000);

        $this->handler()->handle();

        // L1 требует 1 воды — целиком из рюкзака, склад не тронут.
        $this->assertSame(99, $this->backpackQty($c));
        $this->assertSame(1000, $this->storageQty($c));
    }

    public function testStorageCoversShortfallAfterBackpackDrained(): void
    {
        $c = 3;
        $this->makeGreenhouse($c, 4); // L4 требует 4 воды
        $this->setBackpackWater($c, 1); // в рюкзаке только 1
        $this->setStorageWater($c, 500);

        $this->handler()->handle();

        $this->assertSame(0, $this->backpackQty($c), 'рюкзак вычерпан');
        $this->assertSame(497, $this->storageQty($c), 'недостача (3) ушла со склада');
        $this->assertNull($this->backpackRow($c), 'полный расход не оставляет строку с нулём (путь СПИСАНИЯ)');
    }

    /**
     * Acceptance: полный расход воды из рюкзака НЕ оставляет строку с нулём. В отличие от
     * `testShortageWarningFiresWhenBackpackEmptyButPoolLowAndLeavesNoZeroRow` (там строки
     * не было вовсе — доказывает только путь уведомления), здесь строка ЕСТЬ до списания
     * и ровно вычерпывается путём СПИСАНИЯ (fromStorage=0, склад не участвует).
     */
    public function testFullBackpackDrainByConsumptionLeavesNoZeroRow(): void
    {
        $c = 10;
        $this->makeGreenhouse($c, 1); // L1 требует 1 воды
        $this->setBackpackWater($c, 1); // рюкзак = ровно требуемое
        $this->setStorageWater($c, 500); // склад есть, но не должен понадобиться

        $this->handler()->handle();

        $this->assertNull($this->backpackRow($c), 'списание вычерпало рюкзак — строка удалена, не осталась нулём');
        $this->assertSame(500, $this->storageQty($c), 'склад не тронут — хватило рюкзака');
    }

    public function testNoShortageWarningWhenPoolIsSufficient(): void
    {
        $c = 4;
        $this->makeGreenhouse($c, 1);
        $this->setBackpackWater($c, 1); // <= threshold(3) в рюкзаке
        $this->setStorageWater($c, 7887); // но пул огромный

        $h = $this->handler();
        $h->handle();

        $this->assertSame([], $h->captured, 'пул выше threshold — предупреждение не шлётся');
    }

    public function testShortageWarningFiresOnPoolAndReportsTotal(): void
    {
        $c = 5;
        $this->makeGreenhouse($c, 1);
        $this->setBackpackWater($c, 2);
        $this->setStorageWater($c, 0); // пул = 2 <= threshold(3)

        $h = $this->handler();
        $h->handle();

        $this->assertSame([[5, 2]], $h->captured);
    }

    public function testShortageWarningFiresWhenBackpackEmptyButPoolLowAndLeavesNoZeroRow(): void
    {
        // Рюкзак пуст (строки нет вовсе — как после «выложить всё на склад»),
        // склад тоже почти пуст: пул=2 <= threshold(3).
        $c = 6;
        $this->makeGreenhouse($c, 1);
        $this->setStorageWater($c, 2);

        $h = $this->handler();
        $h->handle();

        $this->assertSame([[6, 2]], $h->captured, 'уведомление ушло даже без строки в character_resources');

        // Cooldown-метка теперь в кэше, не в character_resources — не должно остаться нулевой
        // строки, которая протекла бы в UI инвентаря (ResourcesGatheredAction без фильтра qty>0).
        $this->assertNull($this->backpackRow($c), 'не заведена заглушка-строка ради cooldown-метки');
    }

    public function testShortageWarningCooldownSuppressesRepeat(): void
    {
        $c = 7;
        $buildingId = $this->makeGreenhouse($c, 1);
        $this->setBackpackWater($c, 2);
        $this->setStorageWater($c, 0);
        $this->markRecentlyNotified($buildingId);

        $h = $this->handler();
        $h->handle();

        $this->assertSame([], $h->captured, 'cooldown не истёк — повторно не шлём');
    }

    /** Дубли построек разрешены намеренно — у одного игрока может стоять 2+ Теплицы одновременно. */
    public function testCooldownIsPerBuildingNotPerCharacter(): void
    {
        $c = 9;
        $firstGreenhouseId = $this->makeGreenhouse($c, 1);
        $this->makeGreenhouse($c, 1); // вторая Теплица того же персонажа
        $this->setBackpackWater($c, 2); // общий рюкзак: пул=2 <= threshold(3) для ОБЕИХ
        $this->setStorageWater($c, 0);

        // Первой уже недавно предупреждали, второй — ещё нет.
        $this->markRecentlyNotified($firstGreenhouseId);

        $h = $this->handler();
        $h->handle();

        // Ровно одно уведомление — от той теплицы, у которой не было своей cooldown-метки.
        // Пул общий (один рюкзак на персонажа), первая теплица его слегка подъедает при
        // списании — поэтому конкретное число pool здесь не фиксируем, важен факт: одно
        // уведомление, не ноль (общий cooldown задушил бы обе) и не два (нет общего окна).
        $this->assertCount(1, $h->captured, 'cooldown первой Теплицы не должен глушить вторую');
        $this->assertSame(9, $h->captured[0][0]);
    }

    public function testPoolDisabledByKillswitchFallsBackToBackpackOnly(): void
    {
        $this->setPoolEnabled(false);
        $c = 8;
        $this->makeGreenhouse($c, 4); // L4 требует 4 воды — не хватает на списание
        $this->setBackpackWater($c, 1);
        $this->setStorageWater($c, 7887);

        $h = $this->handler();
        $h->handle();

        // Пул выключен → склад не виден: списание не проходит, зато уведомление уходит
        // с карманным числом (1), а склад остаётся нетронутым — ровно сегодняшнее поведение.
        $this->assertSame(1, $this->backpackQty($c), 'списание не прошло — только рюкзак виден');
        $this->assertSame(7887, $this->storageQty($c), 'склад не тронут при выключенном killswitch');
        $this->assertSame([[8, 1]], $h->captured);
    }
}
