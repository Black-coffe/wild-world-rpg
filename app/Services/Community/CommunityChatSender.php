<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\AdminAuditLogModel;
use App\Models\CommunityMessageModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Telegram\Request;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use Throwable;

/**
 * ADR-176 (community-chat-bot, story 06) — единственная точка, через которую бот
 * говорит в групповом чате: ответ в топик реплаем на исходное сообщение
 * (`sendAnswer`) и служебная реакция-эмодзи вместо молчания (`react`).
 *
 * `sendAnswer()` — автоматический тик (`CommunityAutoReplyHandler`). `sendManualAnswer()`
 * (story 18) — владелец за админкой: снимает килсвитч `community.autoreply.enabled`,
 * потолок в час и кулдаун автора (они существуют, чтобы бот не забивал топик сам собой,
 * а не чтобы ограничивать живого владельца), но не другие гейты — `community.enabled`,
 * `silent_topics`, длина/парность `*`/канон имени остаются для обеих отправок. Аудит
 * различает их именем действия (`COMMUNITY_MANUAL_ANSWER_*` vs `COMMUNITY_ANSWER_*`),
 * иначе метрика «бот против живых» посчитает ручные ответы владельца ответами бота.
 * `App\Controllers\Admin\CommunityController` (story 12) на момент этой story ещё
 * зовёт `sendAnswer()` — сама дверь `sendManualAnswer()` открыта, но не подключена
 * (`CommunityController.php` вне `## Files`, Закон 3); подключение — отдельная story.
 *
 * НЕ `BroadcastService`/`MediaSender`/`MessageController` — та инфраструктура шлёт
 * только в личные чаты игроков (по `telegram_id`), тут же адресат всегда групповой
 * `chat_id` + `message_thread_id` конкретного топика, и ответ ОБЯЗАН быть реплаем —
 * иначе в топике с сотнями сообщений в сутки он нечитаем без привязки.
 *
 * Гейты (в порядке из контракта story) читаются из `GameSettingsService`:
 * `community.enabled` → `community.autoreply.enabled` → топик не в `silent_topics`
 * → потолок `max_per_hour_per_topic` → кулдаун `author_cooldown_seconds` → длина
 * `max_answer_chars`. При срабатывании потолка бот молчит ПОЛНОСТЬЮ, не через раз —
 * выборочное молчание игроки читают как «мне не отвечают».
 *
 * Реакция (`react()`) — квитанция ВМЕСТО молчания, не сообщение: у неё нет своего
 * гейта потолка/кулдауна и она НЕ расходует и не пополняет чужой (ремонтный круг 1,
 * 2026-08-25) — иначе шестой вопрос в топике за час не получит даже 👀, и это читается
 * игроком как «меня игнорят», ровно то, ради предотвращения чего реакции появились.
 * Для реакции остаются только `community.enabled` → `autoreply.enabled` → `silent_topics`.
 *
 * Потолок/кулдаун текстовых ответов считаются по собственным прошлым отправкам —
 * источник правды это `admin_audit_log`, action `COMMUNITY_ANSWER_SENT` (реакции,
 * `COMMUNITY_REACTION_SENT`, в счёт НЕ идут), присоединённый к `community_messages`
 * через `target_id`, чтобы не заводить третью таблицу под то же самое (план явно
 * запрещает лишние сущности для community-подсистемы). Каждая попытка (успех и отказ,
 * ответ и реакция) пишется в `admin_audit_log` с причиной — `admin_user_id=0`
 * помечает, что действие сделал бот, а не человек за админкой.
 *
 * ⚠️ Vendor `longman/telegram-bot` 0.81.0 (текущий `composer.lock`) не знает действия
 * `setMessageReaction` вовсе — его нет в приватном whitelist `Request::$actions`,
 * `ensureValidAction()` бросит `TelegramException` раньше, чем сработает
 * `PHPUNIT_TESTSUITE`-заглушка. Апгрейд composer вне `## Files` этой story, поэтому
 * `react()` в проде идёт напрямую на Bot API через curl (`rawBotApiCall()`), минуя
 * `App\Services\Telegram\Request`. Контракт story называет метод `sendMessageReaction`
 * — реального метода с таким именем в Bot API нет (правильное имя — `setMessageReaction`,
 * `POST /bot<token>/setMessageReaction`), используется оно.
 *
 * Оба исходящих вызова идут через один инжектируемый `$transport` (сигнатура
 * `(string $method, array $data): ServerResponse`) — сеам для тестов, без сети.
 */
