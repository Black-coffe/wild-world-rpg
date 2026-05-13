<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Images\GeminiImageProvider;
use App\Services\Images\ImageProviderInterface;
use App\Services\Images\OpenAiImageProvider;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\ImageRegistry;
use RuntimeException;
use Throwable;

/**
 * Генерация изображений игры в едином стиле «Найденная фотоплёнка» (ADR-022,
 * `mmorpg-vault/reference/Image-Style-Bible.md`). Промпт = STYLE CORE + LEXICON-запись +
 * scene-хвост + модификатор режима (V1/V2/V3/V4); провайдер по умолчанию — OpenAI `gpt-image-2`
 * ({@see OpenAiImageProvider}; альтернатива {@see GeminiImageProvider} — пока стаб).
 * Готовые байты → даунскейл ≤1536 → JPEG q84 ≤300 КБ → запись in-place в `public/uploads/telegram/...`
 * (имя файла НЕ меняется). Перед перезаписью оригинал бэкапится в `uploads/telegram/_legacy/` (идемпотентно).
 *
 * Для работы генерации нужен API-ключ в `.env`: `images.api_key=sk-...` (gitignored; ключ — только локально,
 * в прод приезжают готовые файлы). `--status` / `--dry-run` работают без ключа.
 *
 * Реестр и параметры — {@see ImageRegistry}. Runbook — `mmorpg-vault/runbooks/image-generation.md`.
 *
 * Запуск (опции spark — через пробел, НЕ через `=`):
 *   php spark images:generate --status
 *   php spark images:generate --key camp/blast_furnace --dry-run
 *   php spark images:generate --key camp/blast_furnace          # сгенерить одну (нужен ключ)
 *   php spark images:generate --category craft/standard         # всю категорию
 *   php spark images:generate --missing                         # только status=pending
 *   php spark images:generate --all                             # всё (осторожно)
 */
class GenerateImages extends BaseCommand
{
    /**
     * Хардкод-блок: эти картинки НИКОГДА не должны пройти через image-rebrand пайплайн.
     * `world_map_1000x1000.png` и `beautiful_map.png` — попиксельно нарисованные в Photoshop
     * технические карты реальных биомов/локаций из БД (7-9 цветов, ровно 2000×2000),
     * на них MapService рисует красное перекрестие позиции игрока через GD.
     * Инцидент 2026-05-13: Пачка N переписала обе в JPEG 1536×1024 → бот падал на «Карта»
     * с «Ошибка GD: не могу открыть PNG.» Восстановлены из коммита e3a88be.
     */
    private const BLOCKED_KEYS = [
        'character/world_map_1000x1000',
        'character/beautiful_map',
    ];

    protected $group       = 'Images';
    protected $name        = 'images:generate';
    protected $description = 'Генерация изображений игры в едином стиле «Найденная фотоплёнка» (ADR-022). Нужен images.api_key в .env; --status/--dry-run — без ключа.';
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
        $entries = $this->stripBlocked($entries);
        if ($entries === []) {
            CLI::error('Не выбрано ни одной картинки. Используйте --status / --key=<key> / --category=<prefix> / --missing / --all.');
            return;
        }

        $dryRun = (bool) CLI::getOption('dry-run');
        CLI::write(sprintf('Выбрано картинок: %d%s', count($entries), $dryRun ? ' (dry-run)' : ''), 'yellow');
        CLI::write('Провайдер: ' . $cfg->provider . ':' . $cfg->model . ' | формат: ' . $cfg->outputFormat
            . ' q' . $cfg->jpegQuality . ' / ≤' . (int) round($cfg->maxBytes / 1024) . 'KB / aspect ' . $cfg->aspectRatio, 'dark_gray');

        $provider = $dryRun ? null : $this->makeProvider($cfg);

        $ok = $failed = 0;
        foreach ($entries as $e) {
            $prompt = str_replace('{SCENE}', $this->buildScene($cfg, $e), $cfg->styleCore);
            CLI::write('');
            CLI::write('• ' . $e['key'] . '  [' . $e['mode'] . ' / ' . $e['lexicon'] . ' / ' . $e['status'] . ']', 'green');
            if (!isset($cfg->lexicon[$e['lexicon']])) {
                CLI::write('  ⚠ LEXICON-запись "' . $e['lexicon'] . '" не найдена в Config\\ImageRegistry::$lexicon', 'red');
            }
            if ($dryRun) {
                CLI::write('  file: ' . $e['file']);
                CLI::write('  prompt:', 'dark_gray');
                CLI::write('  ' . $prompt);
                continue;
            }

            $ref = isset($e['ref']) && $e['ref'] !== '' ? FCPATH . $e['ref'] : null;

            try {
                /** @var ImageProviderInterface $provider — гарантировано не null в этой ветке */
                $bytes = $provider->generate($prompt, ['aspectRatio' => $cfg->aspectRatio, 'refImagePath' => $ref]);
                $this->backupOriginal($cfg, $e['file']);
                $this->writeImage($cfg, $e['file'], $bytes);
            } catch (Throwable $ex) {
                CLI::error('  ✗ ' . $ex->getMessage());
                $failed++;
                continue;
            }

            $bytesOnDisk = filesize(FCPATH . $e['file']);
            CLI::write('  ✓ ' . $e['file'] . ' (' . ($bytesOnDisk === false ? '?' : (int) round($bytesOnDisk / 1024)) . ' KB). '
                . '⚠️ выставите status="generated" в Config\\ImageRegistry для этой записи.', 'green');
            $ok++;
        }

