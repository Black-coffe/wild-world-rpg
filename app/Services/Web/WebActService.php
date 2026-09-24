<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Services\Db\ConditionalWriteService;
use App\Services\Db\WriteOutcome;
use App\Services\Logging\TelegramDeliveryProbe;
use App\Services\Telegram\Request;
use App\Services\Telegram\UpdatePipeline;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Config\WebPlay;
use InvalidArgumentException;
use RuntimeException;

/**
 * web-bridge-p1-07 (ADR-189 §1, §2, §6) — одно действие игрока на `/play`.
 *
 * Порядок: личность из персонажа сессии (никогда из запроса) → проверка намерения (callback —
 * только с кнопки своего экрана/входящих, ADR-189 инв. 3) → дедуп `web_play_intents`
 * (`update_id = −id`) → синтетический апдейт → конвейер вебхука под мостом:
 * Probe install → client() → beginCapture → setClient(BridgeClient) → run(web) →
 * finally setClient(Probe) + endCapture (инв. 4) → экран сохраняется.
 *
 * Отказ в намерении — {@see InvalidArgumentException} (контроллер отвечает 4xx), ничего не
 * диспетчеризуется. Падение конвейера не выходит наружу: транспорт восстановлен, игрок видит
 * общее сообщение.
 *
 * @phpstan-import-type Msg from WebScreenStore
 * @phpstan-import-type State from WebScreenStore
 * @phpstan-import-type Capture from WebScreenStore
 * @phpstan-type Identity array{telegram_user_id:int, telegram_id:int, first_name:string, username:?string, language_code:?string, virtual:bool}
 */
class WebActService
{
    public const KIND_CALLBACK = 'callback';
    public const KIND_TEXT     = 'text';
    public const KIND_COMMAND  = 'command';

    /** Команда первого входа без сохранённого экрана (plan A13, Q7: даёт док и карточку). */
    public const BOOTSTRAP_COMMAND = '/start';

    public const FAILED_ALERT = 'Не получилось выполнить действие. Попробуй ещё раз.';

    /** @var BaseConnection<object, object> */
    private BaseConnection $db;

    private WebScreenStore $store;

    private WebInboxService $inbox;

    private VirtualIdentityService $identities;

    private WebPlay $config;

    /**
     * @param BaseConnection<object, object>|null $db
     * @param UpdatePipeline|null                 $pipeline null — как у вебхука ({@see UpdatePipeline::telegram()})
     */
    public function __construct(
        ?BaseConnection $db = null,
        private ?UpdatePipeline $pipeline = null,
        ?WebPlay $config = null
    ) {
        $this->db         = $db ?? Database::connect();
        $this->config     = $config ?? new WebPlay();
        $this->store      = new WebScreenStore($this->db, $this->config);
        $this->inbox      = new WebInboxService($this->db, $this->config);
        $this->identities = new VirtualIdentityService($this->db);
    }

    /**
     * @param array<string, mixed> $intent `{intent_id, kind, data, message_id?}` из формы
     *
     * @return array{state: State, alert: ?string, unread: int}
     *
     * @throws InvalidArgumentException намерение отвергнуто (4xx, без диспетча)
     */
    public function act(int $accountId, int $characterId, array $intent): array
    {
        if (! $this->owns($accountId, $characterId)) {
            throw new InvalidArgumentException('character is not owned by the account');
        }
        $identity = $this->identities->identityForCharacter($characterId);
        if ($identity === null) {
            throw new InvalidArgumentException('character has no identity');
        }

        $intentId = $intent['intent_id'] ?? null;
        if (! is_string($intentId) || $intentId === '' || strlen($intentId) > 64) {
            throw new InvalidArgumentException('bad intent_id');
        }
        $build = $this->validate($characterId, $identity, $intent);

        $rowId = $this->claimIntent($accountId, $intentId);
        if ($rowId === null) {
            // Повтор того же намерения — текущее состояние, без второго диспетча.
            return $this->result($characterId, null);
        }

        $update  = $build(-$rowId);
        [$capture, $failed] = $this->runBridged($update, $identity['telegram_id'], $characterId);
        $this->store->applyCapture($characterId, $capture);

        return $this->result($characterId, $capture['alert'] ?? ($failed ? self::FAILED_ALERT : null));
    }

    /**
     * Сохранённое состояние без диспетча.
     *
     * @return array{state: State, alert: ?string, unread: int}
     */
    public function current(int $characterId): array
    {
        return $this->result($characterId, null);
    }

    /**
     * Первый вход без сохранённого экрана: одна команда {@see BOOTSTRAP_COMMAND} (ключ намерения
     * на персонажа — второй раз не уйдёт). Экран уже есть — просто состояние.
     *
     * @return array{state: State, alert: ?string, unread: int}
     */
    public function bootstrap(int $accountId, int $characterId): array
    {
        $current = $this->current($characterId);
        if ($current['state']['screen'] !== [] || $current['state']['history'] !== []) {
            return $current;
        }

        return $this->act($accountId, $characterId, [
            'intent_id' => 'bootstrap-' . $characterId,
            'kind'      => self::KIND_COMMAND,
            'data'      => self::BOOTSTRAP_COMMAND,
        ]);
    }

