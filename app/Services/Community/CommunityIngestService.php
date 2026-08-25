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

    /**
     * Имя бота в обращениях («Роби, …») — единственный источник, используется и
     * в {@see addressedToBot()}, и в {@see addressesSpecificPerson()}, чтобы не
     * заводить второй хардкод, который может разъехаться с первым.
     */
    private const BOT_NAME = 'роби';

    /**
     * Коллективные обращения к чату («Народ, …», «Ребят, …») — это обращение
     * в том числе к боту, а не к конкретному постороннему человеку.
     *
     * @var list<string>
     */
    private const COLLECTIVE_ADDRESS_WORDS = [
        'народ', 'ребят', 'ребята', 'пацаны', 'всем', 'люди', 'мужики', 'друзья',
    ];

    /**
     * Telegram `from.id` анонимного админа группы (`GroupAnonymousBot`), общий на всех
     * чатах и группах. Приходит с `is_bot: true`, но story 35
     * ({@see \App\Services\Community\CommunityAnswerMatcher::GROUP_ANONYMOUS_BOT_ID})
     * постановила считать его человеком — фильтр посторонних ботов ниже обязан делать
     * для этого id исключение, иначе ветка story 35 снова недостижима (story 45).
     */
    private const GROUP_ANONYMOUS_BOT_ID = 1087968824;

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
        if (is_array($messageRaw)) {
            $this->ingestNewMessage($messageRaw);
            return;
        }

        // Правка сообщения — story 24 делегировала её story 25, та записала в
        // Non-goals и оставила `$update['edited_message']` непрочитанным (дефект
        // ревью 2026-08-25 №2). Обновляем ТУ ЖЕ строку по UNIQUE(chat_id,message_id),
        // не вставляем новую — правка не должна плодить дубли.
        $editedRaw = $update['edited_message'] ?? null;
        if (is_array($editedRaw)) {
            $this->ingestEditedMessage($editedRaw);
        }
    }

    /**
     * @param array<array-key, mixed> $messageRaw
     */
    private function ingestNewMessage(array $messageRaw): void
    {
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

        // story 41 — автор-бот (сторонний бот в общем чате, не наш Роби — свои исходящие
        // сообщения к нам вебхуком не приходят вовсе) не пишется в `community_messages`.
        // Иначе его реплай на вопрос ловился бы `CommunityAnswerMatcher::isCancelledByHumanReply()`
        // как «человек уже помог» и молча отменял выдержку — тот же класс дефекта, что
        // чинила story 35, но для постороннего бота, а не самого автора вопроса.
        //
        // Исключение (story 45): анонимный админ группы (`GroupAnonymousBot`,
        // `self::GROUP_ANONYMOUS_BOT_ID`) тоже приходит с `is_bot: true`, но story 35
        // постановила считать его человеком — без исключения его строка не попадала
        // бы в таблицу вовсе, и ветка story 35 была бы недостижима.
        if (($fromRaw['is_bot'] ?? false) === true && $telegramUserId !== self::GROUP_ANONYMOUS_BOT_ID) {
            return;
        }

        if ((new CommunityMessageModel())->where('chat_id', $chatId)->where('message_id', $messageId)->first() !== null) {
            return; // идемпотентность (chat_id,message_id) — повторная доставка апдейта
        }

        $textRaw     = $messageRaw['text'] ?? $messageRaw['caption'] ?? null;
        $text        = is_string($textRaw) ? $textRaw : null;
        $dateRaw     = $messageRaw['date'] ?? null;
        $sentAt      = is_int($dateRaw) ? date('Y-m-d H:i:s', $dateRaw) : date('Y-m-d H:i:s');

        // `is_question` остаётся ЧИСТО эвристикой «похоже на вопрос» — эту колонку
        // читает не только тик, но и очередь `/admin/community` со счётчиками; смешивать
        // в неё «обращено к боту» значило бы, что «Роби, спасибо, всё понял» станет
        // вопросом в очереди. Доведение прямого обращения без эвристики до тика — story 57
        // (выборка `is_question=1 OR addressed_to_bot=1`), эта story её не трогает.
        //
        // Анти-флуд (дефект ревью 2026-08-25 №1) обязан глушить подслушанное, а не
        // прямое обращение к боту — иначе на «Роби, помоги» бот молчал бы после пятого
        // вопроса автора за час.
        $isAddressedToBot = $this->addressedToBot($messageRaw, $text);
        $isQuestion       = $text !== null && $this->looksLikeQuestion($text);
        if ($isQuestion && ! $isAddressedToBot && $this->authorOverQuota($telegramUserId, $sentAt)) {
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
            'addressed_to_bot'    => $isAddressedToBot ? 1 : 0,
            'status'              => 'new',
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Правка сообщения (`edited_message`) — обновляет текст и пересчитывает
     * is_question/addressed_to_bot у УЖЕ существующей строки, найденной по
     * UNIQUE(chat_id, message_id). Если строки нет (TTL-чистка, автор-бот,
     * не тот чат — сообщение никогда не проходило приём) — no-op, без вставки
     * и без падения (Non-goals истории).
     *
     * @param array<array-key, mixed> $messageRaw
     */
    private function ingestEditedMessage(array $messageRaw): void
    {
        $chatRaw   = $messageRaw['chat'] ?? null;
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

        $model    = new CommunityMessageModel();
        $existing = $model->where('chat_id', $chatId)->where('message_id', $messageId)->first();
        if ($existing === null) {
            return;
        }

        $textRaw = $messageRaw['text'] ?? $messageRaw['caption'] ?? null;
        $text    = is_string($textRaw) ? $textRaw : null;

        $telegramUserId = (int) $existing['telegram_user_id'];
        $sentAt         = (string) $existing['sent_at'];

        // Та же дисциплина, что в `ingestNewMessage()`: `is_question` — чистая эвристика,
        // квота не гасит прямое обращение к боту.
        $isAddressedToBot = $this->addressedToBot($messageRaw, $text);
        $isQuestion       = $text !== null && $this->looksLikeQuestion($text);
        if ($isQuestion && ! $isAddressedToBot && $this->authorOverQuota($telegramUserId, $sentAt)) {
            $isQuestion = false;
        }

        $model->update($existing['id'], [
            'text'             => $text,
            'is_question'      => $isQuestion ? 1 : 0,
            'addressed_to_bot' => $isAddressedToBot ? 1 : 0,
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
     *
     * Имя бота («Роби, …») и коллективные обращения к чату («Народ, …», «Ребят, …»)
     * из этого отсева исключены — это не «чужой разговор», а обращение к боту либо
     * ко всем сразу, то есть в том числе к боту.
     */
    private function addressesSpecificPerson(string $text): bool
    {
        if (preg_match('/^([А-ЯЁ][а-яё]{1,15}),\s/u', $text, $matches) !== 1) {
            return false;
        }

        $name = mb_strtolower($matches[1], 'UTF-8');

        return $name !== self::BOT_NAME && ! in_array($name, self::COLLECTIVE_ADDRESS_WORDS, true);
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
        if ($text !== null && mb_stripos(ltrim($text), self::BOT_NAME, 0, 'UTF-8') === 0) {
            return true;
        }

        if ($this->repliesToBot($message)) {
            return true;
        }

        return $text !== null && $this->mentionsBotUsername($text);
    }

    /**
     * Обращением к Роби считается реплай на сообщение именно Роби — не на сообщение
     * произвольного стороннего бота (модератора, другого игрового бота в том же чате).
     * Telegram не различает ботов признаком помимо identity, поэтому сверяем
     * `reply_to_message.from.username` с собственным `$this->botUsername` (story 45).
     *
     * @param array<array-key, mixed> $message
     */
    private function repliesToBot(array $message): bool
    {
        if ($this->botUsername === '') {
            return false;
        }

        $replyRaw = $message['reply_to_message'] ?? null;
        if (! is_array($replyRaw)) {
            return false;
        }

        $replyFromRaw = $replyRaw['from'] ?? null;
        if (! is_array($replyFromRaw)) {
            return false;
        }

        $replyUsernameRaw = $replyFromRaw['username'] ?? null;

        return is_string($replyUsernameRaw) && strcasecmp($replyUsernameRaw, $this->botUsername) === 0;
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
