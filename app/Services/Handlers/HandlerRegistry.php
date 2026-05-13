<?php

declare(strict_types=1);

namespace App\Services\Handlers;

use RuntimeException;
use Throwable;

/**
 * Plugin-реестр handler-классов: единая точка lookup'а по `key` и по интерфейсу.
 *
 * Phase B1 (Foundation, ADR-023, 2026-05-13). Кэширование в
 * `writable/cache/handler-registry.json`, инвалидация по mtime директорий
 * scan-путей. Lazy-load: первый вызов `get*()` триггерит scan через
 * {@see HandlerDiscoveryScanner}, последующие — из in-memory кэша.
 *
 * Phase B2-B4 подключают сюда реальные namespace'ы (event-effects, task-handlers,
 * world-objects). До этого момента реестр работает на любом списке prefix'ов,
 * но фактически пуст (никто из existing handler'ов ещё не имеет
 * {@see \App\Attributes\HandlerKey} атрибута). 0 behaviour change для prod-кода.
 *
 * Кэш-формат `writable/cache/handler-registry.json`:
 *   {
 *     "namespaces": ["App\\Services\\Events\\Effects\\", ...],
 *     "max_mtime":  1715600000,
 *     "entries": [
 *       {"class": "...", "key": "...", "displayName": "...", "description": "...", "inputSchema": [...], "interfaces": [...]}
 *     ]
 *   }
 *
 * Инвалидация: при каждом `discover()` сравниваем max-mtime всех `.php` файлов
 * в scan-путях с `max_mtime` в кэше. Если файлы новее → rescan.
 */
final class HandlerRegistry
{
    /**
     * @var array<string, HandlerEntry>|null Index `handler_key` → entry; null = ещё не загружен.
     */
    private ?array $entriesByKey = null;

    /**
     * @var list<string>
     */
    private array $namespacePrefixes;

    /**
     * @param list<string>             $namespacePrefixes Namespace-префиксы для scan'а. Каждый с trailing `\\` (e.g. `'App\\TaskHandlers\\'`).
     * @param HandlerDiscoveryScanner  $scanner           Auto-discovery движок (DI для тестируемости).
     * @param string|null              $cachePath         Путь к JSON-кэшу. Null = default (WRITEPATH . 'cache/handler-registry.json').
     * @param bool                     $cacheEnabled      Если false — всегда делаем свежий scan (для unit-тестов).
     */
    public function __construct(
        array $namespacePrefixes,
        private readonly HandlerDiscoveryScanner $scanner,
        private readonly ?string $cachePath = null,
        private readonly bool $cacheEnabled = true,
    ) {
        $this->namespacePrefixes = $namespacePrefixes;
    }

    /**
     * Найти handler по `key`. Null = handler не зарегистрирован.
     */
    public function getByKey(string $key): ?HandlerEntry
    {
        $this->ensureLoaded();

        return $this->entriesByKey[$key] ?? null;
    }

    /**
     * Все зарегистрированные handler'ы (для `spark handlers:list` self-doc).
     *
     * @return list<HandlerEntry>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return array_values($this->entriesByKey ?? []);
    }

    /**
     * Handler'ы, реализующие конкретный интерфейс (e.g. `EventEffectInterface::class`).
     * Используется в admin-форме B5 для генерации dropdown'а по типу handler'а.
     *
     * @param string $interface FQCN интерфейса (admin/CLI input — не обязательно валидный class-string).
     * @return list<HandlerEntry>
     */
    public function getByInterface(string $interface): array
    {
        $this->ensureLoaded();

        $result = [];
        foreach (($this->entriesByKey ?? []) as $entry) {
            if (in_array($interface, $entry->interfaces, true)) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Список handler'ов для admin-form dropdown'а с фильтрацией по интерфейсу.
     *
     * @param string $interface FQCN интерфейса (admin input).
     * @return list<array{key: string, displayName: string, description: string}>
     */
    public function optionsFor(string $interface): array
    {
        $options = [];
        foreach ($this->getByInterface($interface) as $entry) {
            $options[] = $entry->toAdminOption();
        }

        return $options;
    }

    /**
     * Принудительный rescan (для admin-кнопки «обновить реестр» или после
     * деплоя нового handler-класса без `cache:clear`).
     */
    public function refresh(): void
    {
        $this->entriesByKey = null;
        $this->scanAndCache();
    }

    /**
     * Lazy-load + кэш-чек.
     */
    private function ensureLoaded(): void
    {
        if ($this->entriesByKey !== null) {
            return;
        }

        if ($this->cacheEnabled) {
            $cached = $this->loadCacheIfFresh();
            if ($cached !== null) {
                $this->entriesByKey = $this->indexByKey($cached);
                return;
            }
        }

        $this->scanAndCache();
    }

    /**
     * @return list<HandlerEntry>|null  null = кэш отсутствует / устарел / битый.
     */
    private function loadCacheIfFresh(): ?array
    {
        $path = $this->resolveCachePath();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['namespaces'], $decoded['max_mtime'], $decoded['entries'])) {
            return null;
        }

        if ($decoded['namespaces'] !== $this->namespacePrefixes) {
            // Конфиг scan'а изменился → invalidate cache.
            return null;
        }

        if (!is_int($decoded['max_mtime']) || $this->computeMaxMtime() > $decoded['max_mtime']) {
            return null;
        }

        if (!is_array($decoded['entries'])) {
            return null;
        }

        try {
            return $this->hydrateEntries(array_values($decoded['entries']));
        } catch (Throwable) {
            return null;
        }
    }