    /**
     * Конвейер под мостом (ADR-189 §2): транспорт и захват восстанавливаются в `finally`, что бы
     * ни случилось внутри.
     *
     * @param array<string, mixed> $update
     *
     * @return array{0: Capture, 1: bool} захват и «упало»
     */
    private function runBridged(array $update, int $actorChat, int $characterId): array
    {
        // Telegram строится ДО probe: его конструктор ставит Longman'у клиент по умолчанию, если своего нет.
        $pipeline = $this->pipeline ?? new UpdatePipeline(UpdatePipeline::telegram());

        TelegramDeliveryProbe::install();
        $client = TelegramDeliveryProbe::client();
        if ($client === null) {
            log_message('error', '[WebActService] delivery client unavailable — act not dispatched');

            return [WebDelivery::endCapture(), true];
        }

        $failed = false;
        WebDelivery::beginCapture($actorChat, $characterId);
        try {
            Request::setClient(new BridgeClient($client, $actorChat));
            $failed = ! $this->runPipeline($pipeline, $update);
        } catch (\Throwable $e) {
            log_message('error', '[WebActService] act failed: ' . $e->getMessage());
            $failed = true;
        } finally {
            Request::setClient($client);
            $capture = WebDelivery::endCapture();
            DeliveryContext::reset();
        }

        return [$capture, $failed];
    }

    /**
     * Шов для тестов (R4: восстановление при исключении).
     *
     * @param array<string, mixed> $update
     */
    protected function runPipeline(UpdatePipeline $pipeline, array $update): bool
    {
        return $pipeline->run($update, UpdatePipeline::SOURCE_WEB);
    }

    /**
     * Проверка намерения; возвращает построитель апдейта по `update_id`.
     *
     * @param Identity             $identity
     * @param array<string, mixed> $intent
     *
     * @return callable(int): array<string, mixed>
     */
    private function validate(int $characterId, array $identity, array $intent): callable
    {
        $kind = $intent['kind'] ?? null;
        $data = $intent['data'] ?? null;
        if (! is_string($data)) {
            throw new InvalidArgumentException('bad data');
        }
        $factory   = new SyntheticUpdateFactory();
        $messageId = self::intOrNull($intent['message_id'] ?? null);

        if ($kind === self::KIND_CALLBACK) {
            if (! $this->store->callbackAllowed($characterId, $data)) {
                throw new InvalidArgumentException('callback not on the character screens');
            }
            $message = $messageId === null ? null : $this->findMessage($characterId, $messageId);
            if ($message === null) {
                throw new InvalidArgumentException('unknown message_id');
            }

            return static fn (int $updateId): array => $factory->callback($identity, $message, $data, $updateId);
        }

        if ($kind !== self::KIND_TEXT && $kind !== self::KIND_COMMAND) {
            throw new InvalidArgumentException('bad kind');
        }
        if (trim($data) === '' || mb_strlen($data) > $this->config->textMaxLength) {
            throw new InvalidArgumentException('bad text length');
        }
        if ($kind === self::KIND_COMMAND && ! str_starts_with($data, '/')) {
            throw new InvalidArgumentException('command must start with /');
        }
        $replyTo = $messageId === null ? null : $this->findMessage($characterId, $messageId);

        return static fn (int $updateId): array => $factory->message($identity, $data, $replyTo, $updateId);
    }

    /** @return Msg|null */
    private function findMessage(int $characterId, int $messageId): ?array
    {
        return $this->store->findMessage($characterId, $messageId)
            ?? $this->inbox->findMessage($characterId, $messageId);
    }

    /** Id строки намерения; null — такое намерение уже было (дубль). */
    private function claimIntent(int $accountId, string $intentId): ?int
    {
        $outcome = (new ConditionalWriteService($this->db))->insertUnique('web_play_intents', [
            'account_id' => $accountId,
            'intent_id'  => $intentId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($outcome !== WriteOutcome::Applied) {
            return null;
        }
        $id = $this->db->insertID();
        if (! is_numeric($id) || (int) $id <= 0) {
            throw new RuntimeException('WebActService: intent insert returned no id');
        }

        return (int) $id;
    }

    private function owns(int $accountId, int $characterId): bool
    {
        $res = $this->db->query('SELECT 1 AS ok FROM characters WHERE id = ? AND account_id = ? LIMIT 1', [$characterId, $accountId]);

        return $res instanceof ResultInterface && is_array($res->getRowArray());
    }

    /** @return array{state: State, alert: ?string, unread: int} */
    private function result(int $characterId, ?string $alert): array
    {
        return [
            'state'  => $this->store->state($characterId),
            'alert'  => $alert,
            'unread' => $this->inbox->unreadCount($characterId),
        ];
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }

        return is_string($v) && preg_match('/^\d{1,19}$/', $v) === 1 ? (int) $v : null;
    }
}
