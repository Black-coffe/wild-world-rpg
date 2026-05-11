<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Images\GeminiImageProvider;
use App\Services\Images\ImageProviderInterface;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\ImageRegistry;
use RuntimeException;
use Throwable;

/**
 * Генерация изображений игры в едином стиле «Найденная фотоплёнка» (ADR-022,
 * `mmorpg-vault/reference/Image-Style-Bible.md`). Промпт = STYLE CORE + сцена из реестра.
 *
 * ⚠️ СКАФФОЛД. `--status` работает; генерация (`--all`/`--missing`/`--key`/`--category`)
 * требует сконфигурированного провайдера (API-ключ `images.api_key` в `.env`) и его реализации
 * ({@see GeminiImageProvider} — пока стаб). До этого момента команда полезна для:
 *   - инспекции реестра (`php spark images:generate --status`),
 *   - сборки/просмотра полного промпта картинки (`php spark images:generate --key=<key> --dry-run`).
 *
 * Реестр и параметры — {@see ImageRegistry}. Перед перезаписью оригинал бэкапится в `_legacy/`.
 *
 * Запуск (опции spark — через пробел, НЕ через `=`):
 *   php spark images:generate --status
 *   php spark images:generate --key camp/blast_furnace --dry-run
 *   php spark images:generate --category camp --dry-run
 *   php spark images:generate --missing      # только status=pending
 *   php spark images:generate --all          # всё (осторожно)
 */