    private function scanAndCache(): void
    {
        $entries            = $this->scanner->discover($this->namespacePrefixes);
        $this->entriesByKey = $this->indexByKey($entries);

        if ($this->cacheEnabled) {
            $this->writeCache($entries);
        }
    }

    /**
     * @param list<HandlerEntry> $entries
     * @return array<string, HandlerEntry>
     */
    private function indexByKey(array $entries): array
    {
        $idx = [];
        foreach ($entries as $entry) {
            $idx[$entry->getKey()] = $entry;
        }

        return $idx;
    }

    /**
     * @param list<HandlerEntry> $entries
     */
    private function writeCache(array $entries): void
    {
        $path = $this->resolveCachePath();
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;  // cache-write best-effort; никогда не валим request из-за невозможности записать кэш.
        }

        $payload = [
            'namespaces' => $this->namespacePrefixes,
            'max_mtime'  => $this->computeMaxMtime(),
            'entries'    => array_map(
                static fn (HandlerEntry $e): array => [
                    'class'       => $e->class,
                    'key'         => $e->metadata->key,
                    'displayName' => $e->metadata->displayName,
                    'description' => $e->metadata->description,
                    'inputSchema' => $e->metadata->inputSchema,
                    'interfaces'  => $e->interfaces,
                ],
                $entries,
            ),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $path);
        }
    }

    /**
     * @param list<mixed> $raw
     * @return list<HandlerEntry>
     */
    private function hydrateEntries(array $raw): array
    {
        $entries = [];
        foreach ($raw as $rec) {
            if (!is_array($rec)) {
                throw new RuntimeException('Cache entry is not array');
            }
            $class       = $rec['class'] ?? null;
            $key         = $rec['key'] ?? null;
            $displayName = $rec['displayName'] ?? null;
            $description = $rec['description'] ?? '';
            $inputSchema = $rec['inputSchema'] ?? [];
            $interfaces  = $rec['interfaces'] ?? [];

            if (!is_string($class) || !class_exists($class, false) || !is_string($key) || !is_string($displayName) || !is_array($interfaces) || !is_array($inputSchema)) {
                throw new RuntimeException('Cache entry has wrong types');
            }
            /** @var class-string $class */

            // Narrow $inputSchema → list<array<string, mixed>>.
            $schemaClean = [];
            foreach (array_values($inputSchema) as $field) {
                if (is_array($field)) {
                    $schemaClean[] = $field;
                }
            }

            // Narrow $interfaces → list<string>.
            $interfacesClean = [];
            foreach (array_values($interfaces) as $iface) {
                if (is_string($iface)) {
                    $interfacesClean[] = $iface;
                }
            }

            $metadata = new \App\Attributes\HandlerKey(
                key:         $key,
                displayName: $displayName,
                description: is_string($description) ? $description : '',
                inputSchema: $schemaClean,
            );
            $entries[] = new HandlerEntry($class, $metadata, $interfacesClean);
        }

        return $entries;
    }

    private function resolveCachePath(): string
    {
        return $this->cachePath ?? (defined('WRITEPATH') ? WRITEPATH . 'cache/handler-registry.json' : sys_get_temp_dir() . '/handler-registry.json');
    }

    /**
     * Максимальный mtime среди всех `.php` файлов под scan-путями.
     * Используется для cache-invalidation: если кто-то добавил новый
     * handler-класс или поменял атрибут — mtime файла станет больше, чем
     * mtime файла кэша → rescan.
     */
    private function computeMaxMtime(): int
    {
        $rootPath = defined('APPPATH') ? APPPATH : __DIR__ . '/../../';
        $appPrefix = 'App\\';
        $maxMtime  = 0;

        foreach ($this->namespacePrefixes as $namespace) {
            if (!str_starts_with($namespace, $appPrefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($namespace, strlen($appPrefix)));
            $dir      = rtrim($rootPath, '/\\') . '/' . rtrim($relative, '/');
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!($file instanceof \SplFileInfo)) {
                    continue;
                }
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $mtime = $file->getMTime();
                    if ($mtime > $maxMtime) {
                        $maxMtime = $mtime;
                    }
                }
            }
        }

        return $maxMtime;
    }
}
