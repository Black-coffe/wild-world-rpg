<?php

declare(strict_types=1);

namespace App\Services\Handlers;

use App\Attributes\HandlerKey;
use ReflectionAttribute;
use ReflectionClass;
use Throwable;

/**
 * Auto-discovery handler-классов по Composer class-map'у + PHP-атрибутам.
 *
 * Phase B1 (Foundation, ADR-023, 2026-05-13). Не привязан к конкретным
 * интерфейсам — принимает список namespace-префиксов как input. Это позволяет
 * подключать event-effects (B2), task-handlers (B3), world-objects (B4)
 * поэтапно, переиспользуя один и тот же scanner.
 *
 * Алгоритм:
 *   1. Читает Composer класс-карту (`vendor/composer/autoload_classmap.php`).
 *   2. Фильтрует классы по namespace-префиксам.
 *   3. Для каждого подходящего класса: ReflectionClass → ищет атрибут
 *      {@see HandlerKey} → если есть, собирает {@see HandlerEntry}.
 *   4. Классы без атрибута / с ошибками рефлексии — silently skip (legacy
 *      handler'ы могут не иметь атрибута во время dual-write окна B2-B6).
 *
 * Кеширование — отдельная задача {@see HandlerRegistry}; этот scanner всегда
 * делает «свежий» scan. Скорость: ~200 классов проходят за 30-80мс на typical
 * dev-машине (одна reflection per class).
 *
 * Использование (B2+):
 *   $scanner = new HandlerDiscoveryScanner();
 *   $entries = $scanner->discover([
 *       'App\Services\Events\Effects\\',
 *       'App\TaskHandlers\\',
 *   ]);
 *   // → list<HandlerEntry>, у которых атрибут HandlerKey найден
 */
final class HandlerDiscoveryScanner
{
    /**
     * @var array<string, string>|null Composer class-map (class FQCN → file path), либо null если не загружена.
     */
    private ?array $classMap = null;

    /**
     * @param string|null $composerClassmapPath Путь к autoload_classmap.php. Null = default (vendor/composer/autoload_classmap.php от корня проекта). Параметр существует для unit-тестов (передаём fixture-классмап).
     */
    public function __construct(private readonly ?string $composerClassmapPath = null)
    {
    }

    /**
     * Просканировать классы под указанными namespace-префиксами и собрать
     * handler-entry для тех, у которых есть атрибут {@see HandlerKey}.
     *
     * @param list<string> $namespacePrefixes E.g. `['App\TaskHandlers\\', 'App\Services\Events\Effects\\']`. Trailing `\\` обязателен — фильтрация по `str_starts_with`.
     * @return list<HandlerEntry>
     */
    public function discover(array $namespacePrefixes): array
    {
        $candidates = [];

        // Шлях 1: Composer class-map (vendor/composer/autoload_classmap.php).
        // Покриває vendor-пакети + App\*, якщо composer dump-autoload --classmap-authoritative
        // згенерував map для PSR-4 каталогів. У дефолтному CI4-проекті composer.json
        // НЕ декларує App\\ як PSR-4 (CI4 має власний autoloader), тому класмап
        // зазвичай покриває тільки vendor. Це причина додати шлях 2.
        foreach ($this->loadClassMap() as $class => $_file) {
            if ($this->matchesAnyPrefix($class, $namespacePrefixes)) {
                $candidates[$class] = true;
            }
        }

        // Шлях 2: Filesystem-обхід для App\* namespace-префіксів. Маппимо namespace
        // в каталог через APPPATH (CI4 convention) і знаходимо FQCN кожного .php
        // файлу. Це покриває хвостові handler-класи у dev/test/prod коли classmap
        // не оптимізовано.
        foreach ($this->collectAppNamespaceClasses($namespacePrefixes) as $class) {
            $candidates[$class] = true;
        }

        $entries = [];
        foreach (array_keys($candidates) as $class) {
            $entry = $this->inspectClass($class);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Filesystem-walk для App\* namespace-префіксів. Кожен `.php` файл під
     * відповідним каталогом → FQCN кандидат.
     *
     * Для не-App\ префіксів (наприклад, fixture `Tests\Support\Handlers\Fixtures\\`)
     * повертає `[]` — там працює classmap-шлях (fixture-classmap тестів) або
     * не потрібен взагалі.
     *
     * @param list<string> $namespacePrefixes
     * @return list<string>
     */
    private function collectAppNamespaceClasses(array $namespacePrefixes): array
    {
        $appPathRoot = defined('APPPATH') ? rtrim(APPPATH, '/\\') : null;
        if ($appPathRoot === null) {
            return [];
        }

        $appPrefix = 'App\\';
        $classes   = [];

        foreach ($namespacePrefixes as $prefix) {
            if (!str_starts_with($prefix, $appPrefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($prefix, strlen($appPrefix)));
            $dir      = $appPathRoot . '/' . rtrim($relative, '/');
            if (!is_dir($dir)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                );
            } catch (Throwable) {
                continue;
            }

            foreach ($iterator as $file) {
                if (!($file instanceof \SplFileInfo) || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relPath = str_replace('\\', '/', substr($file->getPathname(), strlen($appPathRoot) + 1));
                $classFqcn = $appPrefix . str_replace('/', '\\', substr($relPath, 0, -4));
                $classes[] = $classFqcn;
            }
        }

        return $classes;
    }

    /**
     * Загрузить Composer class-map (cached между вызовами на инстансе scanner'а).
     *
     * @return array<string, string>
     */
    private function loadClassMap(): array
    {
        if ($this->classMap !== null) {
            return $this->classMap;
        }

        $path = $this->composerClassmapPath ?? (defined('ROOTPATH') ? ROOTPATH . 'vendor/composer/autoload_classmap.php' : __DIR__ . '/../../../vendor/composer/autoload_classmap.php');

        if (!is_file($path)) {
            $this->classMap = [];
            return $this->classMap;
        }

        $map = require $path;
        if (!is_array($map)) {
            $this->classMap = [];
            return $this->classMap;
        }

        $clean = [];
        foreach ($map as $class => $file) {
            if (is_string($class) && is_string($file)) {
                $clean[$class] = $file;
            }
        }
        $this->classMap = $clean;

        return $this->classMap;
    }

    /**
     * @param list<string> $namespacePrefixes
     */
    private function matchesAnyPrefix(string $class, array $namespacePrefixes): bool
    {
        foreach ($namespacePrefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reflect один класс — если у него есть атрибут {@see HandlerKey}, вернуть
     * {@see HandlerEntry}, иначе null. Любые ошибки рефлексии (autoload-сбой,
     * abstract-классы и пр.) → null (silently skip; не валим scan на единичной
     * битой записи в class-map'е).
     */
    private function inspectClass(string $class): ?HandlerEntry
    {
        if (!class_exists($class, true)) {
            return null;
        }
        /** @var class-string $class */

        $refl = new ReflectionClass($class);

        if ($refl->isAbstract() || $refl->isInterface() || $refl->isTrait()) {
            return null;
        }

        $attrs = $refl->getAttributes(HandlerKey::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs === []) {
            return null;
        }

        try {
            $metadata = $attrs[0]->newInstance();
        } catch (Throwable) {
            return null;
        }

        return new HandlerEntry($class, $metadata, $refl->getInterfaceNames());
    }
}
