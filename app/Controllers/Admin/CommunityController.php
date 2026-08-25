<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CommunityAnswerModel;
use App\Models\CommunityMessageModel;
use App\Services\Community\CommunityChatSender;
use App\Services\Community\CommunityGuard;
use App\Services\GameSettings\GameSettingsReaderTrait;
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
    use GameSettingsReaderTrait;

    /** План §13: метрики считаются за окно, а не за всё время. */
    private const METRICS_WINDOW_DAYS = 7;

    /**
     * Story 32, дефект 1 — прошлая константа (72ч) жила независимо от порога, которым
     * `CommunityCleanup` закрывает зависшие вопросы в `ignored` (`community.question.
     * max_age_hours`, дефолт 48ч). Чистка почти всегда успевала закрыть вопрос ДО того,
     * как он доживал до 72ч — будильник деградировал до одних `escalated`. Явное
     * отношение вместо второй независимой константы: порог "просрочен" — доля того же
     * ключа GameSettings, СТРОГО МЕНЬШЕ порога авто-закрытия, чтобы у владельца был
     * запас среагировать до того, как чистка в 03:45 молча уберёт строку из очереди.
     */
    private const STALE_FRACTION = 0.5;

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
            'staleHours'   => $this->staleQuestionHours(),
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

    /**
     * ADR-178 (story 68) — одобрение с непустыми `advisories` не проходит с первого
     * нажатия: страница переотрисовывается тем же шаблоном {@see editForm()}, но с
     * пометками рубежа 1 и чекбоксом «отвечаю за него» (`confirm_advisories`).
     * Второе нажатие с отмеченным чекбоксом несёт `confirm_advisories=1` и проходит.
     */
    public function approve(int $answerId): string|RedirectResponse|ResponseInterface
    {
        $answerRaw = $this->answerModel->find($answerId);
        if (! is_array($answerRaw)) {
            return $this->failNotFound('Черновик не найден.');
        }

        $messageIdRaw = $this->request->getPost('message_id');
        $messageId    = is_numeric($messageIdRaw) ? (int) $messageIdRaw : null;

        $confirmRaw         = $this->request->getPost('confirm_advisories');
        $confirmedAdvisories = $confirmRaw === '1';

        $result = $this->approveAnswer($answerId, $messageId, $confirmedAdvisories);

        $advisories = $result['advisories'] ?? [];
        if (! $result['ok'] && $advisories !== []) {
            return view('admin/community_answer_form', [
                'title'            => 'Правка ответа: #' . $answerId,
                'answer'           => $this->normalize($answerRaw),
                'openMessages'     => $this->openQuestionsFlat(),
                'advisories'       => $advisories,
                'pendingMessageId' => $messageId,
            ]);
        }

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
     * ADR-178 (story 68) — `$confirmAdvisories` несёт второе явное подтверждение
     * владельца («текст не подтверждён источником — отвечаю за него»). Провенанс
     * (рубеж 1) не имеет права вето: непустые `$verdict->advisories` не блокируют
     * `verdict()->isAllow()`, но без `$confirmAdvisories=true` одобрение здесь всё
     * равно останавливается — форма обязана переотрисоваться с пометками, а не
     * пройти бегло. Отсутствие пометки НЕ означает «подтверждено источником»: рубеж
     * 1 просто не нашёл, к чему придраться (см. ADR-178, замер — 4 фабриката из 22
     * прошли без пометки случайно).
     *
     * @return array{ok: bool, error: ?string, advisories?: list<string>}
     */
    public function approveAnswer(int $answerId, ?int $messageRowId, bool $confirmAdvisories = false): array
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
        // Story 72: $isApprovalContext=true — единственное место, где `deny` вправе
        // денить (ADR-178 «вето только на одобрении»).
        $verdict = $this->guard->verdict($answerText, $questionText, $requiresSetting, true);
        if (! $verdict->isAllow()) {
            return ['ok' => false, 'error' => 'CommunityGuard отклонил текст: ' . $verdict->reason];
        }

        if ($verdict->advisories !== [] && ! $confirmAdvisories) {
            return [
                'ok'         => false,
                'error'      => 'Часть текста не подтверждена источником — нужно второе подтверждение.',
                'advisories' => $verdict->advisories,
            ];
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
            'message_row_id'       => $messageRowId,
            // ADR-178 — «одобрено вопреки пометке»: сигнал для будущего разбора, не
            // только для текущего решения. null, если пометок не было вовсе.
            'advisories_confirmed' => $verdict->advisories !== [] ? $verdict->advisories : null,
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
     *     auto_closed_unanswered: int,
     *     top_repeated: list<array{answer_id:int, question_pattern:string, uses:int}>,
     * }
     */
    public function computeMetrics(?DateTimeImmutable $now = null): array
    {
        $now            = $now ?? new DateTimeImmutable();
        $since          = $now->modify('-' . self::METRICS_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');
        $staleThreshold = $now->modify('-' . $this->staleQuestionHours() . ' hours')->format('Y-m-d H:i:s');

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
        // помечает escalated), и (б) полоса A без совпадения банка, где гвард ПРОПУСТИЛ
        // честное «не знаю» и текст ушёл (`Decision::escalated`, `resolveAndSend()` всё
        // равно ставит escalated при успешной отправке). Только (а) — отказ гварда; (б)
        // отличим по своей `COMMUNITY_ROUTE_LOGGED` (см. guardDeniedCount()) — маркер о
        // том, что строку записали как отказ, независимо от того, что именно отправил
        // бот. Story 58 заводит отдельное действие `COMMUNITY_ROUTE_SENT` для самой
        // отправки маршрута (story 57 переводит на него `CommunityAutoReplyHandler`) —
        // с этого момента отказ и ответ пишут РАЗНЫЕ действия и структурно не могут
        // пересечься: `autoAnswerCount()` считает только `COMMUNITY_ANSWER_SENT`, отказ
        // туда никогда не попадает, и `guardTotal` не задваивает одно сообщение.
        $guardDenied        = $this->guardDeniedCount($since);
        $guardTotal         = $botAnswers + $guardDenied;
        $guardRejectionRate = $guardTotal > 0 ? (float) $guardDenied / $guardTotal : null;

        // Дефект 1: очередь и метрика просроченных обязаны использовать одно
        // определение «открытого вопроса» (см. openQuestionsBuilder()).
        $staleOpen = (int) $this->openQuestionsBuilder()
            ->where('sent_at <', $staleThreshold)
            ->countAllResults();

        return [
            'bot_vs_human_share'      => $botVsHuman,
            'guard_rejection_rate'    => $guardRejectionRate,
            'stale_open_questions'    => $staleOpen,
            'auto_closed_unanswered'  => $this->autoClosedCount($since),
            'top_repeated'            => $this->topRepeatedQuestions($since),
        ];
    }

    /**
     * Story 32, acceptance «не исчезает молча»: `CommunityCleanup` пишет
     * `COMMUNITY_QUESTION_AUTO_CLOSED` на каждую строку, которую чистка в 03:45
     * переводит в `ignored` по возрасту — иначе вопрос без ответа тихо выпадает
     * из `openQuestionsBuilder()` (только `new`/`escalated`), и владелец о нём
     * никогда не узнаёт.
     */
    private function autoClosedCount(string $since): int
    {
        $sql = "SELECT COUNT(*) AS n
                FROM admin_audit_log
                WHERE action = 'COMMUNITY_QUESTION_AUTO_CLOSED' AND created_at >= ?";

        $query = $this->db->query($sql, [$since]);
        if (! $query instanceof BaseResult) {
            return 0;
        }
        $row = $query->getRowArray();

        return isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
    }

    /**
     * Story 32, дефект 1 — единый источник с `CommunityCleanup::cleanup()`
     * (`community.question.max_age_hours`), явная доля {@see STALE_FRACTION}
     * вместо совпадения двух независимых констант.
     */
    private function staleQuestionHours(): int
    {
        $maxAgeHours = $this->gsInt('community.question.max_age_hours', 48);

        return max(1, (int) round($maxAgeHours * self::STALE_FRACTION));
    }

    /**
     * Автоматические ОТВЕТЫ бота в окне — только `COMMUNITY_ANSWER_SENT` (автотик).
     * Отказ гварда сюда не попадает: `sendGuardRoute()` (story 58) пишет отдельное
     * действие `COMMUNITY_ROUTE_SENT`, `CommunityAutoReplyHandler` переходит на него
     * в story 57 — считать по действию, а не по маркеру журнала `COMMUNITY_ROUTE_LOGGED`
     * (который значит «строку записали как отказ», а не «что именно отправил бот»),
     * иначе метрика зависит от совпадения двух независимых механизмов (story 59, ревью
     * лида). Ручные (`COMMUNITY_MANUAL_ANSWER_SENT`) сюда по-прежнему не входят
     * (computeMetrics()).
     */
    private function autoAnswerCount(string $since): int
    {
        $sql = "SELECT COUNT(*) AS n
                FROM admin_audit_log a
                INNER JOIN community_messages cm ON cm.id = a.target_id
                WHERE a.action = 'COMMUNITY_ANSWER_SENT'
                  AND cm.sent_at >= ?";

        $query = $this->db->query($sql, [$since]);
        if (! $query instanceof BaseResult) {
            return 0;
        }
        $row = $query->getRowArray();

        return isset($row['n']) && is_numeric($row['n']) ? (int) $row['n'] : 0;
    }

    /**
     * Строки `escalated` в окне, для которых гвард НЕ выдал allow. Различитель — СВОЯ
     * отметка `COMMUNITY_ROUTE_LOGGED` (ниже): значит «строку записали как отказ гварда»,
     * независимо от того, ушёл ли и чем именно текст игроку — `autoAnswerCount()` эту
     * отметку не читает вовсе, отказ и ответ различаются по РАЗНЫМ действиям аудита
     * (`COMMUNITY_ROUTE_SENT` у отправки маршрута, story 58, против `COMMUNITY_ANSWER_SENT`
     * у ответа), а не по присутствию/отсутствию этого журнального маркера.
     *
     * Story 32, дефект 2 — этого одного условия недостаточно: story 23 завела
     * терминальные отказы ГЕЙТА ОТПРАВИТЕЛЯ (`CommunityChatSender::checkGates()` —
     * длина, непарный `*`, неканоничное имя), которые `CommunityAutoReplyHandler`
     * тоже переводит в `escalated` без `SENT` — но это НЕ отказ гварда: гвард уже
     * сказал allow, текст отклонил гейт отправки.
     *
     * Story 39, дефект — различитель через `NOT EXISTS (... COMMUNITY_ANSWER_REJECTED ...)`
     * смотрел на строку целиком, а не на ЭТУ попытку: если сообщение раньше упёрлось в
     * `topic_rate_limit` (отправитель пишет `COMMUNITY_ANSWER_REJECTED`, строка возвращается
     * в `new`), а затем ту же строку денит гвард (`escalated` без `SENT`), давняя
     * `_REJECTED` всё ещё есть — строка тихо выпадала и из числителя, и из знаменателя.
     * Различитель теперь — СВОЯ положительная запись: `resolveAndSend()` пишет
     * `COMMUNITY_ROUTE_LOGGED` (см. `CommunityAutoReplyHandler::logRoute()`) именно и
     * только в момент отказа гварда, до вызова `sendAnswer()` — гейт отправки этот
     * экшен не пишет вовсе. `EXISTS` вместо `NOT EXISTS` на чужой истории.
     *
     * Story 46 — этот запрос не менялся: дефект переехал не в SQL, а в то, что писала
     * запись. `resolveAndSend()` вызывала `logRoute()` один раз на строку-представителя,
     * а `escalated` получала ВСЯ склейка дублей — остальные строки склейки были
     * `escalated` без своего `COMMUNITY_ROUTE_LOGGED` и выпадали из этого `EXISTS` (тот же
     * класс дефекта, что и story 39, просто переехавший с «упёрлись в лимит» на дубликаты
     * одного вопроса). Починка — {@see \App\TaskHandlers\Community\CommunityAutoReplyHandler::escalateGuardDenial()}:
     * маршрут пишется на КАЖДУЮ строку склейки, одной транзакцией со статусом, так что
     * сбой best-effort вставки больше не может оставить `escalated` без аудит-строки,
     * по которой её опознаёт этот запрос.
     */
    private function guardDeniedCount(string $since): int
    {
        $sql = "SELECT COUNT(*) AS n
                FROM community_messages cm
                WHERE cm.status = 'escalated'
                  AND cm.sent_at >= ?
                  AND EXISTS (
                      SELECT 1 FROM admin_audit_log a
                      WHERE a.action = 'COMMUNITY_ROUTE_LOGGED' AND a.target_id = cm.id
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

    /**
     * @return list<array<string, mixed>> строки несут дополнительный ключ `route`
     *         (string|null) — {@see routesByMessageId()}, дефект 3.
     */
    private function openQuestionsFlat(): array
    {
        $rows = $this->openQuestionsBuilder()
            ->orderBy('message_thread_id', 'ASC')
            ->orderBy('sent_at', 'ASC')
            ->findAll();

        $out          = [];
        $escalatedIds = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->normalize($row);
            $out[]      = $normalized;
            if (($normalized['status'] ?? null) === 'escalated' && isset($normalized['id']) && is_numeric($normalized['id'])) {
                $escalatedIds[] = (int) $normalized['id'];
            }
        }

        if ($escalatedIds === []) {
            foreach ($out as &$row) {
                $row['route'] = null;
            }
            unset($row);

            return $out;
        }

        $routes = $this->routesByMessageId($escalatedIds);
        foreach ($out as &$row) {
            $id           = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            $row['route'] = $id !== null ? ($routes[$id] ?? null) : null;
        }
        unset($row);

        return $out;
    }

    /**
     * Дефект 3: `COMMUNITY_ROUTE_LOGGED` (пишет `CommunityAutoReplyHandler::logRoute()`
     * на вердикт гварда `deny`/`manual`) читался ТОЛЬКО на общем `/admin/audit-log` —
     * владелец не видел маршрут отказа рядом со строкой очереди, где реально её
     * разбирает. Последняя запись на строку (склейка дублей могла переоценивать
     * гвард несколько раз) — `ORDER BY id DESC`, первая встреченная и есть свежая.
     *
     * @param list<int> $messageIds
     * @return array<int, string> id сообщения → человекочитаемый маршрут
     */
    private function routesByMessageId(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $sql          = "SELECT target_id, payload
                FROM admin_audit_log
                WHERE action = 'COMMUNITY_ROUTE_LOGGED' AND target_id IN ({$placeholders})
                ORDER BY id DESC";

        $query = $this->db->query($sql, $messageIds);
        if (! $query instanceof BaseResult) {
            return [];
        }

        $out = [];
        foreach ($query->getResultArray() as $row) {
            $targetId = isset($row['target_id']) && is_numeric($row['target_id']) ? (int) $row['target_id'] : null;
            if ($targetId === null || isset($out[$targetId])) {
                continue;
            }
            $payloadRaw = $row['payload'] ?? null;
            $payload    = is_string($payloadRaw) ? json_decode($payloadRaw, true) : null;
            $route      = is_array($payload) && isset($payload['route']) && is_string($payload['route']) ? $payload['route'] : null;
            if ($route !== null && $route !== '') {
                $out[$targetId] = $route;
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
