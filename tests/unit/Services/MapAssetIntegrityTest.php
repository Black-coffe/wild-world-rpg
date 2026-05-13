<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Защита от регрессии 2026-05-13: Пачка N image-ребренда переписала
 * world_map_1000x1000.png и beautiful_map.png в JPEG 1536×1024,
 * из-за чего MapService::showMapWithPlayer() падал на imagecreatefrompng().
 *
 * Карта обязана оставаться валидным PNG ровно 2000×2000 (масштаб 2px = 1coord).
 *
 * @internal
 */
final class MapAssetIntegrityTest extends CIUnitTestCase
{
    private const REQUIRED_W = 2000;
    private const REQUIRED_H = 2000;

    /** @return array<string, array{string}> */
    public static function mapFilesProvider(): array
    {
        return [
            'accurate' => [FCPATH . 'uploads/telegram/character/world_map_1000x1000.png'],
            'beautiful' => [FCPATH . 'uploads/telegram/character/beautiful_map.png'],
        ];
    }

    /**
     * @dataProvider mapFilesProvider
     */
    public function testFileExists(string $path): void
    {
        $this->assertFileExists($path, "Карта отсутствует: {$path}");
    }

    /**
     * @dataProvider mapFilesProvider
     */
    public function testHasPngMagicBytes(string $path): void
    {
        $head = file_get_contents($path, false, null, 0, 8);
        $this->assertSame(
            "\x89PNG\r\n\x1a\n",
            $head,
            "Файл {$path} не является PNG — imagecreatefrompng() упадёт"
        );
    }

    /**
     * @dataProvider mapFilesProvider
     */
    public function testGdCanDecode(string $path): void
    {
        $im = @imagecreatefrompng($path);
        $this->assertNotFalse($im, "GD не открыл {$path} как PNG");
        imagedestroy($im);
    }

    /**
     * @dataProvider mapFilesProvider
     */
    public function testDimensionsAre2000x2000(string $path): void
    {
        $info = @getimagesize($path);
        $this->assertNotFalse($info, "getimagesize() не смог прочитать {$path}");
        $this->assertSame(self::REQUIRED_W, $info[0], "Ширина {$path} ≠ 2000 — координатная сетка MapService сломается");
        $this->assertSame(self::REQUIRED_H, $info[1], "Высота {$path} ≠ 2000 — координатная сетка MapService сломается");
    }
}