final class CommunityChatSender
{
    private const REACTION_METHOD = 'setMessageReaction';

    /**
     * Story 58: подстрока, по которой Bot API опознаёт отказ по содержимому текста
     * (не сеть/права/адресат) — общая для legacy `Markdown` и `MarkdownV2`. Хрупко:
     * Telegram формулировку явно не документирует, и она может смениться без
     * уведомления. Если этот матч перестанет срабатывать — `isContentParseError()`
     * начнёт молчать, а такие отказы вернутся к обычному `_FAILED`/ретраю (дефект 1
     * story 58 снова оживёт незаметно). Признак поломки — рост `*_FAILED` с причиной
     * `telegram_not_ok: Bad Request: can't parse entities...` в `admin_audit_log`.
     */
    private const TELEGRAM_CONTENT_PARSE_ERROR_MARKER = "can't parse entities";

    private CommunityMessageModel $messageModel;
    private AdminAuditLogModel $auditModel;

    /** @var BaseConnection<\mysqli, \mysqli_result> */
    private BaseConnection $db;

    /** @var callable(string, array<string, mixed>): ServerResponse */
    private $transport;

    /** @var callable(string, mixed): mixed */
    private $settingsGetter;

    /**
     * @param BaseConnection<\mysqli, \mysqli_result>|null $db
     * @param (callable(string, array<string, mixed>): ServerResponse)|null $transport
     * @param (callable(string, mixed): mixed)|null $settingsGetter сеам для тестов —
     *        `GameSettingsService` объявлен `final`, подменить его подклассом нельзя,
     *        поэтому чтение настроек идёт через инжектируемый callable
     *        `(string $key, mixed $default): mixed`, по умолчанию `[$settings, 'get']`.
     */
    public function __construct(
        ?CommunityMessageModel $messageModel = null,
        ?GameSettingsService $settings = null,
        ?AdminAuditLogModel $auditModel = null,
        ?BaseConnection $db = null,
        ?callable $transport = null,
        ?callable $settingsGetter = null,
    ) {
        $this->messageModel   = $messageModel ?? new CommunityMessageModel();
        $settings              = $settings ?? new GameSettingsService();
        $this->settingsGetter  = $settingsGetter ?? [$settings, 'get'];
        $this->auditModel      = $auditModel ?? new AdminAuditLogModel();
        $this->db              = $db ?? Database::connect();
        $this->transport       = $transport ?? [$this, 'defaultTransport'];
    }

    /**
     * Ответ в тот же топик, реплаем на исходное сообщение. Реплай не опционален:
     * без него ответ в топике с активным чатом нечитаем — не понятно, на что он.
     *
     * Автоматический путь — единственный вызывающий {@see \App\TaskHandlers\Community\CommunityAutoReplyHandler}.
     * Полный набор гейтов, включая килсвитч `community.autoreply.enabled`, потолок в
     * час и кулдаун автора: они существуют, чтобы бот не забивал топик сам по себе.
     */
    public function sendAnswer(int $messageRowId, string $text): bool
    {
        return $this->sendText($messageRowId, $text, 'COMMUNITY_ANSWER', false);
    }

    /**
     * Ручная отправка — владелец за админкой (`/admin/community`, story 12), одобряет
     * черновик или шлёт правку. Story 18: `community.autoreply.enabled=false` НЕ
     * блокирует эту дверь (план §12 — первые три дня Роби немой, владелец отвечает
     * руками; тот же ключ гасит и аварийный откат, который иначе отбирал бы у
     * владельца и ручной канал). Потолок в час и кулдаун автора — тоже только про
     * автоматику, живого владельца не ограничивают. `community.enabled`,
     * `silent_topics`, длина, парность `*` и канон имени остаются — ручной режим не
     * означает «без правил».
     *
     * Аудит различает ручную и автоматическую отправку названием действия
     * (`COMMUNITY_MANUAL_ANSWER_*` vs `COMMUNITY_ANSWER_*`) — иначе метрика «бот
     * против живых» посчитает ответы владельца ответами бота.
     */
    public function sendManualAnswer(int $messageRowId, string $text): bool
    {
        return $this->sendText($messageRowId, $text, 'COMMUNITY_MANUAL_ANSWER', true);
    }

