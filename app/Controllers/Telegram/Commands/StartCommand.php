<?php

namespace App\Controllers\Telegram\Commands;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\Player\CharacterService;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

// не ReplyKeyboardMarkup, а именно Keyboard

// Подключаем ваш CharacterService

class StartCommand extends UserCommand
{
    protected $name        = 'start';
    protected $description = 'Start command';
    protected $usage       = '/start';
    protected $version     = '1.2.0';

    public function execute(): ServerResponse
    {
        $message    = $this->getMessage();
        $chatId     = $message->getChat()->getId();
        $from       = $message->getFrom();
        $telegramId = $from->getId();
        $username   = $from->getUsername();
        $firstName  = $from->getFirstName();
        $lastName   = $from->getLastName();
        // Атрибуция интейка: payload deep-link `/start <src_*>` (без команды через getText(true)).
        // First-touch — пишется один раз при создании пользователя ниже.
        $acquisitionSource = self::extractAcquisitionSource($message->getText(true));

        // S8 (ADR-146): реферальная петля «позови выжившего». При killswitch ON и payload вида
        // `ref_<telegram_users.id>` это приглашение (не маркетинг `src_*`): помечаем источник
        // 'referral', а ребро пишем ниже после создания персонажа (first-touch). При OFF парсинга
        // нет → ref_<id> падает в обычную acquisition-атрибуцию как сегодня (byte-identical).
        $referralService = new \App\Services\Player\ReferralService();
        $referrerUserId  = $referralService->enabled()
            ? $referralService->parseReferrerId($message->getText(true))
            : null;
        if ($referrerUserId !== null) {
            $acquisitionSource = 'referral';
        }

        // 1. Закрепляем постоянную клавиатуру.
        // ADR-103 Часть A: единый источник истины — BotMenuService::mainReplyKeyboard()
        // (раньше определение жило только здесь).
        $replyKeyboard = \App\Services\Telegram\BotMenuService::mainReplyKeyboard();

        // 2. Проверяем/создаём пользователя
        // Язык клиента (аудит «молчунов» 2026-08-14). Игра существует только на русском, а
        // доля тех, кто физически не может прочитать первый экран, до сих пор была неизмерима:
        // Telegram шлёт `language_code` в каждом апдейте, а мы его выбрасывали.
        $languageCode = self::normalizeLanguageCode($from->getLanguageCode());

        $telegramUserModel = new TelegramUserModel();
        $existingUser      = $telegramUserModel->where('telegram_id', $telegramId)->first();
        if (!$existingUser) {
            $createdUserId = $telegramUserModel->insert([
                'telegram_id'        => $telegramId,
                'username'           => $username,
                'first_name'         => $firstName,
                'last_name'          => $lastName,
                'acquisition_source' => $acquisitionSource,
                'language_code'      => $languageCode,
            ], true);
        } else {
            $createdUserId = $existingUser['id'];

            // Добор для уже заведённых: при 1.7 регистрации в сутки замер только по новым
            // набирался бы месяцами. Пишем ОДИН раз (пустое поле → значение) и только на
            // /start, чтобы не превращать каждый апдейт в запись.
            // is_array() сужает тип строки модели явно (не приводим mixed вслепую).
            $knownLanguage = is_array($existingUser) ? ($existingUser['language_code'] ?? null) : null;
            if ($languageCode !== null && $knownLanguage === null) {
                $telegramUserModel->update($createdUserId, ['language_code' => $languageCode]);
            }
        }

        // 3. Ищем персонажа
        $characterModel   = new CharacterModel();
        $existingCharacter= $characterModel->where('telegram_user_id', $createdUserId)->first();

        // 4. Если персонаж НЕ найден → создаём и отправляем приветственное сообщение
        if (!$existingCharacter) {
            // web-accounts-p0-04 (ADR-188): все записи создания персонажа — строка `characters`
            // со стартовыми статами, имя-заглушка `Путник-{id}` для игрока без `@username`
            // (`name` публичен, `telegram_id` туда нельзя — pvp-detection-clarity-07), привязка к
            // аккаунту, спавн, обучающая цепочка, паёк, приманка и встречающий — живут в
            // CharacterProvisioningService: тот же путь зовёт и сайт. Здесь остаётся только UI.
            $provisioning       = new \App\Services\Player\CharacterProvisioningService();
            $createdCharacterId = $provisioning->create(
                $username ?: '',
                (int) $createdUserId,
                (int) $chatId,
                null
            );
            $created = $provisioning->lastTexts();

            // S8 (ADR-146): записать реферальное ребро — first-touch, ТОЛЬКО для нового TG-аккаунта
            // (existingUser отсутствовал). Идемпотентно + анти-self + cap внутри сервиса; при OFF
            // $referrerUserId=null → не вызывается (byte-identical).
            if ($referrerUserId !== null && !$existingUser) {
                $referralService->recordReferralOnRegister(
                    $referrerUserId,
                    (int) $createdUserId,
                    $createdCharacterId
                );
            }

            return $this->renderNewcomer((int) $chatId, $created, $replyKeyboard);

        } else {
            // Если персонаж УЖЕ существует → используем CharacterService.
            //
            // ADR-039 N4 пересмотрен (2026-06-01, после ADR-087 вайпа): /start ВСЕГДА
            // (пере)отправляет постоянную reply-клавиатуру. Раньше для существующих
            // игроков она НЕ слалась (полагались на то, что она уже стоит на клиенте),
            // но игрок, потерявший её (почистил чат / после вайпа), не мог вернуть меню
            // через /start. Карточка персонажа несёт inline-keyboard, а reply+inline
            // на одном сообщении Telegram не совмещает → шлём отдельным лёгким сообщением
            // ПЕРЕД карточкой. Пост-вайп рассылка зовёт «напиши /start» — меню обязано
            // гарантированно вернуться.
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => '🧭 Меню ниже.',
                'reply_markup' => $replyKeyboard,
            ]);

