<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Keyboard; // не ReplyKeyboardMarkup, а именно Keyboard
use App\Models\CharacterModel;
use App\Models\ExploredCellsModel;
use App\Models\MapModel;
use App\Models\BiomeModel;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CharacterFactionModel;
use App\Models\FactionModel;
use DateTime;

class CharacterAction extends BaseAction
{
    protected $characterModel;
    protected $exploredCellsModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $characterFactionModel;
    protected $factionModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);

        $this->characterModel         = new CharacterModel();
        $this->exploredCellsModel     = new ExploredCellsModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterFactionModel  = new CharacterFactionModel();
        $this->factionModel           = new FactionModel();
    }

    public function handle(): ServerResponse
    {
        // 1. Проверяем данные пользователя/персонажа
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 2. Создаём "Reply-клавиатуру" (постоянную) — передаём ВСЁ одной структурой массива
        $replyKeyboard = new Keyboard([
            'keyboard' => [
                // Каждая вложенная строка массива - это одна горизонтальная строка кнопок
                ['Персонаж', 'База', 'Крафт'],
            ],
            'resize_keyboard'   => true,  // сжимаем клавиатуру под кнопки
            'one_time_keyboard' => false, // не скрывать после нажатия
            'selective'         => false, // показывать всем
        ]);

        // 3. Отправляем «короткое» сообщение, чтобы Telegram "зафиксировал" нашу Reply-клавиатуру
        Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => "Используйте меню внизу экрана, чтобы выбрать действие.",
            'reply_markup' => $replyKeyboard,
        ]);

        // --------------------
        // 4. Логика "CharacterAction": формируем краткие сведения о персонаже + inline-кнопки.

        // Собираем данные о персонаже:
        $exploredCellsCount = $this->exploredCellsModel
            ->where('character_id', $character['id'])
            ->countAllResults();
        $totalResources = $this->characterResourceModel
            ->where('id_characters', $character['id'])
            ->countAllResults();

        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        $currentBiome = $this->biomeModel->find($currentCell['biome_id'] ?? 0);

        // Пример вычисления времени в игре
        $createdDate = new DateTime($character['created_at']);
        $now         = new DateTime();
        $interval    = $createdDate->diff($now);
        $timeInGame  = $interval->format('%m мес. %d дн. %h чс.');

        $goldCoins = $character['gold'] ?? 0;
        $goldText  = $goldCoins > 0
            ? "🧰 Есть 💰*" . number_format($goldCoins) . "* золота"
            : "🧰 Золото отсутствует!";

        // Определим фракцию
        $factionName = '';
        $charFaction = $this->characterFactionModel->where('character_id', $character['id'])->first();
        if ($charFaction) {
            $faction = $this->factionModel->find($charFaction['faction_id']);
            $factionName = $faction['name'] ?? '';
        }

        // Сформируем текст
        $cleanName = $this->sanitizeName($character['name'] ?? '');
        $biomeName = $currentBiome['name'] ?? '???';

        $text = "🤖 *Персонаж {$cleanName}*\n";
        if ($factionName) {
            $text .= "🏳️ *Фракция:* {$factionName}\n";
        }
        $text .= "🧭 *Координаты:* X={$currentCell['coordinate_x']} Y={$currentCell['coordinate_y']} | 🌄 {$biomeName}\n"
            . "🎢 *Изучено ячеек:* {$exploredCellsCount}\n"
            . "💼 *Всего видов ресурсов:* {$totalResources}\n"
            . "⏳ *В игре:* {$timeInGame}\n"
            . "📈 *Уровень:* {$character['level']}\n"
            . "🌟 *Опыт:* {$character['experience']}\n"
            . "🤸‍♂️ *Ловкость:* {$character['agility']}\n"
            . "🧠 *Интеллект:* {$character['intellect']}\n"
            . "💖 *Здоровье:* {$character['health']}\n"
            . "💪 *Сила:* {$character['strength']}\n"
            . "🥱 *Выносливость:* {$character['tired']}\n\n"
            . $goldText . "\n"
            . "💹 *Карма торговли:* {$character['trading_karma']}\n\n"
            . "Продолжай исследования, сражения и улучшай свои навыки! Приключения ждут! 🚀";

        // 5. Inline-кнопки (которые идут под сообщением, как в примере):
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🎉 События',     'callback_data' => 'events'],
                ],
                [
                    ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',     'callback_data' => 'shop'],
                ],
            ]
        ];

        // 6. Отправляем «второе» сообщение — уже с inline-кнопками и инфой о персонаже
        $imagePath = base_url('uploads/telegram/picture_of_the_playable_character.png');
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($inlineKeyboard),
        ]);
    }

    private function sanitizeName($name)
    {
        // Заменяем символы "_" и "-" на пробелы
        $name = str_replace(['_', '-'], ' ', $name);
        // Оставляем только цифры, русские, украинские и английские буквы
        return preg_replace('/[^a-zA-Zа-яА-ЯёЁґҐєЄїЇ0-9 ]/u', '', $name);
    }
}
