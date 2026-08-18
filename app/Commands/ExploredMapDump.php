<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\World\ExploredMapService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Смоук личной карты исследованного: числа + рендер PNG для конкретного персонажа.
 *
 * Зачем CLI. Рендер идёт по РЕАЛЬНЫМ данным мира (`map` + `explored_cells`), а в
 * тестовой базе таблицы `map` нет — PHPUnit такой рендер не проверяет в принципе
 * (урок feedback_verify_render_on_db_with_real_world_data). Эта команда — Tier-1
 * способ убедиться, что запрос отрабатывает и картинка собирается, до Tier-3.
 *
 * Запуск: php spark map:explored --char=151 [--out=PATH]
 */
final class ExploredMapDump extends BaseCommand
{
    protected $group       = 'World';
    protected $name        = 'map:explored';
    protected $description = 'Показать сводку исследованного и отрисовать личную карту персонажа.';
    protected $usage       = 'map:explored --char=<id> [--x=N --y=N] [--out=PATH]';

    public function run(array $params): void
    {
        $charOpt = CLI::getOption('char');
        $charId  = is_numeric($charOpt) ? (int) $charOpt : 0;

        if ($charId <= 0) {
            CLI::error('Укажи персонажа: php spark map:explored --char=151');
            return;
        }

        $service = new ExploredMapService();
        $summary = $service->summary($charId);

        CLI::write("Персонаж #{$charId}");
        CLI::write('Открыто клеток: ' . $summary['explored']
            . ' (' . $summary['percent'] . '% мира)');

        if ($summary['explored'] === 0) {
            CLI::write('Рисовать нечего — туман войны не снят ни разу.');
            return;
        }

        CLI::write("Границы: X {$summary['min_x']}–{$summary['max_x']}, Y {$summary['min_y']}–{$summary['max_y']}"
            . " ({$summary['width']}×{$summary['height']})");

        foreach ($summary['biomes'] as $biome) {
            CLI::write("  • {$biome['name']} — {$biome['cells']} кл.");
        }

        // Позиция игрока опциональна: с ней проверяется и метка «ты здесь».
        $xOpt = CLI::getOption('x');
        $yOpt = CLI::getOption('y');
        $file = $service->renderPng(
            $charId,
            is_numeric($xOpt) ? (int) $xOpt : null,
            is_numeric($yOpt) ? (int) $yOpt : null
        );
        if ($file === null) {
            CLI::error('PNG не собрался (нет GD или не удалось записать файл).');
            return;
        }

        $out = CLI::getOption('out');
        if (is_string($out) && $out !== '') {
            if (@copy($file, $out)) {
                $file = $out;
            } else {
                CLI::error("Не удалось скопировать в {$out} — оставляю {$file}");
            }
        }

        $size = @filesize($file);
        CLI::write('PNG: ' . $file . ' (' . ($size === false ? '?' : $size) . ' байт)');
    }
}
