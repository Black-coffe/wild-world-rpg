<?php

declare(strict_types=1);

namespace App\TaskHandlers\Community;

use App\Attributes\HandlerKey;
use App\Models\AdminAuditLogModel;
use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Services\Community\CommunityAnswerMatcher;
use App\Services\Community\CommunityChatSender;
use App\Services\Community\CommunityGuard;
use App\Services\Community\Decision;
use App\Services\GameSettings\GameSettingsService;
use App\TaskHandlers\BaseTaskHandler;
use DateTimeImmutable;

/**
 * ADR-176 (community-chat-bot), story 09 — тик, после которого бот впервые может
 * заговорить в общем чате сообщества. Оркестрирует три уже готовых сервиса и не
 * дублирует их логику (Non-goals story):
 *  - {@see CommunityAnswerMatcher::match()} (story 08) — КОГДА и КОМУ отвечать;
 *  - {@see CommunityGuard::verdict()} (story 07) — МОЖНО ли сказать текст;
 *  - {@see CommunityChatSender} (story 06) — КАК отправить (реплай / реакция),
 *    включая собственные тормоза (потолок/кулдаун/длина) — их результат («не ушло»)
 *    здесь читается как «повторим на следующем тике», а не как отказ гварда.
 *
 * Тик (`everyMinute`, singleInstance): выбирает `community_messages` со
 * `status='new' AND is_question=1`, по порядку `sent_at`, и для каждой ещё
 * необработанной в этом тике строки спрашивает матчера:
 *  - `answer_now` → гвард → отправка реплаем на исходное сообщение;
 *  - `answer_after_delay` → ждём `sent_at + delaySeconds`, ПЕРЕД самой отправкой
 *    обязательно перепроверяем {@see CommunityAnswerMatcher::isCancelledByHumanReply()}
 *    — решение матчера снимок момента матча, а человек мог ответить в тред уже во
 *    время выдержки (🔴 контракт story, тот самый BUILT-BUT-DEAD, из-за которого
 *    завели story 16, если не вызвать);
 *  - `receipt_only` → реакция 👀, ровно один раз (дедуп по `admin_audit_log`
 *    `COMMUNITY_REACTION_SENT`, т.к. `CommunityChatSender` сама не хранит «уже
 *    реагировал» — это ответственность вызывающего);
 *  - `silent` → строка уже покрыта более ранним дублем, помечается `ignored`.
 *
 * `Decision::coveredMessageIds` — склейка дублей: одна отправка/эскалация/отмена
 * закрывает СРАЗУ все перечисленные строки одним обновлением статуса, не по одной.
 *
 * Строка получает `answered` только после `sendAnswer()===true` — при отказе
 * (гейты `CommunityChatSender`, сетевая ошибка, Telegram not-ok) статус остаётся
 * `new`, и следующий тик повторит попытку сам (запрос `status='new'` её снова заберёт).
 *
 * Вердикт гварда `manual`/`deny` → строка (и её склейка) помечается `escalated`,
 * текст не уходит вовсе; попытка чиркнуть 🤔 не обязана быть успешной — молчание
 * `CommunityChatSender` (например `community.autoreply.enabled=false`) не мешает
 * эскалации дойти до владельца через `/admin/community`, куда уже смотрит статус.
 *
 * `Decision::escalated` (полоса A без совпадения банка — уходит `CommunityVoice::UNKNOWN`)
 * помечает строку `escalated`, а не `answered`, даже при успешной отправке: честное
 * «не знаю» ушло, но вопрос всё равно ждёт человека — банк не пополнился сам.
 *
 * Собственный килсвитч (`community.enabled` + `community.autoreply.enabled`) —
 * молча, без единого запроса к БД сообщений, если выключено (контракт story):
 * приём (story 05) продолжает работать независимо, отвечает только этот тик.
 */
