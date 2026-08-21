<?php

namespace App\Controllers\Telegram\Commands\Actions\Craft\WorkbenchGeneral\Components;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Models\CharacterResourceModel;
use App\Models\ResourceModel;
use App\Models\CraftedItemsLogModel; // 1) Подключаем модель логов
use App\Services\Craft\CraftCardHelper;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Класс, показывающий информацию о крафте "Удобрение" (Fertilizer)
 * и формирующий кнопки для количественного крафта (1,5,10,25,50,100).
 */
class FertilizerCraft1Action extends BaseAction
{
    protected $characterResourceModel;
    protected $resourceModel;
    protected $craftedItemsLogModel; // 2) Поле для модели логов
    private CraftCardHelper $craftCardHelper;

    /**
     * Возможные "пакеты" крафта
     */
    private array $craftQuantities = [1, 5, 10, 25, 50, 100];

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->craftedItemsLogModel   = new CraftedItemsLogModel(); // 2) Инициализируем модель логов
        $this->craftCardHelper        = new CraftCardHelper();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден или персонаж не создан.',
            ]);
        }

        // Проверка активного переезда (BaseRelocation)
        if ((new \App\Services\Tasks\ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $this->callbackQuery->getMessage()->getChat()->getId()
        )) {
            return Request::emptyResponse(); // Переезд есть, сервис уже отписался
        }

        $characterId = $character['id'];

        // 1) Узнаём, сколько "Удобрения" (Fertilizer) уже есть в инвентаре у игрока
        $fertNameEng   = 'Fertilizer';
        $fertilizerQty = $this->getCraftedItemQuantity($characterId, $fertNameEng);

        // 2) Формируем заголовок
        $title = '🌿 Удобрение!';
        if ($fertilizerQty > 0) {
            $title .= " (в инв. – {$fertilizerQty} шт.)";
        }

        // Ресурсы (на 1 шт.)
        $requiredResources = [
            'Кости животных' => 1,
            'Вода'           => 5,
            'Водоросли'      => 20,
            'Ил'             => 10,
        ];

        // Информация о текущих ресурсах (для 1 шт.) — тем же пулом (рюкзак + склад базы, ADR-171),
        // которым потом считает старт крафта GenericCraftActionStart::checkResources().
        $resourcesAvailable = $this->craftCardHelper->available($characterId, $requiredResources);
        // Считаем, на сколько штук всего хватает
        $maxCraftableItems  = $this->calculateMaxCraftableItems($resourcesAvailable, $requiredResources);

        // 3) Формируем описание (теперь используя $title)
        $text = "*{$title}*\n\n"
            . "Для крафта *1 шт.* тебе нужны:\n\n";
        foreach ($resourcesAvailable as $res) {
            $need = $requiredResources[$res['name']] ?? 0;
            $have = $res['quantity'];
            $rar  = $res['rarity'];

            $text .= ResourceIconHelper::for($res['name']) . " {$res['name']} - {$need} ед. (в наличии {$have} ед. редк - {$rar})\n";
        }

        $text .= "\n*Стоимость на рынке:* _82_ 💰\n"
            . "*Одноразовый:* _Нет_\n"
            . "*Время крафта (1 шт.):* _~5–12 мин._\n\n"
            . "*Описание:* Компонент для удобрения почвы и растений, важный для фермерства.\n\n";

        // 4) Генерируем клавиатуру
        if ($maxCraftableItems < 1) {
            // Недостаточно ресурсов
            $text .= "__Вы не можете крафтить, так как недостаточно ресурсов даже на 1 шт.__";
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ],
                    [
                        ['text' => '💰 Продать', 'callback_data' => 'sell'],
                        ['text' => '🛍️ Купить',  'callback_data' => 'buy'],
                    ],
                    [
                        $this->craftCardHelper->fallbackButton('Fertilizer'),
                    ],
                    [
                        ['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft'],
                    ],
                ]
            ];
        } else {
            // Можно крафтить
            $quantityButtons = $this->getAvailableQuantityButtons($maxCraftableItems);
            $quantityRows    = array_chunk($quantityButtons, 3);

            // Добавим кнопки "Инвентарь", "Продать/Купить" и т.д.
            $quantityRows[] = [
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ];
            $quantityRows[] = [
                ['text' => '💰 Продать', 'callback_data' => 'sell'],
                ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
            ];
            $quantityRows[] = [['text' => '⬅️ Назад', 'callback_data' => 'componentsCraft']];
            $keyboard = ['inline_keyboard' => $quantityRows];
        }

        // 5) Отправляем
        $imagePath = base_url('uploads/telegram/craft/components/craftFertilizer.jpg');
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
     * Узнать, сколько уже скрафченного "Fertilizer" (или любого itemNameEng) есть у игрока.
     */
    private function getCraftedItemQuantity(int $characterId, string $itemNameEng): int
    {
        // Если в CraftedItemsLogModel есть метод getItemByNameEngAndCharacterId,
        // то используем его. Иначе — свой запрос.
        // Возвращаем 0, если ничего не найдено.
        $itemRow = $this->craftedItemsLogModel->getItemByNameEngAndCharacterId($itemNameEng, $characterId);
        return $itemRow ? (int)$itemRow['quantity'] : 0;
    }

    /**
     * Определяем, на сколько шт. (учитывая все ресурсы) хватает у игрока.
     */
    private function calculateMaxCraftableItems(array $resourcesAvailable, array $requiredResources): int
    {
        $maxCraftable = PHP_INT_MAX;

        foreach ($resourcesAvailable as $res) {
            $name = $res['name'];
            $have = $res['quantity'];
            $need = $requiredResources[$name] ?? 0;

            if ($need > 0) {
                $possible = (int) floor($have / $need);
                if ($possible < $maxCraftable) {
                    $maxCraftable = $possible;
                }
            }
        }

        // Если нет ограничивающего ресурса, вернём 0
        return ($maxCraftable === PHP_INT_MAX) ? 0 : $maxCraftable;
    }

    /**
     * Генерируем кнопки: "Крафт 1шт", "Крафт 5шт", ... "Крафт 100шт" (где N <= maxCraftable).
     * Пример callback_data: "craftFertilizer_5"
     */
    private function getAvailableQuantityButtons(int $maxCraftableItems): array
    {
        $buttons = [];
        foreach ($this->craftQuantities as $q) {
            if ($q <= $maxCraftableItems) {
                $buttons[] = [
                    'text'          => "🛠️ Крафт {$q}шт",
                    'callback_data' => "genericCraft_Fertilizer_{$q}"
                ];
            }
        }
        return $buttons;
    }
}
