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
use App\Services\Community\Verdict;
use App\Services\GameSettings\GameSettingsService;
use App\TaskHandlers\BaseTaskHandler;
use CodeIgniter\Database\BaseResult;
use Config\Database;
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
 * `status='new' AND (is_question=1 OR addressed_to_bot=1)` (story 57, дефект 1 —
 * прямое обращение без вопросительной формы иначе матчера не достигает), по
 * порядку `sent_at`, и для каждой ещё необработанной в этом тике строки спрашивает матчера:
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
 * Строка получает целевой статус (`answered`/`escalated`) ДО вызова `sendAnswer()`
 * (story 23, ремонтная волна 8, дефект 1) — {@see claimGroup()} условным апдейтом,
 * атомарность проверяется `affectedRows`, не read-then-write: сбой записи ПОСЛЕ
 * успешной сети или ложноотрицательный ответ транспорта (исключение уже после
 * фактической доставки) не могут повторно опубликовать ответ, потому что писать
 * уже нечего — статус закоммичен до сетевого вызова. Если `sendAnswer()` возвращает
 * `false`, {@see resolveFailure()} читает причину из `admin_audit_log` (источник
 * правды `CommunityChatSender`, вне `## Files` этой story): гейт-отказ ДО сети
 * (`*_REJECTED`) откатывает статус на `new` для причин, которые сами рассосутся
 * (потолок, кулдаун, килсвитч, тема), и оставляет `escalated` для причин, которые
 * содержимым не исправить (`text_too_long`/`unbalanced_markdown`/`canon_name_violation`,
 * дефект 2 — иначе вечный ретрай каждую минуту плодит строку в журнале, по которому
 * считается сам потолок); `telegram_not_ok:*` (сеть дошла, Telegram явно отказал) тоже
 * откатывает — Telegram подтвердил недоставку; `exception:*` (сеть неопределённа) статус
 * не трогает вовсе — риск дубля хуже риска пропуска.
 *
 * Вердикт гварда `manual`/`deny` → строка (и её склейка) помечается `escalated`;
 * попытка чиркнуть 🤔 не обязана быть успешной — молчание `CommunityChatSender`
 * (например `community.autoreply.enabled=false`) не мешает эскалации дойти до
 * владельца через `/admin/community`, куда уже смотрит статус. `Verdict::route`
 * (дефект 3) при этом не теряется — {@see logRoute()} пишет его в `admin_audit_log`
 * (`COMMUNITY_ROUTE_LOGGED`), где владелец увидит маршрут отказа рядом со строкой
 * очереди. С story 55 тот же канонический текст маршрута (не свободный текст, не
 * повторный проход через гвард) уходит и самому игроку реплаем — {@see sendGuardRouteText()}
 * зовёт тот же `CommunityChatSender::sendAnswer()`, что обычный ответ, поэтому те же
 * гейты (потолок в час, кулдаун автора, килсвитчи) применяются к отказу так же, как к
 * любой другой отправке: молчаливая реакция без единого слова читалась игроком как
 * «бот сломался», хотя объяснение уже было написано и лежало рядом в журнале.
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
    /**
     * Дефект 2 (story 23) — причины гейта `CommunityChatSender::checkGates()`, которые
     * повторной попыткой не исправить (содержимое ответа, не состояние мира): переводят
     * строку в терминальный `'escalated'`, а не бесконечный ретрай каждую минуту.
     *
     * `silent_topic` (story 31, дефект 2) присоединён сюда же: `community.autoreply.silent_topics` —
     * настройка, которая сама не рассосётся так же, как потолок/кулдаун/килсвитч. Без этого
     * каждый тик писал новый `COMMUNITY_ANSWER_REJECTED` на постоянной конфигурации — тот же
     * дефект 2 из story 23, воспроизведённый на теме, а не на тексте ответа.
     *
     * Story 40, дефект 3 — терминальность у `silent_topic` та же, но целевой статус другой:
     * это не отказ гварда (владелец ничего чинить не должен), а намеренно заглушённый топик —
     * см. разбор целевого статуса в {@see resolveFailure()}.
     */
    private const TERMINAL_GATE_REASONS = ['text_too_long', 'unbalanced_markdown', 'canon_name_violation', 'silent_topic'];

    private CommunityMessageModel $messageModel;
    private CommunityAnswerModel $answerModel;
    private CommunityAnswerMatcher $matcher;
    private CommunityGuard $guard;
    private CommunityChatSender $sender;
    private AdminAuditLogModel $auditModel;
    private ?DateTimeImmutable $fixedNow;

    /** @var callable(string, mixed): mixed */
    private $settingsGetter;

    /** @var callable(): void */
    private $telegramInitializer;

    /**
     * @param (callable(string, mixed): mixed)|null $settingsGetter сеам для тестов
     *        (паттерн `CommunityChatSender`/`CommunityAnswerMatcher`) — `GameSettingsService::get()` по умолчанию.
     * @param DateTimeImmutable|null $now сеам для тестов выдержки полосы B —
     *        детерминированное «сейчас»; null — берётся реальное на каждый `handle()`.
     * @param (callable(): void)|null $telegramInitializer story 52 — сеам инициализации Telegram-моста
     *        перед реальной отправкой; по умолчанию `BaseTaskHandler::telegram()` (тот же ленивый
     *        lazy-getter, которым уже пользуются соседние task-handler'ы). `CommunityChatSender` шлёт
     *        через `Request::send()` напрямую, минуя `safeSendMessage()`, поэтому этот тик обязан
     *        вызвать инициализацию сам — иначе `Request::$telegram` остаётся `null` (дефект story 52).
     *        Тесты подменяют на no-op/счётчик, чтобы не трогать реальный Telegram-объект
     *        (`feedback_taskhandler_telegram_init_in_tests`).
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
        ?callable $telegramInitializer = null,
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
        $this->telegramInitializer = $telegramInitializer ?? function (): void {
            $this->telegram();
        };
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

        // Story 76: условие вынесено в CommunityMessageModel::whereAddressedOrQuestion() —
        // тот же метод зовёт очередь владельца (CommunityController::openQuestionsBuilder()),
        // одно место вместо двух совпадающих текстов.
        $rows = $this->messageModel
            ->where('status', 'new')
            ->whereAddressedOrQuestion()
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

        // Story 72: $isApprovalContext=false явно (совпадает с default) — путь
        // отправки, `provenance_mode=deny` здесь никогда не денит (ADR-178).
        $verdict = $this->guard->verdict($text, $question, $requiresSetting, false);

        if (! $verdict->isAllow()) {
            // Story 55 — молчание вместо маршрута читалось игроком как «бот сломался»:
            // текст уходит реплаем ТОЛЬКО если склейка реально ушла в escalated (иначе
            // это тот же дефект, что story 46 закрывала — «отправили, но не записали»,
            // тут наоборот «записали бы дважды на повторной попытке следующего тика»).
            $escalated = $this->escalateGuardDenial($decision->coveredMessageIds, $verdict);
            if ($escalated) {
                $this->sendGuardRouteText($selfId, $verdict);
            }
            $this->reactOnce($selfId, '🤔');
            return;
        }

        // Полоса A без совпадения банка (Decision::escalated) — честное «не знаю»
        // всё равно ждёт человека, а не закрыт банк-ответом.
        $targetStatus = $decision->escalated ? 'escalated' : 'answered';

        // Дефект 1 — строка перехватывается ДО вызова Telegram условным апдейтом:
        // после этой точки статус уже записан, писать «после отправки» больше нечего.
        if (! $this->claimGroup($decision->coveredMessageIds, ['status' => $targetStatus, 'answered_by_id' => $decision->answerId])) {
            return; // всё-или-ничего (story 31, дефект 1) — ни одна строка не осталась перехваченной
        }

        // Story 31, дефект 3 — водораздел ДО сетевого вызова: `resolveFailure()` свяжет
        // причину отказа только с аудит-строкой, записанной СТРОГО после этой отметки,
        // а не с последней строкой журнала (которая могла принадлежать прошлой попытке).
        //
        // Story 51 — водораздел идёт по auto-increment `id` `admin_audit_log`, не по
        // часам БД: `created_at` и отметка попытки делят одну и ту же секундную
        // гранулярность, и на быстрой машине (CI) устаревшая строка прошлой попытки и
        // текущая отметка легко попадают в одну секунду — `created_at >= $since`
        // тогда неотличимо принимает чужую строку за свою. `id` строго монотонен вне
        // зависимости от того, сколько записей легло в одну и ту же секунду.
        $attemptWatermarkId = $this->auditWatermarkId();

        // Story 52 — `CommunityChatSender::sendAnswer()` зовёт `Request::send()` напрямую,
        // минуя `BaseTaskHandler::safeSendMessage()`; без этого вызова `Request::$telegram`
        // в реальном cron-процессе остаётся `null` (тик никогда его не трогал).
        $this->ensureTelegramInitialized();

        if ($this->sender->sendAnswer($selfId, $text)) {
            return; // статус уже закоммичен до сети — дописывать нечего
        }

        $this->resolveFailure($selfId, $decision->coveredMessageIds, $targetStatus, $attemptWatermarkId);
    }

    /**
     * @param list<int> $ids
     * @param array<string, bool|float|int|string|null> $fields
     * @param string|null $onlyIfStatus если задан — условный апдейт `WHERE status=$onlyIfStatus`
     *        (story 31, дефект 4): откат назад (в частности `resolveFailure()`) не должен
     *        затирать строку, которую между `claimGroup()` и этим вызовом уже сдвинул кто-то
     *        другой (владелец руками из `/admin/community`, `community:cleanup`) — тот же
     *        lost-update, что и дефект 1, но со стороны отката, а не заявки.
     */
    private function markGroup(array $ids, array $fields, ?string $onlyIfStatus = null): void
    {
        if ($ids === []) {
            return;
        }

        if ($onlyIfStatus === null) {
            foreach ($ids as $id) {
                $this->messageModel->update($id, $fields);
            }

            return;
        }

        $db = Database::connect();
        $db->table('community_messages')
            ->whereIn('id', $ids)
            ->where('status', $onlyIfStatus)
            ->update($fields);
    }

    /**
     * Условный апдейт `WHERE status='new'` для всей склейки дублей ДО сетевого вызова
     * (дефект 1, контракт story) — атомарность через число затронутых строк, не через
     * предварительное чтение. Целевой статус всегда отличен от `'new'`, поэтому
     * `affectedRows()` совпадает с числом реально перехваченных строк.
     *
     * Story 31, дефект 1 — единственный `UPDATE ... WHERE id IN (...) AND status='new'`
     * задевает КАЖДУЮ подходящую строку независимо от остальных: при частичном совпадении
     * (часть склейки уже не `'new'`) те строки, что ещё были `'new'`, уже переписаны в БД
     * ДО проверки `affectedRows()`. Транзакция делает заявку всё-или-ничего — при
     * несовпадении числа затронутых строк откатывает сам апдейт, а не оставляет частично
     * перехваченную склейку висеть с чужим статусом навсегда.
     *
     * Story 40, дефект 1 — возврат `transBegin()` больше не игнорируется: `false` означает,
     * что транзакция не стартовала (или уже открыта снаружи — сегодня ни `Worker.php`, ни
     * `BaseTaskHandler` её не открывают, но метод не должен полагаться на это молча). Без
     * реальной транзакции последующий `transRollback()` был бы no-op, и частичный перехват
     * склейки дублей закоммитился бы независимо от `affectedRows()` — ровно дефект, который
     * story 31 закрывала. Гарантия отказывает ЗАКРЫТО: перехват не выполняется вовсе.
     *
     * @param list<int> $ids
     * @param array<string, bool|float|int|string|null> $fields
     */
    private function claimGroup(array $ids, array $fields): bool
    {
        if ($ids === []) {
            return false;
        }

        $db = Database::connect();
        if ($db->transBegin() === false) {
            return false;
        }

        $db->table('community_messages')
            ->whereIn('id', $ids)
            ->where('status', 'new')
            ->update($fields);

        $claimed = $db->affectedRows() === count($ids);

        if ($claimed) {
            $db->transCommit();
        } else {
            $db->transRollback();
        }

        return $claimed;
    }

    /**
     * `sendAnswer()===false` после {@see claimGroup()} уже забрал статус — читает
     * причину из аудит-строки `CommunityChatSender` для этого сообщения (источник
     * правды, вне `## Files` story), чтобы решить: отпустить обратно к `'new'`
     * (причина сама рассосётся) или оставить перехваченный статус (терминально
     * или неопределённо доставлено).
     *
     * Story 31, дефект 3 — `$attemptWatermarkId` (auto-increment `admin_audit_log.id`,
     * снятый ДО вызова `sendAnswer()`) ограничивает поиск аудит-строкой ЭТОЙ попытки, а
     * не «последней в журнале»: `CommunityChatSender::audit()` глушит `Throwable`, и при
     * проглоченной вставке прошлой попытки читалась бы её причина — «исключение после
     * реальной доставки» могло превратиться в ретрай по чужому старому `_REJECTED`.
     *
     * Story 51 — водораздел по `id`, не по `created_at`: секундная гранулярность часов
     * БД делала прошлый водораздел неотличим от текущей попытки при совпадении секунды
     * (см. {@see auditWatermarkId()}).
     *
     * Story 31, дефект 4 — оба отката в `'new'`/`'escalated'` идут через
     * {@see markGroup()} с `$claimedStatus` как условием: если статус, выставленный
     * этой же попыткой в {@see claimGroup()}, уже сдвинут кем-то другим (владелец
     * руками, `community:cleanup`), откат его не затирает.
     *
     * @param list<int> $coveredIds
     */
    private function resolveFailure(int $selfId, array $coveredIds, string $claimedStatus, int $attemptWatermarkId): void
    {
        $audit = $this->lastAutoAnswerAudit($selfId, $attemptWatermarkId);
        if ($audit === null) {
            return; // причина не читается — консервативно не трогаем перехваченный статус
        }
        [$action, $reason] = $audit;

        if (str_ends_with($action, '_REJECTED')) {
            // Гейт отказал ДО сети (детерминированно, `CommunityChatSender::checkGates()`).
            if (in_array($reason, self::TERMINAL_GATE_REASONS, true)) {
                // Дефект 2 — содержимым/конфигурацией это не исправить, ретрай каждую
                // минуту только плодит `*_REJECTED` в журнале, по которому считается сам потолок.
                //
                // Story 40, дефект 3 — `silent_topic` внутри терминальных причин не идёт в
                // `escalated`: топик заглушили НАМЕРЕННО, это не ошибка, которую владелец
                // должен разобрать руками в `/admin/community` (`whereIn('status', ['new',
                // 'escalated'])` — {@see \App\Controllers\Admin\CommunityController}). Целевой
                // статус — тот же терминальный `'ignored'`, которым уже закрывается
                // `Decision::isSilent()` выше в {@see handle()}: намеренная тишина, а не
                // отказ, ждущий человека.
                $terminalStatus = $reason === 'silent_topic' ? 'ignored' : 'escalated';
                $this->markGroup($coveredIds, ['status' => $terminalStatus], $claimedStatus);
            } else {
                // Потолок/кулдаун/килсвитч сами рассосутся — вернём на следующий тик.
                $this->markGroup($coveredIds, ['status' => 'new'], $claimedStatus);
            }

            return;
        }

        if (str_starts_with($reason, 'telegram_not_ok:')) {
            // Сеть дошла, Telegram явно подтвердил недоставку — безопасно повторить.
            $this->markGroup($coveredIds, ['status' => 'new'], $claimedStatus);

            return;
        }

        // 'exception: …' — ложноотрицательный ответ транспорта, сообщение могло реально
        // уйти. Статус уже перехвачен claimGroup() ДО отправки — оставляем как есть,
        // повторная публикация опаснее пропущенного ответа (дефект 1, контракт story).
    }

    /**
     * @return array{0: string, 1: string}|null [action, reason] аудит-строки этой попытки
     *         (`id > $sinceId` — story 51, auto-increment вместо часов БД, дефект 3/5)
     */
    private function lastAutoAnswerAudit(int $messageRowId, int $sinceId): ?array
    {
        $row = $this->auditModel
            ->where('target_id', $messageRowId)
            ->like('action', 'COMMUNITY_ANSWER_', 'after')
            ->where('id >', $sinceId)
            ->orderBy('id', 'DESC')
            ->first();

        if (! is_array($row)) {
            return null;
        }

        $action = $row['action'] ?? null;
        if (! is_string($action)) {
            return null;
        }

        $reason  = '';
        $payload = $row['payload'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && is_string($decoded['reason'] ?? null)) {
                $reason = $decoded['reason'];
            }
        }

        return [$action, $reason];
    }

    /**
     * Story 46, дефект 1 — маршрут гварда пишется на КАЖДУЮ строку склейки дублей, не
     * только на строку-представителя: {@see \App\Controllers\Admin\CommunityController::guardDeniedCount()}
     * опознаёт отказ гварда по `EXISTS(COMMUNITY_ROUTE_LOGGED)` для КОНКРЕТНОЙ строки, а
     * `markGroup()` переводит в `escalated` ВСЮ склейку. Раньше `logRoute()` писал только на
     * `$selfId` — остальные строки склейки получали статус, но не аудит-запись, и выпадали
     * и из числителя, и из знаменателя метрики (тот же дефект, что story 39 закрывала на
     * «упёрлись в лимит», переехавший на дубликаты одного вопроса).
     *
     * Story 46, дефект 2 — статус склейки и все аудит-записи маршрута коммитятся ОДНОЙ
     * транзакцией: `logRoute()` раньше глушила `Throwable` на вставке и молча продолжала —
     * при сбое (например, обрыв соединения) строка навсегда оседала `escalated` БЕЗ
     * аудит-строки, по которой её опознаёт метрика, то есть best-effort лог тихо вычитал
     * строку из доли отказов. Теперь сбой вставки откатывает и статус — склейка остаётся
     * `'new'` и получает второй шанс на следующем тике (гвард снова денит, вставка снова
     * попытается), вместо того чтобы навсегда потерять признак, по которому её считает
     * читающая сторона.
     *
     * Story 55 — возвращает `bool` (раньше `void`): {@see resolveAndSend()} шлёт игроку
     * текст маршрута ТОЛЬКО когда эскалация реально закоммитилась. Если транзакция
     * откатилась (сбой аудит-вставки), строка остаётся `'new'` и получит второй шанс
     * на следующем тике — отправка текста ПРЯМО СЕЙЧАС при этом дала бы игроку два
     * одинаковых сообщения (это и следующий тик), когда эскалация всё-таки пройдёт.
     *
     * Story 57, дефект 3 — раньше апдейт был безусловным `whereIn('id', …)->update()`,
     * без `WHERE status=…`: правка владельца между чтением тика ({@see handle()}) и этим
     * вызовом (`/admin/community` руками или `community:cleanup`) молча терялась — чужой
     * новый статус переписывался в `'escalated'` без следа. Теперь апдейт условный
     * `WHERE status='new'` (та же дисциплина, что {@see markGroup()} с `$onlyIfStatus`
     * и {@see claimGroup()} рядом), и `affectedRows()` сверяется с числом строк склейки:
     * при частичном или полном несовпадении транзакция откатывается, эскалация не
     * применяется, а факт — сколько строк ожидалось и сколько реально перехвачено —
     * пишется в лог, чтобы не потеряться молча.
     *
     * @param list<int> $coveredIds
     */
    private function escalateGuardDenial(array $coveredIds, Verdict $verdict): bool
    {
        if ($coveredIds === []) {
            return false;
        }

        $db = Database::connect();
        if ($db->transBegin() === false) {
            return false;
        }

        try {
            $db->table('community_messages')
                ->whereIn('id', $coveredIds)
                ->where('status', 'new')
                ->update(['status' => 'escalated']);

            if ($db->affectedRows() !== count($coveredIds)) {
                $db->transRollback();
                log_message(
                    'error',
                    '[CommunityAutoReplyHandler] guard denial escalation skipped: expected '
                        . count($coveredIds) . ' row(s), matched ' . $db->affectedRows()
                        . ' — status changed between tick read and escalation, ids=' . implode(',', $coveredIds)
                );

                return false;
            }

            foreach ($coveredIds as $coveredId) {
                $this->logRoute($coveredId, $verdict);
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[CommunityAutoReplyHandler] guard denial escalation failed: ' . $e->getMessage());

            return false;
        }

        $db->transCommit();

        return true;
    }

    /**
     * Story 55 — маршрут отказа теперь реально уходит игроку реплаем, а не только в
     * `admin_audit_log` для владельца: молчаливая реакция 🤔 без единого слова читалась
     * игроком как «бот сломался, не заметил или обиделся» (дефект в шапке story), и
     * вопрос задавался снова. Текст канонический (`Verdict::route`) — гвард уже сказал
     * «нельзя своими словами», повторно звать `guard->verdict()` на него не нужно
     * (контракт story: «текст отказа не проходит повторно через гвард по кругу»).
     *
     * Отправка идёт через {@see CommunityChatSender::sendAnswer()} — тот же метод, что
     * шлёт банк-ответ, а не отдельный необгейченный вызов транспорта: он прогоняет текст
     * через ТЕ ЖЕ ограничители, что обычный ответ (потолок в час, кулдаун автора,
     * килсвитчи, длина/парность `*`) — анти-спам контракта story не требует второго
     * источника правды про лимиты рядом с уже существующим в `CommunityChatSender`.
     * Если гейт откажет — `sendGuardRoute()` вернёт `false`, а `CommunityChatSender::audit()`
     * уже запишет `COMMUNITY_ROUTE_REJECTED` с причиной: прежнее поведение (только
     * реакция) сохраняется, и отказ ограничителя виден в журнале — это и есть контракт
     * «факт виден в журнале», выполненный существующим гейтом, а не новым кодом здесь.
     *
     * Story 57, дефект 2: раньше здесь звался `sendAnswer()`, и отказ гварда писался в
     * журнал как `COMMUNITY_ANSWER_SENT` — то есть считался ответом бота в метрике
     * `guardTotal = botAnswers + guardDenied` дважды. `sendGuardRoute()` (story 58)
     * пишет отдельное действие `COMMUNITY_ROUTE_SENT` — имя метода и имя действия не
     * переименовывать, контракт story 58/59.
     *
     * `Verdict::manual()` может нести `route=null` (например `bug_report_topic` — там
     * сказать нечего, только квитанция-реакция); `Verdict::deny()` конструктором
     * гарантирует непустой `route`, но пустая строка после `trim()` тоже трактуется
     * как «нечего слать».
     */
    private function sendGuardRouteText(int $selfId, Verdict $verdict): void
    {
        $route = $verdict->route;
        if ($route === null || trim($route) === '') {
            return;
        }

        $this->ensureTelegramInitialized();
        $this->sender->sendGuardRoute($selfId, $route);
    }

    /**
     * `Verdict::route` не должен быть мёртвым контентом ни для владельца, ни (с story
     * 55) для самого игрока: маршрут сохраняется в тот же журнал, что уже питает
     * потолок/кулдаун — владелец видит его рядом со строкой очереди `/admin/community`,
     * а {@see sendGuardRouteText()} шлёт тот же текст игроку реплаем (тем же гейтом
     * `CommunityChatSender::sendGuardRoute()`, что и маршрут отказа).
     *
     * Больше не глушит `Throwable` сама — {@see escalateGuardDenial()} держит вставку в
     * общей транзакции со статусом склейки (story 46, дефект 2) и откатывает обе стороны
     * разом при сбое.
     */
    private function logRoute(int $messageRowId, Verdict $verdict): void
    {
        if ($verdict->route === null || trim($verdict->route) === '') {
            return;
        }

        $this->auditModel->insert([
            'admin_user_id' => 0,
            'action'        => 'COMMUNITY_ROUTE_LOGGED',
            'target_type'   => 'community_message',
            'target_id'     => $messageRowId,
            'payload'       => json_encode(
                ['reason' => $verdict->reason, 'route' => $verdict->route],
                JSON_UNESCAPED_UNICODE
            ),
            'ip_address' => null,
            'user_agent' => null,
            // Story 31, дефект 5 — «одни часы»: соседний `audit()` в `CommunityChatSender`
            // (story 27) пишет `created_at` часами БД в ту же `admin_audit_log`, а не
            // PHP `date()` — расхождение таймзон приложения и БД иначе ломает сравнение
            // времени между записями одной таблицы.
            'created_at' => $this->dbNow(),
        ]);
    }

    private function reactOnce(int $messageRowId, string $emoji): void
    {
        if ($messageRowId <= 0 || $this->alreadyReacted($messageRowId)) {
            return;
        }
        // Story 52 — та же инициализация, что перед `sendAnswer()`: реакция тоже уходит
        // через `Request::send()` (`CommunityChatSender::react()`).
        $this->ensureTelegramInitialized();
        $this->sender->react($messageRowId, $emoji);
    }

    /**
     * Story 52 — лениво инициализирует Telegram-мост (`Request::initialize()` через
     * `BaseTaskHandler::telegram()`) ровно перед реальной отправкой. Вызывается только
     * с двух точек этого класса, где `CommunityChatSender` реально идёт в сеть
     * ({@see resolveAndSend()}, {@see reactOnce()}) — тик, у которого нет ни одной
     * строки на отправку, эту инициализацию не оплачивает (контракт story).
     */
    private function ensureTelegramInitialized(): void
    {
        ($this->telegramInitializer)();
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

    /**
     * Часы БД (`NOW()`), не PHP `date()` — story 31, дефекты 3 и 5. Служит меткой записи
     * (`logRoute()`), тем же приёмом, что `CommunityChatSender::dbNow()` (story 27,
     * memory `feedback_db_clock_seed_not_php_in_time_window_tests`).
     *
     * Story 51 — границей поиска «своей» аудит-строки (`resolveFailure()` через
     * {@see lastAutoAnswerAudit()}) больше не служит: см. {@see auditWatermarkId()}.
     */
    private function dbNow(): string
    {
        $db    = Database::connect();
        $query = $db->query('SELECT NOW() AS n');
        if ($query instanceof BaseResult) {
            $row = $query->getRowArray();
            if (isset($row['n']) && is_string($row['n'])) {
                return $row['n'];
            }
        }

        // Отказ БД тут уже означает, что и запись/чтение аудита следом провалятся —
        // запасное значение только чтобы не подменять единственный источник времени.
        return date('Y-m-d H:i:s');
    }

    /**
     * Водораздел «эта попытка/прошлая» для {@see lastAutoAnswerAudit()} — story 51.
     * Возвращает максимальный `id` (auto-increment PK) из `admin_audit_log` на момент
     * вызова, снятый ДО `sendAnswer()`: строка, принадлежащая ЭТОЙ попытке, обязана
     * получить `id` строго больше этого значения — гарантия, которую даёт сам механизм
     * auto-increment вне зависимости от того, сколько записей легло в одну и ту же
     * секунду часов БД (в отличие от прежнего `created_at >= $since`, story 31, дефект
     * 3/5, который на быстрой машине не отличал текущую попытку от предыдущей).
     *
     * Пустая таблица (гипотетически, до первой когда-либо записанной аудит-строки) даёт
     * `0` — `id > 0` совпадает с «любая существующая строка», что и требуется до первой
     * реальной вставки.
     */
    private function auditWatermarkId(): int
    {
        $db    = Database::connect();
        $query = $db->query('SELECT MAX(id) AS m FROM admin_audit_log');
        if ($query instanceof BaseResult) {
            $row = $query->getRowArray();
            if (isset($row['m']) && is_numeric($row['m'])) {
                return (int) $row['m'];
            }
        }

        return 0;
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
