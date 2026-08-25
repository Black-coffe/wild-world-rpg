<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\AdminAuditLogModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\Telegram\Request;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;
use Throwable;

/**
 * ADR-176 (community-chat-bot), story 10 — модерация ссылок и вербовки, режим `shadow`.
 *
 * Единственная угроза, которую бот трогает вообще: ссылки / чужие боты / форварды из
 * чужих каналов / QR-картинки от новичков, и фразы вербовки/скама («продам аккаунт»,
 * «куплю аккаунт», «прокачаю за», «донат в обход», ссылка на другую игру) — независимо
 * от стажа автора. **Токсичность, мат и ссоры бот НЕ трогает вообще** — это решение,
 * не упущение (Non-goals story): на паре сотен человек автомодерация тона всегда даёт
 * ложные срабатывания, и первое «за что удалили» стоит дороже самого мата.
 *
 * Вызывается из хендлера отдельно от приёма (`CommunityIngestService` не трогается,
 * Закон 3) — конкретную точку вызова определяет story 11/14 (`CommunityAutoReplyHandler`).
 *
 * Режим — `community.moderation.mode`: `off` — ничего вообще; `shadow` (умолчание первую
 * неделю) — НИКОГДА не удаляет, только личное сообщение владельцу «я бы удалил вот это»
 * с цитатой и ссылкой на сообщение, чтобы решение принималось не вслепую; `live` —
 * удаление (`deleteMessage`) + тот же сигнал. Переход `shadow` → `live` — руками
 * владельца из админки, после недели с нулём ложных срабатываний (не код этой story).
 *
 * Стаж автора — прокси через `community_messages`: число и время прежних сообщений
 * этого `telegram_user_id` ДО текущего (в самой таблице нет отдельного «дата вступления»
 * — Telegram не отдаёт её в апдейте сообщения; событие `chat_member` — другой тип
 * апдейта, вне карты этой story). «Меньше 24 часов» считается от первого известного
 * сообщения автора, «меньше 3 сообщений» — от числа прежних строк.
 *
 * QR-картинка не распознаётся анализом изображения (нет такой инфраструктуры в проекте) —
 * `hasQrPhoto` берёт наличие ЛЮБОГО фото у новичка как консервативный прокси. Ложных
 * срабатываний хватает (скриншот базы/трофея — обычное действие новичка), поэтому этот
 * признак НИКОГДА не ведёт к удалению ни в одном режиме, включая `live` — только к сигналу
 * владельцу (ремонт: ревью Queen, круг 1). Единственные основания для реального удаления —
 * ссылка/чужой бот/форвард из канала (у новичка) или фраза вербовки/скам-ссылка (всегда).
 *
 * Каждое сработавшее действие (флаг в shadow, удаление в live) пишется в
 * `admin_audit_log` с `admin_user_id=0` (бот, не человек за админкой) — паттерн
 * `CommunityChatSender::audit()`.
 */
final class CommunityModerationService
{
    /** Вербовка/скам — независимо от стажа автора (контракт story). @var list<string> */
    private const RECRUIT_PHRASES = [
        'продам аккаунт',
        'куплю аккаунт',
        'прокачаю за',
        'донат в обход',
    ];

    /**
     * Домены магазинов приложений — прокси «ссылки на другую игру» (контракт не даёт
     * списка игр, это единственный устойчивый сигнал без ручного реестра конкурентов).
     *
     * @var list<string>
     */
    private const OTHER_GAME_DOMAINS = [
        'play.google.com',
        'apps.apple.com',
        'store.steampowered.com',
    ];

    private const NEWCOMER_MAX_MESSAGES  = 3;
    private const NEWCOMER_MAX_AGE_HOURS = 24;

    /** Лимит длины `sendMessage` в Telegram — протокольная константа, не баланс. */
    private const NOTICE_MAX_LENGTH = 4096;

    private AdminAuditLogModel $auditModel;

    /** @var BaseConnection<\mysqli, \mysqli_result> */
    private BaseConnection $db;

    /** @var callable(string, mixed): mixed */
    private $settingsGetter;

    /** @var callable(string, array<string, mixed>): ServerResponse удаление в live */
    private $transport;

