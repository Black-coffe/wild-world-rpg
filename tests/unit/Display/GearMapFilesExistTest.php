<?php

declare(strict_types=1);

namespace Tests\Unit\Display;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Анти-дрейф карт экипировки (прод-инцидент 2026-07-10).
 *
 * Карты `name_en => файл` в `GearArmorDetailAction` / `GearWeaponDetailAction` — обычные
 * PHP-массивы, за которыми не следит ни один тип. Утром 10.07 из 20 позиций карты брони
 * 16 ссылались на файлы, которых нет: `Request::encodeFile()` падал на `fopen`, экран умирал.
 * Резолвер это вылечил (нет файла → заглушка), но **тихо**: игрок видит `default_armor.jpg`
 * и не знает, что арт потерян. Ровно так десять стволов с нарисованным артом годами
 * показывали заглушку — их просто забыли вписать в карту.
 *
 * Тест сканирует ИСХОДНИК (инстанцировать хендлеры нельзя — конструкторы лезут в БД) и
 * требует: каждый файл из карты существует хотя бы в одном каталоге веера.
 *
 * @internal
 */
final class GearMapFilesExistTest extends CIUnitTestCase
{
    private const CRAFT_DIRS = ['standard', 'professional', 'general'];

    private const HANDLERS = [
        'броня'  => 'app/Controllers/Telegram/Commands/Profile/GearArmorDetailAction.php',
        'оружие' => 'app/Controllers/Telegram/Commands/Profile/GearWeaponDetailAction.php',
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function handlerProvider(): array
    {
        $cases = [];
        foreach (self::HANDLERS as $label => $relPath) {
            $cases[$label] = [$label, $relPath];
        }

        return $cases;
    }

    /**
     * @dataProvider handlerProvider
     */
    public function testEveryMappedFileExistsOnDisk(string $label, string $relPath): void
    {
        $map = $this->extractMap(ROOTPATH . $relPath);

        $this->assertNotEmpty($map, "{$label}: карта не распарсилась — тест ослеп, почини regex");

        foreach ($map as $nameEn => $filename) {
            $this->assertNotNull(
                $this->locate($filename),
                "{$label}: {$nameEn} => {$filename} — файла нет ни в одном каталоге "
                . '(' . implode(' / ', self::CRAFT_DIRS) . '). Игрок увидит заглушку вместо арта.'
            );
        }
    }

    /**
     * @return array<string, string> name_en => filename
     */
    private function extractMap(string $absPath): array
    {
        $src = (string) file_get_contents($absPath);

        if (preg_match('/Image(?:Map)?\(\).*?return \[(.*?)\];/s', $src, $m) !== 1) {
            return [];
        }

        preg_match_all("/'(\w+)'\s*=>\s*'([\w.]+\.jpg)'/", $m[1], $pairs, PREG_SET_ORDER);

        $map = [];
        foreach ($pairs as $pair) {
            $map[$pair[1]] = $pair[2];
        }

        return $map;
    }

    private function locate(string $filename): ?string
    {
        foreach (self::CRAFT_DIRS as $dir) {
            $abs = FCPATH . 'uploads/telegram/craft/' . $dir . '/' . $filename;
            if (is_file($abs)) {
                return $abs;
            }
        }

        return null;
    }
}
