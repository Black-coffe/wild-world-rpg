<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Handlers\HandlerDiscoveryScanner;
use App\Services\Handlers\HandlerEntry;
use App\Services\Handlers\HandlerRegistry;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Self-documenting CLI для plugin-реестра handler'ов (Phase B1, ADR-023).
 *
 * Запуск:
 *   php spark handlers:list                               # все handler'ы
 *   php spark handlers:list --namespace=App\\TaskHandlers # фильтр scan'а
 *   php spark handlers:list --interface=EventEffectInterface  # фильтр интерфейса
 *   php spark handlers:list --refresh                    # принудительно rescan (no cache)
 *
 * Phase B1 — пока что в проекте 0 handler'ов имеют атрибут {@see \App\Attributes\HandlerKey}
 * (они появятся в B2 для event-effects, B3 для task-handlers, B4 для world-objects).
 * Команда нужна как foundation + smoke-tool для проверки registry/scanner'а.
 */
final class HandlersList extends BaseCommand
{
    protected $group       = 'Handlers';
    protected $name        = 'handlers:list';
    protected $description = 'Показывает все зарегистрированные handler-классы (PHP-атрибут HandlerKey).';
    protected $usage       = 'handlers:list [--namespace=PREFIX] [--interface=FQCN] [--refresh]';
    protected $arguments   = [];
    protected $options     = [
        '--namespace' => 'Namespace-префикс для scan\'а (можно указать несколько через запятую). Default: App\\\\ (вся app).',
        '--interface' => 'Фильтр по реализуемому интерфейсу (FQCN).',
        '--refresh'   => 'Игнорировать кэш handler-registry.json, делать свежий scan.',
    ];

    public function run(array $params): void
    {
        $namespaceOption = CLI::getOption('namespace');
        $interfaceFilter = CLI::getOption('interface');
        $refresh         = (bool) CLI::getOption('refresh');

        $namespaces = is_string($namespaceOption) && $namespaceOption !== ''
            ? $this->parseNamespaces($namespaceOption)
            : ['App\\'];

        $registry = new HandlerRegistry(
            namespacePrefixes: $namespaces,
            scanner:           new HandlerDiscoveryScanner(),
            cachePath:         null,
            cacheEnabled:      !$refresh,
        );

        if ($refresh) {
            $registry->refresh();
        }

        $entries = is_string($interfaceFilter) && $interfaceFilter !== ''
            ? $registry->getByInterface($interfaceFilter)
            : $registry->all();

        if ($entries === []) {
            CLI::write('Registry пуст: ни одного класса с атрибутом #[HandlerKey] под scan-префиксами:', 'yellow');
            foreach ($namespaces as $ns) {
                CLI::write("  · {$ns}");
            }
            if (is_string($interfaceFilter) && $interfaceFilter !== '') {
                CLI::write("  (фильтр по интерфейсу: {$interfaceFilter})", 'dark_gray');
            }
            CLI::newLine();
            CLI::write('Phase B2-B4 подключат event-effects / task-handlers / world-objects. Сейчас это норма.', 'dark_gray');
            return;
        }

        CLI::write(sprintf('Найдено handler-классов: %d', count($entries)), 'green');
        CLI::newLine();

        foreach ($entries as $entry) {
            $this->printEntry($entry);
        }
    }

    /**
     * @return list<string>
     */
    private function parseNamespaces(string $raw): array
    {
        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== '');
        return array_values(array_map(
            static fn (string $p): string => str_ends_with($p, '\\') ? $p : $p . '\\',
            $parts,
        ));
    }

    private function printEntry(HandlerEntry $entry): void
    {
        CLI::write('  ' . CLI::color('#[HandlerKey]', 'cyan') . ' ' . CLI::color($entry->metadata->key, 'white'));
        CLI::write('    class       : ' . $entry->class);
        CLI::write('    displayName : ' . $entry->metadata->displayName);
        if ($entry->metadata->description !== '') {
            CLI::write('    description : ' . $entry->metadata->description);
        }
        if ($entry->interfaces !== []) {
            CLI::write('    interfaces  : ' . implode(', ', $entry->interfaces));
        }
        if ($entry->metadata->inputSchema !== []) {
            CLI::write('    inputSchema : ' . count($entry->metadata->inputSchema) . ' field(s)');
        }
        CLI::newLine();
    }
}