    /**
     * Маршрут отказа гварда (story 55: текст «спроси иначе»/«эту тему не обсуждаем»)
     * — story 58, дефект 2. Те же гейты и потолок, что у {@see sendAnswer()} (не
     * ручная льгота — `$isManual=false`), но **отдельное** аудит-действие
     * `COMMUNITY_ROUTE_SENT`/`COMMUNITY_ROUTE_REJECTED`/`COMMUNITY_ROUTE_FAILED`
     * вместо `COMMUNITY_ANSWER_*`: иначе отказ гварда считается ответом бота в
     * `guardTotal = botAnswers + guardDenied` (`/admin/community`) и попадает в обе
     * половины метрики сразу. Контракт story — имя метода и имя действия не
     * переименовывать (используются story 57/59, которые едут следом).
     */
    public function sendGuardRoute(int $selfId, string $text): bool
    {
        return $this->sendText($selfId, $text, 'COMMUNITY_ROUTE', false);
    }

    /** @param string $auditPrefix `COMMUNITY_ANSWER` / `COMMUNITY_MANUAL_ANSWER` / `COMMUNITY_ROUTE` */
    private function sendText(int $messageRowId, string $text, string $auditPrefix, bool $isManual): bool
    {
        $row = $this->findMessage($messageRowId);
        if ($row === null) {
            $this->audit($auditPrefix . '_REJECTED', $messageRowId, null, 'message_not_found');
            return false;
        }

        $reason = $this->checkGates($row, $text, false, $isManual);
        if ($reason !== null) {
            $this->audit($auditPrefix . '_REJECTED', $messageRowId, $this->authorId($row), $reason);
            return false;
        }

        $data = [
            'chat_id'             => $this->chatId($row),
            'reply_to_message_id' => $this->messageId($row),
            'text'                => $text,
            'parse_mode'          => 'Markdown',
        ];
        $threadId = $this->threadId($row);
        if ($threadId !== null) {
            $data['message_thread_id'] = $threadId;
        }

        return $this->dispatch('sendMessage', $data, $messageRowId, $row, $auditPrefix);
    }

    /**
     * Реакция-эмодзи вместо ответа: «взял в очередь» (👀) или «стоп-тема» (🤔).
     * Не занимает строку в топике и никого не перебивает.
     */
    public function react(int $messageRowId, string $emoji): bool
    {
        $row = $this->findMessage($messageRowId);
        if ($row === null) {
            $this->audit('COMMUNITY_REACTION_REJECTED', $messageRowId, null, 'message_not_found');
            return false;
        }

        $reason = $this->checkGates($row, null, true);
        if ($reason !== null) {
            $this->audit('COMMUNITY_REACTION_REJECTED', $messageRowId, $this->authorId($row), $reason);
            return false;
        }

        $data = [
            'chat_id'    => $this->chatId($row),
            'message_id' => $this->messageId($row),
            'reaction'   => [['type' => 'emoji', 'emoji' => $emoji]],
        ];

        return $this->dispatch(self::REACTION_METHOD, $data, $messageRowId, $row, 'COMMUNITY_REACTION');
    }

    // ── общий путь отправки ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row
     */
    private function dispatch(string $method, array $data, int $messageRowId, array $row, string $auditPrefix): bool
    {
        try {
            $resp = ($this->transport)($method, $data);
        } catch (Throwable $e) {
            $this->audit($auditPrefix . '_FAILED', $messageRowId, $this->authorId($row), 'exception: ' . $e->getMessage());
            return false;
        }

        if (! $resp->isOk()) {
            $description = (string) $resp->getDescription();

            // Дефект 1, story 58: наша предочистка (`checkGates()`) ловит парность
            // `*`/`_`/`` ` ``/`[`…`]`, но не покрывает ВСЕ квирки легаси `Markdown`
            // (вложенные сущности, спецсимволы внутри уже сбалансированной пары).
            // Если Telegram сам вернул «не смог распарсить сущности» — причина не в
            // сети и не рассосётся сама: пишем как отказ гейта (`_REJECTED`), а не
            // как обычный `_FAILED`. `unbalanced_markdown` уже входит в
            // `TERMINAL_GATE_REASONS` у вызывающего (`CommunityAutoReplyHandler`,
            // вне `## Files` этой story) — без этого шага склейка вечно возвращалась
            // бы в `'new'` и повторяла тот же необрабатываемый `ok=false` каждую
            // минуту, каждый раз дописывая строку в тот самый `admin_audit_log`, по
            // которому считается сам часовой потолок. Транзиентные отказы (не про
            // содержимое текста — например, «message thread not found») остаются
            // `_FAILED`, чтобы вызывающий их по-прежнему ретраил.
            if ($method === 'sendMessage' && $this->isContentParseError($description)) {
                $this->audit($auditPrefix . '_REJECTED', $messageRowId, $this->authorId($row), 'unbalanced_markdown');
                return false;
            }

            $this->audit(
                $auditPrefix . '_FAILED',
                $messageRowId,
                $this->authorId($row),
                'telegram_not_ok: ' . $description
            );
            return false;
        }

        $this->audit($auditPrefix . '_SENT', $messageRowId, $this->authorId($row), 'ok');
        return true;
    }

