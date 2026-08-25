<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\CommunityMessageModel;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use Psr\Log\LoggerInterface;

/**
 * ADR-176, story 11 — половина канала «прод → локаль» между боевым сервером и ноутбуком
 * владельца (модель угроз и обоснование транспорта — сам ADR). Печатает пачку сырых
 * реплик настроенного чата сообщества в stdout как JSON; локальный Git Bash-хук (story 13)
 * тянет её по SSH и складывает в `.claude/community/`.
 *
 * Контракт (ADR-176 §Формат и ограничения):
 *  - Курсор `--since=<id>` по автоинкременту `community_messages.id`, НЕ offset — offset
 *    при параллельных вставках молча пропускает строки между вызовами.
 *  - `--limit` по умолчанию 200, жёсткий потолок 1000 ({@see clampLimit()}).
 *  - **stdout несёт только JSON.** Вся диагностика — через stderr (`CLI::error()` или
 *    прямой `fwrite(STDERR, …)`), иначе локальный парсер молча получит мусор на входе.
 *  - Отдаёт открытые вопросы (`is_question=1`) и контекст их треда (остальные сообщения
 *    того же `message_thread_id`), и ничего сверх настроенного `community.chat_id` — как
 *    и `CommunityIngestService`, отсутствующая/несовпадающая настройка это «закрыто», не
 *    «пропускаем проверку» (инвариант fail-closed ADR-176).
 *
 * Запуск: php spark community:export --since=<id> [--limit=200]
 */
final class CommunityExport extends BaseCommand
{
    protected $group       = 'Community';
    protected $name        = 'community:export';
    protected $description = 'Экспорт открытых вопросов настроенного чата сообщества в JSON (stdout) для локального pull по ADR-176.';
    protected $usage       = 'community:export --since=<id> [--limit=200]';
    protected $options     = [
        '--since' => 'Курсор по id community_messages (по умолчанию 0 — с начала буфера).',
        '--limit' => 'Сколько строк максимум (по умолчанию 200, жёсткий потолок 1000).',
    ];

    public const DEFAULT_LIMIT = 200;
    public const HARD_LIMIT_CAP = 1000;

    private GameSettingsService $settings;

    /**
     * `$logger`/`$commands` — стандартная DI-пара `BaseCommand`, **в этом порядке**: раннер
     * `Commands::discoverCommands()` каталогизирует ВСЕ команды и инстанцирует каждую как
     * `new $class($this->logger, $this)` — позиционно, ровно два аргумента. `$settings`
     * обязан идти третьим, иначе автообнаружение падает `TypeError` на каждом spark-вызове,
     * а не только на этой команде. Единственная DI-точка для тестов (двойник
     * `GameSettingsModel`, паттерн `CommunityIngestServiceTest`) — реальная БД
     * `game_settings` тестам не нужна.
     */
    public function __construct(?LoggerInterface $logger = null, ?Commands $commands = null, ?GameSettingsService $settings = null)
    {
        parent::__construct($logger ?? \Config\Services::logger(), $commands ?? \Config\Services::commands());
        $this->settings = $settings ?? new GameSettingsService();
    }

    public function run(array $params): int
    {
        $sinceRaw = CLI::getOption('since');
        $since    = is_numeric($sinceRaw) ? max(0, (int) $sinceRaw) : 0;

        $limitRaw = CLI::getOption('limit');
        $limit    = self::clampLimit(is_numeric($limitRaw) ? (int) $limitRaw : self::DEFAULT_LIMIT);

        try {
            $rows = $this->collectRows($since, $limit);
        } catch (\Throwable $e) {
            CLI::error('community:export: сбой чтения — ' . $e->getMessage());

            return EXIT_ERROR;
        }

        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            CLI::error('community:export: не удалось сериализовать выборку в JSON.');

            return EXIT_ERROR;
        }

        // Единственная запись в stdout за весь запуск — контракт «stdout только JSON».
        fwrite(STDOUT, $json . PHP_EOL);

        fwrite(STDERR, sprintf(
            'community:export: выгружено %d строк (since=%d, limit=%d)%s',
            count($rows),
            $since,
            $limit,
            PHP_EOL
        ));

        return EXIT_SUCCESS;
    }

    /**
     * Жёсткий потолок 1000 — превышение не выгружает всё, а тихо обрезается, а не 0
     * (default-заглушка при мусорном `--limit`) и не «без ограничения».
     */
    public static function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::HARD_LIMIT_CAP);
    }

    /**
     * Открытые вопросы настроенного чата + контекст их треда, курсор по id, отсортировано
     * по возрастанию id. Отдельные `new CommunityMessageModel()` на каждый builder-вызов —
     * состояние builder CI4 не сбрасывается между последовательными `where()` на одном
     * инстансе (см. memory `feedback_ci4_model_builder_state_quirk`).
     *
     * @return list<array<string, mixed>>
     */
    public function collectRows(int $since, int $limit): array
    {
        $configuredRaw = $this->settings->get('community.chat_id', '');
        $configured    = is_string($configuredRaw) ? trim($configuredRaw) : '';
        if ($configured === '' || ! is_numeric($configured)) {
            // Fail-closed (ADR-176): нет настроенного чата — нечего отдавать, не ошибка.
            return [];
        }
        $chatId = (int) $configured;

        // ->distinct() не входит в проброшенные Model-методы (только BaseBuilder),
        // поэтому явный ->builder() — как в `GameSettingsModel::listCategories()`.
        $threadResult = (new CommunityMessageModel())->builder()
            ->select('message_thread_id')
            ->distinct()
            ->where('chat_id', $chatId)
            ->where('is_question', 1)
            ->where('message_thread_id IS NOT NULL')
            ->get();

        $threadIds = [];
        if ($threadResult !== false) {
            foreach ($threadResult->getResultArray() as $row) {
                if (isset($row['message_thread_id']) && is_numeric($row['message_thread_id'])) {
                    $threadIds[] = (int) $row['message_thread_id'];
                }
            }
        }

        $rowsModel = new CommunityMessageModel();
        $rowsModel
            ->where('chat_id', $chatId)
            ->where('id >', $since)
            ->groupStart()
                ->where('is_question', 1);
        if ($threadIds !== []) {
            $rowsModel->orWhereIn('message_thread_id', $threadIds);
        }
        $rowsModel->groupEnd();

        $found = $rowsModel->orderBy('id', 'ASC')->limit($limit)->findAll();

        $rows = [];
        foreach ($found as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }
}