    /**
     * @var callable(string): void сигнал владельцу — прямой `sendMessage` без `parse_mode`.
     *      Не `BroadcastService::broadcastTo()`: тот жёстко шлёт `parse_mode=Markdown`
     *      (legacy, без backslash-экранирования — `feedback_legacy_markdown_no_backslash_escape`),
     *      а цитата в нотисе — сырой текст игрока, который почти всегда несёт `_`/`*`/`` ` ``.
     */
    private $notifyOwner;

    private string $ownerTelegramId;
    private string $botUsername;

    /**
     * @param (callable(string, mixed): mixed)|null $settingsGetter сеам — `GameSettingsService`
     *        объявлен `final`, подменить подклассом нельзя (паттерн `CommunityChatSender`).
     * @param BaseConnection<\mysqli, \mysqli_result>|null $db
     * @param (callable(string, array<string, mixed>): ServerResponse)|null $transport
     * @param (callable(string): void)|null $notifyOwner
     */
    public function __construct(
        ?AdminAuditLogModel $auditModel = null,
        ?GameSettingsService $settings = null,
        ?callable $settingsGetter = null,
        ?BaseConnection $db = null,
        ?callable $transport = null,
        ?callable $notifyOwner = null,
        ?string $ownerTelegramId = null,
        ?string $botUsername = null,
    ) {
        $this->auditModel = $auditModel ?? new AdminAuditLogModel();
        $settings          = $settings ?? new GameSettingsService();
        $this->settingsGetter = $settingsGetter ?? [$settings, 'get'];
        $this->db         = $db ?? Database::connect();
        $this->transport  = $transport ?? [Request::class, 'send'];
        $this->ownerTelegramId = $ownerTelegramId ?? (string) getenv('telegram.MY_CHAT_ID');
        $this->botUsername     = ltrim($botUsername ?? (string) getenv('telegram.BOT_USERNAME'), '@');

        $ownerId           = $this->ownerTelegramId;
        $transportForNotice = $this->transport;
        $this->notifyOwner = $notifyOwner ?? static function (string $text) use ($ownerId, $transportForNotice): void {
            if ($ownerId === '') {
                return;
            }
            try {
                ($transportForNotice)('sendMessage', ['chat_id' => $ownerId, 'text' => $text]);
            } catch (Throwable $e) {
                log_message('error', '[CommunityModerationService] notifyOwner failed: ' . $e->getMessage());
            }
        };
    }

