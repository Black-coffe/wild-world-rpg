<?php

declare(strict_types=1);

namespace Tests\Unit\Player;

use App\Services\Player\DebuffService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Debuffs;

/**
 * Гейт слоя «раны, которые не лечатся едой».
 *
 * Повод — аудит 18.08.2026 по сигналу игрока «непонятен смысл лекарств: быстрее
 * отжираться консервами». Замер подтвердил: лекарства и еда конкурировали на одной
 * оси, и еда выигрывала (Аптечка 40/20 против Сытного рагу 45/55, Бинт лечил 2 HP).
 *
 * Тест держит контракт, на котором стоит вся правка:
 *  - у каждой раны есть предмет, который её снимает (иначе состояние — тупик);
 *  - еда НЕ снимает ни одной раны (иначе ниша лекарств снова исчезает);
 *  - выключенный killswitch гасит слой целиком, даже если строки в БД есть.
 */
final class DebuffServiceTest extends CIUnitTestCase
{
    /** Еда, которая по замеру прода перекрывала лекарства. */
    private const FOOD_ITEMS = ['HeartyStew', 'FishPreserve', 'StewPreserve', 'MushroomSoup', 'DryRation', 'WinterPreserves'];

    public function testEveryWoundHasACure(): void
    {
        $this->assertNotEmpty(Debuffs::keys(), 'Каталог ран не может быть пустым.');

        foreach (Debuffs::CATALOG as $key => $meta) {
            $this->assertNotEmpty($meta['cured_by'], "Рана «{$key}» ничем не снимается — это тупик, а не механика.");
            $this->assertContains($meta['effect'], ['hp_drain', 'heal_cap', 'slowdown'], "Неизвестный тип эффекта у «{$key}».");
            $this->assertNotSame('', trim($meta['what']), "У раны «{$key}» пустое описание — media-off требует полного текста.");
            $this->assertNotSame('', trim($meta['cure_hint']), "У раны «{$key}» не сказано, чем лечиться.");
        }
    }

    /**
     * 🔴 Смысл всей правки: еда кормит, но рану не снимает. Если этот тест падает —
     * лекарства снова не нужны.
     */
    public function testFoodCuresNothing(): void
    {
        foreach (self::FOOD_ITEMS as $food) {
            $this->assertSame(
                [],
                Debuffs::curedByItem($food),
                "Еда «{$food}» снимает рану — тогда лекарства опять теряют смысл."
            );
        }
    }

    /**
     * Лекарства, которые до правки были мусором (Бинт лечил 2 HP, Антисептик — 4),
     * обязаны что-то снимать: это их новая причина существовать.
     */
    public function testUselessMedicinesGotAPurpose(): void
    {
        foreach (['Bandage', 'Antiseptic', 'Regenerator', 'FirstAidKit'] as $medicine) {
            $this->assertNotEmpty(
                Debuffs::curedByItem($medicine),
                "Лекарство «{$medicine}» по-прежнему ничего не снимает."
            );
        }
    }

    /** Аптечка — универсальная: снимает любую рану, потому и дороже прочих. */
    public function testFirstAidKitCuresEverything(): void
    {
        $this->assertSame(
            Debuffs::keys(),
            Debuffs::curedByItem('FirstAidKit'),
            'Аптечка обещает быть универсальной — она должна снимать все состояния.'
        );
    }

    /**
     * Выключенный killswitch гасит слой целиком: ни выдачи, ни эффектов. Строки в
     * БД при этом остаются — иначе повторное включение потеряло бы состояния игроков.
     */
    public function testKillswitchOffDisablesEverything(): void
    {
        $service = new DebuffService(null, $this->settingsWith(['debuff.enabled' => 0]));

        $this->assertFalse($service->enabled());
        $this->assertSame([], $service->active(1));
        $this->assertFalse($service->apply(1, Debuffs::POISON, 'test'));
        $this->assertSame([], $service->cureByItem(1, 'Bandage'));
        $this->assertSame(1.0, $service->healCapFactor(1), 'При выключенном слое потолка лечения быть не должно.');
        $this->assertSame(1.0, $service->slowdownFactor(1), 'При выключенном слое замедления быть не должно.');
    }

    /**
     * Числа урона и тика читаются из админки, а не из кода: ребаланс не должен
     * требовать деплоя (ADR-024).
     */
    public function testPoisonNumbersComeFromSettings(): void
    {
        $service = new DebuffService(null, $this->settingsWith([
            'debuff.enabled'              => 1,
            'debuff.poison.hp_per_tick'   => 5,
            'debuff.poison.tick_minutes'  => 30,
        ]));

        $this->assertSame(5, $service->poisonDamagePerTick(1));
        $this->assertSame(15, $service->poisonDamagePerTick(3), 'Тяжесть множит урон тика.');
        $this->assertSame(30, $service->poisonTickMinutes());
    }

    /** Настоящий GameSettingsService поверх подменённой модели (сервис объявлен final). */
    private function settingsWith(array $values): \App\Services\GameSettings\GameSettingsService
    {
        $model = new class ($values) extends \App\Models\GameSettingsModel {
            /** @param array<string,int> $values */
            public function __construct(private array $values)
            {
            }

            public function findByKey(string $key): ?array
            {
                if (! array_key_exists($key, $this->values)) {
                    return null;
                }

                return [
                    'setting_key' => $key,
                    'value_type'  => 'int',
                    'value_int'   => (int) $this->values[$key],
                ];
            }
        };

        return new \App\Services\GameSettings\GameSettingsService($model);
    }
}
