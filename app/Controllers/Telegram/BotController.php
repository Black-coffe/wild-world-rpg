<?php

namespace App\Controllers\Telegram;

use CodeIgniter\Controller;
use App\Services\Community\CommunityIngestService;
use App\Services\Community\CommunityModerationService;
use App\Services\Telegram\Request;
use App\Services\Telegram\UpdatePipeline;

class BotController extends Controller
{
    private $telegram;

    public function __construct()
    {
        // web-bridge-p1-05 — сборка Telegram живёт в UpdatePipeline::telegram() (её же берёт /play).
        $this->telegram = UpdatePipeline::telegram();
    }

    /**
     * Обрабатывает входящие обновления от Telegram.
     *
     * F0.9 — верификация заголовка X-Telegram-Bot-API-Secret-Token.
     * Если в .env задан telegram.WEBHOOK_SECRET — проверяем что Telegram
     * прислал тот же токен в заголовке. Если значения не совпадают — 403.
     * Без этого любой, кто узнал webhook URL, мог постить fake updates.
     *
     * Если переменная не задана (legacy / dev) — проверка пропускается,
     * чтобы не сломать существующие установки. Чтобы включить:
     *   1) сгенерировать random-токен и положить в .env как
     *      telegram.WEBHOOK_SECRET = '<random_64_chars>'
     *   2) переустановить webhook у бота:
     *      curl -X POST https://api.telegram.org/bot<TOKEN>/setWebhook \
     *           -d "url=https://bot.wildworld.fun/telegram/webhook" \
     *           -d "secret_token=<random_64_chars>"
     */
    public function webhook()
    {
        $expected = getenv('telegram.WEBHOOK_SECRET');
        if (!empty($expected)) {
            $received = $this->request->getHeaderLine('X-Telegram-Bot-API-Secret-Token');
            if (!hash_equals($expected, $received)) {
                log_message('warning', '[Bot.webhook] secret_token mismatch — отклонено');
                return $this->response->setStatusCode(403)->setBody('forbidden');
            }
        }

        $rawBody = $this->request->getBody();
        $update  = is_string($rawBody) ? json_decode($rawBody, true) : null;

        // ADR-181 — дедуп повторной доставки webhook'а по update_id. До этой проверки
        // защиты не было вовсе (встроенный дедуп Longman мёртв: enableMySql() не
        // вызывается). Гейт сразу после разбора JSON и ДО всего остального — community-
        // gate, ADR-168 strip, firehose (ADR-148), E6/E8-хуки, dispatchToTelegram() —
        // повтор не должен наследить нигде из этого. Но ПОСЛЕ секрет-токена, иначе
        // неавторизованный поток просто набивает таблицу дублей. Дубль → тихий 200 OK
        // (тот же мотив, что у ADR-163: любой другой код заставит Telegram ретраить).
        if (is_array($update) && $this->isDuplicateUpdate($update)) {
            return $this->response->setStatusCode(200)->setBody('');
        }

        // community-chat-bot-01 — гейт по типу чата, ДО игровой обработки: групповой/
        // супергрупповой/канальный апдейт не должен двигать firehose (ADR-148), E6/E8-хуки
        // (login-streak, ежедневки, return-digest) и не должен доходить до Longman.
        // Отсутствующий/неизвестный chat.type трактуется как приватный — иначе одна
        // неожиданная форма апдейта обрушила бы игру для всех (см. Contract story-файла).
        if (is_array($update) && $this->isCommunityChat($update)) {
            $this->handleCommunityUpdate($update);

            return $this->response->setStatusCode(200)->setBody('');
        }

        // web-bridge-p1-05 (ADR-189 §1) — всё после гейтов (LastSeen, ADR-168 strip, firehose,
        // E6/E8-хуки, диспетч, finally) — в общем конвейере; порядок шагов прежний. Диспетч —
        // через seam dispatchToTelegram(), его переопределяют тест-спаи контроллера.
        (new UpdatePipeline($this->telegram, fn () => $this->dispatchToTelegram()))
            ->run(is_array($update) ? $update : null, UpdatePipeline::SOURCE_TELEGRAM);
    }