            // ADR-103 Слой 2: добираем обучающую цепочку существующим новичкам (level ≤
            // max). Идемпотентно — повторный /start не дублирует и не трогает ветеранов.
            // returnType модели = CharacterEntity; instanceof сужает union для PHPStan.
            if ($existingCharacter instanceof \App\Entities\CharacterEntity) {
                (new \App\Services\Onboarding\OnboardingChainService())
                    ->ensureChainAssigned($existingCharacter);
            }

            $charService = new CharacterService();
            return $charService->showCharacterInfo($chatId, $existingCharacter);
        }
    }

    /**
     * Ветка нового персонажа — тонкий рендерер ядра первого экрана
     * ({@see \App\Services\Onboarding\ColdOpenWelcomeService}): что, в каком порядке и с какими
     * кнопками слать, решает модель; здесь только отправка и reply-меню на носителе.
     *
     * @param array{spawned: bool, singleScreen: bool, starterKit: ?string, signal: ?string, greeter: ?string} $created
     */
    protected function renderNewcomer(int $chatId, array $created, string $replyKeyboard): ServerResponse
    {
        $response = null;
        foreach ($this->welcomeService()->build(\App\Services\Onboarding\ColdOpenWelcomeService::CLIENT_BOT, $created) as $msg) {
            if ($msg['role'] === 'menu_carrier') {
                $payload = [
                    'chat_id'      => $chatId,
                    'text'         => $msg['text'],
                    'reply_markup' => $replyKeyboard,
                ];
            } else {
                $payload = [
                    'chat_id'                  => $chatId,
                    'text'                     => $msg['text'],
                    'parse_mode'               => $msg['parse_mode'],
                    'disable_web_page_preview' => true,
                ];
                if ($msg['role'] === 'welcome' || $msg['role'] === 'error') {
                    $payload['reply_markup'] = json_encode($msg['inline_keyboard'] === [] ? [] : ['inline_keyboard' => $msg['inline_keyboard']]);
                }
            }
            $response = $this->deliver($payload);
        }

        if ($response === null) {
            throw new \LogicException('ColdOpenWelcomeService вернул пустую модель');
        }

        return $response;
    }

    protected function welcomeService(): \App\Services\Onboarding\ColdOpenWelcomeService
    {
        return new \App\Services\Onboarding\ColdOpenWelcomeService();
    }

    /** @param array<string,mixed> $payload шов для тестов: настоящая отправка */
    protected function deliver(array $payload): ServerResponse
    {
        return Request::sendMessage($payload);
    }

    /**
     * Нормализует payload deep-link `/start <src_*>` в источник регистрации.
     * Оставляет только безопасные символы `[a-zA-Z0-9_-]` (Telegram и так ограничивает
     * start-payload этим набором + 64 симв), обрезает до 191; пусто/мусор → null
     * (органика/прямой вход). Pure — тестируется напрямую.
     */
    /**
     * Нормализует IETF-тег языка из Telegram (`ru`, `en-GB`, `zh-hans`) в компактный ключ.
     *
     * Оставляет только `[a-zA-Z-]`, приводит к нижнему регистру и режет до 16 символов под
     * ширину колонки. Пусто/мусор → null (поле у Telegram опциональное — его может не быть
     * вовсе, и это НЕ ошибка). Pure — тестируется напрямую.
     */
    public static function normalizeLanguageCode(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $clean = preg_replace('/[^a-zA-Z-]/', '', trim($code));
        if (! is_string($clean) || $clean === '') {
            return null;
        }

        return mb_strtolower(mb_substr($clean, 0, 16));
    }

    public static function extractAcquisitionSource(?string $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($payload));
        if (! is_string($clean) || $clean === '') {
            return null;
        }

        return mb_substr($clean, 0, 191);
    }

    /**
     * pvp-detection-clarity-07 (доводка): имя нового персонажа без `@username` в Telegram.
     *
     * `characters.name` публичен — виден другим игрокам (список обнаружения, Арена, рейтинг
     * PvP, лог боя, публичная страница достижений). Сигнатура сознательно берёт ТОЛЬКО
     * `characters.id` (сам по себе уже публичный — см. fallback `№{id}` в
     * `PlayerDetectionService::renderDetectionMessage()`) — никакого доступа к `telegram_id`
     * или другим приватным полям аккаунта здесь нет, поэтому утечка невозможна by construction,
     * а не по осторожности в вызывающем коде.
     */
    public static function mintDistinctName(int $characterId): string
    {
        return \App\Services\Player\CharacterProvisioningService::mintDistinctName($characterId);
    }
}
