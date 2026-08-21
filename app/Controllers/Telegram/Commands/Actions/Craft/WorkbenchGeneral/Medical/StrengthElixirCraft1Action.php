<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Medical;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel;
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс, отвечающий за отображение информации об "Укрепляющем эликсире"
 * и динамическое формирование кнопок крафта в зависимости от имеющихся ресурсов.
 */
class StrengthElixirCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel;
    private CraftCardHelper $craftCardHelper;

    /**
     * Потенциальные варианты крафта (1, 5, 10, 25, 50, 100).
     * Можно вынести в настройки.
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel();
        $this->craftCardHelper        = new CraftCardHelper();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $characterId = $character['id'];

        // Допустим, в таблице crafted_items/crafted_items_log у "Укрепляющего эликсира" name_eng = 'TonicElixir'
        $itemNameEng = 'TonicElixir';

        // Сколько уже есть у игрока
        $elixirQuantity = $this->getCraftedItemQuantity($characterId, $itemNameEng);

        // Формируем заголовок
        $itemTitle = '🧪 Укрепляющий эликсир!';
        if ($elixirQuantity > 0) {
            $itemTitle .= " (в инв. – {$elixirQuantity} шт.)";
        }

        // Ресурсы, необходимые для 1 шт. (пример)
        $requiredResources = [
            'Мед'  => 2,
            'Ягоды'=> 3,
            'Вода' => 20,
        ];

        // Считаем, сколько у игрока есть ресурсов — тем же пулом (рюкзак + склад базы, ADR-171),
        // которым потом считает старт крафта GenericCraftActionStart::checkResources().
        $resourcesAvailable = $this->craftCardHelper->available($characterId, $requiredResources);
        // Максимальное количество эликсиров, которое можно скрафтить
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // Формируем описание
        $text = "*{$itemTitle}*\n\n"
            . "Для крафта *1 шт.* необходимо:\n\n";

        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']];
            $have = $res['quantity'];
            $rarity = $res['rarity'];
            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. (в наличии {$have} ед., редк. {$rarity})\n";
        }

        $text .= "\n*Стоимость на рынке:* _52_ 💰\n"
            . "*Одноразовый:* _Да_\n"
            . "*Время крафта (1 шт.):* _7 минут_\n\n"
            . "*Описание:* Укрепляющий эликсир из раздела лекарств, который "
            . "восстанавливает +18 здоровья и +16 выносливости.\n\n";

        // Если ресурсов не хватает даже на 1 шт.
        if ($maxCraftableItems < 1) {
            $text .= "__Недостаточно ресурсов для крафта хотя бы 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать',   'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',   'callback_data' => 'buy']
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('StrengthElixir'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            // Разбиваем по 3 кнопки в строке
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавляем стандартные кнопки
            $quantityRows[] = [
                ['text' => '👨‍🎤 Персонаж',  'callback_data' => 'character'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать',   'callback_data' => 'sell'],
                ['text' => '🛍️ Купить',   'callback_data' => 'buy']
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'medicinesCraft1']];

            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        $imagePath = base_url('uploads/telegram/craft/tonic_elixir.jpg');
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Получаем, сколько предметов (itemNameEng) у персонажа.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        $logRow = $this->craftedItemsLogModel
            ->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $logRow ? (int)$logRow['quantity'] : 0;
    }

    /**
     * Считаем, на сколько штук хватает ресурсов.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredResources[$name] ?? 0;

            if ($need > 0) {
                $possible = (int)floor($have / $need);
                if ($possible < $maxCraftable) {
                    $maxCraftable = $possible;
                }
            }
        }

        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Формируем кнопки крафта (1, 5, 10, 25, 50, 100), если они <= $maxCraftableItems.
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        // колбэк: "craftStrengtheningElixir_{число}"
        // Можно сделать "craftStrengtheningElixir_50" и т.п.
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_StrengthElixir_{$q}"
                ];
            }
        }
        return $buttons;
    }

    /**
     * (Старый метод) Проверяет только на 1 шт. — можно убрать, если используем calculateMaxCraftableItems().
     */
    private function areAllResourcesSufficient(array $resourcesAvailable, array $requiredResources): bool
    {
        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            if ($res['quantity'] < $need) {
                return false;
            }
        }
        return true;
    }
}
