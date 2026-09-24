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

            // Сообщение по умолчанию на случай ошибки
            $text          = "Извините, произошла ошибка при попытке определить локацию для спавна. Пожалуйста, попробуйте ещё раз.";
            $encodedKeyboard = json_encode([]);
            $starterKitText = $created['starterKit']; // ADR-104: текст набора Роби (если выдан)
            $signalText     = $created['signal'];     // S4 (ADR-139) слайс 3: радио-нарратив приманки (если размещена)
            $greeterText    = $created['greeter'];    // S2 (ADR-144): нарратив встречающего-нейтрала (если размещён)

            if ($created['spawned']) {
                // Слайс «Первые 3 минуты»: режим первого экрана сервис прочитал ДО размещения
                // приманки и встречающего (их навигационный хвост в одном окне только шумит).
                $coldOpen     = new \App\Services\Onboarding\ColdOpenGreetingService();
                $singleScreen = $created['singleScreen'];

                // Формируем приветственное сообщение.
                // S4 (ADR-139) слайс 1b: cold-open framing — короткий интригующий вариант под
                // суб-killswitch `onboarding.cold_open_v2.start_greeting` (default OFF → легаси
                // 121-слово ниже, byte-identical). CTA «Задать имя» / паёк / спавн не меняются.
                if ($coldOpen->greetingEnabled()) {
                    $text = $coldOpen->greeting();
                } else {
                    $text = "🤖 *Wild World — выживание на острове после глобальной катастрофы.* 🌍\n\n"
                        . "Меня зовут *Роби*, я — твой проводник. Мы оказались на остатке земли, где всё рухнуло: цивилизация ушла, выжившие сами разбираются, как жить дальше.\n\n"
                        . "Что тебя ждёт:\n"
                        . "• 9 биомов — от спокойных полей до вулканических земель и подземелий\n"
                        . "• Сбор ресурсов, крафт, постройка лагеря — всё из подручного хлама\n"
                        . "• События мира: погода, болезни, рейдеры, редкие находки\n"
                        . "• На lvl 10 — выбор одной из 4 фракций. У каждой свой путь: доминирование, анархия, научный прорыв или мирное возрождение.\n\n"
                        . "Не все выжившие дружелюбны — продумывай, как защитить себя и базу.\n\n"
                        . "Сейчас покажу несколько подсказок, чтобы ты не блуждал в первый час. Можно прервать в любой момент — но советую дойти до конца.\n\n"
                        . "Вопросы и стратегии обсуждаем в [общем чате](https://t.me/wild_world_info).\n\n"
                        . "Придумай герою имя и поехали.";
                }

                // ADR-168: метка `cold` — это первый экран. Без неё вход неотличим от прочих
                // мест, где та же кнопка встречается позже.
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🤔 Задать имя персонажа', 'callback_data' => \App\Services\Logging\ActionOrigin::tag('setCharacterName', \App\Services\Logging\ActionOrigin::FROM_COLDOPEN)]
                        ],
                    ]
                ];
                $encodedKeyboard = json_encode($keyboard);

                // Слайс «Первые 3 минуты» (замер 2026-07-24) — ОДНО окно вместо пяти.
                //
                // Замер когорты Хабра 07-08.07 (45 человек): 42 из 45 остались на L1, живы
                // через две недели 3; лишь 18 (40%) нажали единственную кнопку первого экрана,
                // а 18 из 26 ушедших уложились в ≤3 минуты. При этом сокращение текста
                // (слайс 1b, `start_greeting`) метрику «не назвали героя» не сдвинуло: было
                // ~58%, стало 60% → барьер не в длине копии, а в форме первого контакта:
                // пять сообщений подряд, CTA в последнем, и он требует ПЕЧАТАТЬ, хотя имя
                // уже автоподставлено из username (см. insert выше).
                //
                // Здесь: паёк/сигнал/встречающий въезжают секциями В welcome (обнуляем их —
                // отдельные sendMessage ниже пропускаются), а первый CTA ведёт в мир
                // (callback `move` → компас ходьбы). Имя остаётся второй кнопкой и явно
                // названо необязательным. При OFF (default) — всё byte-identical.
                if ($singleScreen) {
                    $composed = $coldOpen->composeWelcome([
                        $coldOpen->greetingSingleScreen(),
                        $starterKitText,
                        $signalText,
                        $greeterText,
                        $coldOpen->closingSingleScreen(),
                    ]);

                    // Не влезло в одно сообщение (контент разросся) → честный откат на
                    // раздельную отправку, а не потеря экрана целиком.
                    if ($coldOpen->fitsSingleMessage($composed)) {
                        $text            = $composed;
                        // ADR-168: обе двери первого экрана помечены `cold`. Особенно важно для
                        // `move` — этот callback шлют около полусотни экранов («к карте»,
                        // «уйти», «идти дальше»), и без метки первый шаг новичка неотличим от
                        // любого возврата ветерана на карту, то есть эффект правки не измерить.
                        $encodedKeyboard = json_encode([
                            'inline_keyboard' => [
                                [
                                    ['text' => '🧭 Сделать первый шаг', 'callback_data' => \App\Services\Logging\ActionOrigin::tag('move', \App\Services\Logging\ActionOrigin::FROM_COLDOPEN)],
                                ],
                                [
                                    ['text' => '✏️ Назвать героя', 'callback_data' => \App\Services\Logging\ActionOrigin::tag('setCharacterName', \App\Services\Logging\ActionOrigin::FROM_COLDOPEN)],
                                ],
                            ],
                        ]);

                        $starterKitText = null;
                        $signalText     = null;
                        $greeterText    = null;
                    }
                }
            }

            // N4 (ADR-039): постоянную reply-клавиатуру шлём только новичку. Для
            // существующих игроков её ставит CharacterService::showCharacterInfo —
            // раньше StartCommand слал её безусловно → дубль keyboard-сообщения на /start.
            //
            // Правка «одна инструкция вместо двух» (аудит 2026-08-14): текст этого сообщения
            // приказывал «используйте меню ниже» и конкурировал с CTA следующего экрана —
            // 36% свежей когорты уходили в нижнее меню и делали втрое меньше действий.
            // Теперь он направляет вперёд. Клавиатура по-прежнему ставится (прятать её нельзя:
            // игрок должен знать, что меню есть). Dormant под `cold_open_v2.menu_defer`.
            Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => (new \App\Services\Onboarding\ColdOpenGreetingService())->menuAttachText(),
                'reply_markup' => $replyKeyboard,
            ]);

            // ADR-104 Фаза 1: если стартовый набор выдан — шлём сообщение Роби о пайке
            // ПЕРЕД финальным welcome'ом, чтобы CTA «Задать имя» остался последним
            // (самым заметным). MEDIA-OFF: текстовое, весь смысл в тексте.
            if ($starterKitText !== null) {
                Request::sendMessage([
                    'chat_id'                  => $chatId,
                    'text'                     => $starterKitText,
                    'parse_mode'               => 'Markdown',
                    'disable_web_page_preview' => true,
                ]);
            }

            // S4 (ADR-139) слайс 3: радио-нарратив приманки (если размещена) — ПОСЛЕ пайка,
            // ПЕРЕД финальным welcome'ом, чтобы CTA «Задать имя» остался последним. MEDIA-OFF.
            if ($signalText !== null) {
                Request::sendMessage([
                    'chat_id'                  => $chatId,
                    'text'                     => $signalText,
                    'parse_mode'               => 'Markdown',
                    'disable_web_page_preview' => true,
                ]);
            }

            // S2 (ADR-144): нарратив встречающего-нейтрала (если размещён) — ПОСЛЕ сигнала,
            // ПЕРЕД финальным welcome'ом, чтобы CTA «Задать имя» остался последним. MEDIA-OFF.
            if ($greeterText !== null) {
                Request::sendMessage([
                    'chat_id'                  => $chatId,
                    'text'                     => $greeterText,
                    'parse_mode'               => 'Markdown',
                    'disable_web_page_preview' => true,
                ]);
            }

            // Возвращаем результат
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
                'reply_markup' => $encodedKeyboard
            ]);

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