    /**
     * Story 58, дефект 1: Telegram использует одну и ту же формулировку для ЛЮБОЙ
     * не разобранной сущности, независимо от `parse_mode` (легаси `Markdown` или
     * `MarkdownV2`) — по ней и опознаём отказ, вызванный содержимым текста, а не
     * сетью/правами/адресатом.
     */
    private function isContentParseError(string $description): bool
    {
        return stripos($description, self::TELEGRAM_CONTENT_PARSE_ERROR_MARKER) !== false;
    }

    /** @param array<string, mixed> $data */
    private function defaultTransport(string $method, array $data): ServerResponse
    {
        if ($method === self::REACTION_METHOD) {
            return $this->rawBotApiCall($method, $data);
        }

        return Request::send($method, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rawBotApiCall(string $method, array $data): ServerResponse
    {
        $apiKeyEnv = getenv('telegram.API_KEY');
        $apiKey    = is_string($apiKeyEnv) ? $apiKeyEnv : '';
        $url       = "https://api.telegram.org/bot{$apiKey}/{$method}";

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        $body    = is_string($encoded) ? $encoded : '{}';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded)) {
            $decoded = ['ok' => false, 'description' => 'invalid_or_empty_response'];
        }

        /** @var array<string, mixed> $decoded */
        return new ServerResponse($decoded, '');
    }

    // ── гейты ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     * @return string|null код причины отказа, либо null если можно отправлять
     *
     * `$isReaction` отделяет реакцию от текстового ответа (ремонтный круг 1,
     * 2026-08-25): реакция — дешёвая квитанция вместо молчания («взял в очередь» /
     * «стоп-тема»), она не занимает строку и никого не перебивает, поэтому потолок
     * `max_per_hour_per_topic` и кулдаун `author_cooldown_seconds` — про ТЕКСТОВЫЕ
     * ответы, на реакции не распространяются вовсе (не просто «щедрее», а НЕ гейт).
     * Иначе шестой вопрос в топике за час не получит даже 👀, и игрок читает это как
     * «меня игнорят» — ровно то, ради чего реакции и придуманы. Реакция и так ставится
     * не больше одного раза на сообщение — свой предохранитель избыточен.
     *
     * `$isManual` (story 18) отделяет ручную отправку владельца от автоматического
     * тика: снимает ТОЛЬКО гейты, существующие ради того, чтобы бот не забивал топик
     * сам по себе — килсвитч `community.autoreply.enabled`, потолок в час, кулдаун
     * автора. `community.enabled`, `silent_topics` и все проверки текста остаются
     * для обеих отправок без исключений.
     */
    private function checkGates(array $row, ?string $text, bool $isReaction = false, bool $isManual = false): ?string
    {
        if (! $this->readBool('community.enabled', false)) {
            return 'community_disabled';
        }
        if (! $isManual && ! $this->readBool('community.autoreply.enabled', false)) {
            return 'autoreply_disabled';
        }

        $threadId    = $this->threadId($row);
        $silentTopic = $this->readString('community.autoreply.silent_topics', '');
        if ($threadId !== null && in_array($threadId, $this->parseSilentTopics($silentTopic), true)) {
            return 'silent_topic';
        }

        if ($isReaction) {
            return null;
        }

        if (! $isManual) {
            $maxPerHour = $this->readInt('community.autoreply.max_per_hour_per_topic', 5);
            if ($maxPerHour >= 0 && $this->sentInTopicLastHour($this->chatId($row), $threadId) >= $maxPerHour) {
                return 'topic_rate_limit';
            }

            $cooldown = $this->readInt('community.autoreply.author_cooldown_seconds', 600);
            $authorId = $this->authorId($row);
            if ($cooldown > 0 && $authorId !== null && $this->authorSentWithinCooldown($authorId, $cooldown)) {
                return 'author_cooldown';
            }
        }

        if ($text !== null) {
            $maxChars = $this->readInt('community.autoreply.max_answer_chars', 600);
            if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
                return 'text_too_long';
            }
            if ($this->hasUnbalancedMarkdownEntities($text)) {
                return 'unbalanced_markdown';
            }
            if (mb_stripos($text, 'Робби') !== false) {
                return 'canon_name_violation';
            }
        }

