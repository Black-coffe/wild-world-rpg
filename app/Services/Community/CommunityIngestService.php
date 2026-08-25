<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityMessageModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * ADR-176 (community-chat-bot), story 05 — приём сообщений группового чата сообщества.
 *
 * Пишет каждую реплику настроенного чата в `community_messages` (идемпотентно по
 * `chat_id`+`message_id`), проставляет топик/тред/автора, определяет — вопрос ли это
 * (не по знаку «?», см. {@see looksLikeQuestion()}) и адресован ли он боту, и режет
 * анти-флуд по автору. Ничего не отправляет (story 06) и не матчит с банком ответов
 * (story 08) — только ingest.
 *
 * Вызывающая сторона — `BotController::handleCommunityUpdate()` (точка расширения
 * story 01, сейчас пустая); эта story её не трогает (Закон 3, см. `## Findings`).
 *
 * Fail-closed: молчит целиком, если `community.enabled=false` или `chat_id` сообщения
 * не совпал с настроенным `community.chat_id` — отсутствие/несовпадение настройки
 * трактуется как «закрыто», а не «открыто по умолчанию».
 */
final class CommunityIngestService
{
    /**
     * Вопросительные слова/фразы контракта — совпадение целым словом (не подстрокой).
     *
     * @var list<string>
     */
    private const QUESTION_WORDS = [
        'как', 'где', 'что', 'когда', 'почему', 'зачем', 'сколько', 'какой', 'можно ли', 'кто',
    ];

    /**
     * Междометия с «?», которые по смыслу не вопрос (контракт story).
     *
     * @var list<string>
     */
    private const INTERJECTION_BLACKLIST = ['серьёзно?', 'да ну?', 'правда?', 'а?'];

    private const MIN_QUESTION_LENGTH = 3;

    private GameSettingsService $settings;
    private string $botUsername;

    public function __construct(?GameSettingsService $settings = null, ?string $botUsername = null)
    {
        $this->settings    = $settings ?? new GameSettingsService();
        $this->botUsername = ltrim($botUsername ?? (string) getenv('telegram.BOT_USERNAME'), '@');
    }

