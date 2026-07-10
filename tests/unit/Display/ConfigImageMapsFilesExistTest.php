<?php

declare(strict_types=1);

namespace Tests\Unit\Display;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Анти-дрейф картинок, потребляемых ВСЛЕПУЮ из конфигов и хардкод-карт (аудит 2026-07-10).
 *
 * Сиблинг [[GearMapFilesExistTest]], но для другого класса потребителей: экраны построек и
 * крафта берут путь картинки из `Config\Buildings` / `Config\CraftRecipes` (поля
 * `image_in_progress` / `image_completed` / `completion_image`) или из хардкод-карты
 * `DefensiveBuildingHandler::DEF` и суют его в `Request::encodeFile()` — fopen без `is_file`.
 * Ссылка на несуществующий файл = падение экрана (класс-баг Gear) ИЛИ тихая потеря
 * completion-уведомления. Резолверы это лечат, но ТИХО — заглушка вместо арта.
 *
 * Тест требует: каждый путь картинки, на который ссылается конфиг/карта, существует на диске.
 * Так «арт ship'ится вместе с контентом»: добавил рецепт/постройку с несгенерированной
 * картинкой → тест красный, пока арт не нарисован (или путь не переведён на существующий).
 *
 * Сканирует ИСХОДНИК (не рефлексию): конфиги — сотни записей, дешевле грепнуть литералы.
 *
 * @internal
 */
final class ConfigImageMapsFilesExistTest extends CIUnitTestCase
{
    private const SOURCES = [
        'Config\Buildings'        => 'app/Config/Buildings.php',
        'Config\CraftRecipes'     => 'app/Config/CraftRecipes.php',
        'DefensiveBuildingHandler' => 'app/Controllers/Telegram/Commands/Actions/Camp/Buildings/DefensiveBuildingHandler.php',
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        $cases = [];
        foreach (self::SOURCES as $label => $rel) {
            $cases[$label] = [$label, $rel];
        }

        return $cases;
    }

    /**
     * @dataProvider sourceProvider
     */
    public function testEveryReferencedImageExistsOnDisk(string $label, string $relPath): void
    {
        $paths = $this->extractImagePaths(ROOTPATH . $relPath);

        $this->assertNotEmpty($paths, "{$label}: не найдено ни одного пути картинки — тест ослеп, почини regex");

        foreach ($paths as $rel) {
            $abs = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $this->assertFileExists(
                $abs,
                "{$label}: путь '{$rel}' ссылается на несуществующий файл. "
                . 'Экран осмотра упадёт на encodeFile ИЛИ тихо покажет заглушку. '
                . 'Сгенерируй арт (php spark images:generate) или переведи путь на существующий.'
            );
        }
    }

    /**
     * Все литералы вида 'uploads/telegram/....(jpg|jpeg|png|webp)' в файле, дедуп.
     *
     * @return list<string>
     */
    private function extractImagePaths(string $absPath): array
    {
        $src = (string) file_get_contents($absPath);

        preg_match_all(
            "/'(uploads\/telegram\/[^']*\.(?:jpg|jpeg|png|webp))'/i",
            $src,
            $m
        );

        return array_values(array_unique($m[1]));
    }
}