class GenerateImages extends BaseCommand
{
    protected $group       = 'Images';
    protected $name        = 'images:generate';
    protected $description = 'Генерация изображений игры в едином стиле (ADR-022). Скаффолд: --status работает, генерация — TODO (нужен API-ключ).';
    protected $usage       = 'images:generate [--status] [--key <key>] [--category <prefix>] [--missing] [--all] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--status'   => 'Показать реестр (key / категория / lexicon / mode / status). Ничего не генерирует.',
        '--key'      => 'Сгенерировать одну картинку по её key (путь без расширения). Пример: --key camp/blast_furnace',
        '--category' => 'Сгенерировать все картинки, key которых начинается с этого префикса. Пример: --category craft/standard',
        '--missing'  => 'Сгенерировать только те, у кого status=pending.',
        '--all'      => 'Сгенерировать все записи реестра.',
        '--dry-run'  => 'Не вызывать API и не писать файлы — только показать, что было бы сделано (+ полный промпт).',
    ];

    public function run(array $params)
    {
        $cfg = config(ImageRegistry::class);

        if ((bool) CLI::getOption('status')) {
            $this->printStatus($cfg);
            return;
        }

        $entries = $this->selectEntries($cfg);
        if ($entries === []) {
            CLI::error('Не выбрано ни одной картинки. Используйте --status / --key=<key> / --category=<prefix> / --missing / --all.');
            return;
        }

        $dryRun = (bool) CLI::getOption('dry-run');
        CLI::write(sprintf('Выбрано картинок: %d%s', count($entries), $dryRun ? ' (dry-run)' : ''), 'yellow');
        CLI::write('Провайдер: ' . $cfg->provider . ':' . $cfg->model . ' | формат: ' . $cfg->outputFormat
            . ' q' . $cfg->jpegQuality . ' / ≤' . (int) round($cfg->maxBytes / 1024) . 'KB / aspect ' . $cfg->aspectRatio, 'dark_gray');

        $provider = $dryRun ? null : $this->makeProvider($cfg);

        foreach ($entries as $e) {
            $prompt = str_replace('{SCENE}', $e['scene'], $cfg->styleCore);
            CLI::write('');
            CLI::write('• ' . $e['key'] . '  [' . $e['mode'] . ' / ' . $e['lexicon'] . ' / ' . $e['status'] . ']', 'green');
            if ($dryRun) {
                CLI::write('  file: ' . $e['file']);
                CLI::write('  prompt:', 'dark_gray');
                CLI::write('  ' . $prompt);
                continue;
            }

            // Реальная генерация (когда провайдер реализован):
            try {
                /** @var ImageProviderInterface $provider — гарантировано не null в этой ветке */
                $bytes = $provider->generate($prompt, ['aspectRatio' => $cfg->aspectRatio, 'refImagePath' => null]);
            } catch (Throwable $ex) {
                CLI::error('  ✗ ' . $ex->getMessage());
                CLI::write('  (скаффолд — реальная генерация ещё не реализована; см. ADR-022 / GeminiImageProvider)', 'dark_gray');
                return;
            }

            $this->backupOriginal($cfg, $e['file']);
            $this->writeImage($cfg, $e['file'], $bytes);
            CLI::write('  ✓ записано (' . strlen($bytes) . ' байт). ⚠️ обновите status в Config\\ImageRegistry → "generated".', 'green');
        }
    }

    /** @return list<array{key:string, file:string, lexicon:string, scene:string, mode:string, status:string, used_in?:string, ref?:string, notes?:string}> */
    private function selectEntries(ImageRegistry $cfg): array
    {
        if ((bool) CLI::getOption('all')) {
            return $cfg->images;
        }
        if ((bool) CLI::getOption('missing')) {
            return array_values(array_filter($cfg->images, static fn (array $e): bool => $e['status'] === 'pending'));
        }
        $key = CLI::getOption('key');
        if (is_string($key) && $key !== '') {
            return array_values(array_filter($cfg->images, static fn (array $e): bool => $e['key'] === $key));
        }
        $cat = CLI::getOption('category');
        if (is_string($cat) && $cat !== '') {
            $prefix = rtrim($cat, '/');
            return array_values(array_filter(
                $cfg->images,
                static fn (array $e): bool => $e['key'] === $prefix || str_starts_with($e['key'], $prefix . '/'),
            ));
        }
        return [];
    }

    private function printStatus(ImageRegistry $cfg): void
    {
        CLI::write('Image registry — ' . count($cfg->images) . ' записей (СКАФФОЛД: только пилот-кандидаты; ~140–150 реальных — TODO, см. reference/image-inventory.md)', 'yellow');
        CLI::write('Провайдер: ' . $cfg->provider . ':' . $cfg->model . '  |  ключ из .env: ' . $cfg->apiKeyEnv . ($this->apiKey($cfg) !== '' ? ' (задан)' : ' (НЕ задан)'), 'dark_gray');

        $rows = [];
        $byStatus = [];
        foreach ($cfg->images as $e) {
            $cat = str_contains($e['key'], '/') ? substr($e['key'], 0, (int) strrpos($e['key'], '/')) : '(root)';
            $rows[] = [$e['key'], $cat, $e['lexicon'], $e['mode'], $e['status']];
            $byStatus[$e['status']] = ($byStatus[$e['status']] ?? 0) + 1;
        }
        CLI::table($rows, ['key', 'категория', 'lexicon', 'mode', 'status']);

        $summary = [];
        foreach (['pending', 'generated', 'approved', 'rejected'] as $s) {
            $summary[] = $s . ': ' . ($byStatus[$s] ?? 0);
        }
        CLI::write('Итого — ' . implode(' | ', $summary), 'green');
    }

    private function makeProvider(ImageRegistry $cfg): ImageProviderInterface
    {
        $apiKey = $this->apiKey($cfg);
        return match ($cfg->provider) {
            'gemini' => new GeminiImageProvider($apiKey, $cfg->model),
            // 'openai' => new OpenAiImageProvider($apiKey, $cfg->model),   // TODO(Блок 2)
            default  => throw new RuntimeException('Неизвестный провайдер: ' . $cfg->provider . ' (Config\\ImageRegistry::$provider).'),
        };
    }

    private function apiKey(ImageRegistry $cfg): string
    {
        $v = env($cfg->apiKeyEnv, '');
        return is_string($v) ? $v : '';
    }

    private function backupOriginal(ImageRegistry $cfg, string $relFile): void
    {
        $src = FCPATH . $relFile;
        if (!is_file($src)) {
            return; // новой картинки ещё нет — нечего бэкапить
        }
        $rel = $relFile;
        if (str_starts_with($rel, $cfg->imagesDir . '/')) {
            $rel = substr($rel, strlen($cfg->imagesDir) + 1);
        }
        $dst = FCPATH . $cfg->legacyDir . '/' . $rel;
        if (is_file($dst)) {
            return; // оригинал уже забэкаплен — не перезаписываем бэкап
        }
        @mkdir(dirname($dst), 0775, true);
        @copy($src, $dst);
    }

    private function writeImage(ImageRegistry $cfg, string $relFile, string $bytes): void
    {
        // TODO(Блок 2): декод входных байт → resize до maxLongSide → перекод в JPEG q=jpegQuality
        //               → если > maxBytes, понизить качество шагами → записать в FCPATH.$relFile
        //               (имя файла НЕ меняем — 0 правок кода). Сейчас — placeholder-запись «как есть».
        $path = FCPATH . $relFile;
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $bytes);
    }
}