        return null;
    }

    /**
     * Story 58, дефект 1: `checkGates()` раньше проверял парность только `*`, хотя
     * отправка идёт `parse_mode=Markdown` (легаси, `:163`), где сущностей больше:
     * `_`, обратный апостроф, `[`…`]`. Текст банка с непарным `_` (слаг с
     * подчёркиванием, обрезанная скобка после правки владельца) раньше уходил в
     * сеть, Telegram возвращал `ok=false`, а склейка возвращалась в `'new'` — тик
     * повторял её каждую минуту вечно. Причина-класс одна и та же —
     * `unbalanced_markdown` — чтобы вызывающий (`CommunityAutoReplyHandler`,
     * `TERMINAL_GATE_REASONS`) не различал их отдельно.
     */
    private function hasUnbalancedMarkdownEntities(string $text): bool
    {
        foreach (['*', '_', '`'] as $marker) {
            if (substr_count($text, $marker) % 2 !== 0) {
                return true;
            }
        }

        return substr_count($text, '[') !== substr_count($text, ']');
    }

    /**
     * Читатели настроек, сужающие `mixed` из `$settingsGetter` до конкретного типа через
     * ПЕРЕМЕННУЮ (не повторный доступ к offset'у) — иначе phpstan L9 ловит `cast.int`
     * на прямом `(int) $mixedCallResult` (memory `feedback_phpstan_no_mixed_to_int_cast`).
     */
    private function readBool(string $key, bool $default): bool
    {
        $raw = ($this->settingsGetter)($key, $default);
        return is_bool($raw) ? $raw : $default;
    }

    private function readInt(string $key, int $default): int
    {
        $raw = ($this->settingsGetter)($key, $default);
        return is_numeric($raw) ? (int) $raw : $default;
    }

    private function readString(string $key, string $default): string
    {
        $raw = ($this->settingsGetter)($key, $default);
        return is_string($raw) ? $raw : $default;
    }

    /** @return list<int> */
    private function parseSilentTopics(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && is_numeric($piece)) {
                $out[] = (int) $piece;
            }
        }
        return $out;
    }

    // ── счётчики (собственные прошлые отправки, через admin_audit_log) ────

    private function sentInTopicLastHour(int $chatId, ?int $threadId): int
    {
        // Только текстовые ответы — реакция не занимает строку и не расходует
        // потолок ответов в топике (ремонтный круг 1, 2026-08-25).
        //
        // Story 58 (ремонт по замечанию лида, 2026-08-26): `COMMUNITY_ANSWER_SENT` И
        // `COMMUNITY_ROUTE_SENT` — оба считаются в потолок. Потолок топика защищает чат
        // от того, чтобы бот тараторил, ему всё равно, ответ это или маршрут отказа. Если
        // маршрут уважает потолок, но не пополняет его — тот, кто целенаправленно выуживает
        // читы, получает гарантированный текстовый ответ на КАЖДУЮ пробу: счётчик от его
        // отказов никогда не растёт. Это ровно вектор спама, запрещённый `## Non-goals`
        // story. Метрика «доля ответов бота» (`/admin/community`, story 59) — другой
        // счётчик, считает только `COMMUNITY_ANSWER_SENT`; она вне этого файла.
        $sql = 'SELECT COUNT(*) AS n
                FROM admin_audit_log a
                INNER JOIN community_messages cm ON cm.id = a.target_id
                WHERE a.action IN (\'COMMUNITY_ANSWER_SENT\', \'COMMUNITY_ROUTE_SENT\')
                  AND cm.chat_id = ?
                  AND cm.message_thread_id <=> ?
                  AND a.created_at >= (NOW() - INTERVAL 1 HOUR)';

        $query = $this->db->query($sql, [$chatId, $threadId]);
        if (! $query instanceof BaseResult) {
            return 0;
        }
        $row = $query->getRowArray();
        return isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
    }

    private function authorSentWithinCooldown(int $authorId, int $cooldownSeconds): bool
    {
        // Только текстовые ответы — прошлая реакция автору не запускает кулдаун
        // на следующий текстовый ответ (ремонтный круг 1, 2026-08-25).
        //
        // Story 58 (ремонт по замечанию лида, 2026-08-26): та же логика, что у
        // {@see sentInTopicLastHour()} — маршрут отказа тоже запускает кулдаун автору,
        // иначе автор, целенаправленно выуживающий чити, дёргает бота раз за разом без
        // ограничения, потому что каждый отказ маршрутом сам кулдаун не заводит.
        $sql = 'SELECT COUNT(*) AS n
                FROM admin_audit_log a
                INNER JOIN community_messages cm ON cm.id = a.target_id
                WHERE a.action IN (\'COMMUNITY_ANSWER_SENT\', \'COMMUNITY_ROUTE_SENT\')
                  AND cm.telegram_user_id = ?
                  AND a.created_at >= (NOW() - INTERVAL ? SECOND)';

        $query = $this->db->query($sql, [$authorId, $cooldownSeconds]);
        if (! $query instanceof BaseResult) {
            return false;
        }
        $row = $query->getRowArray();
        return isset($row['n']) && is_numeric($row['n']) && (int) $row['n'] > 0;
    }

    // ── аудит ────────────────────────────────────────────────────────────

    private function audit(string $action, int $messageRowId, ?int $authorTelegramId, string $reason): void
    {
        try {
            $this->auditModel->insert([
                'admin_user_id' => 0, // 0 = бот, не человек за админкой
                'action'        => $action,
                'target_type'   => 'community_message',
                'target_id'     => $messageRowId,
                'payload'       => json_encode(
                    ['reason' => $reason, 'telegram_user_id' => $authorTelegramId],
                    JSON_UNESCAPED_UNICODE
                ),
                'ip_address'    => null,
                'user_agent'    => null,
                'created_at'    => $this->dbNow(),
            ]);
        } catch (Throwable $e) {
            // Аудит-запись не должна ронять отправку/отказ.
            log_message('error', '[CommunityChatSender] audit insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Часы, которыми сеть считает `sentInTopicLastHour()`/`authorSentWithinCooldown()` —
     * `NOW()` из той же MySQL-сессии. Отметка времени записи обязана идти из того же
     * источника, иначе потолок и кулдаун сравнивают запись PHP-часов с чтением
     * MySQL-часов: при расхождении таймзон приложения и БД потолок либо не срабатывает
     * никогда, либо блокирует всегда (memory `feedback_db_clock_seed_not_php_in_time_window_tests`,
     * story community-chat-bot-27).
     */
    private function dbNow(): string
    {
        $query = $this->db->query('SELECT NOW() AS n');
        if ($query instanceof BaseResult) {
            $row = $query->getRowArray();
            if (isset($row['n']) && is_string($row['n'])) {
                return $row['n'];
            }
        }

        // Отказ БД тут уже означает, что и сама вставка аудита провалится следом —
        // запасное значение только чтобы не звать date() из другого источника времени.
        return date('Y-m-d H:i:s');
    }

    // ── чтение строки сообщения ─────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function findMessage(int $messageRowId): ?array
    {
        $row = $this->messageModel->find($messageRowId);
        if (! is_array($row)) {
            return null;
        }

        $normalized = [];
        foreach ($row as $k => $v) {
            $normalized[(string) $k] = $v;
        }
        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private function chatId(array $row): int
    {
        return isset($row['chat_id']) && is_numeric($row['chat_id']) ? (int) $row['chat_id'] : 0;
    }

    /** @param array<string, mixed> $row */
    private function messageId(array $row): int
    {
        return isset($row['message_id']) && is_numeric($row['message_id']) ? (int) $row['message_id'] : 0;
    }

    /** @param array<string, mixed> $row */
    private function threadId(array $row): ?int
    {
        return isset($row['message_thread_id']) && is_numeric($row['message_thread_id'])
            ? (int) $row['message_thread_id']
            : null;
    }

    /** @param array<string, mixed> $row */
    private function authorId(array $row): ?int
    {
        return isset($row['telegram_user_id']) && is_numeric($row['telegram_user_id'])
            ? (int) $row['telegram_user_id']
            : null;
    }
}
