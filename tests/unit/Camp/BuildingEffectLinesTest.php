<?php

declare(strict_types=1);

namespace Tests\Unit\Camp;

use App\Controllers\Telegram\Commands\Actions\Camp\BaseDevelopmentAction;
use App\Services\BuildingEffects\BuildingEffectLines;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * w2-n4-tails-02 — расчёт строки эффекта переехал из `BaseDevelopmentAction` в {@see BuildingEffectLines}.
 *
 * {@see SNAPSHOT_BEFORE} снят с кода ДО переезда (`buildText()` «🏗 Развитие базы» на уровнях 0/1/2/5/9/10/11
 * по всем зданиям с эффектом и без, плюс пустая база) — после переезда текст обязан совпасть байт-в-байт.
 * Настройки обороны — дефолты (пустая `game_settings` из миграции).
 *
 * @internal
 */
final class BuildingEffectLinesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;

    private const NAMES = [
        'Workshop', 'BlastFurnace', 'Laboratory', 'RoboticsWorkshop', 'Greenhouse', 'SolarStation', 'TeleportationCenter',
        'HandPump', 'Gym', 'Warehouse', 'Arsenal', 'CommunicationTower', 'WoodenWall', 'BarbedFence', 'WatchTower', 'LeanTo',
    ];

    private const SNAPSHOT_BEFORE = <<<'JSON'
        {
         "0": "🏗 *Развитие базы*\n🏠 База: База (1, 2)\n\nСредний уровень построек: *0/10* (16 шт.)\n\n🔧 *Workshop* — ур. *0/10*\n    базовый эффект  →  ур.0+1: базовый эффект\n🔥 *BlastFurnace* — ур. *0/10*\n    базовый эффект  →  ур.0+1: базовый эффект\n🥼 *Laboratory* — ур. *0/10*\n    базовый эффект  →  ур.0+1: базовый эффект\n🤖 *RoboticsWorkshop* — ур. *0/10*\n    базовый эффект  →  ур.0+1: базовый эффект\n🌱 *Greenhouse* — ур. *0/10*\n    базовый эффект  →  ур.0+1: базовый эффект\n☀️ *SolarStation* — ур. *0/10*\n    базовый эффект  →  ур.0+1: базовый эффект\n🌀 *TeleportationCenter* — ур. *0/10*\n    базовый эффект  →  ур.0+1: базовый эффект\n🚰 *HandPump* — ур. *0/10*\n    ≈1 воды/мин (зависит от биома)  →  ур.1+1: ≈2\n🥊 *Gym* — ур. *0/10*\n    +0.01 силы / 30 мин  →  ур.1+1: +0.02\n🏚️ *Warehouse* — ур. *0/10*\n    закрытый рынок (покупка крафта) + бонус к продаже (флэт)\n⚔️ *Arsenal* — ур. *0/10*\n    хранение и экипировка оружия и брони (флэт)\n📢 *CommunicationTower* — ур. *0/10*\n    радиус роботов: 100 клеток  →  ур.1+1: 200 клеток\n🪵 *WoodenWall* — ур. *0/10*\n    −15% урона по базе при рейде (макс 40%)  →  ур.1+1: −18%\n🌵 *BarbedFence* — ур. *0/10*\n    +3 контрурона атакующему/раунд  →  ур.1+1: +4\n🗼 *WatchTower* — ур. *0/10*\n    +8% инициативы в обороне + алерт о подходе врага  →  ур.1+1: +10%\n🏗 *LeanTo* — ур. *0/10*\n\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._",
         "1": "🏗 *Развитие базы*\n🏠 База: База (1, 2)\n\nСредний уровень построек: *1/10* (16 шт.)\n\n🔧 *Workshop* — ур. *1/10*\n    базовый эффект  →  ур.1+1: базовый эффект\n🔥 *BlastFurnace* — ур. *1/10*\n    базовый эффект  →  ур.1+1: базовый эффект\n🥼 *Laboratory* — ур. *1/10*\n    базовый эффект  →  ур.1+1: базовый эффект\n🤖 *RoboticsWorkshop* — ур. *1/10*\n    базовый эффект  →  ур.1+1: базовый эффект\n🌱 *Greenhouse* — ур. *1/10*\n    базовый эффект  →  ур.1+1: базовый эффект\n☀️ *SolarStation* — ур. *1/10*\n    базовый эффект  →  ур.1+1: базовый эффект\n🌀 *TeleportationCenter* — ур. *1/10*\n    базовый эффект  →  ур.1+1: базовый эффект\n🚰 *HandPump* — ур. *1/10*\n    ≈1 воды/мин (зависит от биома)  →  ур.1+1: ≈2\n🥊 *Gym* — ур. *1/10*\n    +0.01 силы / 30 мин  →  ур.1+1: +0.02\n🏚️ *Warehouse* — ур. *1/10*\n    закрытый рынок (покупка крафта) + бонус к продаже (флэт)\n⚔️ *Arsenal* — ур. *1/10*\n    хранение и экипировка оружия и брони (флэт)\n📢 *CommunicationTower* — ур. *1/10*\n    радиус роботов: 100 клеток  →  ур.1+1: 200 клеток\n🪵 *WoodenWall* — ур. *1/10*\n    −15% урона по базе при рейде (макс 40%)  →  ур.1+1: −18%\n🌵 *BarbedFence* — ур. *1/10*\n    +3 контрурона атакующему/раунд  →  ур.1+1: +4\n🗼 *WatchTower* — ур. *1/10*\n    +8% инициативы в обороне + алерт о подходе врага  →  ур.1+1: +10%\n🏗 *LeanTo* — ур. *1/10*\n\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._",
         "2": "🏗 *Развитие базы*\n🏠 База: База (1, 2)\n\nСредний уровень построек: *2/10* (16 шт.)\n\n🔧 *Workshop* — ур. *2/10*\n    базовый эффект  →  ур.2+1: базовый эффект\n🔥 *BlastFurnace* — ур. *2/10*\n    базовый эффект  →  ур.2+1: базовый эффект\n🥼 *Laboratory* — ур. *2/10*\n    базовый эффект  →  ур.2+1: базовый эффект\n🤖 *RoboticsWorkshop* — ур. *2/10*\n    базовый эффект  →  ур.2+1: базовый эффект\n🌱 *Greenhouse* — ур. *2/10*\n    базовый эффект  →  ур.2+1: базовый эффект\n☀️ *SolarStation* — ур. *2/10*\n    базовый эффект  →  ур.2+1: базовый эффект\n🌀 *TeleportationCenter* — ур. *2/10*\n    базовый эффект  →  ур.2+1: базовый эффект\n🚰 *HandPump* — ур. *2/10*\n    ≈2 воды/мин (зависит от биома)  →  ур.2+1: ≈3\n🥊 *Gym* — ур. *2/10*\n    +0.02 силы / 30 мин  →  ур.2+1: +0.03\n🏚️ *Warehouse* — ур. *2/10*\n    закрытый рынок (покупка крафта) + бонус к продаже (флэт)\n⚔️ *Arsenal* — ур. *2/10*\n    хранение и экипировка оружия и брони (флэт)\n📢 *CommunicationTower* — ур. *2/10*\n    радиус роботов: 200 клеток  →  ур.2+1: 300 клеток\n🪵 *WoodenWall* — ур. *2/10*\n    −18% урона по базе при рейде (макс 40%)  →  ур.2+1: −21%\n🌵 *BarbedFence* — ур. *2/10*\n    +4 контрурона атакующему/раунд  →  ур.2+1: +4\n🗼 *WatchTower* — ур. *2/10*\n    +10% инициативы в обороне + алерт о подходе врага  →  ур.2+1: +11%\n🏗 *LeanTo* — ур. *2/10*\n\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._",
         "5": "🏗 *Развитие базы*\n🏠 База: База (1, 2)\n\nСредний уровень построек: *5/10* (16 шт.)\n\n🔧 *Workshop* — ур. *5/10*\n    базовый эффект  →  ур.5+1: базовый эффект\n🔥 *BlastFurnace* — ур. *5/10*\n    базовый эффект  →  ур.5+1: базовый эффект\n🥼 *Laboratory* — ур. *5/10*\n    базовый эффект  →  ур.5+1: базовый эффект\n🤖 *RoboticsWorkshop* — ур. *5/10*\n    базовый эффект  →  ур.5+1: базовый эффект\n🌱 *Greenhouse* — ур. *5/10*\n    базовый эффект  →  ур.5+1: базовый эффект\n☀️ *SolarStation* — ур. *5/10*\n    базовый эффект  →  ур.5+1: базовый эффект\n🌀 *TeleportationCenter* — ур. *5/10*\n    базовый эффект  →  ур.5+1: базовый эффект\n🚰 *HandPump* — ур. *5/10*\n    ≈7 воды/мин (зависит от биома)  →  ур.5+1: ≈9\n🥊 *Gym* — ур. *5/10*\n    +0.07 силы / 30 мин  →  ур.5+1: +0.09\n🏚️ *Warehouse* — ур. *5/10*\n    закрытый рынок (покупка крафта) + бонус к продаже (флэт)\n⚔️ *Arsenal* — ур. *5/10*\n    хранение и экипировка оружия и брони (флэт)\n📢 *CommunicationTower* — ур. *5/10*\n    радиус роботов: 500 клеток  →  ур.5+1: 600 клеток\n🪵 *WoodenWall* — ур. *5/10*\n    −27% урона по базе при рейде (макс 40%)  →  ур.5+1: −30%\n🌵 *BarbedFence* — ур. *5/10*\n    +5 контрурона атакующему/раунд  →  ур.5+1: +6\n🗼 *WatchTower* — ур. *5/10*\n    +14% инициативы в обороне + алерт о подходе врага  →  ур.5+1: +16%\n🏗 *LeanTo* — ур. *5/10*\n\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._",
         "9": "🏗 *Развитие базы*\n🏠 База: База (1, 2)\n\nСредний уровень построек: *9/10* (16 шт.)\n\n🔧 *Workshop* — ур. *9/10*\n    базовый эффект  →  ур.9+1: базовый эффект\n🔥 *BlastFurnace* — ур. *9/10*\n    базовый эффект  →  ур.9+1: базовый эффект\n🥼 *Laboratory* — ур. *9/10*\n    базовый эффект  →  ур.9+1: базовый эффект\n🤖 *RoboticsWorkshop* — ур. *9/10*\n    базовый эффект  →  ур.9+1: базовый эффект\n🌱 *Greenhouse* — ур. *9/10*\n    базовый эффект  →  ур.9+1: базовый эффект\n☀️ *SolarStation* — ур. *9/10*\n    базовый эффект  →  ур.9+1: базовый эффект\n🌀 *TeleportationCenter* — ур. *9/10*\n    базовый эффект  →  ур.9+1: базовый эффект\n🚰 *HandPump* — ур. *9/10*\n    ≈17 воды/мин (зависит от биома)  →  ур.9+1: ≈20\n🥊 *Gym* — ур. *9/10*\n    +0.14 силы / 30 мин  →  ур.9+1: +0.15\n🏚️ *Warehouse* — ур. *9/10*\n    закрытый рынок (покупка крафта) + бонус к продаже (флэт)\n⚔️ *Arsenal* — ур. *9/10*\n    хранение и экипировка оружия и брони (флэт)\n📢 *CommunicationTower* — ур. *9/10*\n    радиус роботов: 900 клеток  →  ур.9+1: 1000 клеток\n🪵 *WoodenWall* — ур. *9/10*\n    −39% урона по базе при рейде (макс 40%)  →  ур.9+1: −40%\n🌵 *BarbedFence* — ур. *9/10*\n    +8 контрурона атакующему/раунд  →  ур.9+1: +8\n🗼 *WatchTower* — ур. *9/10*\n    +21% инициативы в обороне + алерт о подходе врага  →  ур.9+1: +22%\n🏗 *LeanTo* — ур. *9/10*\n\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._",
         "10": "🏗 *Развитие базы*\n🏠 База: База (1, 2)\n\nСредний уровень построек: *10/10* (16 шт.)\n\n🔧 *Workshop* — ур. *10/10*\n    базовый эффект\n🔥 *BlastFurnace* — ур. *10/10*\n    базовый эффект\n🥼 *Laboratory* — ур. *10/10*\n    базовый эффект\n🤖 *RoboticsWorkshop* — ур. *10/10*\n    базовый эффект\n🌱 *Greenhouse* — ур. *10/10*\n    базовый эффект\n☀️ *SolarStation* — ур. *10/10*\n    базовый эффект\n🌀 *TeleportationCenter* — ур. *10/10*\n    базовый эффект\n🚰 *HandPump* — ур. *10/10*\n    ≈20 воды/мин (зависит от биома)\n🥊 *Gym* — ур. *10/10*\n    +0.15 силы / 30 мин\n🏚️ *Warehouse* — ур. *10/10*\n    закрытый рынок (покупка крафта) + бонус к продаже (флэт)\n⚔️ *Arsenal* — ур. *10/10*\n    хранение и экипировка оружия и брони (флэт)\n📢 *CommunicationTower* — ур. *10/10*\n    радиус роботов: 1000 клеток\n🪵 *WoodenWall* — ур. *10/10*\n    −40% урона по базе при рейде (макс 40%)\n🌵 *BarbedFence* — ур. *10/10*\n    +8 контрурона атакующему/раунд\n🗼 *WatchTower* — ур. *10/10*\n    +22% инициативы в обороне + алерт о подходе врага\n🏗 *LeanTo* — ур. *10/10*\n\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._",
         "11": "🏗 *Развитие базы*\n🏠 База: База (1, 2)\n\nСредний уровень построек: *11/10* (16 шт.)\n\n🔧 *Workshop* — ур. *11/10*\n    базовый эффект\n🔥 *BlastFurnace* — ур. *11/10*\n    базовый эффект\n🥼 *Laboratory* — ур. *11/10*\n    базовый эффект\n🤖 *RoboticsWorkshop* — ур. *11/10*\n    базовый эффект\n🌱 *Greenhouse* — ур. *11/10*\n    базовый эффект\n☀️ *SolarStation* — ур. *11/10*\n    базовый эффект\n🌀 *TeleportationCenter* — ур. *11/10*\n    базовый эффект\n🚰 *HandPump* — ур. *11/10*\n    ≈20 воды/мин (зависит от биома)\n🥊 *Gym* — ур. *11/10*\n    +0.15 силы / 30 мин\n🏚️ *Warehouse* — ур. *11/10*\n    закрытый рынок (покупка крафта) + бонус к продаже (флэт)\n⚔️ *Arsenal* — ур. *11/10*\n    хранение и экипировка оружия и брони (флэт)\n📢 *CommunicationTower* — ур. *11/10*\n    радиус роботов: 1000 клеток\n🪵 *WoodenWall* — ур. *11/10*\n    −40% урона по базе при рейде (макс 40%)\n🌵 *BarbedFence* — ур. *11/10*\n    +8 контрурона атакующему/раунд\n🗼 *WatchTower* — ур. *11/10*\n    +22% инициативы в обороне + алерт о подходе врага\n🏗 *LeanTo* — ур. *11/10*\n\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._",
         "empty": "🏗 *Развитие базы*\n🏠 База: База (1, 2)\n\nУ тебя пока нет построек.\n\n_Разбей лагерь («База» → «🏕 Разбить лагерь») и строй: каждый уровень постройки усиливает её эффект._"
        }
        JSON;

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $db->query('DROP TABLE IF EXISTS game_settings');
        require_once APPPATH . 'Database/Migrations/2026-05-19-100000_CreateGameSettingsTable.php';
        (new \App\Database\Migrations\CreateGameSettingsTable(Database::forge()))->up();
        service('cache')->clean();
    }

    protected function tearDown(): void
    {
        Database::connect()->query('DROP TABLE IF EXISTS game_settings');
        service('cache')->clean();
        parent::tearDown();
    }

    public function testBaseDevelopmentTextIsByteIdenticalToBeforeTheMove(): void
    {
        $before = json_decode(self::SNAPSHOT_BEFORE, true);
        $this->assertIsArray($before);

        $class  = new \ReflectionClass(BaseDevelopmentAction::class);
        $action = $class->newInstanceWithoutConstructor();
        $prop   = $class->getProperty('lines');
        $prop->setAccessible(true);
        $prop->setValue($action, new BuildingEffectLines());
        $build = $class->getMethod('buildText');
        $build->setAccessible(true);

        foreach ([0, 1, 2, 5, 9, 10, 11] as $lvl) {
            $built = array_map(static fn (string $n): array => ['name_en' => $n, 'name_ru' => $n, 'lvl' => $lvl], self::NAMES);
            $this->assertSame($before[(string) $lvl], $build->invoke($action, $built, 'База (1, 2)'), "уровень {$lvl}");
        }
        $this->assertSame($before['empty'], $build->invoke($action, [], 'База (1, 2)'));
    }

    public function testEffectAtGivesOnePhrasePerLevelAndNullWithoutEffect(): void
    {
        $lines = new BuildingEffectLines();

        $this->assertSame('базовый эффект', $lines->effectAt('Workshop', 1));
        $this->assertSame('≈1 воды/мин (зависит от биома)', $lines->effectAt('HandPump', 1));
        $this->assertSame('≈2 воды/мин (зависит от биома)', $lines->effectAt('HandPump', 2));
        $this->assertSame('радиус роботов: 300 клеток', $lines->effectAt('CommunicationTower', 3));
        $this->assertNull($lines->effectAt('LeanTo', 1));
        $this->assertNull($lines->effectAt('', 1));

        // Множители — из GameSettings (здесь — подставной читатель): проценты и строка «Развития базы».
        $tuned = new BuildingEffectLines(new \App\Services\BuildingEffects\BuildingEffectsService(null, null, static fn (string $key): ?float => [
            'building.workshop.l2.craft_time_multiplier'  => 0.88,
            'building.workshop.l3.craft_time_multiplier'  => 0.8,
            'building.greenhouse.l2.harvest_yield_multiplier' => 1.15,
        ][$key] ?? null));
        $this->assertSame('−12% время крафта', $tuned->effectAt('Workshop', 2));
        $this->assertSame('−20% время крафта', $tuned->effectAt('Workshop', 3));
        $this->assertSame('+15% урожай', $tuned->effectAt('Greenhouse', 2));
        $this->assertSame('−12% время крафта  →  ур.2+1: −20% время крафта', $tuned->developmentLine('Workshop', 2));

        // Фраза уровня совпадает с началом строки «Развития базы» — один расчёт на оба экрана.
        foreach (self::NAMES as $name) {
            foreach ([1, 4, 10] as $lvl) {
                $now = $lines->effectAt($name, $lvl);
                $dev = $lines->developmentLine($name, $lvl);
                $this->assertSame($now === null, $dev === null, "{$name} {$lvl}");
                if ($now !== null) {
                    $this->assertStringStartsWith($now, (string) $dev, "{$name} {$lvl}");
                    $this->assertDoesNotMatchRegularExpression('/[*_`\[]/', $now, "markdown-safe: {$name} {$lvl}");
                }
            }
        }
    }
}
