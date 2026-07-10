<?php

declare(strict_types=1);

namespace Tests\Unit\Display;

use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Общий анти-дрейф ссылок на картинки (итог аудита карт name→файл 2026-07-10).
 *
 * [[GearMapFilesExistTest]] покрыл 2 карты Gear, [[ConfigImageMapsFilesExistTest]] — конфиги
 * построек/крафта. Оставались ~112 РАЗБРОСАННЫХ литералов `'uploads/telegram/....jpg'` прямо в
 * коде хендлеров/экшенов/сервисов (`base_url('uploads/telegram/quests/...')`, `FCPATH.'uploads/
 * ...'`), уходящих в `encodeFile` — часть под гвардом, часть нет. Их «проверено вручную, файлы
 * на месте» разъезжается ровно как разъехались карты брони/оружия (арт есть, но невидим).
 *
 * Тест сканирует ПОТРЕБИТЕЛЕЙ (Controllers/TaskHandlers/Services) и требует, чтобы каждый ПОЛНЫЙ
 * литерал-путь существовал на диске. Ловит удалённый при деплое ассет, опечатку пути, ссылку на
 * несгенерированную картинку. Динамические конкатенации (`'uploads/telegram/'.$x`) не матчатся —
 * в литерале нет расширения; их существование гарантировать статикой нельзя (это отдельный слой).
 *
 * `app/Config` НЕ сканируем: там ДЕКЛАРАЦИИ `ImageRegistry` со своим `status`-жизненным циклом
 * (pending/dormant-запись, напр. `islandheart`, легитимно ещё не на диске). Конфиги, которые
 * реально ПОТРЕБЛЯЮТСЯ путём, закрыты отдельным ConfigImageMapsFilesExistTest.
 *
 * @internal
 */
final class ConsumerImagePathsExistTest extends CIUnitTestCase
{
    private const CONSUMER_DIRS = [
        'app/Controllers',
        'app/TaskHandlers',
        'app/Services',
    ];

    public function testEveryReferencedImageLiteralExistsOnDisk(): void
    {
        $refs = $this->collectImageLiterals();

        $this->assertNotEmpty($refs, 'не найдено ни одного литерала — тест ослеп, почини regex/пути');

        $missing = [];
        foreach ($refs as $rel => $files) {
            $abs = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (! is_file($abs)) {
                $missing[] = "  ✗ {$rel}  ←  " . implode(', ', $files);
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Ссылки на несуществующие картинки (encodeFile упадёт ИЛИ тихо покажет заглушку):\n"
            . implode("\n", $missing)
        );
    }

    /**
     * @return array<string, list<string>> относительный путь → [файлы, где встретился]
     */
    private function collectImageLiterals(): array
    {
        $refs = [];
        foreach (self::CONSUMER_DIRS as $dir) {
            $abs = ROOTPATH . $dir;
            if (! is_dir($abs)) {
                continue;
            }
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $file) {
                if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (! preg_match_all("/'(uploads\/telegram\/[^']*\.(?:jpg|jpeg|png|webp|gif))'/i", $src, $m)) {
                    continue;
                }
                foreach (array_unique($m[1]) as $rel) {
                    $refs[$rel][] = $file->getBasename();
                }
            }
        }

        // Дедуп имён файлов на каждый путь.
        foreach ($refs as $rel => $files) {
            $refs[$rel] = array_values(array_unique($files));
        }

        return $refs;
    }
}
