<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\CommunityAnswerModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * ADR-176, story 11 — вторая половина канала «локаль → прод» (ADR обосновывает транспорт
 * и модель угроз). Читает JSON-пачку черновиков ответов со stdin и пишет её в
 * `community_answers`. Локальный хук (story 13) собирает пачку из решений, принятых в
 * сессии Claude Code, генерит `client_key` (ULID) и шлёт её сюда по SSH.
 *
 * Контракт (ADR-176 §Модель угроз, §Формат и ограничения) — это и есть защита push-канала:
 * опасен именно push, не pull, потому что через него на прод заезжает текст, который бот
 * скажет игрокам от своего лица.
 *
 *  🔴 **Создаёт строки только со `status='draft'`.** Любой `status`, пришедший во входном
 *     JSON, игнорируется — вставка никогда не передаёт это поле, БД-default решает сама.
 *  🔴 **Строку в статусе `approved`/`rejected`/`revoked` импорт не трогает** — иначе
 *     повторный push тихо отменил бы решение владельца, принятое в `/admin/community`.
 *     Изменять импорт умеет только строку, всё ещё лежащую в `draft`.
 *  - Идемпотентность — по `client_key` (UNIQUE, story 02): повторный импорт той же пачки
 *    не плодит строк, а обновляет уже существующий `draft`.
 *  - Максимум 100 черновиков и 256 КБ на вызов — превышение отклоняет пачку **целиком**
 *    (не частично: неполный импорт хуже, чем понятная ошибка). Черновик длиннее 3500
 *    символов отклоняется **поштучно** — остальная пачка всё равно доходит до банка.
 *
 * Диагностика (счётчики создано/обновлено/проигнорировано, предупреждения о неизменяемых
 * строках) — только в stderr; stdout несёт компактную JSON-сводку для локального хука.
 *
 * Запуск: echo '[...]' | php spark community:import
 */
final class CommunityImport extends BaseCommand
{
    protected $group       = 'Community';
    protected $name        = 'community:import';
    protected $description = 'Импорт черновиков ответов community-chat-bot из JSON (stdin) в community_answers, всегда status=draft (ADR-176).';
    protected $usage       = "echo '[{...}]' | php spark community:import";

    public const MAX_BYTES          = 262_144; // 256 КБ
    public const MAX_DRAFTS         = 100;
    public const MAX_ANSWER_LENGTH  = 3500;
    public const MAX_CLIENT_KEY_LEN = 32;

    public function run(array $params): int
    {
        $raw    = $this->readStdin();
        $parsed = self::parseInput($raw);

        if (! $parsed['ok']) {
            CLI::error('community:import: ' . $parsed['error']);

            return EXIT_ERROR;
        }

        if ($parsed['drafts'] === [] && trim($raw) === '') {
            $this->printSummary(['created' => 0, 'updated' => 0, 'ignored' => 0, 'messages' => []]);

            return EXIT_SUCCESS;
        }

        $result = $this->applyBatch($parsed['drafts']);
        $this->printSummary($result);

        return EXIT_SUCCESS;
    }

    /**
     * Разбор и провалидация сырого stdin **без** побочных эффектов (без БД) — вынесено
     * из `run()`, чтобы тестироваться на строке напрямую, не читая настоящий STDIN
     * (в тестовом раннере это заблокировало бы процесс в ожидании реального ввода).
     * Пустой вход — легитимный случай (`ok=true`, `drafts=[]`), не ошибка.
     *
     * @return array{ok: bool, drafts: list<mixed>, error: ?string}
     */
    public static function parseInput(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return [
                'ok'     => false,
                'drafts' => [],
                'error'  => sprintf(
                    'вход %d байт превышает потолок %d байт — пачка отклонена целиком.',
                    strlen($raw),
                    self::MAX_BYTES
                ),
            ];
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['ok' => true, 'drafts' => [], 'error' => null];
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return ['ok' => false, 'drafts' => [], 'error' => 'вход не является валидным JSON.'];
        }

        $drafts = array_is_list($decoded) ? $decoded : ($decoded['drafts'] ?? null);
        if (! is_array($drafts)) {
            return ['ok' => false, 'drafts' => [], 'error' => 'ожидается JSON-массив черновиков или {"drafts": [...]}.'];
        }

        if (count($drafts) > self::MAX_DRAFTS) {
            return [
                'ok'     => false,
                'drafts' => [],
                'error'  => sprintf(
                    '%d черновиков превышает потолок %d — пачка отклонена целиком.',
                    count($drafts),
                    self::MAX_DRAFTS
                ),
            ];
        }