    /**
     * @param array<array-key, mixed> $update
     */
    public function evaluate(array $update): void
    {
        $mode = $this->readString('community.moderation.mode', 'off');
        if ($mode === 'off') {
            return;
        }

        if (! $this->readBool('community.enabled', false)) {
            return;
        }

        // Правка сообщения не должна обходить модерацию (ремонт story 24): гейт по типу
        // чата пропускает `edited_message` на community-путь, значит и сюда он должен
        // доходить и судиться по актуальному (отредактированному) тексту.
        $messageRaw = $update['message'] ?? $update['edited_message'] ?? null;
        if (! is_array($messageRaw)) {
            return;
        }

        $chatRaw   = $messageRaw['chat'] ?? null;
        $chatIdRaw = is_array($chatRaw) ? ($chatRaw['id'] ?? null) : null;
        if (! is_numeric($chatIdRaw)) {
            return;
        }
        $chatId = (int) $chatIdRaw;

        $configuredChatId = $this->readString('community.chat_id', '');
        if ($configuredChatId === '' || $configuredChatId !== (string) $chatId) {
            return;
        }

        $messageIdRaw = $messageRaw['message_id'] ?? null;
        if (! is_int($messageIdRaw)) {
            return;
        }
        $messageId = $messageIdRaw;

        $fromRaw     = $messageRaw['from'] ?? null;
        $authorIdRaw = is_array($fromRaw) ? ($fromRaw['id'] ?? null) : null;
        if (! is_numeric($authorIdRaw)) {
            return;
        }
        $authorId = (int) $authorIdRaw;

        $threadId = $this->intOrNull($messageRaw['message_thread_id'] ?? null);
        $dateRaw  = $messageRaw['date'] ?? null;
        $sentAt   = is_int($dateRaw) ? date('Y-m-d H:i:s', $dateRaw) : date('Y-m-d H:i:s');

        $textRaw = $messageRaw['text'] ?? $messageRaw['caption'] ?? null;
        $text    = is_string($textRaw) ? $textRaw : '';

        $signals = $this->contentSignals($messageRaw, $text);
        $isNewcomer = $this->isNewcomer($authorId, $sentAt);

        // «Есть фото» — консервативный прокси без анализа изображения, ложных срабатываний
        // хватает (любой скриншот трофея от новичка). Он вправе завести сигнал владельцу,
        // но НИКОГДА не ведёт к удалению ни в одном режиме (ремонт: ревью Queen, круг 1) —
        // единственные основания для deleteMessage в live: однозначные сигналы (ссылка,
        // чужой бот, форвард из канала, вербовка/скам).
        $tenureGatedDeletable = $isNewcomer
            && ($signals['hasLink'] || $signals['hasForeignBotMention'] || $signals['hasForwardFromChannel']);
        $tenureIndependent = $signals['hasSuspiciousPhrase'] || $signals['hasOtherGameLink'];
        $photoOnly         = $isNewcomer && $signals['hasQrPhoto']
            && ! $tenureGatedDeletable && ! $tenureIndependent;

        if (! $tenureGatedDeletable && ! $tenureIndependent && ! $photoOnly) {
            return;
        }

        $canDelete = $tenureGatedDeletable || $tenureIndependent;
        $reason    = match (true) {
            $tenureIndependent => 'recruitment_or_other_game',
            $tenureGatedDeletable => 'newcomer_link_or_forward',
            default => 'newcomer_photo',
        };
        $notice = $this->buildOwnerNotice($chatId, $threadId, $messageId, $fromRaw, $authorId, $text, $reason);

        if ($mode === 'live' && $canDelete) {
            $this->deleteMessage($chatId, $messageId);
            $this->audit('COMMUNITY_MODERATION_DELETED', $messageId, $authorId, $reason);
        } else {
            // shadow (или любое незнакомое значение — fail-closed на «не удалять»),
            // либо live с photoOnly (фото никогда не удаляется — только сигнал).
            $this->audit('COMMUNITY_MODERATION_FLAGGED', $messageId, $authorId, $reason);
        }

        ($this->notifyOwner)($notice);
    }

    // ── стаж автора ──────────────────────────────────────────────────────

    /**
     * «Автор в чате менее 24 часов ИЛИ менее 3 сообщений» — OR, не AND: любого из двух
     * достаточно, чтобы считаться новичком. Отсутствие прежних строк (первое известное
     * сообщение автора вообще) трактуется как «новичок» на обоих счётах.
     */
    private function isNewcomer(int $authorId, string $sentAt): bool
    {
        $stats = $this->priorMessageStats($authorId, $sentAt);
        if ($stats['count'] < self::NEWCOMER_MAX_MESSAGES) {
            return true;
        }

        if ($stats['firstAt'] === null) {
            return true;
        }

        $ageHours = (strtotime($sentAt) - strtotime($stats['firstAt'])) / 3600;
        return $ageHours < self::NEWCOMER_MAX_AGE_HOURS;
    }

    /** @return array{count: int, firstAt: string|null} */
    private function priorMessageStats(int $authorId, string $beforeAt): array
    {
        $sql = 'SELECT COUNT(*) AS n, MIN(sent_at) AS first_at
                FROM community_messages
                WHERE telegram_user_id = ? AND sent_at < ?';

        $query = $this->db->query($sql, [$authorId, $beforeAt]);
        if (! $query instanceof BaseResult) {
            return ['count' => 0, 'firstAt' => null];
        }

        $row = $query->getRowArray();
        $count = isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
        $firstAt = isset($row['first_at']) && is_string($row['first_at']) ? $row['first_at'] : null;

        return ['count' => $count, 'firstAt' => $firstAt];
    }

    // ── сигналы содержимого ─────────────────────────────────────────────

