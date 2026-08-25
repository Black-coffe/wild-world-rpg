<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Services\GameSettings\GameSettingsService;
use Config\CommunityVoice;
use DateTimeImmutable;

/**
 * ADR-176 (community-chat-bot), story 08 — сопоставляет вопрос игрока с банком
 * утверждённых ответов и решает, отвечать ли сейчас, позже или никогда (план §4).
 * Не отправляет ничего (это `CommunityChatSender`, story 06/09) и не решает МОЖНО ли
 * сказать текст (это `CommunityGuard`, story 07) — только КОГДА и КОМУ.
 *
 * **Полоса A (`addressed_to_bot=1`)** — порог `community.match.threshold_addressed`,
 * низкий: раз игрока обратился к боту прямо, перебивать некого. Совпало — банк-текст
 * сразу (`answer_now`); не совпало — банк-текст НЕ подставляется, вместо него уходит
 * утверждённое `CommunityVoice::UNKNOWN` тем же `answer_now` с `escalated=true`.
 * 🔴 Молчание в ответ на прямое обращение запрещено структурно: у полосы A нет ветки,
 * возвращающей `Decision::silent()`.
 *
 * **Полоса B (подслушанное)** — порог `community.match.threshold_overheard`, заметно
 * выше: бот вмешивается в чужой разговор только при уверенном совпадении. Совпало —
 * `answer_after_delay` с `community.autoreply.delay_seconds`; не совпало — `receipt_only`
 * (реакция 👀 вместо ответа, вопрос копится как открытый).
 *
 * **Срок годности банка** (план §5, рубеж 5 "плюс два срока годности") — запись не
 * участвует в матче вовсе, если `status != 'approved'`, `revoked_at` заполнен, или
 * `approved_at` старше `community.answer.max_age_days`: устаревшая запись не даёт
 * `answer_now`/`answer_after_delay` ни в одной полосе, читается как «нет совпадения»
 * (для A это по-прежнему `answer_now` с `UNKNOWN`, для B — `receipt_only`).
 *
 * **Возраст вопроса** — `community.question.max_age_hours`: вопрос старше этого срока
 * публично не отвечается банк-текстом (для A — снова `UNKNOWN`, для B — `receipt_only`),
 * иначе поздний ответ читается как некро-постинг (план §4).
 *
 * **Склейка дублей** — несколько ОТКРЫТЫХ (`status='new'`) сообщений в одном
 * `chat_id`+`message_thread_id`, матчащихся на одну и ту же запись банка, дают ОДИН
 * ответ: у самого раннего (наименьший `id`) — реальное решение с `coveredMessageIds`,
 * покрывающим все совпавшие строки; у более поздних дублей — `Decision::silent()`
 * (уже покрыты решением более раннего). Правило действует для полосы B, где решение
 * иначе повторялось бы построчно; полоса A всегда отвечает адресату лично, но также
 * несёт `coveredMessageIds` — дубли-соседи получают этот же текст без своего решения.
 *
 * **Отмена выдержки полосы B** — отдельный метод {@see isCancelledByHumanReply()}:
 * решение `answer_after_delay` вычисляется в момент матча, а человек может ответить
 * ПОЗЖЕ, во время самой выдержки. Story 09 (исполнитель отложенной отправки) обязан
 * вызвать этот метод непосредственно перед публикацией, а не полагаться на снимок,
 * посчитанный раньше.
 *
 * Матч детерминированный (Non-goals story): нормализация текста + значимые слова
 * (≥4 буквы, минус короткий стоп-лист вопросительных/служебных слов) + грубый
 * префиксный стемминг (3 символа, тот же приём, что `CommunityGuard::stems()`) +
 * коэффициент Жаккара по множествам стеммов. Никаких эмбеддингов и вызовов ИИ —
 * результат воспроизводим байт-в-байт в тесте.
 */
