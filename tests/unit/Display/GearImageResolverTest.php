<?php

namespace Tests\Unit\Display;

use App\Services\Display\GearImageResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Регресс-гвард прод-инцидента 2026-07-10 (char 1106, 31 ошибка за 4 минуты):
 * осмотр брони падал на `fopen(.../craft/standard/tesla_shard_armor.jpg)` — карта
 * возвращала путь, не проверяя существование файла, а T3-броня лежит в professional/.
 *
 * Запирает три инварианта:
 *  - резолв ищет по каталогам, а не только в standard/ (иначе 16 из 20 позиций падают);
 *  - несуществующий файл → заглушка, а не битый путь (encodeFile больше не падает);
 *  - нет вообще ничего → null, вызывающий деградирует к тексту (MEDIA-OFF, ADR-020).
 *
 * @internal
 */
final class GearImageResolverTest extends CIUnitTestCase
{
    /** Реальная карта брони из GearArmorDetailAction (позиции, ломавшие прод). */
    private const ARMOR_MAP = [
        'RaggedShirt'           => 'ragged_shirt.jpg',        // standard/ — работал и раньше
        'LeatherJacket'         => 'leather_jacket.jpg',      // standard/ — работал и раньше
        'TeslaShardArmor'       => 'tesla_shard_armor.jpg',   // professional/ — ПАДАЛ на проде
        'TitanPowerArmor'       => 'titan_power_armor.jpg',   // professional/ — падал бы
        'JuggernautBattleArmor' => 'juggernaut_battle_armor.jpg',
        'HunterVest'            => 'hunter_vest.jpg',         // файла нет нигде → заглушка
    ];

    public function testProdIncidentTeslaShardArmorResolvesInsteadOfCrashing(): void
    {
        $path = GearImageResolver::armorImage('TeslaShardArmor', self::ARMOR_MAP);

        $this->assertIsString($path, 'TeslaShardArmor: путь обязан резолвиться (иначе encodeFile падает)');
        $this->assertFileExists($path, 'Резолвер обязан возвращать только существующий файл');
        $this->assertStringContainsString('professional', $path, 'T3-броня живёт в professional/');
    }

    public function testStandardArmorKeepsPreviousBehaviour(): void
    {
        foreach (['RaggedShirt', 'LeatherJacket'] as $nameEn) {
            $path = GearImageResolver::armorImage($nameEn, self::ARMOR_MAP);
            $this->assertIsString($path, "{$nameEn}: путь ожидался");
            $this->assertFileExists($path);
            $this->assertStringContainsString('standard', $path, "{$nameEn}: byte-identical со standard/");
        }
    }

    public function testMissingArmorFileFallsBackToPlaceholderNotBrokenPath(): void
    {
        // Предмет есть в карте, но файла нет ни в одном каталоге. Имя намеренно синтетическое:
        // раньше здесь стоял HunterVest, и тест сломался, когда броне нарисовали арт. Проверяем
        // ПОВЕДЕНИЕ (нет файла → заглушка), а не отсутствие конкретной картинки.
        $path = GearImageResolver::armorImage('GhostOfAMissingFile', ['GhostOfAMissingFile' => 'no_such_armor_file.jpg']);

        $this->assertIsString($path, 'Отсутствующий файл → заглушка, а не null (заглушка существует)');
        $this->assertFileExists($path, 'Заглушка обязана существовать — иначе encodeFile снова упадёт');
        $this->assertStringContainsString(GearImageResolver::DEFAULT_ARMOR, $path);
    }

    public function testUnknownItemFallsBackToPlaceholder(): void
    {
        $path = GearImageResolver::armorImage('ThereIsNoSuchArmor', self::ARMOR_MAP);

        $this->assertIsString($path);
        $this->assertStringContainsString(GearImageResolver::DEFAULT_ARMOR, $path);
    }

    public function testWeaponMapResolvesAndFallsBack(): void
    {
        $map = ['MetalSpear' => 'metal_spear.jpg'];

        $known = GearImageResolver::weaponImage('MetalSpear', $map);
        $this->assertIsString($known);
        $this->assertFileExists($known);

        $unknown = GearImageResolver::weaponImage('HydraPlasmaCannon', $map);
        $this->assertIsString($unknown, 'T3-оружие вне карты → заглушка');
        $this->assertStringContainsString(GearImageResolver::DEFAULT_WEAPON, $unknown);
    }

    public function testNullWhenNothingExistsSoCallerDegradesToText(): void
    {
        // Ни файла, ни заглушки с таким именем — резолвер обязан вернуть null,
        // чтобы хендлер отправил самодостаточный текст вместо падения.
        $this->assertNull(GearImageResolver::locate('no_such_file_ever_42.jpg'));
    }

    public function testRelativeCandidatesArePureAndOrderedStandardFirst(): void
    {
        $candidates = GearImageResolver::relativeCandidates('x.jpg');

        $this->assertSame([
            'uploads/telegram/craft/standard/x.jpg',
            'uploads/telegram/craft/professional/x.jpg',
            'uploads/telegram/craft/general/x.jpg',
        ], $candidates, 'standard/ обязан идти первым — иначе ломается byte-identical для рабочих позиций');

        $this->assertSame([], GearImageResolver::relativeCandidates(''), 'Пустое имя → нет кандидатов');
    }
}
