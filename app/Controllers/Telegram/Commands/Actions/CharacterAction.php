<?php

namespace App\Controllers\Telegram\Commands\Actions;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Entities\ServerResponse;
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
        $this->characterModel = new CharacterModel();
        $this->exploredCellsModel = new ExploredCellsModel();
        $this->mapModel = new MapModel();
        $this->biomeModel = new BiomeModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel = new ResourceModel();
        $this->characterFactionModel = new CharacterFactionModel();
        $this->factionModel = new FactionModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Получаем количество изученных ячеек
        $exploredCellsCount = $this->exploredCellsModel->where('character_id', $character['id'])->countAllResults();

        // Получаем общее количество ресурсов
        $totalResources = $this->characterResourceModel->where('id_characters', $character['id'])->countAllResults();

        // Получаем текущую локацию и биом персонажа
        $currentCell = $this->mapModel->where('cell_number', $character['cell_number'])->first();
        $currentBiome = $this->biomeModel->find($currentCell['biome_id']);

        // Вычисляем время в игре
        $createdDate = new DateTime($character['created_at']);
        $now = new DateTime();
        $interval = $createdDate->diff($now);
        $timeInGame = $interval->format('%m мес. %d дн. %h чс.');

        // Используем напрямую значение золота из записи персонажа
        $goldCoins = $character['gold'] ?? 0;

        $goldCoinsText = $goldCoins > 0
            ? "🧰 В сундуке: 💰*" . number_format($goldCoins) . "* золотишка"
            : "🧰 В сундуке: 💰0💰 золотых монет,\n🛒 Пора заняться торговлей";

        // Получаем имя персонажа
        $characterName = $character['name'] ?? '';

        // Очищаем имя от спецсимволов и пробелов
        $cleanCharacterName = $this->sanitizeName($characterName);

        // Получаем фракцию персонажа
        $factionName = '';
        $characterFaction = $this->characterFactionModel->where('character_id', $character['id'])->first();
        if ($characterFaction) {
            $faction = $this->factionModel->find($characterFaction['faction_id']);
            if ($faction) {
                $factionName = $faction['name'];
            }
        }

        // Формируем сообщение
        $text = "👤 *Персонаж ID*: {$character['id']}\n";
        if ($cleanCharacterName) {
            $text .= "👤 *Имя*: {$cleanCharacterName}\n";
        }
        if ($factionName) {
            $text .= "🏳️ *Фракция*: {$factionName}\n";
        }
        $text .= "\n"
            . "🧭 *Координаты:* X {$currentCell['coordinate_x']} | Y {$currentCell['coordinate_y']} | 🌄 {$currentBiome['name']}\n"
            . "🎢 *Изучено ячеек:* {$exploredCellsCount}\n"
            . "💼 *Всего видов ресурсов:* {$totalResources}\n"
            . "⏳ *В игре:* {$timeInGame}\n"
            . "📈 *Уровень:* {$character['level']}\n🌟 *Опыт:* {$character['experience']}\n"
            . "💖 *Здоровье:* {$character['health']}\n💪 *Сила:* {$character['strength']}\n"
            . "🤸‍♂️ *Ловкость:* {$character['agility']}\n🧠 *Интеллект:* {$character['intellect']}\n"
            . "🥱 *Выносливость:* {$character['tired']}\n\n"
            . $goldCoinsText . "\n"
            . "💹 *Карма торговли:* {$character['trading_karma']}\n\n"
            . "Продолжай исследования, сражения и улучшай свои навыки! Приключения ждут! 🚀";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '🎉 События', 'callback_data' => 'events']
                ],
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
                    ['text' => '🛠️ Крафт', 'callback_data' => 'crafting']
                ],
                [
                    ['text' => '💊 Аптечка', 'callback_data' => 'pharmacy'],
                    ['text' => '🏠 База', 'callback_data' => 'Base'],
                    ['text' => '🧍 Страховка', 'callback_data' => 'PersonalInsurance'],
                ]
            ]
        ];
        $imagePath = base_url('uploads/telegram/picture_of_the_playable_character.png'); // Укажите актуальный путь к изображению
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Ответ в телеграм
        return Request::sendPhoto([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo'   => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
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
