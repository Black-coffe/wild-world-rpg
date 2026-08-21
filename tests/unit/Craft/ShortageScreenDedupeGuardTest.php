<?php

declare(strict_types=1);

namespace Tests\Unit\Craft;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tier-2 story craft-shortage-screen-dedupe-01 — `shortageScreen()` был
 * скопирован почти дословно в семь `Start*2Action` (брони и телепорт-маяков
 * `WorkbenchStandard`): два будущих фикса экрана нехватки уже приходилось
 * бы вносить семь раз, и слой уже расходился ровно так — часть копий строила
 * ключи компонентов иначе.
 *
 * ⚠️ Это АНТИ-ДРЕЙФОВЫЙ ЗАМОК ПРИСУТСТВИЯ (как `CraftCardExitGuardTest`), не
 * покрытие поведения самого экрана — оно проверяется отдельно у
 * `CraftShortageService`/`CraftShortageScreenHelper`. Тест смотрит только на
 * исходник каждого из семи файлов: делегирует ли `shortageScreen()` общей
 * точке `App\Services\Craft\CraftShortageScreenHelper`, а не завёл собственную
 * копию логики (`new CraftShortageService()` / прямой `describe()` внутри
 * класса — verbatim-признак старой копии).
 *
 * @internal
 */
final class ShortageScreenDedupeGuardTest extends CIUnitTestCase
{
    private const ARMOR_DIR = APPPATH . 'Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/Armor/';

    private const TELEPORT_DIR = APPPATH . 'Controllers/Telegram/Commands/Actions/Craft/WorkbenchStandard/TeleportBeacon/';

    /** @var list<string> все семь файлов с историей копий shortageScreen() */
    private const TARGET_FILES = [
        self::ARMOR_DIR . 'StartCraftArmorDrifterClothes2Action.php',
        self::ARMOR_DIR . 'StartCraftArmorRaggedShirt2Action.php',
        self::ARMOR_DIR . 'StartCraftLeatherJacket2Action.php',
        self::ARMOR_DIR . 'StartCraftReinforcedLeather2Action.php',
        self::TELEPORT_DIR . 'StartCraftPortableTeleport2Action.php',
        self::TELEPORT_DIR . 'StartCraftTeleportBackpack2Action.php',
        self::TELEPORT_DIR . 'StartCraftTeleportBeaconBasic2Action.php',
    ];

    private const DELEGATION_MARKER = 'CraftShortageScreenHelper';

    /** Признак старой, до-дедупликации копии: собственный вызов сервиса внутри класса. */
    private const OWN_COPY_MARKER = 'new \\App\\Services\\Craft\\CraftShortageService()';

    public function testAllSevenTargetFilesExist(): void
    {
        // Страховка от переименования/перемещения одного из семи файлов —
        // молчаливо выпавший файл иначе просто не проверится ниже.
        foreach (self::TARGET_FILES as $file) {
            $this->assertFileExists($file);
        }
    }

    public function testEveryTargetFileDelegatesToTheSharedHelper(): void
    {
        $offenders = [];

        foreach (self::TARGET_FILES as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, self::DELEGATION_MARKER)) {
                $offenders[] = $this->relativePath($file);
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "shortageScreen() без делегирования CraftShortageScreenHelper — снова собственная копия:\n- "
            . implode("\n- ", $offenders)
        );
    }

    public function testNoTargetFileRebuildsItsOwnCopyOfTheScreenLogic(): void
    {
        $offenders = [];

        foreach (self::TARGET_FILES as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, self::OWN_COPY_MARKER)) {
                $offenders[] = $this->relativePath($file);
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "Файлы завели собственный new CraftShortageService() вместо общей точки:\n- "
            . implode("\n- ", $offenders)
        );
    }

    private function relativePath(string $absolute): string
    {
        $normalized = str_replace('\\', '/', $absolute);
        $appPath    = str_replace('\\', '/', APPPATH);

        return 'app/' . ltrim(str_replace($appPath, '', $normalized), '/');
    }
}