final class CommunityAnswerMatcher
{
    /**
     * `from.id`, которым Telegram подписывает ЛЮБОЕ сообщение, отправленное «от имени
     * группы» (анонимный админ, реплай через переключатель «Отправлять как канал»).
     * Общий для ВСЕХ анонимных админов чата — по нему нельзя отличить одного
     * анонимного человека от другого (community-chat-bot-35, см. {@see isCancelledByHumanReply()}).
     */
    private const GROUP_ANONYMOUS_BOT_ID = 1087968824;

    /** Слово короче этого не считается значимым (предлоги/союзы/частицы). */
    private const MIN_WORD_LEN = 4;

    /** Длина префикса грубого стемминга — тот же приём, что в `CommunityGuard`. */
    private const STEM_LEN = 3;

    /**
     * Вопросительные и служебные слова, которые сами по себе не несут темы вопроса:
     * «как убить X» и «как открыть Y» не обязаны совпадать из-за общего «как».
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'как', 'что', 'где', 'когда', 'почему', 'зачем', 'сколько', 'какой', 'какая',
        'какое', 'какие', 'можно', 'нужно', 'надо', 'это', 'кто', 'для', 'при', 'если',
        'меня', 'тебя', 'себя', 'который', 'которая', 'которое',
    ];

    private CommunityAnswerModel $answerModel;
    private CommunityMessageModel $messageModel;

    /** @var callable(string, mixed): mixed */
    private $settingsGetter;

    /**
     * @param (callable(string, mixed): mixed)|null $settingsGetter сеам для тестов
     *        (паттерн `CommunityChatSender`) — `GameSettingsService::get()` по умолчанию.
     */
    public function __construct(
        ?CommunityAnswerModel $answerModel = null,
        ?CommunityMessageModel $messageModel = null,
        ?GameSettingsService $settings = null,
        ?callable $settingsGetter = null,
    ) {
        $this->answerModel   = $answerModel ?? new CommunityAnswerModel();
        $this->messageModel  = $messageModel ?? new CommunityMessageModel();
        $settings              = $settings ?? new GameSettingsService();
        $this->settingsGetter  = $settingsGetter ?? [$settings, 'get'];
    }

    /**
     * @param array<string, mixed> $message строка `community_messages` (минимум:
     *        `id`, `chat_id`, `message_thread_id`, `message_id`, `text`, `sent_at`,
     *        `addressed_to_bot`).
     * @param DateTimeImmutable|null $now сеам для тестов — «сейчас» иначе не
     *        воспроизводимо детерминированно (возраст вопроса/записи банка).
     */
    public function match(array $message, ?DateTimeImmutable $now = null): Decision
    {
        $now       = $now ?? new DateTimeImmutable();
        $addressed = $this->truthy($message['addressed_to_bot'] ?? false);
        $chatId    = $this->intOrNull($message['chat_id'] ?? null) ?? 0;
        $threadId  = $this->intOrNull($message['message_thread_id'] ?? null);
        $selfId    = $this->intOrNull($message['id'] ?? null);

        $tooOld = $this->questionTooOld($message, $now);
        $bank   = $tooOld ? null : $this->bestBankMatch($this->messageText($message), $now);

        if ($addressed) {
            $threshold = $this->readFloat('community.match.threshold_addressed', 0.45);
            if ($bank !== null && $bank['score'] >= $threshold) {
                $covered = $this->coveredDuplicates($chatId, $threadId, $selfId, $bank['record'], $threshold);
                return Decision::answerNow(
                    $this->stringField($bank['record'], 'answer_text'),
                    $this->intOrNull($bank['record']['id'] ?? null),
                    $this->withSelf($selfId, $covered),
                    false,
                );
            }

            // Не совпало (или запись истекла/вопрос устарел) — не молчание, а честное
            // "не знаю" + эскалация владельцу. Никогда `Decision::silent()`.
            return Decision::answerNow(
                CommunityVoice::UNKNOWN[0],
                null,
                $selfId !== null ? [$selfId] : [],
                true,
            );
        }

        $thresholdOverheard = $this->readFloat('community.match.threshold_overheard', 0.80);
        if ($bank === null || $bank['score'] < $thresholdOverheard) {
            return Decision::receiptOnly();
        }

        if ($this->hasEarlierOpenDuplicate($chatId, $threadId, $selfId, $bank['record'], $thresholdOverheard)) {
            // Более ранний открытый вопрос в этом же топике уже матчится на ту же
            // запись банка — его решение покроет и это сообщение (склейка дублей).
            return Decision::silent();
        }

        $delaySeconds = $this->readInt('community.autoreply.delay_seconds', 75);
        $covered      = $this->coveredDuplicates($chatId, $threadId, $selfId, $bank['record'], $thresholdOverheard);
        $answerId     = $this->intOrNull($bank['record']['id'] ?? null) ?? 0;

        return Decision::answerAfterDelay(
            $this->stringField($bank['record'], 'answer_text'),
            $answerId,
            $delaySeconds,
            $this->withSelf($selfId, $covered),
        );
    }

