<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\AdminAuditLogModel;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Story 22 (community-chat-bot, ADR-176) — механизм под обещание закрепа «хранится 30
 * дней, дальше удаляется автоматически».
 *
 * Запуск: php spark community:cleanup
 * Расписание: Config\Tasks → daily('03:45') (после player-actions:cleanup 03:40).
 *
 * Две независимые оси:
 *   1. TTL — удалить строки `community_messages` старше community.retention_days по
 *      sent_at (не created_at: sent_at — момент реплики в чате, тот самый срок из
 *      обещания игрокам).
 *   2. Зависшие вопросы — строки status='new' старше community.question.max_age_hours
 *      (тот же порог, которым CommunityAnswerMatcher уже режет старые вопросы для
 *      публикации) переводятся в терминальный статус 'ignored', чтобы тик и матчер
 *      перестали пересканировать их каждую минуту.
 *
 * НЕ гейтится community.enabled — обещание про удаление не должно зависеть от того,
 * включён ли автоответ (см. contract story). `community_answers` (банк) не трогается
 * вообще: это отдельная таблица, KEEP, авторский корпус без TTL.
 *
 * Story 32, acceptance «не исчезает молча»: каждая закрытая зависшая строка пишет
 * `COMMUNITY_QUESTION_AUTO_CLOSED` в `admin_audit_log` — иначе она молча выпадает из
 * `CommunityController::openQuestionsBuilder()` (только `new`/`escalated`), и владелец
 * никогда не узнаёт, что вопрос остался без ответа. `/admin/community` читает эти
 * записи в KPI «закрыто чисткой без ответа» ({@see \App\Controllers\Admin\CommunityController::computeMetrics()}).
 *
 * Идемпотентна: оба запроса условные (WHERE по возрасту/статусу), повторный прогон
 * в тот же день просто находит 0 строк и ничего не делает.
 *
 * Pattern: 1:1 по духу с CleanupPlayerActionLog (TTL-ось, без кап-оси — объём группового
 * чата на порядки меньше firehose) + DI-конструктор по образцу CommunityExport (тесты
 * подменяют GameSettingsService без реальной таблицы game_settings).
 */