        if (! $dryRun) {
            CLI::write('');
            CLI::write(sprintf('Готово: %d ок, %d с ошибкой.', $ok, $failed), $failed > 0 ? 'yellow' : 'green');
        }
    }

    /**
     * Снимает из выборки все ключи из {@see self::BLOCKED_KEYS} и громко предупреждает.
     *
     * @param list<array{key:string, file:string, lexicon:string, scene:string, mode:string, status:string, used_in?:string, ref?:string, notes?:string}> $entries
     * @return list<array{key:string, file:string, lexicon:string, scene:string, mode:string, status:string, used_in?:string, ref?:string, notes?:string}>
     */
    private function stripBlocked(array $entries): array
    {
        $out = [];
        foreach ($entries as $e) {
            if (in_array($e['key'], self::BLOCKED_KEYS, true)) {
                CLI::write('  ⛔ Заблокировано: ' . $e['key'] . ' — это попиксельная техническая карта (см. BLOCKED_KEYS, инцидент 2026-05-13).', 'red');
                continue;
            }
            $out[] = $e;
        }
        return $out;
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
        CLI::write('Image registry — ' . count($cfg->images) . ' записей (полный реестр; orphan-дубли в игру не возвращаются — см. reference/image-inventory.md)', 'yellow');
        CLI::write('Провайдер: ' . $cfg->provider . ':' . $cfg->model . ' (' . $cfg->quality . ')  |  формат: ' . $cfg->outputFormat
            . ' q' . $cfg->jpegQuality . ' ≤' . (int) round($cfg->maxBytes / 1024) . 'KB / aspect ' . $cfg->aspectRatio
            . '  |  ключ из .env: ' . $cfg->apiKeyEnv . ($this->apiKey($cfg) !== '' ? ' (задан)' : ' (НЕ задан)'), 'dark_gray');

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
            'openai' => new OpenAiImageProvider($apiKey, $cfg->model, $cfg->quality),
            'gemini' => new GeminiImageProvider($apiKey, $cfg->model),
            default  => throw new RuntimeException('Неизвестный провайдер: ' . $cfg->provider . ' (Config\\ImageRegistry::$provider).'),
        };
    }

    /**
     * Собрать Subject-of-this-photograph хвост промпта =
     * LEXICON-текст записи + scene-хвост записи + модификатор режима (V1/V2/V3/V4, Image-Style-Bible §1.1).
     * Каждый кусок нормализуется в самостоятельное предложение (заглавная + точка), чтобы не было run-on.
     *
     * @param array{key:string, file:string, lexicon:string, scene:string, mode:string, status:string, used_in?:string, ref?:string, notes?:string} $e
     */
    private function buildScene(ImageRegistry $cfg, array $e): string
    {
        $lexText  = $cfg->lexicon[$e['lexicon']] ?? '';
        $modifier = $cfg->modeModifiers[$e['mode']] ?? '';

        $parts = [];
        foreach ([$lexText, trim($e['scene']), $modifier] as $raw) {
            $p = trim($raw);
            if ($p === '') {
                continue;
            }
            $p = ucfirst($p);
            if (! in_array(substr($p, -1), ['.', '!', '?', '…'], true)) {
                $p .= '.';
            }
            $parts[] = $p;
        }

        return implode(' ', $parts);
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

    /**
     * Записать байты картинки от провайдера в `FCPATH.$relFile` в финальном формате игры:
     * декод → даунскейл до `maxLongSide` (если больше) → перекод в JPEG `jpegQuality` →
     * если файл всё ещё > `maxBytes`, понижать качество шагами до 40 → атомарная запись.
     * Имя/расширение файла НЕ меняем (Telegram определяет тип по содержимому — 0 правок кода;
     * напр. `camp/new_camp.png` физически станет JPEG, но останется с тем же путём).
     */
    private function writeImage(ImageRegistry $cfg, string $relFile, string $bytes): void
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new RuntimeException('writeImage: провайдер вернул не-изображение (' . strlen($bytes) . ' байт) — не удалось декодировать.');
        }

        $w    = imagesx($img);
        $h    = imagesy($img);
        $long = max($w, $h);
        if ($long > $cfg->maxLongSide) {
            $scale  = $cfg->maxLongSide / $long;
            $scaled = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);
            if ($scaled !== false) {
                imagedestroy($img);
                $img = $scaled;
            }
        }

        $path = FCPATH . $relFile;
        @mkdir(dirname($path), 0775, true);
        $tmp = $path . '.tmp';

        $quality = $cfg->jpegQuality;
        while (true) {
            if (! imagejpeg($img, $tmp, $quality)) {
                imagedestroy($img);
                throw new RuntimeException('writeImage: imagejpeg() не смог записать ' . $tmp);
            }
            $size = filesize($tmp);
            if ($size === false || $size <= $cfg->maxBytes || $quality <= 40) {
                break;
            }
            $quality -= 8;
        }
        imagedestroy($img);

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('writeImage: не удалось переименовать временный файл в ' . $path);
        }
    }
}