    /**
     * Полоса B: выдержка `answer_after_delay` отменяется, если за время ожидания в
     * том же чате появился ответ **другого** человека реплаем на исходное сообщение
     * (план §4). Реплай самого автора вопроса (уточнение, дописанный скриншот) — не
     * отмена: тормоз существует, чтобы не перебивать того, кто уже помогает, а не
     * чтобы гасить выдержку от того, что спрашивающий сам себе что-то дописал (story 29).
     * Story 09 обязан вызвать это НЕПОСРЕДСТВЕННО перед публикацией — решение из
     * {@see match()} снимок момента матча, а не момента отправки.
     *
     * Анонимный админ группы (`from.id = `{@see GROUP_ANONYMOUS_BOT_ID}, общий на всех
     * анонимных админов чата) — тоже человек, а не пустая строка: `telegram_user_id`
     * в `community_messages` `NOT NULL` (ingest выходит раньше записи, если `from.id`
     * не число), так что реального `IS NULL`-реплая быть не может — но анонимного
     * автора нужно опознавать по этому общему id, а не по значению вовсе (community-chat-bot-35).
     * Если автор вопроса САМ был анонимным админом, отличить «ответил себе» от
     * «ответил другой анонимный админ» нельзя — ниже такой случай консервативно
     * считается отменой выдержки (см. story 35 notes), а не молчанием.
     *
     * @param array<string, mixed> $message строка `community_messages`, на которую
     *        ждём (нужны `chat_id` и `message_id` — Telegram-id, не PK-строка; и
     *        `telegram_user_id` автора вопроса — реплай самого автора не в счёт).
     */
    public function isCancelledByHumanReply(array $message): bool
    {
        $chatId    = $this->intOrNull($message['chat_id'] ?? null);
        $messageId = $this->intOrNull($message['message_id'] ?? null);
        $authorId  = $this->intOrNull($message['telegram_user_id'] ?? null);
        if ($chatId === null || $messageId === null) {
            return false;
        }

        $query = $this->messageModel
            ->where('chat_id', $chatId)
            ->where('reply_to_message_id', $messageId);

        // Автор вопроса известен и не анонимен (или анонимность автора не важна для
        // фильтра — см. ниже) — исключаем только его собственный реплай.
        if ($authorId !== null && $authorId !== self::GROUP_ANONYMOUS_BOT_ID) {
            $query = $query->where('telegram_user_id !=', $authorId);
        }
        // Если автор вопроса сам был анонимным админом ($authorId ===
        // GROUP_ANONYMOUS_BOT_ID), фильтр по автору НЕ применяется вовсе: любой
        // реплай, включая реплай с тем же общим id, засчитывается как отмена
        // выдержки. Различить «автор дописал сам себе» от «ответил другой
        // анонимный админ» невозможно — консервативно выбрано не молчать.

        return $query->first() !== null;
    }

    // ── банк ответов ─────────────────────────────────────────────────────

