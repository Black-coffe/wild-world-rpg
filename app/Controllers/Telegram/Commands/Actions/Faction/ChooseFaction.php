<?php

namespace App\Controllers\Telegram\Commands\Actions\Faction;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;
use App\Models\CharacterModel;
use App\Models\CharacterFactionModel;
use App\Models\FactionModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Config\CraftRecipes;

class ChooseFaction extends BaseAction
{
    use GameSettingsReaderTrait;

    /**
     * transport-11 (ADR-174, docs/specs/transport-system/) — выбор фракции на 10 уровне
     * теперь ещё и необратимый выбор транспорта. Ключ рецепта берётся из того же
     * `Config\CraftRecipes`, что читает крафт (story 06) — единый источник правды
     * для имени машины и уровня доступа, экран не набирает их руками.
     *
     * @var array<int,string>
     */
    private const VEHICLE_RECIPE_BY_FACTION_ID = [
        1 => 'Snowmobile',      // Милитари
        2 => 'MountainBike',    // Партизаны
        3 => 'AutonomousDrone', // Инженеры
        4 => 'DraftCart',       // Фермеры
    ];

    /**
     * Человеческая фраза о характере машины — не число баланса (концепт §1
     * держит числа в GameSettings), а то, что игрок реально почувствует.
     *
     * @var array<string,string>
     */
    private const VEHICLE_CHARACTER_PHRASE = [
        'Snowmobile'      => 'мчит по снегу, но требует топлива',
        'MountainBike'    => 'быстрее там, где ещё не был',
        'AutonomousDrone' => 'не ускоряет, зато бережёт силы и заряжается сам',
        'DraftCart'       => 'тянет ровно и без устали',
    ];

    /** @var array<int,string> */
    private const FACTION_TITLES = [
        1 => '🛡️ *Милитари*',
        2 => '🌲 *Партизаны*',
        3 => '🛠️ *Инженеры*',
        4 => '🌾 *Фермеры*',
    ];

    /**
     * Строка «фракция → её машина» одной фракции. Имя, эмодзи и уровень читаются из
     * `Config\CraftRecipes` (story 06) — единый источник с рецептами крафта, экран не
     * может разойтись с каталогом.
     */
    public static function vehicleLine(int $factionId, ?CraftRecipes $recipes = null): string
    {
        $recipeKey = self::VEHICLE_RECIPE_BY_FACTION_ID[$factionId] ?? null;
        $title     = self::FACTION_TITLES[$factionId] ?? null;
        if ($recipeKey === null || $title === null) {
            return '';
        }

        $recipes ??= new CraftRecipes();
        $recipe = $recipes->get($recipeKey);
        if (! $recipe) {
            return '';
        }

        $iconRaw  = $recipe['icon_emoji'] ?? '';
        $icon     = is_string($iconRaw) ? trim($iconRaw) : '';

        $nameRaw  = $recipe['item_name_rus'] ?? $recipeKey;
        $name     = is_string($nameRaw) ? $nameRaw : $recipeKey;

        $levelRaw = $recipe['required_level'] ?? 0;
        $level    = is_int($levelRaw) ? $levelRaw : (is_numeric($levelRaw) ? (int) $levelRaw : 0);

        $phrase  = self::VEHICLE_CHARACTER_PHRASE[$recipeKey];
        $vehicle = trim("{$icon} {$name}");

        return "{$title} — {$vehicle} (ур. {$level}): {$phrase}.";
    }