    /**
     * @param array<array-key, mixed> $messageRaw
     * @return array{
     *     hasLink: bool,
     *     hasForeignBotMention: bool,
     *     hasForwardFromChannel: bool,
     *     hasQrPhoto: bool,
     *     hasSuspiciousPhrase: bool,
     *     hasOtherGameLink: bool,
     * }
     */
    private function contentSignals(array $messageRaw, string $text): array
    {
        $entitiesRaw = $messageRaw['entities'] ?? $messageRaw['caption_entities'] ?? [];
        $entities    = is_array($entitiesRaw) ? $entitiesRaw : [];

        return [
            'hasLink'               => $this->hasLinkEntity($entities) || $this->looksLikeUrl($text),
            'hasForeignBotMention'  => $this->hasForeignBotMention($entities, $text),
            'hasForwardFromChannel' => $this->isForwardFromChannel($messageRaw),
            'hasQrPhoto'            => isset($messageRaw['photo']) && is_array($messageRaw['photo']) && $messageRaw['photo'] !== [],
            'hasSuspiciousPhrase'   => $this->hasSuspiciousPhrase($text),
            'hasOtherGameLink'      => $this->hasOtherGameLink($text, $entities),
        ];
    }

    /** @param array<array-key, mixed> $entities */
    private function hasLinkEntity(array $entities): bool
    {
        foreach ($entities as $entity) {
            if (is_array($entity) && in_array($entity['type'] ?? null, ['url', 'text_link'], true)) {
                return true;
            }
        }
        return false;
    }

    private function looksLikeUrl(string $text): bool
    {
        return (bool) preg_match('#(https?://|www\.|t\.me/)#i', $text);
    }

    /**
     * «Чужой бот»: `@username`, оканчивающийся на `bot` (соглашение Telegram для ботов),
     * и не равный нашему собственному username. Прямой поиск по тексту, не по
     * `entities[].offset/length` — та же UTF-16-ловушка, что и в `CommunityIngestService`.
     *
     * @param array<array-key, mixed> $entities
     */
    private function hasForeignBotMention(array $entities, string $text): bool
    {
        $hasMentionEntity = false;
        foreach ($entities as $entity) {
            if (is_array($entity) && ($entity['type'] ?? null) === 'mention') {
                $hasMentionEntity = true;
                break;
            }
        }
        if (! $hasMentionEntity) {
            return false;
        }

        if (preg_match_all('/@([a-z0-9_]{4,31}bot)\b/i', $text, $matches) === false) {
            return false;
        }

        foreach ($matches[1] as $mentioned) {
            if (strcasecmp($mentioned, $this->botUsername) !== 0) {
                return true;
            }
        }
        return false;
    }

    /** @param array<array-key, mixed> $messageRaw */
    private function isForwardFromChannel(array $messageRaw): bool
    {
        $origin = $messageRaw['forward_origin'] ?? null;
        if (is_array($origin) && ($origin['type'] ?? null) === 'channel') {
            return true;
        }

        $legacy = $messageRaw['forward_from_chat'] ?? null;
        return is_array($legacy) && ($legacy['type'] ?? null) === 'channel';
    }