    /**
     * @return array{score: float, record: array<string, mixed>}|null
     */
    private function bestBankMatch(string $text, DateTimeImmutable $now): ?array
    {
        $textStems = $this->stems($text);
        if ($textStems === []) {
            return null;
        }

        $maxAgeDays = $this->readInt('community.answer.max_age_days', 90);

        $best = null;
        foreach ($this->activeBankRecords($now, $maxAgeDays) as $record) {
            $score = $this->similarity($textStems, $this->stems($this->stringField($record, 'question_pattern')));
            if ($best === null || $score > $best['score']) {
                $best = ['score' => $score, 'record' => $record];
            }
        }

        return $best;
    }

    /**
     * @return list<array<string, mixed>> только `status='approved'`, не отозванные,
     *         не старше `community.answer.max_age_days` от `approved_at` (план §5.5).
     */
    private function activeBankRecords(DateTimeImmutable $now, int $maxAgeDays): array
    {
        $rows = $this->answerModel->where('status', 'approved')->findAll();

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeRow($row);
            if (($normalized['revoked_at'] ?? null) !== null) {
                continue;
            }
            $approvedAt = $this->parseDateTime($normalized['approved_at'] ?? null);
            if ($approvedAt === null) {
                continue;
            }
            $ageDays = ($now->getTimestamp() - $approvedAt->getTimestamp()) / 86400;
            if ($ageDays > $maxAgeDays) {
                continue;
            }
            $out[] = $normalized;
        }

