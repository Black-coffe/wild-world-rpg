<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Services\Community\CommunityChatSender;
use App\Services\Community\CommunityGuard;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeImmutable;

/**
 * ADR-176 (community-chat-bot), story 12 — `/admin/community`: руки владельца.
 * Единственный путь `community_answers.status` из `draft` в `approved` (план §3, §10:
 * «путь draft -> сказано в чат идёт исключительно через `/admin/community` с
 * `audit()`»); импорт (`community:import`, story 11) создаёт и трогает только `draft`.
 *
 * Экран показывает две живые сущности разом: открытые вопросы `community_messages`
 * (`status IN ('new','escalated') AND is_question=1` — {@see openQuestionsBuilder()},
 * единое определение с метрикой просроченных) и черновики банка `community_answers`
 * (`status='draft'`). Они НЕ связаны FK — `community_answers.source_ref` несёт
 * провенанс ТЕКСТА (`guide:…`/`tip:…`/`post:…`, тот же формат, что корпус
 * `CommunityGuard::defaultCorpus()`), а не id вопроса, который его породил.
 * Поэтому «на какое открытое сообщение ответить этим черновиком» — решение
 * владельца в момент одобрения (явный выбор в форме), не автоматический матч.
 *
 * Четыре действия контракта (план §3 диаграмма):
 *  - **Одобрить** — {@see approveAnswer()}: `CommunityGuard` перепроверяется на
 *    АКТУАЛЬНЫЙ текст (владелец мог отредактировать его до одобрения — иначе гвард
 *    обходится вручную и все пять рубежей ADR-176 §5 теряют смысл), затем (если выбрана
 *    цель) отправка реплаем через `CommunityChatSender` и смена статуса — атомарно:
 *    отказ отправки не меняет ни `community_answers`, ни `community_messages`.
 *  - **Правка** — {@see save()}: текст меняется, пока строка ещё `draft`.
 *  - **Отклонить** — {@see reject()}: `draft` → `rejected`.
 *  - **Отозвать** — {@see revokeAnswer()}: `approved` → `revoked` + поправка реплаем
 *    в тот же топик (то же исходное сообщение, если оно ещё известно через
 *    `community_messages.answered_by_id`) — единственный способ починить ошибку,
 *    без которого её ищут глазами.
 *
 * Кнопка «Стереть всё от этого игрока» — {@see eraseMessagesFromPlayer()} — обещание
 * из закрепа (план §9): без реально работающей кнопки просьба на удаление невыполнима.
 *
 * Ручная дверь (`approveAnswer()`/`revokeAnswer()`) зовёт {@see CommunityChatSender::sendManualAnswer()}
 * (story 18/19), а не `sendAnswer()` — этот путь НЕ гасится килсвитчем
 * `community.autoreply.enabled`, потолком в час и кулдауном автора: они существуют
 * только против того, чтобы бот забивал топик сам собой, живого владельца за
 * админкой не ограничивают. `community.enabled`, `silent_topics` и проверки текста
 * (длина/парность `*`/канон имени) остаются для обеих отправок без исключений.
 */
final class CommunityController extends BaseAdminController
{
    /** План §13: метрики считаются за окно, а не за всё время. */
    private const METRICS_WINDOW_DAYS = 7;

    /** План §13: открытый вопрос старше этого — инцидент, не «низкий приоритет». */
    private const STALE_QUESTION_HOURS = 72;

    private CommunityMessageModel $messageModel;
    private CommunityAnswerModel $answerModel;
    private CommunityGuard $guard;
    private CommunityChatSender $sender;

    /** @var BaseConnection<\mysqli, \mysqli_result> */
    private BaseConnection $db;

