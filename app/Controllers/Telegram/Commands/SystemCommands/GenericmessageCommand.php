<?php

namespace App\Controllers\Telegram\Commands\SystemCommands;

use App\Controllers\Telegram\Commands\Actions\SettingsAction;
use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Services\BaseService;
use App\Services\Player\CharacterService;
use App\Services\Player\CraftService;
use App\Services\World\MapService;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// Модели

// Сервисы

class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $rawText = trim($message->getText(true) ?? '');
        $text    = mb_strtolower($rawText);
        $chatId  = $message->getChat()->getId();

        // Идея #6 (Arseny, 21.01.2025): ForceReply ответ на trade-промпт
        // с маркером "SELL:123" / "BUY:123" в тексте → выполнить продажу/покупку с qty.
        // Скобки вокруг маркера опциональны: при parse_mode=Markdown Telegram «съедал»
        // квадратные скобки (вид [SELL:123] → SELL:123), regex терпим к обоим вариантам —
        // и продажа «своим числом» снова работает (баг 2026-05-11: «Не понял…» на ответ).
        $reply = $message->getReplyToMessage();
        if ($reply !== null) {
            $promptText = (string) ($reply->getText() ?? '');
            if (preg_match('/(SELL|BUY):(\d+)/', $promptText, $m)) {
                return $this->handleTradeReply($chatId, $m[1], (int) $m[2], $rawText);
            }
            // N4 (ADR-039): forceReply-ввод имени персонажа в онбординге.
            // SetNameAction шлёт промпт с маркером «✍ NAME» → имя из ответа в NameService.
            if (mb_strpos($promptText, '✍ NAME') !== false) {
                return $this->handleNameReply($chatId, $rawText);
            }
            // ADR-122 (UX-хвост): forceReply-ввод координат «Полноценного переезда».
            // Промпт помечен «🚚 ПЕРЕЕЗД» → координаты из свободного ответа («357 391»).
            if (mb_strpos($promptText, \App\Services\Player\Relocation\RelocationRequestService::PROMPT_MARKER) !== false) {
                return $this->handleRelocationReply($chatId, $rawText);
            }
        }

        switch ($text) {
            // ADR-150: «Перс»/«База»/«Крафт» есть и в старом, и в новом каркасе — сами по себе
            // уликой не служат, но пере-аттач всё равно пробуем: он one-shot и пропускает тех,
            // кто уже получил новое меню (маркер ставится и при `/start`). Без этого игроки,
            // которые никогда не жмут «Карта» (её шлют вчетверо реже), навсегда остались бы
            // со старой клавиатурой.
            // ADR-150 ФИНАЛ: подписи получили иконки («🧑 Я», «🏠 База», «🔨 Крафт»). Старые
            // голые слова остаются навсегда — их шлют и со старой клавиатуры, и руками.
            // Голое «я» в case НЕ ловим: слишком частое слово в обычной речи.
            case 'перс':
            case '🧑 я':
                $charResponse = $this->handleCharacter($chatId);
                $this->refreshStaleMenu($chatId);

                return $charResponse;

            case 'база':
            case '🏠 база':
                $baseResponse = $this->handleBase($chatId);
                $this->refreshStaleMenu($chatId);

                return $baseResponse;

            case 'крафт':
            case '🔨 крафт':
                $craftResponse = $this->handleCraft($chatId);
                $this->refreshStaleMenu($chatId);

                return $craftResponse;

            case 'карта':
            // Text-алиасы (2026-07-10, аудит firehose `status=unrouted` за 90 дней).
            // Игроки НАБИРАЮТ то, что обучающие тексты называют кнопкой: «двигаться»,
            // «как двигаться?», «вверх». Раньше это уходило в «Не понял команду» —
            // прямое нарушение ONBOARDING-COVERAGE (навигация не должна теряться).
            // Компас (MoveSurfaceService) как раз и даёт кнопки направлений.
            // `map` — латиница, зеркало уже существующего `settings`.
            case 'map':
            case 'двигаться':
            case 'двигатся':
            case 'идти':
            case 'иду':
            case 'ходить':
            case 'как двигаться':
            case 'как двигаться?':
            case 'вверх':
            case 'вниз':
            case 'влево':
            case 'вправо':
                // ADR-150 Слайс 1: при world_hub ON «Карта» ведёт к компасу ходьбы
                // (MoveSurfaceService), при OFF — к фото (byte-identical). Старая
                // reply-клавиатура ещё шлёт «Карта» после флипа — тоже роутим на компас.
                // Алиасы уважают тот же флаг: слово ведёт ровно туда же, куда кнопка.
                $mapResponse = \App\Services\Telegram\BotMenuService::worldHubEnabled()
                    ? $this->handleWorld($chatId)
                    : $this->handleMap($chatId);
                // Текст «Карта» в новом каркасе не существует («🌍 Мир») → клавиатура устарела.
                // Набранное руками слово — тоже улика: скорее всего меню потеряно.
                $this->refreshStaleMenu($chatId);

                return $mapResponse;

            // ADR-150 Слайс 1: новая нижняя кнопка «🌍 Мир» (при world_hub ON) → компас.
            // Пере-аттач пробуем и здесь: у игрока может висеть ПЕРЕХОДНЫЙ каркас (слайсы 1-4),
            // где «🌍 Мир» уже есть, а финальной сетки 2×3 ещё нет.
            case '🌍 мир':
            case 'мир':
                $worldResponse = $this->handleWorld($chatId);
                $this->refreshStaleMenu($chatId);

                return $worldResponse;

            // ADR-150 Слайс 3: новая нижняя кнопка «📋 Дела» (при tasks_hub ON) → хаб целей.
            // Голое «дела» ловим тоже — игрок печатает без эмодзи.
            case '📋 дела':
            case 'дела':
            // Text-алиасы (2026-07-10): «квест» приходил в firehose как unrouted. Хаб «Дела»
            // и есть дом квестов + заданий дня. При tasks_hub OFF handleTasks сам вернёт
            // fallback «не понял» → мёртвого слова не возникает.
            case 'квест':
            case 'квесты':
            case 'задание':
            case 'задания':
                $tasksResponse = $this->handleTasks($chatId);
                $this->refreshStaleMenu($chatId);

                return $tasksResponse;

            // ADR-150 Слайс 4: новая нижняя кнопка «⚙️ Ещё» (при more_hub ON) → хаб «Ещё».
            // «еще» без ё — игрок часто печатает так.
            case '⚙️ ещё':
            case 'ещё':
            case 'еще':
                $moreResponse = $this->handleMore($chatId);
                $this->refreshStaleMenu($chatId);

                return $moreResponse;

            // Text-алиасы (2026-07-10): «топ» и «тут есть топ игроков?» приходили в firehose как
            // unrouted от ДВУХ разных игроков. Тогда алиас не сделали — общего топа не было, и
            // вести слово в рейтинг ДУЭЛЕЙ значило соврать. Теперь экран есть → слово ведёт в него.
            case 'топ':
            case 'рейтинг':
            case 'топ игроков':
            case 'тут есть топ игроков?':
                return $this->handleLeaderboard($chatId);

            case 'настройки':
            case 'settings':
                $settingsResponse = $this->handleSettings($chatId);
                // «Настройки» как нижняя кнопка живут только в старом каркасе (в новом — «⚙️ Ещё»).
                // Латинское «settings» шлют не с клавиатуры — уликой не считаем, но вреда нет:
                // maybeRefresh сам one-shot и проверяет, изменился ли каркас.
                $this->refreshStaleMenu($chatId);

                return $settingsResponse;

            // Новые команды для смены типа карты
            case 'beautiful_map':
                return $this->handleMapPreference($chatId, 'beautiful');

            case 'accurate_map':
                return $this->handleMapPreference($chatId, 'accurate');

            // Идея #14 (Yupirex, 13.02.2025): toggle для отключения изображений
            // (старые текстовые команды; новый UI — экран «Настройки», см. handleSettings).
            case 'media_off':
                return $this->handleMediaPreference($chatId, 1);

            case 'media_on':
                return $this->handleMediaPreference($chatId, 0);

            default:
                return $this->unrecognized($chatId);
        }
    }

    /**
     * ADR-103 Часть A — escape-hatch на нераспознанный текст: подсказываем, как вернуть нижнее
     * меню, если игрок его потерял (свернул reply-клавиатуру). /start и /menu гарантированно
     * её пере-аттачивают; `/`-меню (☰ у поля ввода) всегда на месте.
     *
     * Список кнопок берётся из {@see BotMenuService::replyButtonsLine} — единого источника
     * истины каркаса. Хардкод врал бы про UI после каждого слайса ADR-150.
     */
    private function unrecognized(int $chatId): ServerResponse
    {
        // ADR-148 — нераспознанный текст: пометить действие как 'unrouted' в firehose.
        \App\Services\Logging\PlayerActionLogger::current()->markUnrouted();

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'parse_mode' => 'Markdown',
            'text'       => "Не понял команду.\n\n"
                . 'Используй нижнее меню: ' . \App\Services\Telegram\BotMenuService::replyButtonsLine() . ".\n\n"
                . "_Меню пропало? Нажми_ /menu _или_ /start _— нижняя панель вернётся. "
                . "Все команды также доступны через значок «☰» рядом с полем ввода._",
        ]);
    }

    /**
     * ADR-122 (UX-хвост) — ответ игрока на forceReply-промпт «Полноценного переезда».
     * Координаты разбираются свободно («357 391», «357,391», «X=357Y=391»), дальше — общий
     * пайплайн {@see \App\Services\Player\Relocation\RelocationRequestService::handleCoords},
     * тот же, что у команды `/base_shifting`. Второй копии валидаций не существует.
     */
    private function handleRelocationReply(int $chatId, string $rawText): ServerResponse
    {
        $svc    = new \App\Services\Player\Relocation\RelocationRequestService();
        $coords = \App\Services\Player\Relocation\RelocationRequestService::parseCoords($rawText);

        if ($coords === null) {
            return $svc->coordsNotUnderstood($chatId);
        }

        $telegramId = (int) $this->getMessage()->getFrom()->getId();

        return $svc->handleCoords($chatId, $telegramId, $coords[0], $coords[1]);
    }

    /**
     * Экран «⚙️ Настройки» (тумблер картинок, идея #14). Рендер — {@see SettingsAction::buildScreen()}.
     * Вызывается по тексту «настройки»/«settings» и кнопкой «Настройки» постоянной клавиатуры.
     */
    private function handleSettings(int $chatId): ServerResponse
    {
        $telegramId = (int) $this->getMessage()->getFrom()->getId();
        /** @var array<string,mixed>|null $userRow */
        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if ($userRow === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден. Используйте /start.']);
        }
        /** @var \App\Entities\CharacterEntity|null $character */
        $character = (new CharacterModel())->where('telegram_user_id', $userRow['id'] ?? 0)->first();
        if ($character === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден. Используйте /start.']);
        }

        return Request::sendMessage(['chat_id' => $chatId] + SettingsAction::buildScreen($character));
    }

    /**
     * Идея #14: toggle disable_media через типизированную команду.
     */
    private function handleMediaPreference(int $chatId, int $disable): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();
        $userRow    = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }
        // TelegramUserModel + CharacterModel возвращают Entity (F1.4) или array
        // в зависимости от ToColumns. Поддерживаем оба.
        $userId = isset($userRow['id']) ? (int) $userRow['id'] : 0;

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userId)->first();
        if (!$characterRow) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        $charId = isset($characterRow['id']) ? (int) $characterRow['id'] : 0;

        $charModel->update($charId, ['disable_media' => $disable]);
        \App\Services\Notifications\MediaSender::reset();

        $msg = $disable === 1
            ? "🚫 *Режим без медиа* включён. Бот будет слать только текст.\n\n_Чтобы вернуть картинки — напиши_ `media_on`."
            : "🖼️ *Режим с медиа* включён. Бот будет присылать изображения.\n\n_Чтобы отключить — напиши_ `media_off`.";

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $msg,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Идея #6: обработка ForceReply ответа на trade-промпт.
     * Парсим qty из ответа (любой мусор вокруг цифр срезаем), дёргаем ResourceTradeService.
     *
     * ⚠️ Персонажа берём через `asArray()` — `ResourceTradeService::sellResource()` ждёт
     * именно массив (`$character['id']`/`$character['gold']`); `(array)` каст по
     * `CharacterEntity` давал «битый» массив с mangled-ключами → продажа молча падала
     * в «У вас нет такого ресурса» (часть бага 2026-05-11).
     */
    private function handleTradeReply(int $chatId, string $direction, int $resourceId, string $rawReply): ServerResponse
    {
        $qty = (int) preg_replace('/[^\d]/', '', $rawReply);
        if ($qty <= 0) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => '❌ Не понял число. Введите положительное целое число (например, 25000).',
            ]);
        }

        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();
        $userRow    = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }
        $character = (new CharacterModel())->asArray()->where('telegram_user_id', $userRow['id'])->first();
        if (!is_array($character)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        /** @var array<string,mixed> $character — строка БД (asArray): ключи всегда строковые */

        $svc    = new \App\Services\Player\Trade\ResourceTradeService();
        $result = $direction === 'SELL'
            ? $svc->sellResource($character, $resourceId, $qty)
            : $svc->buyResource($character, $resourceId, $qty);

        // Логируем продажу сырья через ForceReply («своё число») в action_log — кнопочный
        // путь логируется в SellResourceAction, а этот (GenericmessageCommand) — здесь, иначе
        // продажи «своим числом» оставались невидимы в форензике расхода ресурсов.
        if ($direction === 'SELL' && $result['success'] === true) {
            try {
                (new \App\Models\ActionLogModel())->save([
                    'character_id'  => is_numeric($character['id'] ?? null) ? (int) $character['id'] : null,
                    'chat_id'       => $chatId,
                    'action_name'   => 'SELL_RESOURCE',
                    'action_status' => 'Completed',
                    'description'   => mb_substr(
                        "res={$resourceId} qty=" . ($result['qty'] ?? '?')
                            . ' gold=+' . ($result['amount'] ?? '?') . ' (forcereply)',
                        0,
                        500
                    ),
                ]);
            } catch (\Throwable $e) {
                log_message('error', '[handleTradeReply] SELL log insert failed: ' . $e->getMessage());
            }
        }

        // Arseny report 2026-05-26 (хвост): после сделки «своим числом» — возврат в список
        // той же редкости, а не только в корень магазина.
        $rows     = [];
        $resource = (new \App\Models\ResourceModel())->find($resourceId);
        $rarity   = $resource->rarity ?? 0;
        if ($rarity > 0) {
            $rows[] = [
                [
                    'text'          => "⬅️ К редкости {$rarity}",
                    'callback_data' => $direction === 'SELL' ? "sellResource_rarity_{$rarity}" : "buy_rarity_{$rarity}",
                ],
                [
                    'text'          => $direction === 'SELL' ? '💰 Другая редкость' : '🛍️ Другая редкость',
                    'callback_data' => $direction === 'SELL' ? 'sell' : 'buy',
                ],
            ];
        }
        $rows[] = [
            ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $result['message'],
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /**
     * N4 (ADR-039): обработка ForceReply-ответа на промпт ввода имени (онбординг).
     * Имя из ответа → NameService->applyName (та же валидация/tier-логика, что и /name slash).
     */
    private function handleNameReply(int $chatId, string $rawName): ServerResponse
    {
        $telegramId = $this->getMessage()->getFrom()->getId();
        $user       = (new TelegramUserModel())->asArray()->where('telegram_id', $telegramId)->first();
        if (!is_array($user)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }

        $character = (new CharacterModel())->asArray()->where('telegram_user_id', $user['id'])->first();
        if (!is_array($character)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $result = (new \App\Services\Player\NameService())->applyName($character, $rawName);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $result['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => $result['keyboard'] !== null ? json_encode($result['keyboard']) : null,
        ]);
    }

    /**
     * Установка предпочтения карты (beautiful/accurate)
     */
    private function handleMapPreference(int $chatId, string $mapType): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel = new TelegramUserModel();
        $userRow   = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден, не могу установить тип карты.',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден, не могу установить тип карты.',
            ]);
        }

        // Обновляем поле в БД
        $charModel->update($characterRow['id'], [
            'preferred_map_type' => $mapType,
        ]);

        $human = ($mapType === 'accurate')
            ? '🗺 точная (пиксель в пиксель)'
            : '🎨 художественная';

        // Команды остаются рабочими (backwards-compat), но дальше зовём в тумблер:
        // именно его отсутствие рождало вопрос «режим карты хрен поменять уже, да?».
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => "Вид карты изменён: {$human}\n\n"
                . "_Переключать можно и кнопкой: «⚙️ Ещё» → «⚙️ Настройки» → «Вид карты мира»._",
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     *  Логика для команды «персонаж»
     */
    private function handleCharacter(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (character).',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (character).',
            ]);
        }

        // Вызываем сервис персонажа
        $charService = new CharacterService();
        return $charService->showCharacterInfo($chatId, $characterRow);
    }

    /**
     *  Логика для команды «база»
     */
    private function handleBase(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (base).',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (base).',
            ]);
        }

        // Вызываем сервис базы
        $baseService = new BaseService();
        return $baseService->showBaseInfo($chatId, $characterRow);
    }

    /**
     *  Логика для команды «крафт»
     */
    private function handleCraft(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (craft).',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (craft).',
            ]);
        }

        // Вызываем сервис крафта
        $craftService = new CraftService();
        $response     = $craftService->showCraftMenu($chatId);

        // ADR-103 just-in-time: новичок открыл крафт-хаб (нижняя кнопка «Крафт» / текст
        // «крафт» — основной путь), но ещё ничего не скрафтил → подсказываем, с чего начать.
        // One-shot + killswitch/opt-out/level-гейт внутри сервиса. Закрывает горлышко
        // OnbStepCraft (пере-срез A+B 2026-06-20: 12/36 = 33%). Дубль — в BotMenuService::openCraft
        // (slash /craft); one-shot дедуп не даёт двойной отправки.
        if ($characterRow instanceof \App\Entities\CharacterEntity) {
            (new \App\Services\Onboarding\OnboardingHintService())
                ->maybeSendFirstCraftHint($characterRow, $chatId);
        }

        return $response;
    }

    /**
     * ADR-150 Слайс 1 — открыть поверхность «🌍 Мир» (компас ходьбы) из нижней кнопки/текста.
     * Единый рендер {@see \App\Services\World\MoveSurfaceService::show} (тот же экран,
     * что callback `move` и `/go`).
     */
    private function handleWorld(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        $userModel = new TelegramUserModel();
        $userRow   = $userModel->where('telegram_id', $telegramId)->first();
        if (! is_array($userRow)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (world).',
            ]);
        }

        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (! $characterRow instanceof \App\Entities\CharacterEntity) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (world).',
            ]);
        }

        return (new \App\Services\World\MoveSurfaceService())->show($chatId, $characterRow);
    }

    /**
     * ADR-150 Слайс 3 — открыть поверхность «📋 Дела» из нижней кнопки/текста. Единый рендер
     * {@see \App\Services\Tasks\TasksSurfaceService::show} (тот же экран, что callback
     * `tasksHub` и slash `/tasks`).
     *
     * При killswitch OFF слово «дела» — обычный нераспознанный текст → отдаём его в общий
     * fallback (иначе dormant-флаг протёк бы наружу новым поведением).
     */
    private function handleTasks(int $chatId): ServerResponse
    {
        if (! \App\Services\Telegram\BotMenuService::tasksHubEnabled()) {
            return $this->unrecognized($chatId);
        }

        $telegramId = (int) $this->getMessage()->getFrom()->getId();

        return \App\Services\Telegram\BotMenuService::openTasks($chatId, $telegramId);
    }

    /**
     * ADR-150 — пере-аттач нижнего меню тому, кто прислал подпись СТАРОЙ кнопки («Карта» /
     * «Настройки»): в новом каркасе таких кнопок нет, значит клавиатура у игрока устарела.
     * Telegram сам её не обновляет — без этого шага Слайсы 1/3/4 остаются невидимыми
     * (замер 09.07: `/start` после активации нажали 6 из 79 активных).
     *
     * One-shot на персонажа + гейт «каркас изменился» — внутри {@see NavMenuRefreshService}.
     * Никогда не роняет основное действие игрока (сервис ловит Throwable сам).
     */
    private function refreshStaleMenu(int $chatId): void
    {
        $telegramId = (int) $this->getMessage()->getFrom()->getId();

        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (! is_array($userRow)) {
            return;
        }

        $character = (new CharacterModel())->where('telegram_user_id', $userRow['id'])->first();
        if (! $character instanceof \App\Entities\CharacterEntity) {
            return;
        }

        $charIdRaw = $character->id ?? null;
        $charId    = is_numeric($charIdRaw) ? (int) $charIdRaw : 0;

        (new \App\Services\Telegram\NavMenuRefreshService())->maybeRefresh($charId, $chatId);
    }

    /**
     * ADR-150 Слайс 4 — открыть поверхность «⚙️ Ещё» из нижней кнопки/текста. Единый рендер
     * {@see \App\Services\More\MoreSurfaceService::show} (тот же экран, что callback `moreHub`).
     *
     * При killswitch OFF слово «ещё» — обычный нераспознанный текст → общий fallback
     * (иначе dormant-флаг протёк бы наружу новым поведением).
     */
    /**
     * Топ игроков по слову «топ»/«рейтинг» (2026-07-10). Рендер — общий {@see LeaderboardScreen},
     * тот же, что у кнопки «🏆 Топ игроков». При killswitch OFF — честный текст «отключён
     * администрацией», а не «Не понял команду»: слово не становится мёртвым.
     */
    private function handleLeaderboard(int $chatId): ServerResponse
    {
        $screen = new \App\Services\Social\LeaderboardScreen();
        if (! (new \App\Services\Social\LeaderboardService())->enabled()) {
            return Request::sendMessage(['chat_id' => $chatId] + $screen->disabledPayload());
        }

        $telegramId = (int) $this->getMessage()->getFrom()->getId();
        $userRow    = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (! is_array($userRow)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Пользователь не найден.']);
        }

        // Модель типизирована CharacterEntity (F1.4.1) — Entity в array-контекст не суём
        // вслепую (урок feedback_entity_strict_array_typehint_trap), нарроуим явно.
        $charRow = (new CharacterModel())->where('telegram_user_id', $userRow['id'])->first();
        $charId  = $charRow instanceof \App\Entities\CharacterEntity && is_numeric($charRow['id'] ?? null)
            ? (int) $charRow['id']
            : 0;

        return Request::sendMessage(['chat_id' => $chatId] + $screen->payload($charId));
    }

    private function handleMore(int $chatId): ServerResponse
    {
        if (! \App\Services\Telegram\BotMenuService::moreHubEnabled()) {
            return $this->unrecognized($chatId);
        }

        $telegramId = (int) $this->getMessage()->getFrom()->getId();

        return \App\Services\Telegram\BotMenuService::openMore($chatId, $telegramId);
    }

    private function handleMap(int $chatId): ServerResponse
    {
        $from       = $this->getMessage()->getFrom();
        $telegramId = $from->getId();

        // 1) Ищем пользователя
        $userModel  = new TelegramUserModel();
        $userRow    = $userModel->where('telegram_id', $telegramId)->first();
        if (!$userRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден (map).',
            ]);
        }

        // 2) Ищем персонажа
        $charModel    = new CharacterModel();
        $characterRow = $charModel->where('telegram_user_id', $userRow['id'])->first();
        if (!$characterRow) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Персонаж не найден (map).',
            ]);
        }

        // 3) Вызываем сервис карты
        $mapService = new MapService();
        $response   = $mapService->showMapWithPlayer($chatId, $characterRow);

        // ADR-103 just-in-time (2026-06-21): нижняя кнопка «Карта» идёт сюда напрямую,
        // минуя BotMenuService::openMap, где висит хинт первого шага — без этого вызова
        // FIRST_MOVE срабатывал только на slash /map (а новички жмут кнопку). One-shot +
        // killswitch/opt-out/level/barely-moved внутри сервиса; one-shot дедуп общий с openMap.
        if ($characterRow instanceof \App\Entities\CharacterEntity) {
            (new \App\Services\Onboarding\OnboardingHintService())
                ->maybeSendFirstMoveHint($characterRow, $chatId);
        }

        return $response;
    }

}
