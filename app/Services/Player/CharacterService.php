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
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;
use DateTime;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

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
    protected $charactersWeaponsModel;
    protected $weaponsModel;
    protected $charactersOutfitsModel;
    protected $outfitsModel;

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
        $this->charactersWeaponsModel = new CharactersWeaponsModel();
        $this->weaponsModel           = new WeaponModel();
        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->outfitsModel           = new OutfitModel();
    }

    /**
     * Показ информации о персонаже + установка клавиатуры.
     */
    public function showCharacterInfo(int $chatId, array|\App\Entities\CharacterEntity $characterRow): ServerResponse
    {
        // 1. Устанавливаем клавиатуру
        $replyKeyboard = new Keyboard([
            'keyboard' => [['Перс', 'База', 'Крафт', 'Карта']],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
            'selective'         => false,
        ]);

        Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => "Используйте меню внизу экрана, чтобы выбрать действие.",
            'reply_markup' => $replyKeyboard,
        ]);

        // 2. Собираем сведения о персонаже
        $exploredCount = $this->exploredCellsModel->where('character_id', $characterRow['id'])->countAllResults();
        $totalResources = $this->characterResourceModel->where('id_characters', $characterRow['id'])->countAllResults();

        $cell  = $this->mapModel->where('cell_number', $characterRow['cell_number'])->first();
        $biome = ($cell) ? $this->biomeModel->find($cell['biome_id']) : null;

        // v0.51.121 hotfix: cast Time|null|string → string. CI4 Entity wraps
        // `created_at` як Time object (per F1.4.4-B v0.48.0 dates array).
        $createdAtRaw = $characterRow['created_at'] ?? null;
        $createdAtStr = $createdAtRaw instanceof \DateTimeInterface
            ? $createdAtRaw->format('Y-m-d H:i:s')
            : (string) ($createdAtRaw ?? '1970-01-01');
        $createdDate = new DateTime($createdAtStr);
        $interval   = $createdDate->diff(new DateTime());
        $timeInGame = $interval->format('%m мес. %d дн. %h чс.');

        $gold = $characterRow['gold'] ?? 0;
        $goldText = ($gold > 0)
            ? "🧰 Есть 💰*" . number_format($gold) . "* золота"
            : "🧰 Золото отсутствует!";

        // Фракция персонажа
        $factionName = '';
        $charFaction = $this->characterFactionModel->where('character_id', $characterRow['id'])->first();
        if ($charFaction) {
            $faction = $this->factionModel->find($charFaction['faction_id']);
            if ($faction) {
                $factionName = $faction['name'];
            }
        }

        // Получаем экипировку (броня и оружие)
        $equippedWeapon = $this->getEquippedWeapon($characterRow['id']);
        $equippedArmor  = $this->getEquippedArmor($characterRow['id']);

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

        // Добавляем броню и оружие
        $text .= "🛡 *Броня:* " . ($equippedArmor ?: "❌ Нет") . "\n";
        $text .= "⚔️ *Оружие:* " . ($equippedWeapon ?: "❌ Нет") . "\n";

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

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($inlineKeyboard),
        ]);
    }

    private function getEquippedWeapon(int $characterId): ?string
    {
        $row = $this->charactersWeaponsModel->where('character_id', $characterId)->where('equipped', 1)->first();
        return $row ? ($this->weaponsModel->find($row['weapon_id'])['name'] ?? null) : null;
    }

    private function getEquippedArmor(int $characterId): ?string
    {
        // Берём все экипированные предметы
        $equippedItems = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('equipped', 1)
            ->findAll();

        if (empty($equippedItems)) {
            return null;
        }

        $armorNames = [];
        foreach ($equippedItems as $item) {
            // Для каждого предмета достаём запись из outfits
            $outfitRow = $this->outfitsModel->find($item['outfit_id']);
            if ($outfitRow) {
                $armorNames[] = $outfitRow['name'];
            }
        }

        // Склеиваем все названия запятой или любым нужным разделителем
        return !empty($armorNames) ? implode(', ', $armorNames) : null;
    }

    private function sanitizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Zа-яА-ЯёЁґҐєЄїЇ0-9 ]/u', '', str_replace(['_', '-'], ' ', $name)) ?? '';
    }
}