#[HandlerKey(
    key: 'community_auto_reply',
    displayName: 'Авто-ответ в чате сообщества (ADR-176)',
    description: 'everyMinute: матчит новые вопросы community_messages банком ответов (CommunityAnswerMatcher), гейтит текст (CommunityGuard), отправляет реплаем/реакцией (CommunityChatSender). Килсвитч community.enabled + community.autoreply.enabled.',
)]
final class CommunityAutoReplyHandler extends BaseTaskHandler
{
    private CommunityMessageModel $messageModel;
    private CommunityAnswerModel $answerModel;
    private CommunityAnswerMatcher $matcher;
    private CommunityGuard $guard;
    private CommunityChatSender $sender;
    private AdminAuditLogModel $auditModel;
    private ?DateTimeImmutable $fixedNow;

    /** @var callable(string, mixed): mixed */
    private $settingsGetter;

    /**
     * @param (callable(string, mixed): mixed)|null $settingsGetter сеам для тестов
     *        (паттерн `CommunityChatSender`/`CommunityAnswerMatcher`) — `GameSettingsService::get()` по умолчанию.
     * @param DateTimeImmutable|null $now сеам для тестов выдержки полосы B —
     *        детерминированное «сейчас»; null — берётся реальное на каждый `handle()`.
     */
    public function __construct(
        ?CommunityMessageModel $messageModel = null,
        ?CommunityAnswerModel $answerModel = null,
        ?CommunityAnswerMatcher $matcher = null,
        ?CommunityGuard $guard = null,
        ?CommunityChatSender $sender = null,
        ?AdminAuditLogModel $auditModel = null,
        ?GameSettingsService $settings = null,
        ?callable $settingsGetter = null,
        ?DateTimeImmutable $now = null,
    ) {
        $this->messageModel  = $messageModel ?? new CommunityMessageModel();
        $this->answerModel   = $answerModel ?? new CommunityAnswerModel();
        $this->matcher        = $matcher ?? new CommunityAnswerMatcher();
        $this->guard           = $guard ?? new CommunityGuard();
        $this->sender          = $sender ?? new CommunityChatSender();
        $this->auditModel      = $auditModel ?? new AdminAuditLogModel();
        $settings               = $settings ?? new GameSettingsService();
        $this->settingsGetter   = $settingsGetter ?? [$settings, 'get'];
        $this->fixedNow         = $now;
    }

