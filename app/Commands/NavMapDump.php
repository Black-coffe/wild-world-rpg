<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Navigation\NavigationMapService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Регенерация артефакта карты навигации (`docs/navigation/navmap.json`) из кода.
 *
 * Граф строит {@see NavigationMapService} детерминированно (read-only). Тот же
 * payload отдаёт live-эндпоинт `/admin/navigation/data`; CLI нужен для коммита
 * статичного снимка в репо (для код-ревью / диффа навигации между релизами).
 *
 * Запуск: php spark navmap:export [--out=PATH]
 */
final class NavMapDump extends BaseCommand
{
    protected $group       = 'Navigation';
    protected $name        = 'navmap:export';
    protected $description = 'Регенерировать docs/navigation/navmap.json (граф навигации из кода).';
    protected $usage       = 'navmap:export [--out=PATH]';

    public function run(array $params): void
    {
        $out     = CLI::getOption('out');
        $outPath = is_string($out) && $out !== '' ? $out : (ROOTPATH . 'docs/navigation/navmap.json');

        $graph = (new NavigationMapService())->buildGraph();
        $json  = json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            CLI::error('Не удалось сериализовать граф навигации в JSON.');
            return;
        }

        $dir = dirname($outPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($outPath, $json);

        CLI::write('Граф навигации сохранён → ' . $outPath, 'green');
        CLI::newLine();
        CLI::write('Статистика:', 'cyan');
        foreach ($graph['stats'] as $key => $val) {
            CLI::write(sprintf('  %-12s : %d', $key, $val));
        }
    }
}