    /**
     * Чистая render-функция экрана «Фракции» (transport-11). Без побочных эффектов —
     * тест идёт по ней напрямую. `$incentiveLine` передаётся аргументом, а не читается
     * тут из GameSettings, чтобы функция оставалась чистой и тестируемой без БД.
     */
    public static function renderFactionInfoText(string $incentiveLine = '', ?CraftRecipes $recipes = null): string
    {
        $recipes ??= new CraftRecipes();

        $vehicleLines = implode("\n", [
            self::vehicleLine(1, $recipes),
            self::vehicleLine(2, $recipes),
            self::vehicleLine(3, $recipes),
            self::vehicleLine(4, $recipes),
        ]);

        $message = "*🎉 Поздравляем!*\n\n"
            . "Ваш персонаж достиг уровня 10. Теперь вы выбираете не только фракцию, но и "
            . "транспорт — тот, что останется с тобой навсегда.\n\n"
            . $vehicleLines . "\n\n"
            . "*Что даёт членство:* легендарное оружие и броня своей фракции, общий "
            . "крафт-проект (−10% к крафту всем своим на время) и скидки у "
            . "караванов-союзников.\n"
            . ($incentiveLine !== '' ? "\n{$incentiveLine}\n" : "\n")
            . "*⚠️ Выбор необратим.* Сменить фракцию нельзя вплоть до вайпа персонажа — "
            . "вместе с ней навсегда закрепится и твоя машина.\n\n"
            . "*Учтите:* все фракции PvP, но бои доступны только за пределами стартовой "
            . "зоны.\n\n"
            . "🔽 *Нажатие кнопки ниже не выбирает фракцию — только покажет подробности.*";

        return $message;
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        $callbackData = $this->callbackQuery->getData();
        $callbackParts = explode('_', $callbackData);

        if ($callbackParts && $callbackParts[1] == 'info') {
            return $this->sendFactionInfo($chatId);
        }

        // E7 (ROADMAP-100) — витрина выгод: сравнительный экран 4 фракций в момент выбора.
        if (($callbackParts[1] ?? null) === 'compare') {
            return $this->sendFactionComparison($chatId);
        }

        $faction = $callbackParts[1];
        $characterModel = new CharacterModel();
        $characterId = $characterModel->getCharacterIdByTelegramId($chatId);
        $character = $characterModel->find($characterId);

        if (isset($callbackParts[2]) && $callbackParts[1] == 'joinFaction') {
            return $this->fractionation($chatId, $character, $callbackParts[2]);
        }

        if (!$character) {
            Request::answerCallbackQuery(['callback_query_id' => $chatId]);
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text' => 'Персонаж не найден.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $factionDetails = $this->getFactionDetails($faction);
        $messageText = "*{$factionDetails['title']}*\n\n{$factionDetails['description']}\n\n*➕ Преимущества:*\n{$factionDetails['advantages']}\n\n*➖ Недостатки:*\n{$factionDetails['disadvantages']}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Вступить', 'callback_data' => "chooseFaction_joinFaction_{$faction}"],
                    ['text' => 'Фракции', 'callback_data' => 'chooseFaction_info'],
                    ['text' => 'Персонаж', 'callback_data' => 'character'],
                ],
            ],
        ];
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $messageText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function sendFactionInfo($chatId)
    {
        $message = self::renderFactionInfoText($this->incentiveLine());

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛡️ Милитари', 'callback_data' => 'chooseFaction_Military'],
                    ['text' => '🌲 Партизаны', 'callback_data' => 'chooseFaction_Partisans'],
                ],
                [
                    ['text' => '🛠️ Инженеры', 'callback_data' => 'chooseFaction_Engineers'],
                    ['text' => '🌾 Фермеры', 'callback_data' => 'chooseFaction_Farmers'],
                ],
                [
                    ['text' => '📊 Сравнить фракции', 'callback_data' => 'chooseFaction_compare'],
                ],
            ]
        ];
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * E7 (ROADMAP-100) — витрина выгод: 4 фракции рядом, чтобы сравнить ДО клика в каждую.
     * Срез E7: момент выбора — место для конверсии; сравнительная подача снимает «паралич
     * выбора». Самодостаточно текстом (MEDIA-OFF неактуален — диалог чисто текстовый).
     */
    protected function sendFactionComparison(int|string $chatId): ServerResponse
    {
        $message = "*📊 Сравнение фракций*\n\n"
            . "🛡️ *Милитари* — прямой бой и захват точек.\n"
            . "   _Сила:_ мощное вооружение, укреплённая оборона.\n"
            . "   _Цена:_ высокий расход ресурсов и логистики.\n\n"
            . "🌲 *Партизаны* — скрытность, засады, мобильность.\n"
            . "   _Сила:_ внезапные удары, лёгкий уход от погони.\n"
            . "   _Цена:_ слабее в тяжёлом вооружении.\n\n"
            . "🛠️ *Инженеры* — роботы, электроника, технологии.\n"
            . "   _Сила:_ уникальный крафт, влияние через техпроекты.\n"
            . "   _Цена:_ требовательны к редким ресурсам и энергии.\n\n"
            . "🌾 *Фермеры* — производство еды и ресурсов, экономика.\n"
            . "   _Сила:_ снабжение союзников, сильная экономбаза.\n"
            . "   _Цена:_ слабее в чистом бою.\n\n"
            . "*Общее для всех:* легендарное оружие и броня своей стороны, "
            . "общий крафт-проект (−10% к крафту всем своим) и скидки у караванов-союзников.\n\n"
            . "_Выбор необратим до вайпа персонажа — выбирай по своему стилю игры._";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛡️ Милитари', 'callback_data' => 'chooseFaction_Military'],
                    ['text' => '🌲 Партизаны', 'callback_data' => 'chooseFaction_Partisans'],
                ],
                [
                    ['text' => '🛠️ Инженеры', 'callback_data' => 'chooseFaction_Engineers'],
                    ['text' => '🌾 Фермеры', 'callback_data' => 'chooseFaction_Farmers'],
                ],
                [
                    ['text' => '⬅️ Назад', 'callback_data' => 'chooseFaction_info'],
                ],
            ],
        ];
        Request::answerCallbackQuery(['callback_query_id' => $chatId]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function getFactionDetails($faction)
    {
        $factionDetails = [
            'Military' => [
                'title' => '🛡️ Милитари',
                'description' => "Фракция, специализирующаяся на военном развитии. Игроки, выбравшие этот путь, могут строить и укреплять военные базы, оснащать свои отряды передовым вооружением и техникой. Их главная цель — защитить свои территории и расширять влияние, захватывая ключевые точки на карте.",
                'advantages' => "👉🏻Доступ к мощному вооружению и технике\n👉🏻Возможность захватывать и удерживать ключевые точки\n👉🏻Военные базы с укреплённой обороной",
                'disadvantages' => "👉🏻Высокий расход ресурсов на вооружение\n👉🏻Серьёзная зависимость от логистики (пища, электроэнергия)",
            ],
            'Partisans' => [
                'title' => '🌲 Партизаны',
                'description' => "Мастера скрытных операций и саботажа. Их стратегия — действовать из тени, устраивать засады и подрывать силы противников, избегая прямых столкновений, когда это невыгодно. Враг никогда не знает, откуда придёт удар.",
                'advantages' => "👉🏻Высокая скрытность и мобильность\n👉🏻Умение устраивать засады и наносить внезапные удары\n👉🏻Лёгкость в уходе от преследования",
                'disadvantages' => "👉🏻Ограниченный доступ к тяжёлому вооружению\n👉🏻Зависимость от неожиданности и тактического преимущества",
            ],
            'Engineers' => [
                'title' => '🛠️ Инженеры',
                'description' => 'Фракция, сфокусированная на высоких технологиях и автоматизации. Инженеры могут строить роботов, разрабатывать сложные электронные системы и использовать их для защиты базы или атаки врагов.',
                'advantages' => "👉🏻Создание и модификация роботов\n👉🏻Уникальные виды крафта и электроники\n👉🏻Возможность влиять на мир через технологические проекты",
                'disadvantages' => "👉🏻Высокая требовательность к редким ресурсам\n👉🏻Зависимость от энергии и научных разработок",
            ],
            'Farmers' => [
                'title' => '🌾 Фермеры',
                'description' => 'Специалисты по агрокультуре и продовольствию. Они обеспечивают мир продуктами питания и лекарственными растениями, формируя мощную экономическую базу для себя и союзников. Однако, при необходимости, тоже участвуют в PvP и способны оборонять свои владения.',
                'advantages' => "👉🏻Расширенное фермерство и производство пищи\n👉🏻Сильное влияние на экономику игры\n👉🏻Способность поддерживать союзников припасами",
                'disadvantages' => "👉🏻Низкая боевая специализация\n👉🏻Возможная уязвимость при недостаточной защите",
            ],
        ];

        return $factionDetails[$faction] ?? [
            'title' => 'Неизвестная фракция',
            'description' => 'Описание этой фракции недоступно.',
            'advantages' => 'Преимущества неизвестны.',
            'disadvantages' => 'Недостатки неизвестны.',
        ];
    }

    /**
     * Строка-стимул «подъёмные за выбор» для экрана выбора (ADR-097). Пусто, если стимул
     * выключен / сумма 0. Сумма live-tunable (GameSettings faction.choice.incentive_*).
     */
    protected function incentiveLine(): string
    {
        if (! $this->gsBool('faction.choice.incentive_enabled', true)) {
            return '';
        }
        $gold = $this->gsInt('faction.choice.incentive_gold', 1000);
        if ($gold <= 0) {
            return '';
        }

        return "💰 *Бонус за выбор фракции: +" . number_format($gold) . "💰* — начислим сразу при вступлении.\n";
    }

    protected function fractionation($chatId, $character, $faction)
    {
        $factionIds = [
            'Military'   => 1,
            'Partisans'  => 2,
            'Engineers'  => 3,
            'Farmers'    => 4,
        ];

        if (!isset($factionIds[$faction])) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Неверная фракция.',
            ]);
        }

        $factionId = $factionIds[$faction];
        $currentTime = date('Y-m-d H:i:s');

        $characterFactionModel = new CharacterFactionModel();
        $factionModel          = new FactionModel();

        // Узнаём, есть ли уже запись (обычно должна быть с faction_id=5, если ещё не выбрал)
        $existingFaction = $characterFactionModel
            ->where('character_id', $character['id'])
            ->first();

        // Если персонаж УЖЕ выбрал фракцию (joined_at не null, notification_status='True'), запрещаем
        if ($existingFaction
            && !empty($existingFaction['joined_at'])
            && $existingFaction['notification_status'] === 'True'
            && (int)$existingFaction['faction_id'] !== 5
        ) {
            $message = "⚑ *Фракция уже выбрана.*\n\n"
                . "Сменить сторону нельзя до вайпа персонажа — присяга есть присяга. "
                . "Сражайся за своих и веди фракцию к превосходству.";

            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => $message,
                'parse_mode' => 'Markdown',
            ]);
        }

        // Если это первая запись (или есть запись, но там faction_id=5), обновляем.
        // Помечаем, что фракция выбрана окончательно
        $data = [
            'faction_id'          => $factionId,
            'joined_at'           => $currentTime,
            'notification_status' => 'True',
            'notified_at'         => $currentTime, // можно обновить, чтоб не слало напоминания
        ];

        // notification_count обязателен по валидации CharacterFactionModel — insert-ветка
        // без него МОЛЧА падала (insert() → false), запись не создавалась. С ADR-097-стимулом
        // это стало эксплойтом (золото без записи → повторный выбор), поэтому: (а) даём
        // notification_count, (б) начисляем подъёмные ТОЛЬКО при успешной фиксации выбора.
        $data['notification_count'] = is_array($existingFaction) && isset($existingFaction['notification_count'])
            ? $existingFaction['notification_count']
            : 0;

        if ($existingFaction) {
            // update (query-builder минует валидацию — обновляем запись faction_id=5 на выбор)
            $saved = (bool) $characterFactionModel
                ->where('id', $existingFaction['id'])
                ->set($data)
                ->update();
        } else {
            // insert (записи не было — например, выбор раньше крон-уведомления)
            $data['character_id'] = $character['id'];
            $saved = (bool) $characterFactionModel->insert($data);
        }

        if (! $saved) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => '⚠️ Не удалось закрепить выбор фракции. Попробуйте ещё раз.',
                'parse_mode' => 'Markdown',
            ]);
        }

        // ADR-097 — ОДНОРАЗОВЫЕ «подъёмные» за первый выбор. Безопасно: выше гейт «уже выбрана»
        // (return) + начисляем ТОЛЬКО после успешной записи выбора ($saved) → не профармить.
        $incentiveText = '';
        if ($this->gsBool('faction.choice.incentive_enabled', true)) {
            $gold = $this->gsInt('faction.choice.incentive_gold', 1000);
            if ($gold > 0) {
                (new CharacterModel())->increaseGold((int) $character['id'], (float) $gold);
                $incentiveText = "\n💰 *Подъёмные зачислены: +" . number_format($gold) . "💰*\n";
            }
        }

        // Получаем данные о новой фракции
        $factionDetails = $this->getFactionDetails($faction);
        $message = "*🎉 Поздравляем!*\n\n"
            . "Вы выбрали фракцию: {$factionDetails['title']}\n"
            . $incentiveText
            . "\n*Описание:*\n{$factionDetails['description']}\n\n"
            . "*➕ Преимущества:*\n{$factionDetails['advantages']}\n\n"
            . "*➖ Недостатки:*\n{$factionDetails['disadvantages']}";

        // Кнопки после выбора
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨‍🎤 Персонаж', 'callback_data' => 'character'],
                    ['text' => '🧭 Двигаться',  'callback_data' => 'move'],
                ],
                [
                    ['text' => '🏕️ Окопаться',     'callback_data' => 'entrench'],
                    ['text' => '📜 Квесты/задания', 'callback_data' => 'questAndTask'],
                ],
            ],
        ];

        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);
        return Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => $message,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

}