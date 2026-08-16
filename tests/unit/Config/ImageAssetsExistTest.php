<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\CraftRecipes;
use Config\ImageRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 🔴 АНТИ-ДРИФТ ГЕЙТ картинок: объявил путь — положи файл.
 *
 * Повод (2026-08-16). Экран костра рендерился картинкой общего верстака — фото
 * мастерской с ножами и молотком там, где варят похлёбку. Нашлось это глазами
 * на Tier-3, а не тестом, и следом ручной аудит вскрыл ещё два таких экрана
 * (сезонный крафт, защита). Ручной аудит не повторяем — он и был проблемой.
 *
 * Что гейт ловит (детерминированно, без БД и без сети):
 *  - рецепт объявил `image_in_progress`/`image_completed`, а файла нет → экран
 *    молча падает на generic-фолбэк, и игрок смотрит на чужую картинку;
 *  - запись реестра объявлена, а файл не сгенерён/не доехал;
 *  - хендлер зашил путь строкой, а файл переименовали/удалили → `encodeFile`
 *    бросает исключение на `fopen` и убивает ВЕСЬ экран, не только картинку
 *    (урок `feedback_gear_image_must_resolve_via_is_file`).
 *
 * Чего гейт НЕ ловит: смысловое несоответствие «картинка не про то, что на
 * экране». Файл существует — тест зелёный. Это остаётся за Tier-3 и за
 * `reference/Image-Style-Bible.md` §2.3 (у экрана механики свой якорный объект).
 *
 * @internal
 */
final class ImageAssetsExistTest extends CIUnitTestCase
{
    /**
     * Осознанные дыры: путь объявлен, файла нет, и это пока приемлемо.
     *
     * Список самоочищающийся — тест требует РОВНО этот набор. Появилась новая
     * дыра → красный. Дыру закрыли (файл сгенерили) → тоже красный, пока строку
     * отсюда не убрали. Так список не превращается в свалку вечных исключений.
     *
     * @var array<string,string> путь относительно public/ => почему терпим
     */
    private const KNOWN_MISSING = [
        // Пусто — и пусть остаётся пустым. Единственная запись (`objects/islandheart.jpg`,
        // Сердце острова: объект dormant, `world.strategic.islandheart.max_spawns=0`)
        // закрыта 2026-08-16 генерацией арта, а не продлением исключения.
    ];

    /** Поля рецепта, содержащие путь к картинке. */
    private const RECIPE_IMAGE_FIELDS = ['image_in_progress', 'image_completed'];

    /**
     * Рецепты — гейт без послаблений: у крафта картинка либо есть, либо путь не
     * объявлен. Дыр в этом слое нет и заводить их незачем.
     */
    public function testEveryRecipeImageExistsOnDisk(): void
    {
        $recipes = new CraftRecipes();
        $missing = [];

        foreach ($recipes->keys() as $key) {
            $recipe = $recipes->get($key);
            if ($recipe === null) {
                continue;
            }

            foreach (self::RECIPE_IMAGE_FIELDS as $field) {
                $rel = $recipe[$field] ?? null;
                if (! is_string($rel) || $rel === '') {
                    continue;
                }

                if (! $this->existsInPublic($rel)) {
                    $missing[] = sprintf('%s.%s → %s', $key, $field, $rel);
                }
            }
        }

        $this->assertSame([], $missing, "Рецепт ссылается на несуществующий файл картинки — экран уйдёт на generic-фолбэк:\n" . implode("\n", $missing));
    }

    /**
     * Реестр — источник правды для `php spark images:generate`. Запись без файла
     * означает «сгенерить забыли» либо «файл не доехал в релиз».
     */
    public function testEveryRegistryEntryHasFileOnDisk(): void
    {
        $missing = [];

        foreach ((new ImageRegistry())->images as $row) {
            $rel = $row['file'];

            // Пустой `file` — та же дыра, что и отсутствующий файл: генератору
            // нечего писать, экрану нечего показать. Ключ в тексте ошибки, чтобы
            // было понятно, какую запись реестра чинить.
            if ($rel === '') {
                $missing[] = sprintf('%s → пустой file', $row['key']);

                continue;
            }

            if (! $this->existsInPublic($rel)) {
                $missing[] = $rel;
            }
        }

        $this->assertKnownMissingExactly($missing, 'запись реестра');
    }

    /**
     * Пути, зашитые строкой в коде (хендлеры экранов, сервисы, конфиги).
     *
     * Сканируем исходники, а не вызываем экраны: гейт обязан работать в любой
     * CI-полосе, без Telegram и без БД. Комментарии тоже попадают в выборку — и
     * это намеренно: комментарий, указывающий на несуществующий файл, врёт
     * ровно так же, как код.
     */
    public function testEveryHardcodedImagePathExistsOnDisk(): void
    {
        $missing = [];

        foreach ($this->phpSources(APPPATH) as $file) {
            $code = (string) file_get_contents($file);
            if (! preg_match_all('#uploads/telegram/[A-Za-z0-9_/.-]+\.(?:png|jpg|jpeg)#i', $code, $matches)) {
                continue;
            }

            foreach (array_unique($matches[0]) as $rel) {
                if (! $this->existsInPublic($rel)) {
                    $missing[] = $rel;
                }
            }
        }

        $this->assertKnownMissingExactly($missing, 'зашитый в коде путь');
    }

    /**
     * Набор отсутствующих файлов обязан совпасть с {@see KNOWN_MISSING} ровно:
     * и лишнее красное, и «уже не отсутствует» красное.
     *
     * @param list<string> $missing
     */
    private function assertKnownMissingExactly(array $missing, string $what): void
    {
        $actual   = array_values(array_unique($missing));
        $expected = array_keys(self::KNOWN_MISSING);
        sort($actual);
        sort($expected);

        $unexpected = array_diff($actual, $expected);
        $resolved   = array_diff($expected, $actual);

        $this->assertSame(
            [],
            array_values($unexpected),
            sprintf(
                "Без файла на диске (%s):\n%s\n\nЛибо сгенерить (`php spark images:generate --key <key>`), либо, если это осознанная дыра, завести строку в ImageAssetsExistTest::KNOWN_MISSING с объяснением.",
                $what,
                implode("\n", $unexpected),
            ),
        );

        $this->assertSame(
            [],
            array_values($resolved),
            sprintf(
                "Файл появился, а исключение в ImageAssetsExistTest::KNOWN_MISSING осталось:\n%s\n\nУбери строку — список исключений не должен копить мёртвые записи.",
                implode("\n", $resolved),
            ),
        );
    }

    private function existsInPublic(string $relative): bool
    {
        return is_file(FCPATH . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/')));
    }

    /**
     * @return list<string> абсолютные пути ко всем .php под каталогом
     */
    private function phpSources(string $dir): array
    {
        $files    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