    /**
     * Опциональные аргументы — сеам для тестов на реальной изолированной схеме
     * `wildworld_tests` (паттерн `CommunityAutoReplyHandlerTest`), без сети —
     * `CommunityChatSender`/`CommunityGuard` инжектируются с двойниками.
     *
     * @param BaseConnection<\mysqli, \mysqli_result>|null $db
     */
    public function __construct(
        ?CommunityMessageModel $messageModel = null,
        ?CommunityAnswerModel $answerModel = null,
        ?CommunityGuard $guard = null,
        ?CommunityChatSender $sender = null,
        ?BaseConnection $db = null,
    ) {
        $this->messageModel = $messageModel ?? new CommunityMessageModel();
        $this->answerModel  = $answerModel ?? new CommunityAnswerModel();
        $this->guard         = $guard ?? new CommunityGuard();
        $this->sender         = $sender ?? new CommunityChatSender();
        $this->db             = $db ?? Database::connect();
    }

    // ── экраны ───────────────────────────────────────────────────────────

    public function index(): string
    {
        return view('admin/community_index', [
            'title'        => 'Очередь модерации чата сообщества',
            'topics'       => $this->openQuestionsByTopic(),
            'openMessages' => $this->openQuestionsFlat(),
            'drafts'       => (new CommunityAnswerModel())->where('status', 'draft')->orderBy('created_at', 'ASC')->findAll(),
            'approved'     => (new CommunityAnswerModel())->where('status', 'approved')->orderBy('approved_at', 'DESC')->findAll(),
            'metrics'      => $this->computeMetrics(),
            'staleHours'   => self::STALE_QUESTION_HOURS,
        ]);
    }

    public function editForm(int $answerId): string|ResponseInterface
    {
        $answer = $this->answerModel->find($answerId);
        if (! is_array($answer)) {
            return $this->failNotFound('Черновик не найден.');
        }

        return view('admin/community_answer_form', [
            'title'        => 'Правка ответа: #' . $answerId,
            'answer'       => $this->normalize($answer),
            'openMessages' => $this->openQuestionsFlat(),
        ]);
    }

    public function save(int $answerId): RedirectResponse|ResponseInterface
    {
        $answer = $this->answerModel->find($answerId);
        if (! is_array($answer)) {
            return $this->failNotFound('Черновик не найден.');
        }
        if (($this->normalize($answer)['status'] ?? null) !== 'draft') {
            return $this->redirectBackWithError('Правка доступна только для черновика.');
        }

        $textRaw    = $this->request->getPost('answer_text');
        $patternRaw = $this->request->getPost('question_pattern');
        $text       = is_string($textRaw) ? $textRaw : '';
        $pattern    = is_string($patternRaw) ? $patternRaw : '';

        $requiresRaw = $this->request->getPost('requires_setting');
        $requires    = is_string($requiresRaw) && trim($requiresRaw) !== '' ? trim($requiresRaw) : null;

        if (trim($text) === '' || trim($pattern) === '') {
            return $this->redirectBackWithError('Текст ответа и паттерн вопроса обязательны.');
        }

        $this->answerModel->update($answerId, [
            'answer_text'      => $text,
            'question_pattern' => $pattern,
            'requires_setting' => $requires,
        ]);
        $this->audit('COMMUNITY_ANSWER_EDITED', 'community_answer', $answerId, []);

        return $this->redirectWithSuccess(site_url('admin/community/answer/' . $answerId . '/edit'), 'Черновик сохранён.');
    }

    public function approve(int $answerId): RedirectResponse|ResponseInterface
    {
        if (! is_array($this->answerModel->find($answerId))) {
            return $this->failNotFound('Черновик не найден.');
        }

        $messageIdRaw = $this->request->getPost('message_id');
        $messageId    = is_numeric($messageIdRaw) ? (int) $messageIdRaw : null;

        $result = $this->approveAnswer($answerId, $messageId);
        if (! $result['ok']) {
            return $this->redirectBackWithError($result['error'] ?? 'Не удалось одобрить ответ.');
        }

        return $this->redirectWithSuccess(
            site_url('admin/community'),
            $messageId !== null ? 'Ответ одобрен и отправлен в чат.' : 'Ответ одобрен и пополнил банк.'
        );
    }