class CommunityCleanup extends BaseCommand
{
    protected $group       = 'Tasks';
    protected $name        = 'community:cleanup';
    protected $description = 'Удаляет community_messages старше community.retention_days и закрывает зависшие вопросы (status=new) старше community.question.max_age_hours.';
    protected $usage       = 'community:cleanup [--days=] [--max-age-hours=] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--days'          => 'TTL: удалить строки старше N дней (default — GameSettings community.retention_days)',
        '--max-age-hours' => 'Порог зависших вопросов: закрыть status=new старше N часов (default — GameSettings community.question.max_age_hours)',
        '--dry-run'       => 'Только показать, сколько будет удалено/закрыто, без выполнения',
    ];

    private GameSettingsService $settings;
    private AdminAuditLogModel $auditModel;

    /**
     * `$logger`/`$commands` — стандартная DI-пара `BaseCommand`, в этом порядке (см.
     * CommunityExport для полного объяснения автообнаружения). `$settings`/`$auditModel`
     * — четвёртый и пятый, DI-точки для тестов (двойник настроек, реальная модель
     * аудита — таблица `admin_audit_log` уже часть базовой схемы `tests`).
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?Commands $commands = null,
        ?GameSettingsService $settings = null,
        ?AdminAuditLogModel $auditModel = null,
    ) {
        parent::__construct($logger ?? \Config\Services::logger(), $commands ?? \Config\Services::commands());
        $this->settings   = $settings ?? new GameSettingsService();
        $this->auditModel = $auditModel ?? new AdminAuditLogModel();
    }

    public function run(array $params)
    {
        $daysOpt = CLI::getOption('days');
        $days    = is_numeric($daysOpt) ? (int) $daysOpt : self::gsInt($this->settings, 'community.retention_days', 30);

        $hoursOpt    = CLI::getOption('max-age-hours');
        $maxAgeHours = is_numeric($hoursOpt) ? (int) $hoursOpt : self::gsInt($this->settings, 'community.question.max_age_hours', 48);

        $dryRun = (bool) CLI::getOption('dry-run');

        $result = $this->cleanup($days, $maxAgeHours, $dryRun);

        if ($dryRun) {
            CLI::write("TTL: к удалению {$result['ttlCandidates']} строк старше {$days} дней (по sent_at).", 'cyan');
            CLI::write("Зависшие: {$result['staleCandidates']} строк status=new старше {$maxAgeHours}ч.", 'cyan');
            CLI::write('--dry-run: реально ничего не изменено.', 'cyan');
            return;
        }

        CLI::write("✓ Готово. Удалено {$result['ttlDeleted']} (TTL={$days}д), закрыто {$result['staleClosed']} зависших (порог={$maxAgeHours}ч).", 'green');
        log_message(
            'info',
            "[community:cleanup] removed {$result['ttlDeleted']} rows (days={$days}), closed {$result['staleClosed']} stale new rows (max_age_hours={$maxAgeHours})"
        );
    }

    /**
     * Ядро без CLI-обвязки — тестируется напрямую (`DatabaseTestTrait` на группе `tests`).
     *
     * @return array{ttlDeleted: int, staleClosed: int, ttlCandidates: int, staleCandidates: int}
     */
    public function cleanup(int $days, int $maxAgeHours, bool $dryRun = false): array
    {
        $db = Database::connect();

        $ttlCandidates = $days > 0
            ? $this->scalar('SELECT COUNT(*) AS c FROM community_messages WHERE sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days])
            : 0;

        $staleCandidates = $maxAgeHours > 0
            ? $this->scalar(
                "SELECT COUNT(*) AS c FROM community_messages WHERE status = 'new' AND sent_at < DATE_SUB(NOW(), INTERVAL ? HOUR)",
                [$maxAgeHours]
            )
            : 0;

        if ($dryRun) {
            return ['ttlDeleted' => 0, 'staleClosed' => 0, 'ttlCandidates' => $ttlCandidates, 'staleCandidates' => $staleCandidates];
        }

        $ttlDeleted = 0;
        if ($days > 0 && $ttlCandidates > 0) {
            $db->query(
                'DELETE FROM community_messages WHERE sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$days]
            );
            $ttlDeleted = $db->affectedRows();
        }

        $staleClosed = 0;
        if ($maxAgeHours > 0 && $staleCandidates > 0) {
            // Снимок id и сам UPDATE — в одной транзакции, снимок берётся с
            // `FOR UPDATE` (row-lock): строка, которую параллельно меняет другая
            // транзакция (уходит из `new`), либо ждёт эту блокировку и не входит
            // в снимок при следующем прогоне, либо уже вне снимка — учтённой
            // закрытой считается только строка, которую этот же UPDATE
            // действительно перевёл (story 42).
            $db->transStart();

            $staleIds = $this->staleQuestionIdsForUpdate($maxAgeHours);

            if ($staleIds !== []) {
                $placeholders = implode(',', array_fill(0, count($staleIds), '?'));
                $db->query(
                    "UPDATE community_messages SET status = 'ignored' WHERE status = 'new' AND id IN ({$placeholders})",
                    $staleIds
                );
                $staleClosed = $db->affectedRows();
            }

            $db->transComplete();

            $this->auditAutoClosed($staleIds, $maxAgeHours);
        }

        return ['ttlDeleted' => $ttlDeleted, 'staleClosed' => $staleClosed, 'ttlCandidates' => $ttlCandidates, 'staleCandidates' => $staleCandidates];
    }

    /**
     * Снимок id зависших вопросов с row-lock (`FOR UPDATE`) — вызывать только внутри
     * транзакции, которая следом же и переводит эти строки в `ignored`.
     *
     * @return list<int>
     */
    private function staleQuestionIdsForUpdate(int $maxAgeHours): array
    {
        $res = Database::connect()->query(
            "SELECT id FROM community_messages WHERE status = 'new' AND sent_at < DATE_SUB(NOW(), INTERVAL ? HOUR) FOR UPDATE",
            [$maxAgeHours]
        );
        if (! $res instanceof BaseResult) {
            return [];
        }

        $ids = [];
        foreach ($res->getResultArray() as $row) {
            if (isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Story 32, acceptance «не исчезает молча» — по одной строке аудита на каждый
     * закрытый вопрос; источник для KPI «закрыто чисткой без ответа» на
     * `/admin/community`. Аудит-сбой не должен ронять саму чистку (тот же приём,
     * что `CommunityChatSender::audit()`).
     *
     * @param list<int> $ids
     */
    private function auditAutoClosed(array $ids, int $maxAgeHours): void
    {
        foreach ($ids as $id) {
            try {
                $this->auditModel->insert([
                    'admin_user_id' => 0, // 0 = крон, не человек за админкой
                    'action'        => 'COMMUNITY_QUESTION_AUTO_CLOSED',
                    'target_type'   => 'community_message',
                    'target_id'     => $id,
                    'payload'       => json_encode(['max_age_hours' => $maxAgeHours], JSON_UNESCAPED_UNICODE),
                    'ip_address'    => null,
                    'user_agent'    => null,
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $e) {
                log_message('error', '[community:cleanup] audit insert failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Скалярный COUNT с type-guard результата (query() возвращает bool|BaseResult|Query).
     *
     * @param list<int|string> $binds
     */
    private function scalar(string $sql, array $binds = []): int
    {
        $res = Database::connect()->query($sql, $binds);
        if (! $res instanceof \CodeIgniter\Database\BaseResult) {
            return 0;
        }
        $val = $res->getRow('c');
        return is_numeric($val) ? (int) $val : 0;
    }

    private static function gsInt(GameSettingsService $gs, string $key, int $default): int
    {
        $raw = $gs->get($key, $default);
        return is_numeric($raw) ? (int) $raw : $default;
    }
}
