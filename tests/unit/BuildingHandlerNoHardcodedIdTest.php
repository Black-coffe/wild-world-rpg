<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * E28 (NAVIGATION_MAP #25) — анти-дрифт: ID зданий резолвятся по СТАБИЛЬНОМУ
 * `name_en` (BuildingModel::findByNameEn), а не позиционным хардкодом.
 *
 * Причина: позиционные id дрейфят при реседе / реордере миграций. ArsenalHandler
 * когда-то держал `$buildingId = 15`, тогда как реальный id «Арсенала» = 11
 * (id 15 принадлежит «Дозорной вышке») → хардкод грузил бы чужое здание. Этот
 * гейт не даёт вернуть хардкод-id в *Handler'ы зданий и активаторы роботов.
 *
 * @internal
 */
final class BuildingHandlerNoHardcodedIdTest extends CIUnitTestCase
{
    private const DIR = APPPATH . 'Controllers/Telegram/Commands/Actions/Camp/Buildings';

    /**
     * @return list<string> абсолютные пути ко всем .php в каталоге зданий (рекурсивно)
     */
    private function phpFiles(): array
    {
        $files = [];
        $it    = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::DIR, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Исходник БЕЗ комментариев — чтобы regex не ловил пояснения в доках
     * (например упоминание легаси-хардкода `$buildingId = 15` в комментарии Arsenal).
     */
    private function codeWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }

        return $out;
    }

    public function testCatalogIsScanned(): void
    {
        // Sanity: каталог существует и в нём действительно лежат хендлеры зданий.
        $this->assertGreaterThan(10, count($this->phpFiles()), 'Каталог зданий не найден или пуст.');
    }

    public function testNoHardcodedBuildingIdAssignment(): void
    {
        foreach ($this->phpFiles() as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/\$buildingId\s*=\s*\d/',
                $this->codeWithoutComments($path),
                basename($path) . ': хардкод `$buildingId = N`. Резолви id через BuildingModel::findByNameEn(name_en).'
            );
        }
    }

    public function testNoHardcodedBuildingIdInWhereClause(): void
    {
        foreach ($this->phpFiles() as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/building_id[\'"]\s*,\s*\d/',
                $this->codeWithoutComments($path),
                basename($path) . ": хардкод where('building_id', N). Резолви id по name_en (findByNameEn)."
            );
        }
    }
}