    public function reject(int $answerId): RedirectResponse|ResponseInterface
    {
        $answer = $this->answerModel->find($answerId);
        if (! is_array($answer)) {
            return $this->failNotFound('Черновик не найден.');
        }
        if (($this->normalize($answer)['status'] ?? null) !== 'draft') {
            return $this->redirectBackWithError('Отклонить можно только черновик.');
        }

        $this->answerModel->update($answerId, ['status' => 'rejected']);
        $this->audit('COMMUNITY_ANSWER_REJECTED_BY_ADMIN', 'community_answer', $answerId, []);

        return $this->redirectWithSuccess(site_url('admin/community'), 'Черновик отклонён.');
    }

    public function revoke(int $answerId): RedirectResponse|ResponseInterface
    {
        $correctionRaw = $this->request->getPost('correction_text');
        $correction    = is_string($correctionRaw) && trim($correctionRaw) !== '' ? $correctionRaw : null;

        $result = $this->revokeAnswer($answerId, $correction);
        if (! $result['ok']) {
            return $this->redirectBackWithError($result['error'] ?? 'Не удалось отозвать ответ.');
        }

        return $this->redirectWithSuccess(site_url('admin/community'), 'Ответ отозван.');
    }

    public function erase(): RedirectResponse
    {
        $telegramUserIdRaw = $this->request->getPost('telegram_user_id');
        $telegramUserId    = is_numeric($telegramUserIdRaw) ? (int) $telegramUserIdRaw : null;
        if ($telegramUserId === null) {
            return $this->redirectBackWithError('Не указан игрок.');
        }

        $deleted = $this->eraseMessagesFromPlayer($telegramUserId);

        return $this->redirectWithSuccess(site_url('admin/community'), "Стёрто сообщений игрока: {$deleted}.");
    }

