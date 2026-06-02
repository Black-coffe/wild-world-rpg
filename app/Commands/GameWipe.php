<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\AdminAuditLogModel;
use App\Services\Admin\WipeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Полный вайп сервера из консоли (ADR-087).
 *
 *   php spark game:wipe                          — сухой отчёт (preview), НИЧЕГО не меняет
 *   php spark game:wipe --confirm=WIPE-ALL-PLAYERS — реальный вайп всех игроков
 *
 * Безопасный путь для smoke-тестирования движка на testbot и аварийный CLI-доступ.
 * Деструктивный запуск требует точную фразу подтверждения. Admin-UI (WipeController)
 * использует тот же {@see WipeService}.
 */
class GameWipe extends BaseCommand
{
    protected $group       = 'Game';
    protected $name        = 'game:wipe';
    protected $description = 'Полный вайп всех игроков (сброс прогресса). По умолчанию — сухой отчёт.';
    protected $usage       = 'game:wipe [--confirm=WIPE-ALL-PLAYERS]';
    protected $arguments   = [];
    protected $options     = [
        '--confirm' => 'Точная фраза WIPE-ALL-PLAYERS для реального запуска (иначе только preview).',
    ];

    private const CONFIRM_PHRASE = 'WIPE-ALL-PLAYERS';

    public function run(array $params)
    {
        $service = new WipeService();
        $preview = $service->preview();

        $this->printPreview($preview);

        $gaps = $preview['coverage'];
        if ($gaps['unclassified'] !== []) {
            CLI::error('🔴 Незаклассифицированные таблицы (вайп невозможен): ' . implode(', ', $gaps['unclassified']));
            CLI::write('Добавь их в Config\\WipeManifest. См. ADR-087.', 'yellow');
            return;
        }
        if ($gaps['stale'] !== []) {
            CLI::write('⚠ Stale-записи манифеста (таблиц нет в БД): ' . implode(', ', $gaps['stale']), 'yellow');
        }

        $confirm = $this->resolveConfirm();

        if ($confirm === '') {
            CLI::write('');
            CLI::write('Это был СУХОЙ ОТЧЁТ — ничего не изменено.', 'cyan');
            CLI::write('Для реального вайпа: php spark game:wipe --confirm=' . self::CONFIRM_PHRASE, 'yellow');
            return;
        }

        if ($confirm !== self::CONFIRM_PHRASE) {
            CLI::error('Неверная фраза подтверждения. Ожидается: ' . self::CONFIRM_PHRASE);
            return;
        }

        CLI::write('');
        CLI::write('🔥 ЗАПУСК ПОЛНОГО ВАЙПА...', 'red');

        try {
            $report = $service->wipeAll();
        } catch (Throwable $e) {
            CLI::error('Вайп провалился: ' . $e->getMessage());
            log_message('error', '[game:wipe] ' . $e->getMessage());
            return;
        }

        $deletedTotal = array_sum($report['deleted']);
        CLI::write('✓ Вайп завершён.', 'green');
        CLI::write("  Удалено строк: {$deletedTotal} в " . count($report['deleted']) . ' таблицах', 'green');
        CLI::write("  Персонажей сброшено/респавнено: {$report['characters_respawned']}", 'green');

        try {
            (new AdminAuditLogModel())->record(0, 'GAME_WIPE_CLI', 'server', null, [
                'deleted'              => $report['deleted'],
                'reset'                => $report['reset'],
                'characters_respawned' => $report['characters_respawned'],
            ]);
        } catch (Throwable $e) {
            log_message('error', '[game:wipe] audit failed: ' . $e->getMessage());
        }

        log_message('info', "[game:wipe] DONE deleted={$deletedTotal} respawned={$report['characters_respawned']}");
        CLI::write('Не забудь разослать игрокам сообщение о вайпе (admin /admin/wipe или send-message).', 'yellow');
    }

    /**
     * Достаёт фразу подтверждения, поддерживая обе формы:
     *   --confirm WIPE-ALL-PLAYERS   (пробел; нативно для CI4)
     *   --confirm=WIPE-ALL-PLAYERS   (=; CI4-парсер кладёт всё в ключ опции)
     *
     * Без этого `=`-форма (которую советует usage-строка) молча уходила бы в dry-run.
     */
    private function resolveConfirm(): string
    {
        $confirmRaw = CLI::getOption('confirm');
        if (is_string($confirmRaw) && $confirmRaw !== '') {
            return $confirmRaw;
        }

        foreach (array_keys(CLI::getOptions()) as $key) {
            if (str_starts_with($key, 'confirm=')) {
                return substr($key, strlen('confirm='));
            }
        }

        return '';
    }

    /**
     * @param array{players:int, characters:int, tables: list<array{table:string, strategy:string, note:string, rows:int, affected:int, detail:string}>, coverage: array{unclassified: list<string>, stale: list<string>}, totals: array{rows_deleted:int, rows_reset:int, tables_kept:int, tables_deleted:int, tables_reset:int}} $preview
     */
    private function printPreview(array $preview): void
    {
        CLI::write('');
        CLI::write('═══ ПРЕВЬЮ ВАЙПА (' . ($preview['players']) . ' аккаунтов, ' . $preview['characters'] . ' персонажей) ═══', 'white');

        $thead = ['Таблица', 'Стратегия', 'Строк', 'Действие'];
        $tbody = [];
        foreach ($preview['tables'] as $t) {
            // KEEP-таблицы не засоряем — показываем только то, что меняется.
            if ($t['strategy'] === 'keep') {
                continue;
            }
            $tbody[] = [$t['table'], $t['strategy'], (string) $t['rows'], $t['detail']];
        }
        CLI::table($tbody, $thead);

        $tot = $preview['totals'];
        CLI::write('Итого: удалить строк ' . $tot['rows_deleted'] . ', сбросить строк ' . $tot['rows_reset']
            . ' | сохранить таблиц ' . $tot['tables_kept'], 'cyan');
    }
}