    /**
     * @param array<int|string, mixed> $task не используется — recurring handler,
     *        не привязан к `character_tasks` (контракт story, Non-goals).
     */
    public function handle(array $task = []): void
    {
        if (! $this->readBool('community.enabled', false)) {
            return;
        }
        if (! $this->readBool('community.autoreply.enabled', false)) {
            return;
        }

        $now = $this->fixedNow ?? new DateTimeImmutable();

        $rows = $this->messageModel
            ->where('status', 'new')
            ->where('is_question', 1)
            ->orderBy('sent_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        /** @var array<int, true> $handled уже получившие решение в этом тике (склейка дублей) */
        $handled = [];

        foreach ($rows as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $row = $this->normalizeRow($raw);
            $id  = $this->intOrNull($row['id'] ?? null);
            if ($id === null || isset($handled[$id])) {
                continue;
            }
            $handled[$id] = true;

            $decision = $this->matcher->match($row, $now);

            foreach ($decision->coveredMessageIds as $coveredId) {
                $handled[$coveredId] = true;
            }

            if ($decision->isSilent()) {
                $this->messageModel->update($id, ['status' => 'ignored']);
                continue;
            }

            if ($decision->isReceiptOnly()) {
                $this->reactOnce($id, '👀');
                continue;
            }

            if ($decision->isAnswerNow()) {
                $this->resolveAndSend($row, $decision);
                continue;
            }

            if ($decision->isAnswerAfterDelay()) {
                $this->handleDelayed($row, $decision, $now);
            }
        }
    }

    /** @param array<string, mixed> $row строка-«представитель» задержанного решения */
    private function handleDelayed(array $row, Decision $decision, DateTimeImmutable $now): void
    {
        $sentAt = $this->parseDateTime($row['sent_at'] ?? null);
        $delay  = $decision->delaySeconds ?? 0;
        if ($sentAt === null || ($now->getTimestamp() - $sentAt->getTimestamp()) < $delay) {
            return; // выдержка не истекла — повторим на следующем тике
        }

        // 🔴 обязательная перепроверка НЕПОСРЕДСТВЕННО перед публикацией — решение
        // матчера снимок момента матча, человек мог ответить в тред уже во время
        // выдержки (docblock CommunityAnswerMatcher::isCancelledByHumanReply()).
        if ($this->matcher->isCancelledByHumanReply($row)) {
            // Отмена НАВСЕГДА, не откладываем на следующий тик.
            $this->markGroup($decision->coveredMessageIds, ['status' => 'ignored']);
            return;
        }

        $this->resolveAndSend($row, $decision);
    }

    /** @param array<string, mixed> $row строка, на которую уйдёт реплай */
    private function resolveAndSend(array $row, Decision $decision): void
    {
        $selfId = $this->intOrNull($row['id'] ?? null);
        if ($selfId === null) {
            return;
        }

        $requiresSetting = $decision->answerId !== null ? $this->requiresSetting($decision->answerId) : null;
        $question        = $this->stringField($row, 'text');
        $text             = (string) $decision->text;

        $verdict = $this->guard->verdict($text, $question, $requiresSetting);

        if (! $verdict->isAllow()) {
            $this->markGroup($decision->coveredMessageIds, ['status' => 'escalated']);
            $this->reactOnce($selfId, '🤔');
            return;
        }

        if (! $this->sender->sendAnswer($selfId, $text)) {
            return; // остаётся 'new' — гейты/сеть/Telegram not-ok, повторим на следующем тике
        }

        // Полоса A без совпадения банка (Decision::escalated) — честное «не знаю»
        // ушло, но вопрос всё равно ждёт человека, а не закрыт банк-ответом.
        $status = $decision->escalated ? 'escalated' : 'answered';
        $this->markGroup($decision->coveredMessageIds, ['status' => $status, 'answered_by_id' => $decision->answerId]);
    }

    /**
     * @param list<int> $ids
     * @param array<string, bool|float|int|string|null> $fields
     */
    private function markGroup(array $ids, array $fields): void
    {
        foreach ($ids as $id) {
            $this->messageModel->update($id, $fields);
        }
    }

    private function reactOnce(int $messageRowId, string $emoji): void
    {
        if ($messageRowId <= 0 || $this->alreadyReacted($messageRowId)) {
            return;
        }
        $this->sender->react($messageRowId, $emoji);
    }

    /** Реакция ставится не больше раза на сообщение — `CommunityChatSender` сама
     *  этого не гарантирует (её собственный docblock, story 06), гарантия отсюда. */
    private function alreadyReacted(int $messageRowId): bool
    {
        $row = $this->auditModel
            ->where('action', 'COMMUNITY_REACTION_SENT')
            ->where('target_id', $messageRowId)
            ->first();

        return $row !== null;
    }

    private function requiresSetting(int $answerId): ?string
    {
        $row = $this->answerModel->find($answerId);
        if (! is_array($row)) {
            return null;
        }
        $value = $row['requires_setting'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    // ── типобезопасные читатели ──────────────────────────────────────────

    private function readBool(string $key, bool $default): bool
    {
        $raw = ($this->settingsGetter)($key, $default);

        return is_bool($raw) ? $raw : $default;
    }

    /**
     * Строка `findAll()` CI4 приходит как `array<int|string, mixed>` — приводит ключи
     * к `string` без изменения значений (тот же приём, что `CommunityAnswerMatcher`).
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
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_string($value) ? $value : '';
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
}