    private function hasSuspiciousPhrase(string $text): bool
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        foreach (self::RECRUIT_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<array-key, mixed> $entities */
    private function hasOtherGameLink(string $text, array $entities): bool
    {
        $lowerText = strtolower($text);
        foreach (self::OTHER_GAME_DOMAINS as $domain) {
            if (str_contains($lowerText, $domain)) {
                return true;
            }
        }

        foreach ($entities as $entity) {
            if (! is_array($entity) || ($entity['type'] ?? null) !== 'text_link') {
                continue;
            }
            $url = $entity['url'] ?? null;
            if (! is_string($url)) {
                continue;
            }
            $lowerUrl = strtolower($url);
            foreach (self::OTHER_GAME_DOMAINS as $domain) {
                if (str_contains($lowerUrl, $domain)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ── действие: удаление (только live) ────────────────────────────────

    private function deleteMessage(int $chatId, int $messageId): void
    {
        try {
            ($this->transport)('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        } catch (Throwable $e) {
            log_message('error', '[CommunityModerationService] deleteMessage failed: ' . $e->getMessage());
        }
    }

    // ── сигнал владельцу ─────────────────────────────────────────────────

    /** @param array<array-key, mixed>|null $fromRaw */
    private function buildOwnerNotice(
        int $chatId,
        ?int $threadId,
        int $messageId,
        ?array $fromRaw,
        int $authorId,
        string $text,
        string $reason,
    ): string {
        $usernameRaw = is_array($fromRaw) ? ($fromRaw['username'] ?? null) : null;
        $author      = is_string($usernameRaw) && $usernameRaw !== '' ? '@' . $usernameRaw : (string) $authorId;
        $quote       = $text !== '' ? $text : '(без текста — фото/форвард)';
        $verdict     = $reason === 'recruitment_or_other_game'
            ? 'похоже на вербовку или рекламу другой игры'
            : 'похоже на спам от новичка (ссылка/чужой бот/форвард/фото)';
        $link        = $this->messageLink($chatId, $threadId, $messageId);

        // Цитата — единственная часть нотиса без предсказуемой длины (текст игрока).
        // Обрезаем её так, чтобы весь нотис укладывался в лимит `sendMessage`, а не
        // отправлял скелет и полагался на то, что Telegram сам обрежет/примет длинное.
        $skeletonLength = mb_strlen(
            "Модерация чата — я бы удалил вот это ({$verdict}):\n"
                . "От: {$author}\n"
                . "Цитата: «»\n"
                . "Ссылка на сообщение: {$link}",
            'UTF-8'
        );
        $quote = $this->truncateQuote($quote, max(0, self::NOTICE_MAX_LENGTH - $skeletonLength));

        return "Модерация чата — я бы удалил вот это ({$verdict}):\n"
            . "От: {$author}\n"
            . "Цитата: «{$quote}»\n"
            . "Ссылка на сообщение: {$link}";
    }

    private function truncateQuote(string $quote, int $maxLength): string
    {
        if (mb_strlen($quote, 'UTF-8') <= $maxLength) {
            return $quote;
        }

        $ellipsis = '…';
        $keep     = max(0, $maxLength - mb_strlen($ellipsis, 'UTF-8'));

        return mb_substr($quote, 0, $keep, 'UTF-8') . $ellipsis;
    }

    private function messageLink(int $chatId, ?int $threadId, int $messageId): string
    {
        $internal = (string) $chatId;
        if (str_starts_with($internal, '-100')) {
            $internal = substr($internal, 4);
        } elseif (str_starts_with($internal, '-')) {
            $internal = substr($internal, 1);
        }

        return $threadId !== null
            ? "https://t.me/c/{$internal}/{$threadId}/{$messageId}"
            : "https://t.me/c/{$internal}/{$messageId}";
    }

    // ── аудит ────────────────────────────────────────────────────────────

    private function audit(string $action, int $telegramMessageId, ?int $authorTelegramId, string $reason): void
    {
        try {
            $this->auditModel->insert([
                'admin_user_id' => 0, // 0 = бот, не человек за админкой
                'action'        => $action,
                'target_type'   => 'community_chat_message',
                'target_id'     => $telegramMessageId,
                'payload'       => json_encode(
                    ['reason' => $reason, 'telegram_user_id' => $authorTelegramId],
                    JSON_UNESCAPED_UNICODE
                ),
                'ip_address'    => null,
                'user_agent'    => null,
                'created_at'    => $this->dbNow(),
            ]);
        } catch (Throwable $e) {
            // Аудит-запись не должна ронять модерацию.
            log_message('error', '[CommunityModerationService] audit insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Часы, которыми считаются оконные запросы (`CommunityController::autoClosedCount()`
     * и соседние счётчики потолка) — `NOW()` из той же MySQL-сессии, а не `date()` PHP.
     * Отметка времени записи обязана идти из того же источника, что и чтение, иначе
     * при расхождении таймзон приложения и БД строка либо попадает в окно лишний раз,
     * либо выпадает из него (memory `feedback_db_clock_seed_not_php_in_time_window_tests`,
     * story community-chat-bot-27/62; тот же приём — `CommunityChatSender::dbNow()`).
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

    // ── настройки ────────────────────────────────────────────────────────

    /**
     * Каждый читатель сначала присваивает результат callable в переменную и только
     * потом кастит её — прямой `(int) $mixedCallResult` phpstan L9 ловит как `cast.int`
     * (memory `feedback_phpstan_no_mixed_to_int_cast`).
     */
    private function readBool(string $key, bool $default): bool
    {
        $raw = ($this->settingsGetter)($key, $default);
        return is_bool($raw) ? $raw : $default;
    }

    private function readString(string $key, string $default): string
    {
        $raw = ($this->settingsGetter)($key, $default);
        return is_string($raw) ? $raw : $default;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
