<?php

namespace App\Controllers\Telegram;

use CodeIgniter\Controller;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Telegram;
use App\Services\Community\CommunityIngestService;
use App\Services\Community\CommunityModerationService;
use App\Services\Telegram\Request;

class BotController extends Controller
{
    private $telegram;

    public function __construct()
    {
        $API_KEY = getenv('telegram.API_KEY');
        $BOT_USERNAME = getenv('telegram.BOT_USERNAME');
        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            // Регистрация команд
            $this->telegram->addCommandsPath(__DIR__ . '/Commands');

        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
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

        // community-chat-bot-01 — гейт по типу чата, ДО игровой обработки: групповой/
        // супергрупповой/канальный апдейт не должен двигать firehose (ADR-148), E6/E8-хуки
        // (login-streak, ежедневки, return-digest) и не должен доходить до Longman.
        // Отсутствующий/неизвестный chat.type трактуется как приватный — иначе одна
        // неожиданная форма апдейта обрушила бы игру для всех (см. Contract story-файла).
        if (is_array($update) && $this->isCommunityChat($update)) {
            $this->handleCommunityUpdate($update);

            return $this->response->setStatusCode(200)->setBody('');
        }

        // E6 (ADR-108) Фаза 1 — достаём telegram_id ДО обработки, проставляем last_seen
        // ПОСЛЕ (в finally). Порядок важен: во время handle() код видит ПРЕДЫДУЩЕЕ
        // значение last_seen (основа digest «пока тебя не было», Ф2). Defensive — stamp
        // не должен влиять на обработку апдейта.
        $telegramUserId = is_array($update)
            ? \App\Services\Player\LastSeenService::extractTelegramId($update)
            : null;

        // ADR-168 — снять метку источника с callback_data ДО всего остального: и firehose, и
        // Longman обязаны увидеть уже очищенную строку. Метка (`gather~cmp`) отвечает на вопрос
        // «с какого экрана нажали», на который ADR-148 в одиночку ответить не мог. 🔴 Снятие
        // безусловно (кнопки живут в истории чата вечно), простановка — под killswitch.
        $actionOrigin = null;
        if (is_array($update)) {
            [$update, $actionOrigin, $originStripped] = \App\Services\Logging\ActionOrigin::stripUpdate($update);
            \App\Services\Logging\ActionOrigin::set($actionOrigin);

            // Longman читает php://input САМ, а не наш $update, поэтому очищенную строку ему
            // надо отдать явно. Трогаем ввод ТОЛЬКО когда апдейт реально очищен: непомеченный
            // трафик (весь легаси) идёт прежним путём, без json-раундтрипа.
            // 🔴 Условие — на ФАКТЕ очистки, а не на валидности метки: мусорный хвост тоже
            // срезается, и без перезаписи Longman получил бы строку, которой роутер не знает
            // (мёртвая кнопка + firehose, разошедшийся с реальностью). Поймано Tier-3 14.08.
            if ($originStripped && $this->telegram !== null) {
                $reencoded = json_encode($update, JSON_UNESCAPED_UNICODE);
                if (is_string($reencoded)) {
                    $this->telegram->setCustomInput($reencoded);
                }
            }
        }

        // ADR-148 — firehose ВСЕХ прямых действий игрока. begin() парсит «что/кто/откуда» из
        // сырого апдейта (1 апдейт = 1 действие); commit() в finally пишет ровно одну строку с
        // исходом. 🔴 Defensive — захват не влияет на обработку апдейта; не-player апдейты
        // (channel_post, my_chat_member, …) сами отсеиваются в begin() → commit() no-op.
        if (is_array($update)) {
            \App\Services\Logging\PlayerActionLogger::current()->begin($update);
            // ADR-168 — источник нажатия в отдельную колонку. ПОСЛЕ begin(): он сбрасывает
            // состояние захвата. action_name/raw_input остаются легаси-сравнимыми с историей.
            \App\Services\Logging\PlayerActionLogger::current()->setOrigin($actionOrigin);
            // ADR-148 (расширение) — сигнал ДОСТАВКИ. Без него firehose знал только про
            // роутинг и писал 'ok', пока экраны лавки крафта 2.5 месяца уходили в пустоту.
            // Ставится ПОСЛЕ begin(): счётчики отправок живут внутри текущего захвата.
            \App\Services\Logging\TelegramDeliveryProbe::install();
        }

        // E6 (ADR-108) Ф2 — оффлайн-digest «пока тебя не было». ДО handle() (last_seen
        // ещё ПРЕДЫДУЩИЙ; стамп в finally ПОСЛЕ → следующее взаимодействие свежее =
        // естественный one-shot per возврат). Dormant под killswitch; defensive.
        if ($telegramUserId !== null && is_array($update)) {
            $chatId = \App\Services\Player\LastSeenService::extractChatId($update) ?? $telegramUserId;
            try {
                (new \App\Services\Player\ReturnDigestService())->maybeSendDigest($telegramUserId, $chatId);
            } catch (\Throwable $e) {
                log_message('error', '[Bot.webhook] returnDigest: ' . $e->getMessage());
            }
            // E6 (ADR-108) Ф3 — стрик входа: награда на ПЕРВОМ взаимодействии нового дня.
            // ДО handle() → карточка Перса (если это первое действие) покажет обновлённую серию.
            try {
                (new \App\Services\Player\LoginStreakService())->maybeReward($telegramUserId, $chatId);
            } catch (\Throwable $e) {
                log_message('error', '[Bot.webhook] loginStreak: ' . $e->getMessage());
            }
            // E8 (ADR-109) Ф2 — ежедневные задания: ленивое назначение набора за день при
            // первом контакте + one-shot интро-подсказка новичку (just-in-time). Dormant под
            // killswitch quests.daily.enabled; defensive — фон не должен ломать обработку апдейта.
            try {
                (new \App\Services\Quest\DailyTaskService())->ensureForTelegramUser($telegramUserId, $chatId);
            } catch (\Throwable $e) {
                log_message('error', '[Bot.webhook] dailyTasks: ' . $e->getMessage());
            }
        }

        try {
            $this->dispatchToTelegram();
        } catch (TelegramException $e) {
            // Текущее поведение: логируем и глотаем TelegramException.
            log_message('error', $e->getMessage());
            \App\Services\Logging\PlayerActionLogger::current()->markError($e->getMessage());
        } catch (\Throwable $e) {
            // ADR-148 — прочие исключения (TypeError и т.п.) помечаем 'error' и ПРОБРАСЫВАЕМ
            // дальше (поведение как раньше — наверх к обработчику фреймворка).
            \App\Services\Logging\PlayerActionLogger::current()->markError($e->getMessage());
            throw $e;
        } finally {
            if ($telegramUserId !== null) {
                (new \App\Services\Player\LastSeenService())->stampByTelegramId($telegramUserId);
            }
            // ADR-148 — записать строку firehose (defensive; no-op если begin() не активировал
            // захват, killswitch выключен или уже закоммичено). Выполняется и при исключении.
            \App\Services\Logging\PlayerActionLogger::current()->commit();
            // ADR-168 — холдер источника живёт ровно один апдейт (гигиена: процесс может
            // переиспользоваться, и чужая метка не должна протечь в следующее действие).
            \App\Services\Logging\ActionOrigin::reset();
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