        return $out;
    }

    // ── склейка дублей ──────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function openTopicMessages(int $chatId, ?int $threadId, ?int $excludeId): array
    {
        $query = $this->messageModel->where('chat_id', $chatId)->where('status', 'new');
        $query = $threadId === null ? $query->where('message_thread_id', null) : $query->where('message_thread_id', $threadId);
        $rows  = $query->findAll();

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalizeRow($row);
            $id         = $this->intOrNull($normalized['id'] ?? null);
            if ($id === null || $id === $excludeId) {
                continue;
            }
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $record
     */
    private function matchesRecord(array $row, array $record, float $threshold): bool
    {
        $stems = $this->stems($this->messageText($row));
        if ($stems === []) {
            return false;
        }
        $score = $this->similarity($stems, $this->stems($this->stringField($record, 'question_pattern')));

        return $score >= $threshold;
    }

    /**
     * Есть ли в топике более РАННЕЕ (меньший `id`) ещё открытое сообщение, которое
     * тоже матчится на ту же запись банка — тогда решение принадлежит ему, это
     * сообщение получает `silent`.
     *
     * @param array<string, mixed> $record
     */
    private function hasEarlierOpenDuplicate(int $chatId, ?int $threadId, ?int $selfId, array $record, float $threshold): bool
    {
        foreach ($this->openTopicMessages($chatId, $threadId, null) as $row) {
            $id = $this->intOrNull($row['id'] ?? null);
            if ($id === null || $selfId === null || $id >= $selfId) {
                continue;
            }
            if ($this->matchesRecord($row, $record, $threshold)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Другие открытые сообщения того же топика, матчащиеся на ту же запись банка —
     * им уйдёт тот же текст одним ответом, а не по одному на каждое (склейка дублей).
     *
     * @param array<string, mixed> $record
     * @return list<int>
     */
    private function coveredDuplicates(int $chatId, ?int $threadId, ?int $selfId, array $record, float $threshold): array
    {
        $ids = [];
        foreach ($this->openTopicMessages($chatId, $threadId, $selfId) as $row) {
            if (! $this->matchesRecord($row, $record, $threshold)) {
                continue;
            }
            $id = $this->intOrNull($row['id'] ?? null);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function withSelf(?int $selfId, array $ids): array
    {
        if ($selfId !== null) {
            $ids[] = $selfId;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    // ── возраст ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $message */
    private function questionTooOld(array $message, DateTimeImmutable $now): bool
    {
        $sentAt = $this->parseDateTime($message['sent_at'] ?? null);
        if ($sentAt === null) {
            return false;
        }
        $maxAgeHours = $this->readInt('community.question.max_age_hours', 48);
        $ageHours    = ($now->getTimestamp() - $sentAt->getTimestamp()) / 3600;

        return $ageHours > $maxAgeHours;
    }

    // ── похожесть текста (детерминированная, без ИИ) ────────────────────

    /**
     * Коэффициент Жаккара по множествам стеммов: |пересечение| / |объединение|.
     * Не substring и не подсчёт общих слов — общее короткое слово («убить») в двух
     * непохожих вопросах даёт низкий коэффициент, а не ложное совпадение.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = array_intersect($a, $b);
        $union        = array_unique(array_merge($a, $b));

        return count($intersection) / count($union);
    }

    /**
     * Значимые слова (≥{@see MIN_WORD_LEN} букв, минус {@see STOPWORDS}), приведённые
     * к грубому префиксному стемму — гасит русское словоизменение («вещи»/«вещей»)
     * тем же приёмом, что `CommunityGuard::stems()`.
     *
     * @return list<string>
     */
    private function stems(string $text): array
    {
        preg_match_all('/\p{L}+/u', mb_strtolower($text), $matches);

        $stems = [];
        foreach ($matches[0] as $word) {
            if (mb_strlen($word) < self::MIN_WORD_LEN) {
                continue;
            }
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $stems[] = mb_substr($word, 0, self::STEM_LEN);
        }

        return array_values(array_unique($stems));
    }

    // ── типобезопасные читатели ──────────────────────────────────────────

    /**
     * Строка `findAll()` CI4 приходит как `array<int|string, mixed>` — приводит ключи
     * к `string` без изменения значений, чтобы дальше типизировать как `array<string,
     * mixed>` (phpstan L9).
     *
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function messageText(array $row): string
    {
        return $this->stringField($row, 'text');
    }

    /** @param array<string, mixed> $row */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_numeric($value) ? ((int) $value === 1) : false;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $parsed !== false ? $parsed : null;
    }

    /**
     * Читатель настроек, сужающий `mixed` из `$settingsGetter` до `float` через
     * переменную (не прямой cast на offset — phpstan L9 `cast.int`/`cast.float` на
     * `mixed`, memory `feedback_phpstan_no_mixed_to_int_cast`).
     */
    private function readFloat(string $key, float $default): float
    {
        $raw = ($this->settingsGetter)($key, $default);

        return is_numeric($raw) ? (float) $raw : $default;
    }

    private function readInt(string $key, int $default): int
    {
        $raw = ($this->settingsGetter)($key, $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }
}

/**
 * Решение матчера — намеренно в одном файле с `CommunityAnswerMatcher` (не отдельным
 * файлом — story 08 не в праве заводить файлы вне `## Files`, тот же приём, что
 * `Verdict` внутри `CommunityGuard.php`, story 07).
 */
final class Decision
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $text,
        public readonly ?int $answerId,
        public readonly ?int $delaySeconds,
        /** @var list<int> */
        public readonly array $coveredMessageIds,
        public readonly bool $escalated,
    ) {
    }

    /** @param list<int> $coveredMessageIds */
    public static function answerNow(string $text, ?int $answerId, array $coveredMessageIds, bool $escalated): self
    {
        return new self('answer_now', $text, $answerId, null, $coveredMessageIds, $escalated);
    }

    /** @param list<int> $coveredMessageIds */
    public static function answerAfterDelay(string $text, int $answerId, int $delaySeconds, array $coveredMessageIds): self
    {
        return new self('answer_after_delay', $text, $answerId, $delaySeconds, $coveredMessageIds, false);
    }

    public static function receiptOnly(): self
    {
        return new self('receipt_only', null, null, null, [], false);
    }

    public static function silent(): self
    {
        return new self('silent', null, null, null, [], false);
    }

    public function isAnswerNow(): bool
    {
        return $this->status === 'answer_now';
    }

    public function isAnswerAfterDelay(): bool
    {
        return $this->status === 'answer_after_delay';
    }

    public function isReceiptOnly(): bool
    {
        return $this->status === 'receipt_only';
    }

    public function isSilent(): bool
    {
        return $this->status === 'silent';
    }
}
