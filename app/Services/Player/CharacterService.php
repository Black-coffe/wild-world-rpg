<?php

namespace App\Services\Player;

use App\Models\BiomeModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ExploredCellsModel;
use App\Models\FactionModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use DateTime;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

// Модели

class CharacterService
{
    protected $characterModel;
    protected $exploredCellsModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $characterFactionModel;
    protected $factionModel;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->exploredCellsModel     = new ExploredCellsModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterFactionModel  = new CharacterFactionModel();
        $this->factionModel           = new FactionModel();
    }

    /**
     * Показ информации о персонаже + установка постоянной клавиатуры.
     */
    public function showCharacterInfo(int $chatId, array $characterRow): ServerResponse
    {
        // 1. Устанавливаем «ReplyKeyboard»
        $replyKeyboard = new Keyboard([
            'keyboard' => [
                ['Перс', 'База', 'Крафт', 'Карта'],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'selective'         => false,
        ]);

        Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => "Используйте меню внизу экрана, чтобы выбрать действие.",
            'reply_markup' => $replyKeyboard,
        ]);

        // 2. Собираем сведения
        $exploredCount = $this->exploredCellsModel
            ->where('character_id', $characterRow['id'])
            ->countAllResults();

        $totalResources = $this->characterResourceModel
            ->where('id_characters', $characterRow['id'])
            ->countAllResults();

        $cell  = $this->mapModel->where('cell_number', $characterRow['cell_number'])->first();
        $biome = ($cell)
            ? $this->biomeModel->find($cell['biome_id'])
            : null;

        $createdDate = new DateTime($characterRow['created_at']);
        $interval   = $createdDate->diff(new DateTime());
        $timeInGame = $interval->format('%m мес. %d дн. %h чс.');

        $gold = $characterRow['gold'] ?? 0;
        $goldText = ($gold > 0)
            ? "🧰 Есть 💰*" . number_format($gold) . "* золота"
            : "🧰 Золото отсутствует!";

        // Фракция
        $factionName = '';
        $charFaction = $this->characterFactionModel
            ->where('character_id', $characterRow['id'])
            ->first();
        if ($charFaction) {
            $faction = $this->factionModel->find($charFaction['faction_id']);
            if ($faction) {
                $factionName = $faction['name'];
            }
        }

        // Итоговый текст
        $cleanName  = $this->sanitizeName($characterRow['name'] ?? '');
        $biomeName  = $biome['name'] ?? '???';
        $text = "🤖 *Персонаж {$cleanName}*\n";
        if ($factionName) {
            $text .= "🏳️ *Фракция:* {$factionName}\n";
        }
        if ($cell) {
            $text .= "🧭 *Координаты:* X={$cell['coordinate_x']} Y={$cell['coordinate_y']} | 🌄 {$biomeName}\n";
        }
        $text .= "🎢 *Изучено ячеек:* {$exploredCount}\n"
            . "💼 *Всего видов ресурсов:* {$totalResources}\n"
            . "⏳ *В игре:* {$timeInGame}\n"
            . "📈 *Уровень:* {$characterRow['level']}\n"
            . "🌟 *Опыт:* {$characterRow['experience']}\n"
            . "🤸‍♂️ *Ловкость:* {$characterRow['agility']}\n"
            . "🧠 *Интеллект:* {$characterRow['intellect']}\n"
            . "💪 *Сила:* {$characterRow['strength']}\n\n"
            . "💖 *Здоровье:* {$characterRow['health']}\n"
            . "🥱 *Выносливость:* {$characterRow['tired']}\n\n"
            . "💹 *Карма торговли:* {$characterRow['trading_karma']}\n"
            . $goldText . "\n\n";

        // Инлайн-кнопки
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🎉 События',     'callback_data' => 'events'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
                [
                    ['text' => '📡 Маяки',       'callback_data' => 'teleportBeacon'],
                    ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',     'callback_data' => 'shop'],
                ],
                [
                    ['text' => '🧍 Страховка',      'callback_data' => 'PersonalInsurance'],
                    ['text' => '💊 Аптечка',        'callback_data' => 'pharmacy'],
                    ['text' => '⚔️ Экип',           'callback_data' => 'equipMenu'],
                ],
            ]
        ];

        // 3. Отправляем сообщение + картинка
        $imagePath = base_url('uploads/telegram/picture_of_the_playable_character.png');
        return Request::sendPhoto([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($inlineKeyboard),
        ]);
    }

    private function sanitizeName(string $name): string
    {
        $name = str_replace(['_', '-'], ' ', $name);
        return preg_replace('/[^a-zA-Zа-яА-ЯёЁґҐєЄїЇ0-9 ]/u', '', $name);
    }
}