        return ['ok' => true, 'drafts' => array_values($drafts), 'error' => null];
    }

    /**
     * Ядро команды — вынесено в public-метод, чтобы тестироваться напрямую на реальной
     * тестовой БД (паттерн `CommunityIngestServiceTest`), без прогона stdin/CLI.
     *
     * @param list<mixed> $drafts
     * @return array{created: int, updated: int, ignored: int, messages: list<string>}
     */
    public function applyBatch(array $drafts): array
    {
        $created  = 0;
        $updated  = 0;
        $ignored  = 0;
        $messages = [];

        foreach ($drafts as $draft) {
            if (! is_array($draft)) {
                ++$ignored;
                $messages[] = 'community:import: пропущен черновик — не JSON-объект.';
                continue;
            }

            $clientKeyRaw = $draft['client_key'] ?? null;
            $clientKey    = is_string($clientKeyRaw) ? trim($clientKeyRaw) : '';
            if ($clientKey === '' || mb_strlen($clientKey, 'UTF-8') > self::MAX_CLIENT_KEY_LEN) {
                ++$ignored;
                $messages[] = 'community:import: пропущен черновик — некорректный или пустой client_key.';
                continue;
            }

            $questionPattern = is_string($draft['question_pattern'] ?? null) ? trim((string) $draft['question_pattern']) : '';
            $answerText      = is_string($draft['answer_text'] ?? null) ? (string) $draft['answer_text'] : '';
            $sourceRef       = is_string($draft['source_ref'] ?? null) ? trim((string) $draft['source_ref']) : '';
            $requiresSettingRaw = $draft['requires_setting'] ?? null;
            $requiresSetting    = is_string($requiresSettingRaw) && trim($requiresSettingRaw) !== ''
                ? trim($requiresSettingRaw)
                : null;

            if ($questionPattern === '' || trim($answerText) === '' || $sourceRef === '') {
                ++$ignored;
                $messages[] = sprintf('community:import: черновик %s пропущен — не хватает обязательного поля.', $clientKey);
                continue;
            }

            if (mb_strlen($answerText, 'UTF-8') > self::MAX_ANSWER_LENGTH) {
                ++$ignored;
                $messages[] = sprintf(
                    'community:import: черновик %s пропущен — answer_text длиннее %d символов.',
                    $clientKey,
                    self::MAX_ANSWER_LENGTH
                );
                continue;
            }

            // Свежий инстанс на каждый lookup в цикле — builder CI4 иначе копит `where()`
            // между итерациями (memory `feedback_ci4_model_builder_state_quirk`).
            $existingRaw = (new CommunityAnswerModel())->where('client_key', $clientKey)->first();
            $existing    = is_array($existingRaw) ? self::normalizeRow($existingRaw) : null;

            $fields = [
                'client_key'       => $clientKey,
                'question_pattern' => $questionPattern,
                'answer_text'      => $answerText,
                'requires_setting' => $requiresSetting,
                'source_ref'       => $sourceRef,
                // 'status' сюда никогда не попадает — insert/update не в праве его задать,
                // это и есть защита push-канала (ADR-176 §Модель угроз).
            ];

            if ($existing === null) {
                (new CommunityAnswerModel())->insert($fields);
                ++$created;
                continue;
            }

            $existingStatus = is_string($existing['status'] ?? null) ? $existing['status'] : '';
            if ($existingStatus !== 'draft') {
                ++$ignored;
                $messages[] = sprintf(
                    'community:import: черновик %s уже в статусе «%s» — импорт его не меняет.',
                    $clientKey,
                    $existingStatus !== '' ? $existingStatus : '?'
                );
                continue;
            }

            $existingIdRaw = $existing['id'] ?? null;
            $existingId    = is_numeric($existingIdRaw) ? (int) $existingIdRaw : null;
            if ($existingId === null) {
                ++$ignored;
                $messages[] = sprintf('community:import: черновик %s пропущен — строка без корректного id.', $clientKey);
                continue;
            }
            (new CommunityAnswerModel())->update($existingId, $fields);
            ++$updated;
        }

        return ['created' => $created, 'updated' => $updated, 'ignored' => $ignored, 'messages' => $messages];
    }

    private function readStdin(): string
    {
        $content = stream_get_contents(STDIN);

        return $content === false ? '' : $content;
    }

    /**
     * Строка `first()` CI4 приходит как `array<int|string, mixed>` — приводит ключи к
     * `string` без изменения значений (паттерн `CommunityAnswerMatcher::normalizeRow()`).
     *
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array{created: int, updated: int, ignored: int, messages: list<string>} $result
     */
    private function printSummary(array $result): void
    {
        foreach ($result['messages'] as $message) {
            fwrite(STDERR, $message . PHP_EOL);
        }

        fwrite(STDERR, sprintf(
            'community:import: создано=%d обновлено=%d проигнорировано=%d%s',
            $result['created'],
            $result['updated'],
            $result['ignored'],
            PHP_EOL
        ));

        $json = json_encode([
            'created' => $result['created'],
            'updated' => $result['updated'],
            'ignored' => $result['ignored'],
        ], JSON_UNESCAPED_UNICODE);

        fwrite(STDOUT, ($json !== false ? $json : '{}') . PHP_EOL);
    }
}