    /**
     * ADR-181 — дедуп повторной доставки webhook'а. Хранилище — таблица
     * `telegram_updates_seen` (PK `update_id`, story exploit-fix-04). Решение
     * принимается через единственный примитив «вставить и отличить дубль» —
     * `ConditionalWriteService::insertUnique()` (story exploit-fix-18): `Refused`
     * → строка уже была, апдейт отбрасывается; `Applied` → первая доставка.
     * Никакого собственного `try/catch` вокруг сырой вставки здесь больше нет —
     * дубль распознаётся по возвращённому `WriteOutcome`, не по коду ошибки
     * драйвера (story exploit-fix-17, ADR-181 §2 против story exploit-fix-09,
     * которая ловила только `DatabaseException` и молчала при `DBDebug=false`,
     * где CI4 возвращает `false` из `query()` вместо исключения).
     *
     * Апдейт без числового `update_id` (битый JSON) дедупу не подлежит и
     * проходит насквозь — error-лог с тем же маркером `[Bot.webhook] dedup:`,
     * чтобы такие апдейты были видны мониторингу, не только тихим `false`.
     *
     * Fail-open при недоступности хранилища (не при найденном дубле — тот
     * всегда отбрасывается): `insertUnique()` бросает `DatabaseException` и при
     * `DBDebug=true` (исключение от драйвера), и при `DBDebug=false` (сам метод
     * превращает `query() === false` + `$db->error()` в брошенное исключение —
     * см. докблок `ConditionalWriteService::insertUnique()`). Любая другая
     * ошибка БД (нет таблицы, нет соединения) не должна останавливать бота для
     * всех игроков — обработка продолжается, error-лог с фиксированным
     * маркером `[Bot.webhook] dedup:` для грепа/мониторинга.
     *
     * @param array<array-key, mixed> $update
     */
    private function isDuplicateUpdate(array $update): bool
    {
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId)) {
            log_message(
                'error',
                '[Bot.webhook] dedup: апдейт без числового update_id — обработка продолжается без дедупа'
            );

            return false;
        }

        try {
            $outcome = (new \App\Services\Db\ConditionalWriteService())->insertUnique(
                'telegram_updates_seen',
                ['update_id' => $updateId, 'created_at' => date('Y-m-d H:i:s')]
            );

            return $outcome === \App\Services\Db\WriteOutcome::Refused;
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            log_message(
                'error',
                '[Bot.webhook] dedup: telegram_updates_seen недоступна — fail-open, update_id '
                    . $updateId . ' обработан без дедупа (' . $e->getMessage() . ')'
            );

            return false;
        }
    }

    public function sendMessage($chatId, $message, $imagePath = null, $keyboard = null)
    {
        if ($imagePath) {
            // Если передан путь к изображению, отправляем фото
            $data = [
                'chat_id' => $chatId,
                'photo'   => Request::encodeFile($imagePath),
                'caption' => $message,
                'parse_mode' => 'Markdown'
            ];
            if ($keyboard) {
                $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
            }
            return \App\Services\Notifications\MediaSender::sendPhotoOrText($data);
        } else {
            // Если изображение не передано, отправляем обычное текстовое сообщение
            $data = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true
            ];
            if ($keyboard) {
                $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
            }
            return Request::sendMessage($data);
        }
    }

    /**
     * Групповые типы чата (community-chat-bot-01, ADR — spec `community-chat-bot`).
     * `channel` трактуется как групповой путь по контракту story: боту в канале
     * тоже нечего делать в игровом диспетчере.
     *
     * @var list<string>
     */
    private const COMMUNITY_CHAT_TYPES = ['group', 'supergroup', 'channel'];

    /**
     * true, если апдейт пришёл из группы/супергруппы/канала. Отсутствующий или
     * нераспознанный `chat.type` — приватный путь по умолчанию (fail-safe: одна
     * неожиданная форма апдейта не должна выключать игру всем).
     *
     * @param array<array-key, mixed> $update
     */
    protected function isCommunityChat(array $update): bool
    {
        $type = $this->extractChatType($update);

        return $type !== null && in_array($type, self::COMMUNITY_CHAT_TYPES, true);
    }

    /**
     * `chat.type` из message / edited_message / callback_query.message / channel_post /
     * edited_channel_post. Последние два — конверты Telegram-канала: `channel` внесён
     * в {@see COMMUNITY_CHAT_TYPES} сознательно, но до story-25 не был покрыт ни одним
     * путём разбора, поэтому фактически всегда падал в fail-safe «приватный».
     *
     * @param array<array-key, mixed> $update
     */
    private function extractChatType(array $update): ?string
    {
        foreach ([
            ['message'],
            ['edited_message'],
            ['callback_query', 'message'],
            ['channel_post'],
            ['edited_channel_post'],
        ] as $path) {
            $type = $this->dig($update, [...$path, 'chat', 'type']);
            if (is_string($type)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Безопасно достаёт вложенное значение из распарсенного апдейта — тело приходит
     * из сети, любой уровень может оказаться не-массивом.
     *
     * @param array<array-key, mixed> $update
     * @param list<string>            $path
     */
    private function dig(array $update, array $path): mixed
    {
        $node = $update;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * story 16 — связка: делегирует групповой апдейт в `CommunityIngestService`
     * (готов и покрыт тестами с story 05, но до этой story не вызывался ниоткуда —
     * BUILT-BUT-DEAD). story 17 — рядом добавлена модерация (`CommunityModerationService`,
     * готова и покрыта тестами с story 10, тоже была BUILT-BUT-DEAD): сначала приём,
     * потом модерация — она считает стаж автора по уже записанным `community_messages`.
     * Оба вызова независимы: исключение из одного сервиса не должно мешать другому и
     * не должно ронять вебхук, поэтому у каждого свой try/catch.
     *
     * @param array<array-key, mixed> $update
     */
    protected function handleCommunityUpdate(array $update): void
    {
        try {
            (new CommunityIngestService())->handle($update);
        } catch (\Throwable $e) {
            log_message('error', 'CommunityIngestService: ' . $e->getMessage());
        }

        try {
            (new CommunityModerationService())->evaluate($update);
        } catch (\Throwable $e) {
            log_message('error', 'CommunityModerationService: ' . $e->getMessage());
        }
    }

    /**
     * Seam для тестов: реальный `$this->telegram->handle()` требует живого
     * Longman-клиента (читает php://input, диспетчит команды/action-handler'ы,
     * которые могут ходить в сеть). Тест переопределяет этот метод спаем и
     * никогда не трогает `$this->telegram` — паттерн из
     * [[feedback_taskhandler_telegram_init_in_tests]].
     */
    protected function dispatchToTelegram(): void
    {
        $this->telegram->handle();
    }

}