    // ── бизнес-логика (тестируется напрямую, без HTTP-цикла) ──────────────

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function approveAnswer(int $answerId, ?int $messageRowId): array
    {
        $answerRaw = $this->answerModel->find($answerId);
        if (! is_array($answerRaw)) {
            return ['ok' => false, 'error' => 'Черновик не найден.'];
        }
        $answer = $this->normalize($answerRaw);
        if (($answer['status'] ?? null) !== 'draft') {
            return ['ok' => false, 'error' => 'Одобрить можно только черновик.'];
        }

        $answerText      = is_string($answer['answer_text'] ?? null) ? $answer['answer_text'] : '';
        $questionPattern = is_string($answer['question_pattern'] ?? null) ? $answer['question_pattern'] : '';
        $requiresSetting = is_string($answer['requires_setting'] ?? null) ? $answer['requires_setting'] : null;

        $questionText = $questionPattern;
        if ($messageRowId !== null) {
            $messageRaw = $this->messageModel->find($messageRowId);
            if (! is_array($messageRaw)) {
                return ['ok' => false, 'error' => 'Исходное сообщение не найдено.'];
            }
            $message      = $this->normalize($messageRaw);
            $questionText = is_string($message['text'] ?? null) ? $message['text'] : $questionPattern;
        }

        // Владелец мог отредактировать текст перед одобрением («Правка») — гвард
        // обязан перепроверить АКТУАЛЬНЫЙ текст, не снимок на момент импорта черновика.
        $verdict = $this->guard->verdict($answerText, $questionText, $requiresSetting);
        if (! $verdict->isAllow()) {
            return ['ok' => false, 'error' => 'CommunityGuard отклонил текст: ' . $verdict->reason];
        }

        if ($messageRowId !== null && ! $this->sender->sendManualAnswer($messageRowId, $answerText)) {
            // Атомарность контракта: отказ отправки — ни community_answers, ни
            // community_messages не меняются, черновик остаётся draft.
            return ['ok' => false, 'error' => 'Отправка в чат не удалась — статус не изменён.'];
        }

        $this->answerModel->update($answerId, [
            'status'      => 'approved',
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by' => $this->adminLabel(),
        ]);

        if ($messageRowId !== null) {
            $this->messageModel->update($messageRowId, [
                'status'         => 'answered',
                'answered_by_id' => $answerId,
            ]);
        }

        $this->audit('COMMUNITY_ANSWER_APPROVED', 'community_answer', $answerId, [
            'message_row_id' => $messageRowId,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * Отзыв — только для уже одобренного ответа. Запись банка перестаёт матчиться
     * (`CommunityAnswerMatcher::activeBankRecords()` фильтрует `revoked_at IS NOT NULL`),
     * а если ответ реально ушёл в чат (находится сообщение с `answered_by_id=$answerId`)
     * — туда же, реплаем на то же исходное сообщение, уходит поправка.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function revokeAnswer(int $answerId, ?string $correctionText): array
    {
        $answerRaw = $this->answerModel->find($answerId);
        if (! is_array($answerRaw)) {
            return ['ok' => false, 'error' => 'Ответ не найден.'];
        }
        if (($this->normalize($answerRaw)['status'] ?? null) !== 'approved') {
            return ['ok' => false, 'error' => 'Отозвать можно только одобренный ответ.'];
        }

        // `answered_by_id` может стоять сразу на нескольких строках — склейка дублей
        // (`Decision::coveredMessageIds`) помечает им все накрытые строки. Цель
        // отзыва — самая ранняя из них (реальный исходный вопрос, не случайный
        // дубль), поэтому сортировка обязательна, `first()` без неё детерминизма не даёт.
        $answeredMessageRaw = (new CommunityMessageModel())
            ->where('answered_by_id', $answerId)
            ->orderBy('sent_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->first();
        $targetId           = null;
        if (is_array($answeredMessageRaw)) {
            $answeredMessage = $this->normalize($answeredMessageRaw);
            $targetId        = isset($answeredMessage['id']) && is_numeric($answeredMessage['id'])
                ? (int) $answeredMessage['id']
                : null;
        }

        $correctionSent = false;
        if ($targetId !== null && $correctionText !== null) {
            if (! $this->sender->sendManualAnswer($targetId, $correctionText)) {
                return ['ok' => false, 'error' => 'Поправка не отправилась — отзыв отменён.'];
            }
            $correctionSent = true;
        }

        $this->answerModel->update($answerId, [
            'status'     => 'revoked',
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit('COMMUNITY_ANSWER_REVOKED', 'community_answer', $answerId, [
            'target_message_id' => $targetId,
            'correction_sent'   => $correctionSent,
        ]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * «Стереть всё от этого игрока» (план §9) — единственный выход, который делает
     * обещание из закрепа выполнимым. Удаляет ВСЕ строки `community_messages` автора
     * и подтверждает числом удалённых.
     */
    public function eraseMessagesFromPlayer(int $telegramUserId): int
    {
        $count = (int) (new CommunityMessageModel())->where('telegram_user_id', $telegramUserId)->countAllResults();
        if ($count > 0) {
            (new CommunityMessageModel())->where('telegram_user_id', $telegramUserId)->delete();
        }

        $this->audit('COMMUNITY_PLAYER_DATA_ERASED', 'community_message', null, [
            'telegram_user_id' => $telegramUserId,
            'deleted_count'    => $count,
        ]);

        return $count;
    }

    // ── метрики провала (план §13) ─────────────────────────────────────

    /**
     * @return array{
     *     bot_vs_human_share: ?float,
     *     guard_rejection_rate: ?float,
     *     stale_open_questions: int,
     *     top_repeated: list<array{answer_id:int, question_pattern:string, uses:int}>,
     * }
     */
    public function computeMetrics(?DateTimeImmutable $now = null): array
    {
        $now            = $now ?? new DateTimeImmutable();
        $since          = $now->modify('-' . self::METRICS_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');
        $staleThreshold = $now->modify('-' . self::STALE_QUESTION_HOURS . ' hours')->format('Y-m-d H:i:s');

        // «Бот против живых» (план §13, главная метрика): доля АВТОМАТИЧЕСКИХ
        // бот-ответов среди (бот-ответы + человек-человеку) в окне. `status='answered'`
        // ставят ОБА пути (автотик и ручное одобрение владельцем через
        // `approveAnswer()`) — считать по статусу значит засчитывать ручные ответы
        // владельца ответами бота, ровно то, ради чего story 18 завела раздельные
        // имена аудит-действий (`COMMUNITY_ANSWER_SENT` — автотик,
        // `COMMUNITY_MANUAL_ANSWER_SENT` — владелец). Сообщения бота вообще не попадают
        // в community_messages (вебхук не получает апдейт на собственную отправку) —
        // поэтому reply_to_message_id, указывающий на строку в ЭТОЙ ЖЕ таблице,
        // структурно не может быть ответом боту, только другому игроку.
        $botAnswers   = $this->autoAnswerCount($since);
        $humanReplies = $this->humanToHumanReplyCount($since);
        $totalReplies = $botAnswers + $humanReplies;
        $botVsHuman   = $totalReplies > 0 ? (float) $botAnswers / $totalReplies : null;

        // Доля отказов гварда: `status='escalated'` покрывает ДВА разных случая —
        // (а) `CommunityGuard::verdict()` реально не выдал allow (`resolveAndSend()`
        // помечает escalated и ничего не отправляет), и (б) полоса A без совпадения
        // банка, где гвард ПРОПУСТИЛ честное «не знаю» и текст ушёл (`Decision::escalated`,
        // `resolveAndSend()` всё равно ставит escalated при успешной отправке). Только
        // (а) — отказ гварда; (б) отличим по наличию `COMMUNITY_ANSWER_SENT` — эта
        // запись пишется ТОЛЬКО при успешной отправке, гвард-отказ до неё не доходит.
        $guardDenied        = $this->guardDeniedCount($since);
        $guardTotal         = $botAnswers + $guardDenied;
        $guardRejectionRate = $guardTotal > 0 ? (float) $guardDenied / $guardTotal : null;

        // Дефект 1: очередь и метрика просроченных обязаны использовать одно
        // определение «открытого вопроса» (см. openQuestionsBuilder()).
        $staleOpen = (int) $this->openQuestionsBuilder()
            ->where('sent_at <', $staleThreshold)
            ->countAllResults();

        return [
            'bot_vs_human_share'   => $botVsHuman,
            'guard_rejection_rate' => $guardRejectionRate,
            'stale_open_questions' => $staleOpen,
            'top_repeated'         => $this->topRepeatedQuestions($since),
        ];
    }

    /** Автоматические отправки бота в окне — только `COMMUNITY_ANSWER_SENT` (автотик),
     *  ручные (`COMMUNITY_MANUAL_ANSWER_SENT`) сюда не входят (см. computeMetrics()). */
    private function autoAnswerCount(string $since): int
    {
        $sql = 'SELECT COUNT(*) AS n
                FROM admin_audit_log a
                INNER JOIN community_messages cm ON cm.id = a.target_id
                WHERE a.action = \'COMMUNITY_ANSWER_SENT\'
                  AND cm.sent_at >= ?';

        $query = $this->db->query($sql, [$since]);
        if (! $query instanceof BaseResult) {
            return 0;
        }
        $row = $query->getRowArray();

        return isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
    }

    /** Строки `escalated` в окне, для которых гвард НЕ выдал allow — то есть у
     *  строки нет своего `COMMUNITY_ANSWER_SENT`, текст туда не уходил вовсе. */
    private function guardDeniedCount(string $since): int
    {
        $sql = "SELECT COUNT(*) AS n
                FROM community_messages cm
                WHERE cm.status = 'escalated'
                  AND cm.sent_at >= ?
                  AND NOT EXISTS (
                      SELECT 1 FROM admin_audit_log a
                      WHERE a.action = 'COMMUNITY_ANSWER_SENT' AND a.target_id = cm.id
                  )";

        $query = $this->db->query($sql, [$since]);
        if (! $query instanceof BaseResult) {
            return 0;
        }
        $row = $query->getRowArray();

        return isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
    }

    private function humanToHumanReplyCount(string $since): int
    {
        // Человек ответил человеку: сообщение в окне с reply_to_message_id, для
        // которого в ЭТОЙ ЖЕ таблице есть строка с таким message_id (значит, отвечали
        // реальному игроку, чьё сообщение тоже принято), и авторы разные.
        $sql = 'SELECT COUNT(*) AS n
                FROM community_messages r
                INNER JOIN community_messages orig
                    ON orig.chat_id = r.chat_id AND orig.message_id = r.reply_to_message_id
                WHERE r.sent_at >= ?
                  AND r.reply_to_message_id IS NOT NULL
                  AND orig.telegram_user_id <> r.telegram_user_id';

        $query = $this->db->query($sql, [$since]);
        if (! $query instanceof BaseResult) {
            return 0;
        }
        $row = $query->getRowArray();

        return isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
    }

    /**
     * Топ повторяющихся вопросов (план §13) — какой банк-ответ использован чаще
     * всего в окне; готовая карта дыр онбординга (вход в guide/tips-ревью).
     *
     * @return list<array{answer_id:int, question_pattern:string, uses:int}>
     */
    private function topRepeatedQuestions(string $since, int $limit = 5): array
    {
        $sql = 'SELECT cm.answered_by_id AS answer_id, ca.question_pattern AS question_pattern, COUNT(*) AS uses
                FROM community_messages cm
                INNER JOIN community_answers ca ON ca.id = cm.answered_by_id
                WHERE cm.sent_at >= ? AND cm.answered_by_id IS NOT NULL
                GROUP BY cm.answered_by_id, ca.question_pattern
                ORDER BY uses DESC
                LIMIT ' . max(1, $limit);

        $query = $this->db->query($sql, [$since]);
        if (! $query instanceof BaseResult) {
            return [];
        }

        $out = [];
        foreach ($query->getResultArray() as $row) {
            $answerId = isset($row['answer_id']) && is_numeric($row['answer_id']) ? (int) $row['answer_id'] : 0;
            $pattern  = isset($row['question_pattern']) && is_string($row['question_pattern']) ? $row['question_pattern'] : '';
            $uses     = isset($row['uses']) && is_numeric($row['uses']) ? (int) $row['uses'] : 0;
            $out[]    = ['answer_id' => $answerId, 'question_pattern' => $pattern, 'uses' => $uses];
        }

        return $out;
    }

    // ── очередь: открытые вопросы ───────────────────────────────────────

    /**
     * @return array<int, list<array<string, mixed>>> сгруппировано по
     *         `message_thread_id` (ключ `0` — сообщения без треда).
     */
    private function openQuestionsByTopic(): array
    {
        $out = [];
        foreach ($this->openQuestionsFlat() as $row) {
            $threadId       = isset($row['message_thread_id']) && is_numeric($row['message_thread_id'])
                ? (int) $row['message_thread_id']
                : 0;
            $out[$threadId][] = $row;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function openQuestionsFlat(): array
    {
        $rows = $this->openQuestionsBuilder()
            ->orderBy('message_thread_id', 'ASC')
            ->orderBy('sent_at', 'ASC')
            ->findAll();

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $this->normalize($row);
            }
        }

        return $out;
    }

    /**
     * Дефект 1: определение «открытого вопроса» — общее для очереди
     * ({@see openQuestionsFlat()}) и метрики просроченных (`computeMetrics()`).
     * `status IN ('new','escalated')` один `is_question=1` не фильтрует — дефолтный
     * статус ЛЮБОЙ принятой реплики `new`, и её меняет только авто-тик, который на
     * днях 1–5 обкатки не запускается вовсе: без `is_question=1` очередь показывает
     * как «открытые вопросы» каждое сообщение чата.
     */
    private function openQuestionsBuilder(): CommunityMessageModel
    {
        return (new CommunityMessageModel())
            ->whereIn('status', ['new', 'escalated'])
            ->where('is_question', 1);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * `service('auth')` в этом проекте не несёт `user()` — только `getCurrentUser()`
     * (тот же приём, что `GameSettingsController::currentAdminLabel()`).
     */
    private function adminLabel(): string
    {
        $auth = service('auth');
        $user = is_object($auth) && method_exists($auth, 'getCurrentUser')
            ? $auth->getCurrentUser()
            : null;

        return GameSettingsController::resolveAdminLabel($user);
    }
}