    /**
     * @param array<array-key, mixed> $update
     */
    public function handle(array $update): void
    {
        if ($this->settings->get('community.enabled', false) !== true) {
            return;
        }

        $messageRaw = $update['message'] ?? null;
        if (! is_array($messageRaw)) {
            return;
        }

        $chatRaw = $messageRaw['chat'] ?? null;
        $chatIdRaw = is_array($chatRaw) ? ($chatRaw['id'] ?? null) : null;
        if (! is_numeric($chatIdRaw)) {
            return;
        }
        $chatId = (int) $chatIdRaw;

        $configuredChatIdRaw = $this->settings->get('community.chat_id', '');
        $configuredChatId    = is_string($configuredChatIdRaw) ? $configuredChatIdRaw : '';
        if ($configuredChatId === '' || $configuredChatId !== (string) $chatId) {
            return;
        }

        $messageIdRaw = $messageRaw['message_id'] ?? null;
        if (! is_int($messageIdRaw)) {
            return;
        }
        $messageId = $messageIdRaw;

        $fromRaw          = $messageRaw['from'] ?? null;
        $telegramUserIdRaw = is_array($fromRaw) ? ($fromRaw['id'] ?? null) : null;
        if (! is_numeric($telegramUserIdRaw)) {
            return;
        }
        $telegramUserId = (int) $telegramUserIdRaw;

        if ((new CommunityMessageModel())->where('chat_id', $chatId)->where('message_id', $messageId)->first() !== null) {
            return; // идемпотентность (chat_id,message_id) — повторная доставка апдейта
        }

        $textRaw     = $messageRaw['text'] ?? $messageRaw['caption'] ?? null;
        $text        = is_string($textRaw) ? $textRaw : null;
        $dateRaw     = $messageRaw['date'] ?? null;
        $sentAt      = is_int($dateRaw) ? date('Y-m-d H:i:s', $dateRaw) : date('Y-m-d H:i:s');

        $isQuestion = $text !== null && $this->looksLikeQuestion($text);
        if ($isQuestion && $this->authorOverQuota($telegramUserId, $sentAt)) {
            $isQuestion = false;
        }

        // $fromRaw гарантированно array здесь: `$telegramUserId` уже прошёл `is_numeric()`
        // выше, а false-ветка того тернарника всегда даёт null (не numeric) — значит
        // true-ветка (is_array($fromRaw)) обязана была сработать.
        $usernameRaw = $fromRaw['username'] ?? null;

        (new CommunityMessageModel())->insert([
            'chat_id'             => $chatId,
            'message_thread_id'   => $this->intOrNull($messageRaw['message_thread_id'] ?? null),
            'message_id'          => $messageId,
            'reply_to_message_id' => $this->replyToMessageId($messageRaw),
            'telegram_user_id'    => $telegramUserId,
            'username'            => is_string($usernameRaw) ? $usernameRaw : null,
            'text'                => $text,
            'sent_at'             => $sentAt,
            'is_question'         => $isQuestion ? 1 : 0,
            'addressed_to_bot'    => $this->addressedToBot($messageRaw, $text) ? 1 : 0,
            'status'              => 'new',
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Детектор вопроса — НЕ по знаку «?»: вопросительное слово ИЛИ «?», И длина выше
     * минимума, И текст не в чёрном списке междометий, И сообщение не адресовано
     * конкретному человеку («Вась, …» — чужой разговор, а не вопрос).
     */
    private function looksLikeQuestion(string $rawText): bool
    {
        $text = trim($rawText);
        if ($text === '' || mb_strlen($text, 'UTF-8') < self::MIN_QUESTION_LENGTH) {
            return false;
        }

        if ($this->addressesSpecificPerson($text)) {
            return false;
        }

        $normalized = mb_strtolower($text, 'UTF-8');
        if (in_array($normalized, self::INTERJECTION_BLACKLIST, true)) {
            return false;
        }

        if (str_contains($text, '?')) {
            return true;
        }

        foreach (self::QUESTION_WORDS as $word) {
            if ($this->containsWholeWord($normalized, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * «Вась, а как ты качался?» — обращение к конкретному человеку в начале реплики:
     * слово с заглавной буквы (имя/обращение), сразу за которым идёт запятая.
     */
    private function addressesSpecificPerson(string $text): bool
    {
        return (bool) preg_match('/^[А-ЯЁ][а-яё]{1,15},\s/u', $text);
    }

    private function containsWholeWord(string $normalizedText, string $word): bool
    {
        $boundary = 'а-яёa-z0-9';
        $pattern  = '/(?<![' . $boundary . '])' . preg_quote($word, '/') . '(?![' . $boundary . '])/u';

        return (bool) preg_match($pattern, $normalizedText);
    }

    /**
     * Анти-флуд: свыше `community.ingest.max_questions_per_author_per_hour` вопросов
     * от одного автора за час (окно перед `$sentAt`, чтобы не зависеть от часов
     * приложения/БД) — строка пишется, но `is_question=0`.
     */
    private function authorOverQuota(int $telegramUserId, string $sentAt): bool
    {
        $limitRaw = $this->settings->get('community.ingest.max_questions_per_author_per_hour', 5);
        $limit    = is_numeric($limitRaw) ? (int) $limitRaw : 5;
        if ($limit <= 0) {
            return false;
        }

        $windowStart = date('Y-m-d H:i:s', strtotime($sentAt) - 3600);

        $count = (new CommunityMessageModel())
            ->where('telegram_user_id', $telegramUserId)
            ->where('is_question', 1)
            ->where('sent_at >=', $windowStart)
            ->where('sent_at <', $sentAt)
            ->countAllResults();

        return $count >= $limit;
    }

    /**
     * `addressed_to_bot = 1`, если: упоминание `@<bot_username>` в тексте, ИЛИ реплай
     * на сообщение бота, ИЛИ текст начинается с «Роби» (регистронезависимо).
     *
     * @param array<array-key, mixed> $message
     */
    private function addressedToBot(array $message, ?string $text): bool
    {
        if ($text !== null && mb_stripos(ltrim($text), 'роби', 0, 'UTF-8') === 0) {
            return true;
        }

        if ($this->repliesToBot($message)) {
            return true;
        }

        return $text !== null && $this->mentionsBotUsername($text);
    }

    /**
     * @param array<array-key, mixed> $message
     */
    private function repliesToBot(array $message): bool
    {
        $replyRaw = $message['reply_to_message'] ?? null;
        if (! is_array($replyRaw)) {
            return false;
        }

        $replyFromRaw = $replyRaw['from'] ?? null;

        return is_array($replyFromRaw) && ($replyFromRaw['is_bot'] ?? false) === true;
    }

    /**
     * Ищет `@<bot_username>` прямо в тексте, а не через `entities[].offset/length`.
     *
     * Telegram считает `offset`/`length` в UTF-16 code units, а не в символах — любой
     * эмодзи вне BMP (🔥, 🤖, 🏆 — почти весь популярный набор) занимает 2 такие единицы
     * против одного символа PHP-строки. Срез по этим смещениям на `mb_substr()` уезжает
     * при любом таком символе ПЕРЕД упоминанием, и `@бот` перестаёт распознаваться —
     * а адресное обращение к боту план обязывает ВСЕГДА отвечать (включая честное «не
     * знаю»), так что промах здесь — молчание на прямой вопрос, худший исход. Прямой
     * поиск по тексту не завязан на кодировку смещений вообще.
     *
     * Границу справа проверяем явно (`(?![a-z0-9_])`), чтобы `@botname_fanclub` не
     * засчитался за упоминание `@botname`.
     */
    private function mentionsBotUsername(string $text): bool
    {
        if ($this->botUsername === '') {
            return false;
        }

        $pattern = '/@' . preg_quote($this->botUsername, '/') . '(?![a-z0-9_])/iu';

        return (bool) preg_match($pattern, $text);
    }

    /**
     * @param array<array-key, mixed> $message
     */
    private function replyToMessageId(array $message): ?int
    {
        $replyRaw = $message['reply_to_message'] ?? null;
        if (! is_array($replyRaw)) {
            return null;
        }

        return $this->intOrNull($replyRaw['message_id'] ?? null);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
